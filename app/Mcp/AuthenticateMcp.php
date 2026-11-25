<?php

namespace App\Mcp;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Passport\Passport;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateMcp
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $bearer = $request->bearerToken();

        if (is_string($bearer) && substr_count($bearer, '.') === 2 && Auth::guard('api')->check()) {
            Auth::shouldUse('api');
            $request->attributes->set('mcp.oauth', true);
            $request->attributes->set('mcp.oauth_team_id', $this->teamId($bearer));

            return $next($request);
        }

        if (is_string($bearer) && ! str_contains($bearer, '.') && Auth::guard('sanctum')->check()) {
            Auth::shouldUse('sanctum');

            return $next($request);
        }

        abort(401);
    }

    private function teamId(string $bearer): ?int
    {
        $payload = $this->jwtPayload($bearer);
        $tokenId = $payload['jti'] ?? null;

        if (! is_string($tokenId) || $tokenId === '') {
            return null;
        }

        $scopes = $payload['scopes'] ?? [];

        if (! is_array($scopes) || ! in_array('mcp:use', $scopes, true)) {
            return null;
        }

        $teamId = Passport::token()->newQuery()->whereKey($tokenId)->value('team_id');

        return is_numeric($teamId) ? (int) $teamId : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function jwtPayload(string $jwt): ?array
    {
        $parts = explode('.', $jwt);

        if (count($parts) !== 3) {
            return null;
        }

        $payload = strtr($parts[1], '-_', '+/');
        $payload .= str_repeat('=', (4 - strlen($payload) % 4) % 4);
        $json = base64_decode($payload, true);

        if ($json === false) {
            return null;
        }

        $data = json_decode($json, true);

        return is_array($data) ? $data : null;
    }
}
