<?php

namespace App\Modules\ServiceSlides\OAuth;

use Laravel\Passport\Passport;

class GrantedCongregation
{
    public function idForNewToken(): ?int
    {
        $grant = request()->input('grant_type');

        $teamId = match ($grant) {
            'authorization_code' => $this->teamIdFromAuthCode(),
            'refresh_token' => $this->teamIdFromRefreshToken(),
            default => null,
        };

        return is_numeric($teamId) ? (int) $teamId : null;
    }

    private function teamIdFromAuthCode(): mixed
    {
        $codeId = $this->claim(request()->input('code'), 'auth_code_id');

        if ($codeId === null) {
            return null;
        }

        return Passport::authCode()->newQuery()->whereKey($codeId)->value('team_id');
    }

    private function teamIdFromRefreshToken(): mixed
    {
        $accessTokenId = $this->claim(request()->input('refresh_token'), 'access_token_id');

        if ($accessTokenId === null) {
            return null;
        }

        return Passport::token()->newQuery()->whereKey($accessTokenId)->value('team_id');
    }

    private function claim(mixed $payload, string $key): ?string
    {
        $value = OAuthPayload::decrypt($payload)[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
