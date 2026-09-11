<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\IpUtils;
use Throwable;

class AttendanceDdnsService
{
    public function heartbeat(?string $ip): string
    {
        if (! config('attendance.ddns.enabled')) {
            return 'disabled';
        }
        $hostname = config('attendance.ddns.hostname');
        $token = config('attendance.ddns.token');
        if (! is_string($hostname) || ! filter_var($hostname, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)
            || ! str_contains($hostname, '.') || ! is_string($token) || trim($token) === '') {
            return 'missing_configuration';
        }
        if (! $this->isPublicIpv4($ip)) {
            return 'invalid_ip';
        }
        try {
            $addresses = $this->resolveIpv4($hostname);
        } catch (Throwable) {
            return 'dns_failure';
        }
        if (! $addresses) {
            return 'dns_failure';
        }
        if (in_array($ip, $addresses, true)) {
            return 'unchanged';
        }
        try {
            $response = Http::withToken($token, 'Token')
                ->connectTimeout(5)->timeout(10)->withoutRedirecting()
                ->get('https://update.dedyn.io/', [
                    'hostname' => $hostname,
                    'myipv4' => $ip,
                    'myipv6' => 'preserve',
                ]);
            if (! $response->successful() || ! preg_match('/\A(?:good|nochg)(?:\s|$)/', trim($response->body()))) {
                return 'ddns_failure';
            }
        } catch (ConnectionException) {
            return 'ddns_timeout';
        } catch (Throwable) {
            // Never report HTTP exceptions: they may contain authorization headers.
            return 'ddns_failure';
        }

        return 'updated';
    }

    /** Separate resolver seam keeps automated tests independent of live DNS. */
    protected function resolveIpv4(string $hostname): array
    {
        return @gethostbynamel($hostname) ?: [];
    }

    private function isPublicIpv4(?string $ip): bool
    {
        return $ip !== null
            && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false
            // Additional non-global ranges not consistently excluded by PHP.
            && ! IpUtils::checkIp($ip, ['0.0.0.0/8', '100.64.0.0/10', '192.0.0.0/24',
                '192.0.2.0/24', '192.88.99.0/24', '198.18.0.0/15', '198.51.100.0/24',
                '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4']);
    }
}
