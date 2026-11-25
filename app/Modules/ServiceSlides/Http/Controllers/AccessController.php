<?php

namespace App\Modules\ServiceSlides\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Passport\Passport;

/**
 * Shows the MCP address and the apps (Claude, Cursor) signed in for this
 * congregation, and disconnects them.
 */
class AccessController extends Controller
{
    public function index(Request $request, Team $current_team): Response
    {
        $connections = Passport::token()->newQuery()
            ->where('user_id', $request->user()->id)
            ->where('team_id', $current_team->id)
            ->where('revoked', false)
            ->with('client')
            ->latest()
            ->get()
            ->map(fn ($token) => [
                'id' => $token->id,
                'name' => $token->client->name,
                'created_at' => $token->created_at,
            ])->values();

        return Inertia::render('service-slides/Settings', [
            'connections' => $connections,
            'mcpUrl' => url('/mcp'),
        ]);
    }

    public function destroyConnection(Request $request, Team $current_team, string $connection): RedirectResponse
    {
        $token = Passport::token()->newQuery()
            ->whereKey($connection)
            ->where('user_id', $request->user()->id)
            ->where('team_id', $current_team->id)
            ->first();

        if ($token) {
            $token->refreshToken?->revoke();
            $token->revoke();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Connection revoked.')]);

        return back();
    }
}
