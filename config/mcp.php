<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Redirect Domains
    |--------------------------------------------------------------------------
    |
    | OAuth clients may only register these redirect origins. Claude's hosted
    | apps use https://claude.ai. Claude Code uses a loopback address, and the
    | port is ignored for localhost and 127.0.0.1.
    |
    */

    'redirect_domains' => [
        'https://claude.ai',
        'http://localhost',
        'http://127.0.0.1',
    ],

    /*
    |--------------------------------------------------------------------------
    | Allowed Custom Schemes
    |--------------------------------------------------------------------------
    |
    | Desktop clients such as Cursor use a private-use URI scheme for the
    | OAuth callback instead of https.
    |
    */

    'custom_schemes' => [
        'claude',
        'cursor',
        'vscode',
    ],

    /*
    |--------------------------------------------------------------------------
    | Authorization Server
    |--------------------------------------------------------------------------
    |
    | RFC 8414 issuer. Null uses the current application URL.
    |
    */

    'authorization_server' => null,

    /*
    |--------------------------------------------------------------------------
    | Tool Search
    |--------------------------------------------------------------------------
    */

    'tool_search' => [
        'max_tool_calls' => 10,
        'max_output_bytes' => 65_536,
    ],

    /*
    |--------------------------------------------------------------------------
    | MCP Clients
    |--------------------------------------------------------------------------
    */

    'clients' => [
        'github' => [
            'url' => env('MCP_GITHUB_URL', 'https://api.githubcopilot.com/mcp/'),
            'token' => env('MCP_GITHUB_TOKEN'),
            'headers' => [
                'X-MCP-Readonly' => 'true',
            ],
        ],

        'linear' => [
            'url' => env('MCP_LINEAR_URL', 'https://mcp.linear.app/mcp'),
            'token' => env('MCP_LINEAR_TOKEN'),
        ],

        'playwright' => [
            'command' => 'npx',
            'args' => ['-y', '@playwright/mcp@latest', '--headless'],
        ],
    ],

];
