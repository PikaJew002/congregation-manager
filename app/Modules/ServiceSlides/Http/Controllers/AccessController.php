<?php

namespace App\Modules\ServiceSlides\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AccessController extends Controller
{
    public function index(Request $request, Team $current_team): Response
    {
        $tokens = $request->user()->tokens->filter(
            fn ($token) => in_array('congregation:'.$current_team->id, $token->abilities ?? [], true),
        )->map(fn ($token) => [
            'id' => $token->id,
            'name' => $token->name,
            'created_at' => $token->created_at,
            'last_used_at' => $token->last_used_at,
        ])->values();

        return Inertia::render('service-slides/Settings', [
            'tokens' => $tokens,
            'mcpUrl' => url('/mcp/services'),
        ]);
    }

    public function store(Request $request, Team $current_team): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:255']]);
        $token = $request->user()->createToken($data['name'], [
            'service-slides',
            'congregation:'.$current_team->id,
        ]);

        Inertia::flash('plainTextToken', $token->plainTextToken);

        return back();
    }

    public function destroy(Request $request, Team $current_team, int $token): RedirectResponse
    {
        $request->user()->tokens()
            ->whereKey($token)
            ->get()
            ->filter(fn ($row) => in_array('congregation:'.$current_team->id, $row->abilities ?? [], true))
            ->each
            ->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Token revoked.')]);

        return back();
    }
}
