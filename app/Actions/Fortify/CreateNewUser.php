<?php

namespace App\Actions\Fortify;

use App\Actions\Teams\AcceptTeamInvitation;
use App\Actions\Teams\CreateTeam;
use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Rules\TeamName;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    public function __construct(
        private CreateTeam $createTeam,
        private AcceptTeamInvitation $acceptTeamInvitation,
    ) {}

    /**
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        $invitation = $this->pendingInvitation($input['invitation'] ?? null);

        Validator::make($input, [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
            'congregation_name' => [
                Rule::requiredIf($invitation === null),
                'nullable',
                'string',
                'max:255',
                new TeamName,
            ],
        ])->after(function ($validator) use ($invitation, $input) {
            if ($invitation && strcasecmp($invitation->email, (string) ($input['email'] ?? '')) !== 0) {
                $validator->errors()->add('email', __('This invitation was sent to a different email address.'));
            }
        })->validate();

        return DB::transaction(function () use ($input, $invitation) {
            $user = User::create([
                'name' => $input['name'],
                'email' => $input['email'],
                'password' => $input['password'],
            ]);

            if ($invitation) {
                $this->acceptTeamInvitation->handle($user, $invitation);
            } else {
                $this->createTeam->handle($user, $input['congregation_name'], isPersonal: false);
            }

            return $user;
        });
    }

    private function pendingInvitation(mixed $code): ?TeamInvitation
    {
        if (! is_string($code) || $code === '') {
            return null;
        }

        return TeamInvitation::query()
            ->where('code', $code)
            ->whereNull('accepted_at')
            ->where(fn ($query) => $query
                ->whereNull('expires_at')
                ->orWhere('expires_at', '>=', now()))
            ->first();
    }
}
