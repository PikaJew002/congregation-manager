<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Authorize {{ $client->name }} — {{ config('app.name') }}</title>
    <link rel="icon" href="/favicon.ico">
    @vite(['resources/css/app.css'])
</head>
<body class="min-h-screen bg-background font-sans text-foreground antialiased">
@php
    $redirectUri = request()->query('redirect_uri', $client->redirect_uris[0] ?? null);
    $redirectHost = is_string($redirectUri) ? parse_url($redirectUri, PHP_URL_HOST) : null;
    $loopback = in_array($redirectHost, ['localhost', '127.0.0.1', '[::1]'], true);
@endphp
<div class="flex min-h-screen items-center justify-center p-4">
    <div class="w-full max-w-md rounded-lg border bg-card text-card-foreground shadow-sm">
        <div class="space-y-2 p-6 text-center">
            <h1 class="text-2xl font-semibold tracking-tight">Authorize {{ $client->name }}</h1>
            <p class="text-sm text-muted-foreground">
                This app will be able to plan service slides for the congregation you choose.
            </p>
        </div>

        <div class="space-y-4 px-6 pb-6">
            <div class="rounded-lg border bg-muted/50 p-4 text-left">
                <p class="text-sm text-muted-foreground">Signed in as</p>
                <p class="font-medium">{{ $user->email }}</p>
            </div>

            @if (count($scopes) > 0)
                <ul class="space-y-1 text-left text-sm text-muted-foreground">
                    @foreach ($scopes as $scope)
                        <li>{{ $scope->description }}</li>
                    @endforeach
                </ul>
            @endif

            @if ($redirectHost)
                <div class="rounded-lg border p-4 text-left">
                    <p class="text-sm text-muted-foreground">Sends you back to</p>
                    <p class="font-medium break-all">{{ $redirectHost }}</p>
                    @if ($loopback)
                        <p class="mt-2 text-sm text-muted-foreground">
                            That address is on this computer. Continue only if you just started the sign-in from an app here.
                        </p>
                    @endif
                </div>
            @endif

            @if ($teams->isEmpty())
                <p class="rounded-lg border border-dashed p-4 text-sm text-muted-foreground">
                    This account cannot plan slides for any congregation.
                </p>
            @else
                <div class="space-y-2 text-left">
                    <label for="team_id" class="text-sm font-medium">Congregation</label>
                    <select
                        id="team_id"
                        name="team_id"
                        form="authorizeForm"
                        required
                        class="flex h-10 w-full rounded-md border border-input bg-background px-3 text-sm"
                    >
                        @foreach ($teams as $team)
                            <option value="{{ $team->id }}" @selected($team->id === $user->current_team_id)>
                                {{ $team->name }}
                            </option>
                        @endforeach
                    </select>
                    @error('team_id')
                        <p class="text-sm text-destructive">{{ $message }}</p>
                    @enderror
                </div>
            @endif
        </div>

        <div class="flex gap-3 p-6 pt-0">
            <form method="POST" action="{{ route('passport.authorizations.deny') }}" class="flex-1">
                @csrf
                @method('DELETE')
                <input type="hidden" name="client_id" value="{{ $client->id }}">
                <input type="hidden" name="auth_token" value="{{ $authToken }}">
                <button type="submit" class="inline-flex h-10 w-full items-center justify-center rounded-md border border-input bg-background px-4 text-sm font-medium hover:bg-accent">
                    Cancel
                </button>
            </form>

            <form method="POST" action="{{ route('passport.authorizations.approve') }}" id="authorizeForm" class="flex-1">
                @csrf
                <input type="hidden" name="client_id" value="{{ $client->id }}">
                <input type="hidden" name="auth_token" value="{{ $authToken }}">
                <button
                    type="submit"
                    @disabled($teams->isEmpty())
                    class="inline-flex h-10 w-full items-center justify-center rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground hover:bg-primary/90 disabled:pointer-events-none disabled:opacity-50"
                >
                    Authorize
                </button>
            </form>
        </div>
    </div>
</div>
</body>
</html>
