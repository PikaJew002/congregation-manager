<?php

use App\Modules\Email\EmailServiceProvider;
use App\Modules\ServiceSlides\ServiceSlidesServiceProvider;
use App\Modules\ZoomRecordings\ZoomRecordingsServiceProvider;

return [

    /*
    |--------------------------------------------------------------------------
    | Module service providers
    |--------------------------------------------------------------------------
    |
    | Each module registers itself with the module registry, loads its own
    | routes and migrations, and contributes navigation and scheduled tasks.
    |
    */

    'providers' => [
        EmailServiceProvider::class,
        ZoomRecordingsServiceProvider::class,
        ServiceSlidesServiceProvider::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Slide library kinds
    |--------------------------------------------------------------------------
    */

    'slide_kinds' => [
        'song' => 'Song',
        'liturgy' => 'Liturgy',
        'other' => 'Other',
    ],

];
