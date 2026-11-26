<?php

namespace App\Http\Controllers\Teams;

use App\Http\Controllers\Controller;
use App\Http\Requests\Teams\UpdateTeamEmailSendingRequest;
use App\Mail\EmailSending;
use App\Mail\MailChannels\AccountProblem;
use App\Mail\MailChannels\MailChannelsApi;
use App\Mail\MailChannels\MailChannelsException;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * The congregation's own MailChannels account, which every module that sends
 * mail uses.
 */
class TeamEmailSendingController extends Controller
{
    public function update(UpdateTeamEmailSendingRequest $request, Team $team): RedirectResponse
    {
        $data = $request->validated();

        $key = match (true) {
            (bool) ($data['clear_mailchannels_api_key'] ?? false) => null,
            filled($data['mailchannels_api_key'] ?? null) => trim($data['mailchannels_api_key']),
            default => $team->mailchannels_api_key,
        };
        $domain = $data['sending_domain'] ?? null;

        $keyChanged = $key !== $team->mailchannels_api_key;
        $domainChanged = $domain !== $team->sending_domain;

        $team->mailchannels_api_key = $key;
        $team->mailchannels_account_id = $data['mailchannels_account_id'] ?? null;
        $team->sending_domain = $domain;
        $team->daily_email_limit = (int) $data['daily_email_limit'];

        // DKIM keys and checks belong to a domain and an account.
        if ($keyChanged || $domainChanged) {
            $team->dkim_selector = null;
            $team->dkim_dns_record = null;
            $team->domain_check = null;
            $team->domain_checked_at = null;
        }

        if ($key && $domain && ($keyChanged || $domainChanged)) {
            $team->domain_check = $this->checkKey($key, $domain);
            $team->domain_checked_at = now();
        }

        $team->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Email sending saved.')]);

        return to_route('teams.edit', ['team' => $team->slug]);
    }

    /**
     * Have MailChannels create a DKIM key for the sending domain and keep it.
     * We store only the selector and the TXT record to publish.
     */
    public function createDkim(Team $team): RedirectResponse
    {
        Gate::authorize('update', $team);

        if (blank($team->mailchannels_api_key) || blank($team->sending_domain)) {
            throw ValidationException::withMessages([
                'dkim' => __('Save the MailChannels API key and sending domain first.'),
            ]);
        }

        $selector = 'cm'.now()->format('Ym');

        try {
            $record = (new MailChannelsApi((string) $team->mailchannels_api_key))
                ->createDkimKey((string) $team->sending_domain, $selector);
        } catch (MailChannelsException $exception) {
            throw ValidationException::withMessages([
                'dkim' => __('MailChannels could not create the DKIM key. (:detail)', ['detail' => $exception->getMessage()]),
            ]);
        }

        $team->forceFill([
            'dkim_selector' => $selector,
            'dkim_dns_record' => $record,
            'domain_check' => null,
            'domain_checked_at' => null,
        ])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('DKIM key created. Publish its record, then check again.')]);

        return to_route('teams.edit', ['team' => $team->slug]);
    }

    /**
     * Run MailChannels' domain check now, after DNS changes.
     */
    public function check(Team $team): RedirectResponse
    {
        Gate::authorize('update', $team);

        try {
            EmailSending::check($team, force: true);
        } catch (MailChannelsException $exception) {
            throw ValidationException::withMessages([
                'check' => __('Could not check the domain with MailChannels. (:detail)', ['detail' => $exception->getMessage()]),
            ]);
        }

        $problem = EmailSending::problem($team->refresh());

        Inertia::flash('toast', $problem === null
            ? ['type' => 'success', 'message' => __('Everything passes. The congregation is ready to send email.')]
            : ['type' => 'error', 'message' => $problem]);

        return to_route('teams.edit', ['team' => $team->slug]);
    }

    /**
     * Check the domain with the new key. MailChannels refuses a wrong key here,
     * so a key that does not work is never saved.
     *
     * @return array<string, mixed>
     */
    private function checkKey(string $key, string $domain): array
    {
        try {
            return (new MailChannelsApi($key))->checkDomain($domain);
        } catch (AccountProblem $exception) {
            throw ValidationException::withMessages([
                'mailchannels_api_key' => __('MailChannels did not accept this key. Check that it was copied in full and is active. (:detail)', ['detail' => $exception->getMessage()]),
            ]);
        } catch (MailChannelsException $exception) {
            throw ValidationException::withMessages([
                'mailchannels_api_key' => __('Could not check the key with MailChannels. Try again in a few minutes. (:detail)', ['detail' => $exception->getMessage()]),
            ]);
        }
    }
}
