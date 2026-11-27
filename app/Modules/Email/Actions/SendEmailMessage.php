<?php

namespace App\Modules\Email\Actions;

use App\Mail\CongregationMailer;
use App\Mail\MailChannels\EmailSendingNotReady;
use App\Mail\MailChannels\MailChannelsException;
use App\Mail\Transport\MailChannelsTransport;
use App\Models\Team;
use App\Modules\Email\Models\EmailRecipient;
use App\Modules\Email\Models\EmailSetting;

class SendEmailMessage
{
    public function __construct(private RenderEmail $renderer) {}

    /**
     * Send one person an email through the congregation's own MailChannels
     * account. The subject and body are a run's finished text; only {{name}}
     * and {{unsubscribe_url}} are filled in here.
     *
     * @param  string  $campaign  Groups the messages of one send in MailChannels.
     * @return array{message_id: string|null, request_id: string|null} MailChannels' ids for the message.
     *
     * @throws EmailSendingNotReady
     * @throws MailChannelsException
     */
    public function handle(Team $team, EmailSetting $sender, string $subject, string $body, EmailRecipient $recipient, string $campaign): array
    {
        $rendered = $this->renderer->personalize($subject, $body, $recipient);

        $sent = CongregationMailer::for($team)->html($rendered['html'], function ($message) use ($sender, $recipient, $rendered, $campaign) {
            $message->to($recipient->email, $recipient->name)
                ->from($sender->from_address, $sender->from_name)
                ->subject($rendered['subject'])
                ->text($rendered['text']);

            if ($sender->reply_to) {
                $message->replyTo($sender->reply_to);
            }

            $headers = $message->getSymfonyMessage()->getHeaders();
            $headers->addTextHeader(MailChannelsTransport::CAMPAIGN_HEADER, $campaign);

            if ($recipient->exists) {
                $headers->addTextHeader('List-Unsubscribe', '<'.$recipient->unsubscribeUrl().'>');
                $headers->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
            }
        });

        return [
            'message_id' => $sent?->getMessageId(),
            'request_id' => $sent ? MailChannelsTransport::requestId($sent->getDebug()) : null,
        ];
    }
}
