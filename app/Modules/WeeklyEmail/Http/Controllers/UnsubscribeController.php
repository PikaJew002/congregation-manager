<?php

namespace App\Modules\WeeklyEmail\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\WeeklyEmail\Models\EmailRecipient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class UnsubscribeController extends Controller
{
    public function show(string $token): Response
    {
        $recipient = EmailRecipient::query()->where('unsubscribe_token', $token)->firstOrFail();

        return Inertia::render('weekly-email/Unsubscribe', [
            'token' => $token,
            'email' => $recipient->email,
            'unsubscribed' => $recipient->status === 'unsubscribed',
        ]);
    }

    public function store(Request $request, string $token): RedirectResponse
    {
        $recipient = EmailRecipient::query()->where('unsubscribe_token', $token)->firstOrFail();
        $recipient->update([
            'status' => 'unsubscribed',
            'unsubscribed_at' => now(),
        ]);

        return back();
    }
}
