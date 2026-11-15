<?php

namespace App\Modules\WeeklyEmail\Actions;

use App\Modules\WeeklyEmail\Models\EmailRecipient;
use App\Modules\WeeklyEmail\Models\WeeklyEmailSetting;
use Illuminate\Support\Str;

class RenderWeeklyEmail
{
    /**
     * @return array{subject: string, text: string, html: string}
     */
    public function handle(WeeklyEmailSetting $settings, EmailRecipient $recipient): array
    {
        $replace = [
            '{{name}}' => $recipient->name ?: 'friend',
            '{{zoom_link}}' => (string) $settings->zoom_link,
            '{{unsubscribe_url}}' => $recipient->unsubscribe_token ? $recipient->unsubscribeUrl() : '#unsubscribe',
        ];

        $subject = strtr($settings->subject, $replace);
        $text = strtr($settings->body, $replace);
        $html = (string) Str::markdown($text);

        return compact('subject', 'text', 'html');
    }
}
