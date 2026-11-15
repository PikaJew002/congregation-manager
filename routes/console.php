<?php

use App\Models\TeamInvitation;
use App\Support\Modules\ModuleRegistry;
use Illuminate\Support\Facades\Schedule;

Schedule::call(function () {
    TeamInvitation::query()
        ->whereNotNull('expires_at')
        ->where('expires_at', '<', now())
        ->delete();
})->daily()->description('Delete expired congregation invitations');

app(ModuleRegistry::class)->schedule(Schedule::getFacadeRoot());
