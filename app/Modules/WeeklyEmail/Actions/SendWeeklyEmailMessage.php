<?php

namespace App\Modules\WeeklyEmail\Actions;

use App\Mail\CongregationMailer;
use App\Mail\MailChannels\EmailSendingNotReady;
use App\Mail\MailChannels\MailChannelsException;
use App\Mail\Transport\MailChannelsTransport;
use App\Models\Team;
use App\Modules\WeeklyEmail\Models\EmailRecipient;
use App\Modules\WeeklyEmail\Models\WeeklyEmailSetting;

class SendWeeklyEmailMessage
{
    public function __construct(private RenderWeeklyEmail $renderer) {}

    /**
     * Send one person the weekly email through the congregation's own
     * MailChannels account.
     *
     * @param  string  $campaign  Groups the messages of one send in MailChannels.
     * @return string|null MailChannels' id for the message.
     *
     * @throws EmailSendingNotReady
     * @throws MailChannelsException
     */
    public function handle(Team $team, WeeklyEmailSetting $settings, EmailRecipient $recipient, string $campaign): ?string
    {
        $rendered = $this->renderer->handle($settings, $recipient);

        $sent = CongregationMailer::for($team)->html($rendered['html'], function ($message) use ($settings, $recipient, $rendered, $campaign) {
            $message->to($recipient->email, $recipient->name)
                ->from($settings->from_address, $settings->from_name)
                ->subject($rendered['subject'])
                ->text($rendered['text']);

            if ($settings->reply_to) {
                $message->replyTo($settings->reply_to);
            }

            $headers = $message->getSymfonyMessage()->getHeaders();
            $headers->addTextHeader(MailChannelsTransport::CAMPAIGN_HEADER, $campaign);

            if ($recipient->exists) {
                $headers->addTextHeader('List-Unsubscribe', '<'.$recipient->unsubscribeUrl().'>');
                $headers->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
            }
        });

        return $sent?->getMessageId();
    }
}
