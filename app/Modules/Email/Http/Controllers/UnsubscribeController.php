<?php

namespace App\Modules\Email\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Email\Models\EmailRecipient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class UnsubscribeController extends Controller
{
    public function show(string $token): Response
    {
        $recipient = EmailRecipient::query()->where('unsubscribe_token', $token)->firstOrFail();

        return Inertia::render('email/Unsubscribe', [
            'token' => $token,
            'email' => $recipient->email,
            'unsubscribed' => $recipient->status !== 'subscribed',
        ]);
    }

    public function store(Request $request, string $token): RedirectResponse
    {
        $recipient = EmailRecipient::query()->where('unsubscribe_token', $token)->firstOrFail();
        // Someone who complained is already off the list; keep that record.
        if ($recipient->status !== 'complained') {
            $recipient->update([
                'status' => 'unsubscribed',
                'unsubscribed_at' => now(),
            ]);
        }

        return back();
    }
}
