<?php

namespace App\Http\Controllers;

use App\Http\Responses\Concerns\RedirectsToCurrentTeam;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * There is no home page: signed-in people go to their current congregation's
 * dashboard and everyone else to the login screen.
 */
class HomeController extends Controller
{
    use RedirectsToCurrentTeam;

    public function __invoke(Request $request): RedirectResponse
    {
        if (! $request->user()) {
            return to_route('login');
        }

        return to_route('dashboard', ['current_team' => $this->currentTeam($request)->slug]);
    }
}
