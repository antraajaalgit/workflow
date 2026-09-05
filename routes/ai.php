<?php

use App\Mcp\Servers\KaryaServer;
use Laravel\Mcp\Facades\Mcp;

// OAuth discovery + dynamic client registration routes for Claude.
Mcp::oauthRoutes();

// Remote/web MCP endpoint protected by Passport.
Mcp::web('/mcp/karya', KaryaServer::class)
    ->middleware('auth:api');

// Keep local MCP so Inspector continues to work.
Mcp::local('karya', KaryaServer::class);