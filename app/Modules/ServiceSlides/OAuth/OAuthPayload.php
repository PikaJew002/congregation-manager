<?php

namespace App\Modules\ServiceSlides\OAuth;

use Defuse\Crypto\Crypto;
use Laravel\Passport\Passport;
use Throwable;

class OAuthPayload
{
    /**
     * @return array<string, mixed>|null
     */
    public static function decrypt(mixed $payload): ?array
    {
        if (! is_string($payload) || $payload === '') {
            return null;
        }

        try {
            $json = Crypto::decryptWithPassword(
                $payload,
                Passport::tokenEncryptionKey(app('encrypter')),
            );
        } catch (Throwable) {
            return null;
        }

        $data = json_decode($json, true);

        return is_array($data) ? $data : null;
    }
}
