forked and fixed from https://github.com/jos0405/mauticawsmailer

Mautic 5 SNS plugin.

1. Download in your Plugins folder and rename to MauticAWSBundle
2. Set SNS Bounce and Complaint feedback loops to:
https://yourmauticurl.com/mailer/amazon/callback

Functions:

1. Subscription Confirmation
2. Bounce handling
   - The plugin will handle Permanent Bounces as DNC
   - All (Temporary) Transient Bounces will be ignored
3. Complaint handling
   - Complaints are also classified as DNC, with the reason being passed from "complaintFeedbackType" value. If not captured, then 'abuse'
  
