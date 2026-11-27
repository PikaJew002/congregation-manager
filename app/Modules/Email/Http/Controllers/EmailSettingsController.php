<?php

namespace App\Modules\Email\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Mail\CongregationMailer;
use App\Models\Team;
use App\Modules\Email\Actions\RenderEmail;
use App\Modules\Email\Http\Controllers\Concerns\ValidatesVariables;
use App\Modules\Email\Models\Email;
use App\Modules\Email\Models\EmailSetting;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * What every email shares: the sender, and variables such as the Zoom link
 * that any email can use.
 */
class EmailSettingsController extends Controller
{
    use ValidatesVariables;

    public function edit(Team $current_team): Response
    {
        return Inertia::render('email/Settings', [
            'settings' => EmailSetting::forTeam($current_team),
            'sendingDomain' => $current_team->sending_domain,
            'usage' => $this->usage($current_team),
        ]);
    }

    public function update(Request $request, Team $current_team, RenderEmail $renderer): RedirectResponse
    {
        $data = $request->validate([
            'from_address' => ['required', 'email', function (string $attribute, mixed $value, Closure $fail) use ($current_team) {
                if (is_string($value) && ! CongregationMailer::isOnSendingDomain($current_team, $value)) {
                    $fail(__('The from address must be on the congregation\'s sending domain, :domain.', ['domain' => $current_team->sending_domain]));
                }
            }],
            'from_name' => ['required', 'string', 'max:255'],
            'reply_to' => ['nullable', 'email'],
            ...$this->variableRules(),
        ], $this->variableMessages());

        $data['variables'] = $this->cleanVariables($data['variables'] ?? []);
        $settings = EmailSetting::forTeam($current_team);
        $before = $settings->variableMap();
        $after = Email::mapVariables($data['variables']);

        // An email that is not sent yet must not lose a variable it uses. One
        // that was already broken before this change is not this change's doing.
        $broken = Email::query()->forTeam($current_team)->where('status', '!=', 'sent')->orderBy('name')->get()
            ->filter(function (Email $email) use ($renderer, $before, $after) {
                $text = $email->subject."\n".$email->body;

                return count($renderer->problems($text, array_merge($after, $email->variableMap())))
                    > count($renderer->problems($text, array_merge($before, $email->variableMap())));
            })
            ->pluck('name');

        if ($broken->isNotEmpty()) {
            throw ValidationException::withMessages(['variables' => __('These emails use a variable you removed or renamed: :emails. Change those emails first, or give them their own value.', ['emails' => $broken->join(', ')])]);
        }

        $settings->update($data);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Saved.')]);

        return back();
    }

    /**
     * Which emails use each shared variable, so people see what a change
     * affects.
     *
     * @return array<string, list<string>>
     */
    private function usage(Team $team): array
    {
        $usage = [];

        foreach (Email::query()->forTeam($team)->where('status', '!=', 'sent')->orderBy('name')->get() as $email) {
            preg_match_all('/\{\{\s*([a-z][a-z0-9_]*)\s*\}\}/', $email->subject."\n".$email->body, $matches);
            $own = $email->variableMap();

            foreach (array_unique($matches[1]) as $key) {
                if (! array_key_exists($key, $own)) {
                    $usage[$key][] = $email->name;
                }
            }
        }

        return $usage;
    }
}
