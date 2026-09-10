<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;

class TrustProxies extends \Illuminate\Http\Middleware\TrustProxies
{
    protected function setTrustedProxyIpAddresses(Request $request)
    {
        // Read after configuration has loaded. Never use hosting auto-detection,
        // static overrides, wildcards, or the current peer as an implicit proxy.
        $configured = config('trustedproxy.proxies', []);
        $proxies = is_array($configured) ? array_values(array_filter($configured,
            fn ($ip) => is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP) !== false)) : [];

        $request->setTrustedProxies($proxies, $this->getTrustedHeaderNames());
    }
}
