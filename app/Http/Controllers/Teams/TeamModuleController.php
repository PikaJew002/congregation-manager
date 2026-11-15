<?php

namespace App\Http\Controllers\Teams;

use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Support\Modules\ModuleAccess;
use App\Support\Modules\ModuleRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class TeamModuleController extends Controller
{
    public function update(Request $request, Team $team, ModuleAccess $access, ModuleRegistry $modules): RedirectResponse
    {
        Gate::authorize('update', $team);

        $data = $request->validate([
            'module' => ['required', 'string', Rule::in($modules->keys())],
            'enabled' => ['required', 'boolean'],
        ]);

        $access->setEnabled($team, $data['module'], $data['enabled']);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Congregation modules updated.')]);

        return to_route('teams.edit', ['team' => $team->slug]);
    }
}
