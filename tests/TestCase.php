<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Fortify\Features;
use Laravel\Passport\Passport;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    private static ?string $passportPrivateKey = null;

    private static ?string $passportPublicKey = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (self::$passportPrivateKey === null || self::$passportPublicKey === null) {
            $resource = openssl_pkey_new([
                'private_key_bits' => 2048,
                'private_key_type' => OPENSSL_KEYTYPE_RSA,
            ]);

            if ($resource === false) {
                throw new RuntimeException('Unable to create Passport test keys.');
            }

            $private = '';

            if (! openssl_pkey_export($resource, $private)) {
                throw new RuntimeException('Unable to export the Passport test key.');
            }

            $details = openssl_pkey_get_details($resource);
            self::$passportPrivateKey = $private;
            self::$passportPublicKey = is_array($details) ? $details['key'] : null;
        }

        config([
            'passport.private_key' => self::$passportPrivateKey,
            'passport.public_key' => self::$passportPublicKey,
        ]);

        Passport::$validateKeyPermissions = false;
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
