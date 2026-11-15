<?php

namespace App\Modules\WeeklyEmail\Actions;

use App\Modules\WeeklyEmail\Models\EmailRecipient;
use App\Modules\WeeklyEmail\Models\WeeklyEmailSetting;
use Illuminate\Support\Facades\Mail;

class SendWeeklyEmailMessage
{
    public function __construct(private RenderWeeklyEmail $renderer) {}

    public function handle(WeeklyEmailSetting $settings, EmailRecipient $recipient): void
    {
        $rendered = $this->renderer->handle($settings, $recipient);

        Mail::mailer('mailchannels')->html($rendered['html'], function ($message) use ($settings, $recipient, $rendered) {
            $message->to($recipient->email, $recipient->name)
                ->from($settings->from_address, $settings->from_name)
                ->subject($rendered['subject'])
                ->text($rendered['text']);

            if ($settings->reply_to) {
                $message->replyTo($settings->reply_to);
            }

            if ($recipient->exists) {
                $headers = $message->getSymfonyMessage()->getHeaders();
                $headers->addTextHeader('List-Unsubscribe', '<'.$recipient->unsubscribeUrl().'>');
                $headers->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
            }
        });
    }
}
