<?php

namespace App\Mail;

use App\Mail\MailChannels\EmailSendingNotReady;
use App\Models\Team;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Support\Facades\Mail;

/**
 * A mailer for one congregation's own MailChannels account. Congregation mail
 * never falls back to the platform's account in .env.
 *
 * It is only built once EmailSending says the account is ready: a key, a
 * sending domain, and DKIM, SPF and Domain Lockdown passing.
 *
 * Mail sent this way is list mail, so it is marked non-transactional (which
 * MailChannels requires DKIM for) and has open and click tracking off.
 */
class CongregationMailer
{
    /**
     * @throws EmailSendingNotReady
     */
    public static function for(Team $team): Mailer
    {
        $problem = EmailSending::problem($team);

        if ($problem !== null) {
            throw new EmailSendingNotReady($problem);
        }

        return Mail::build([
            'transport' => 'mailchannels',
            'key' => $team->mailchannels_api_key,
            'dkim_domain' => $team->sending_domain,
            'dkim_selector' => $team->dkim_selector,
            'transactional' => false,
            'tracking' => false,
        ]);
    }

    /** Whether the address is on the congregation's sending domain. */
    public static function isOnSendingDomain(Team $team, string $address): bool
    {
        if (blank($team->sending_domain)) {
            return true;
        }

        $domain = strtolower((string) substr(strrchr($address, '@') ?: '', 1));

        return $domain === strtolower($team->sending_domain);
    }
}
