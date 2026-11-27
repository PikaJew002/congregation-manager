<?php

namespace App\Modules\Email\Actions;

use App\Modules\Email\Models\Email;
use App\Modules\Email\Models\EmailRecipient;
use App\Modules\Email\Models\EmailSetting;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Throwable;

/**
 * Fills in an email's placeholders in two steps.
 *
 * When a run is created, resolve() fills in the variables and the dates, so
 * every person in the run gets the same text however long it takes to send.
 * Dates are worked out from the run's send time with PHP's relative date
 * words, never by running code: {{date:saturday|M j, Y}} is the Saturday on
 * or after the send time.
 *
 * As each email goes out, personalize() fills in {{name}} and
 * {{unsubscribe_url}} and turns the Markdown into HTML.
 */
class RenderEmail
{
    /** Filled in per person, so they are left alone by resolve(). */
    public const PERSONAL = ['name', 'unsubscribe_url'];

    /** Names a variable cannot take. */
    public const RESERVED = ['name', 'unsubscribe_url', 'date'];

    public const DEFAULT_DATE_FORMAT = 'M j, Y';

    private const VARIABLE_PATTERN = '/\{\{\s*([A-Za-z][A-Za-z0-9_]*)\s*\}\}/';

    private const DATE_PATTERN = '/\{\{\s*date\s*(?::([^|}]*))?(?:\|([^}]*))?\}\}/';

    /**
     * The variables an email can use: the shared ones, with the email's own
     * taking their place where the names match.
     *
     * @return array<string, string>
     */
    public function variablesFor(Email $email, EmailSetting $settings): array
    {
        return array_merge($settings->variableMap(), $email->variableMap());
    }

    /**
     * Fill in variables and dates. Dates count from $at, in its time zone.
     * Anything unknown is left as written.
     *
     * @param  array<string, string>  $variables
     */
    public function resolve(string $text, array $variables, CarbonImmutable $at): string
    {
        $text = (string) preg_replace_callback(self::VARIABLE_PATTERN, function (array $match) use ($variables) {
            $key = $match[1];

            if (in_array($key, self::RESERVED, true) || ! array_key_exists($key, $variables)) {
                return $match[0];
            }

            return $variables[$key];
        }, $text);

        return (string) preg_replace_callback(self::DATE_PATTERN, function (array $match) use ($at) {
            $date = $this->date($at, $match[1] ?? '');

            if ($date === null) {
                return $match[0];
            }

            $format = trim($match[2] ?? '');

            return $date->format($format === '' ? self::DEFAULT_DATE_FORMAT : $format);
        }, $text);
    }

    /**
     * What would go wrong filling in this text: variables nobody has set, and
     * dates PHP cannot read.
     *
     * @param  array<string, string>  $variables
     * @return list<string>
     */
    public function problems(string $text, array $variables): array
    {
        $problems = [];

        preg_match_all(self::VARIABLE_PATTERN, $text, $variableMatches);

        foreach (array_unique($variableMatches[1]) as $key) {
            if (! in_array($key, self::RESERVED, true) && ! array_key_exists($key, $variables)) {
                $problems[] = "{{{$key}}} is not a variable. Add it to this email's variables or the shared variables.";
            }
        }

        preg_match_all(self::DATE_PATTERN, $text, $dateMatches, PREG_SET_ORDER);

        foreach ($dateMatches as $match) {
            $expression = trim($match[1] ?? '');

            if ($this->date(CarbonImmutable::now(), $expression) === null) {
                $problems[] = "{$match[0]}: “{$expression}” is not a date this can work out. Try words like “saturday”, “next friday” or “+1 week”.";
            }
        }

        return array_values(array_unique($problems));
    }

    /**
     * One person's copy of a run's finished text.
     *
     * @return array{subject: string, text: string, html: string}
     */
    public function personalize(string $subject, string $body, EmailRecipient $recipient): array
    {
        $replace = [
            '{{name}}' => $recipient->name ?: 'friend',
            '{{unsubscribe_url}}' => $recipient->unsubscribe_token ? $recipient->unsubscribeUrl() : '#unsubscribe',
        ];

        $subject = strtr($subject, $replace);
        $text = strtr($body, $replace);

        // Keep single line breaks: "Topic: …" and "Time: …" on their own lines.
        $html = (string) Str::markdown($text, ['renderer' => ['soft_break' => "<br />\n"]]);

        return compact('subject', 'text', 'html');
    }

    private function date(CarbonImmutable $at, string $expression): ?CarbonImmutable
    {
        $expression = trim($expression);

        if ($expression === '') {
            return $at;
        }

        // On PHP 8.3 and later, words it cannot read throw instead of
        // returning false.
        try {
            return $at->modify($expression);
        } catch (Throwable) {
            return null;
        }
    }
}
