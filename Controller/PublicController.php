<?php

namespace MauticPlugin\MauticAWSBundle\Controller;

use Doctrine\Persistence\ManagerRegistry;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\TransferException;
use Mautic\CoreBundle\Controller\FormController as CommonFormController;
use Mautic\CoreBundle\Factory\MauticFactory;
use Mautic\CoreBundle\Factory\ModelFactory;
use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\CoreBundle\Helper\UserHelper;
use Mautic\CoreBundle\Security\Permissions\CorePermissions;
use Mautic\CoreBundle\Service\FlashBag;
use Mautic\CoreBundle\Translation\Translator;
use Mautic\EmailBundle\Model\TransportCallback;
use Mautic\FormBundle\Helper\FormFieldHelper;
use Mautic\LeadBundle\Entity\DoNotContact;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PublicController extends CommonFormController
{
    public function __construct(
        FormFactoryInterface        $formFactory,
        FormFieldHelper             $fieldHelper,
        ManagerRegistry             $managerRegistry,
        MauticFactory               $factory,
        ModelFactory                $modelFactory,
        UserHelper                  $userHelper,
        CoreParametersHelper        $coreParametersHelper,
        EventDispatcherInterface    $dispatcher,
        Translator                  $translator,
        FlashBag                    $flashBag,
        RequestStack                $requestStack,
        CorePermissions             $security,
        protected LoggerInterface   $logger,
        protected Client            $httpClient,
        protected TransportCallback $transportCallback
    )
    {
        $this->factory = $factory;
        parent::__construct($formFactory, $fieldHelper, $managerRegistry, $modelFactory, $userHelper, $coreParametersHelper, $dispatcher, $translator, $flashBag, $requestStack, $security);
    }

    /**
     * Handles mailer transport webhook post.
     *
     * @param $transport
     *
     * @return Response
     */
    public function mailerCallbackAction(Request $request, $transport)
    {
        $this->processCallbackRequest($request);

        return new Response('success');
    }

    /**
     * Handle bounces & complaints from Amazon.
     *
     * @return array
     */
    public function processCallbackRequest(Request $request): void
    {
        $this->logger->debug('Receiving webhook from Amazon');

        $payload = json_decode($request->getContent(), true);

        if (0 !== json_last_error()) {
            throw new HttpException(400, 'AmazonCallback: Invalid JSON Payload');
        }

        if (!isset($payload['Type']) && !isset($payload['eventType'])) {
            throw new HttpException(400, "Key 'Type' not found in payload ");
        }

        // determine correct key for message type (global or via ConfigurationSet)
        $type = (array_key_exists('Type', $payload) ? $payload['Type'] : $payload['eventType']);

        $this->processJsonPayload($payload, $type);
    }

    /**
     * Process json request from Amazon SES.
     *
     * http://docs.aws.amazon.com/ses/latest/DeveloperGuide/best-practices-bounces-complaints.html
     *
     * @param array $payload from Amazon SES
     */

    public function processJsonPayload(array $payload, $type): void
    {
        switch ($type) {
            case 'SubscriptionConfirmation':
                // Confirm Amazon SNS subscription by calling back the SubscribeURL from the playload
                try {
                    $response = $this->httpClient->get($payload['SubscribeURL']);
                    if (200 == $response->getStatusCode()) {
                        $this->logger->info('Callback to SubscribeURL from Amazon SNS successfully');
                        break;
                    }

                    $reason = 'HTTP Code ' . $response->getStatusCode() . ', ' . $response->getBody();
                } catch (TransferException $e) {
                    $reason = $e->getMessage();
                }

                $this->logger->error('Callback to SubscribeURL from Amazon SNS failed, reason: ' . $reason);
                break;
            case 'Notification':
                $message = json_decode($payload['Message'], true);
                $notificationType = $message['notificationType'];

                if ($notificationType === 'Delivery') {
                    // Handle delivery notification

                } elseif ($notificationType === 'Bounce') {
		    if ($message['bounce']['bounceType'] == 'Permanent') {
                    $emailId = null;

                    if (isset($payload['mail']['headers'])) {
                       foreach ($payload['mail']['headers'] as $header) {
                           if ('X-EMAIL-ID' === $header['name']) {
                               $emailId = $header['value'];
                           }
                       }
                   }
                  // Get bounced recipients in an array
                   $bouncedRecipients = $message['bounce']['bouncedRecipients'];
                   foreach ($bouncedRecipients as $bouncedRecipient) {
                       $bounceCode = array_key_exists('diagnosticCode', $bouncedRecipient) ? $bouncedRecipient['diagnosticCode'] : 'unknown';
                       $this->transportCallback->addFailureByAddress($bouncedRecipient['emailAddress'], $bounceCode, DoNotContact::BOUNCED, $emailId);
                      $this->logger->debug("Mark email '" . $bouncedRecipient['emailAddress'] . "' as bounced, reason: " . $bounceCode);
                    }
                  }
                } elseif ($notificationType === 'Complaint') {
//		    $message = json_decode($payload['Message'], true);
                    $emailId = null;
                    if (isset($payload['mail']['headers'])) {
                        foreach ($payload['mail']['headers'] as $header) {
                            if ('X-EMAIL-ID' === $header['name']) {
                                $emailId = $header['value'];
                            }
                        }
		    }
                    // Get bounced recipients in an array
                    $complaintRecipients = $message['complaint']['complainedRecipients'];
                    foreach ($complaintRecipients as $complaintRecipient) {
			$bounceCode = array_key_exists('complaintFeedbackType', $complaintRecipient) ? $complaintRecipient['complaintFeedbackType'] : 'unknown';
                        $this->transportCallback->addFailureByAddress($complaintRecipient['emailAddress'], $bounceCode, DoNotContact::BOUNCED, $emailId);
                        $this->logger->debug("Mark email '" . $complaintRecipient['emailAddress'] . "' has complained, reason: " . $bounceCode);
                }
                break;
                } else {
                    $this->logger->error('Unsupported notification type: ' . $notificationType);
                }
                break;
            default:
                // $this->logger->warning("Received SES webhook of type '$payload[Type]' but couldn't understand payload");
                $this->logger->warning('SES webhook payload: ' . json_encode($payload));
                break;
        }
    }
}
