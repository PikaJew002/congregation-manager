<?php

namespace App\Models\Passport;

use Laravel\Passport\AuthCode as PassportAuthCode;

/**
 * @property int|null $team_id
 */
class AuthCode extends PassportAuthCode
{
    protected static function booted(): void
    {
        static::creating(function (AuthCode $code): void {
            $teamId = session('mcp.oauth_team_id');

            if (! is_numeric($teamId)) {
                return;
            }

            $code->team_id = (int) $teamId;
            session()->forget('mcp.oauth_team_id');
        });
    }
}
