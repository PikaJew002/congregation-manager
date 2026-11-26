<?php

namespace App\Support\Modules;

use App\Models\Team;

/**
 * A module that adds a card to the congregation dashboard.
 *
 * A card is an array with these keys (the front end's DashboardCard type in
 * resources/js/types/dashboard.ts matches it):
 *
 * - headline: {title, detail|null, status: {label, tone}|null}
 * - stats: list of {label, value, tone?}
 * - attention: list of {title, detail|null, href, tone: warning|danger}
 * - activity: list of {at, title, detail|null, href}
 * - upcoming (optional): {title, items: list of {title, detail|null, href, status|null}}
 *
 * Tones are "success", "warning", "danger" or "muted". Each "at" is ISO 8601
 * and is shown in the congregation's time zone.
 */
interface ProvidesDashboard
{
    /**
     * @return array<string, mixed>
     */
    public function dashboard(Team $team): array;

    /**
     * Where the card's "Open" link goes.
     */
    public function dashboardRoute(): string;
}
