<?php

namespace App\Models\Passport;

use App\Modules\ServiceSlides\OAuth\GrantedCongregation;
use Laravel\Passport\Token as PassportToken;

/**
 * @property int|null $team_id
 */
class Token extends PassportToken
{
    protected static function booted(): void
    {
        static::created(function (Token $token): void {
            $teamId = app(GrantedCongregation::class)->idForNewToken();

            if ($teamId === null) {
                return;
            }

            $token->forceFill(['team_id' => $teamId])->saveQuietly();
        });
    }
}
