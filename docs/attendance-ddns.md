# Attendance DDNS heartbeat

The office machine sends an HTTPS `POST /api/attendance/ddns/heartbeat` every five
minutes with `Authorization: Bearer <heartbeat secret>`. No body, browser session,
or CSRF token is required. Do not put either secret in URLs or payloads.

Configure these server environment variables:

```dotenv
ATTENDANCE_DDNS_ENABLED=true
ATTENDANCE_DDNS_HOSTNAME=karya-office.dedyn.io
ATTENDANCE_DDNS_TOKEN=
ATTENDANCE_DDNS_HEARTBEAT_SECRET=
```

Supply a deSEC token and a separate, randomly generated heartbeat secret before
use. Keep the deSEC token only on the server. Refresh Laravel's configuration
cache through the normal deployment process after changing configuration.

The endpoint uses only Laravel's request IP. Body and query IP values are ignored.
The existing exact-IP trusted-proxy configuration applies; reverse proxies must
overwrite forwarding headers. Use HTTPS and configure infrastructure request
logging/APM to redact Authorization headers. The feature itself logs neither
credentials nor upstream error details.

Each source IP is allowed two requests per five-minute window (one scheduled
heartbeat and one retry), including failed authentication attempts. Use a shared
Laravel cache store if serving through multiple application instances.

Responses contain `success` (boolean) and `status`:

| HTTP | Status | Meaning |
| --- | --- | --- |
| 200 | unchanged | DNS already contains the source IPv4; deSEC was not called. |
| 200 | updated | deSEC accepted the explicit IPv4 update (`good` or `nochg`). |
| 401 | unauthorized | Bearer secret missing or incorrect. |
| 422 | invalid_ip | Source is not a public IPv4. |
| 429 | rate_limited | Retry after the rate-limit window. |
| 503 | disabled | Authenticated request, feature disabled. |
| 503 | missing_configuration | Required configuration missing or hostname invalid. |
| 502 | dns_failure | IPv4 lookup failed or returned no records; no update attempted. |
| 502 | ddns_failure | deSEC rejected the request or returned an unexpected response. |
| 504 | ddns_timeout | Timeout or connection failure reaching deSEC. |

The update uses Laravel Http to GET `https://update.dedyn.io/`, with the token in
the Authorization header and explicit `hostname`, `myipv4`, and `myipv6=preserve`
parameters, following the [deSEC update API](https://desec.readthedocs.io/en/latest/dyndns/update-api.html).
Redirects are disabled; connection and total timeouts are five and ten seconds.
No automatic HTTP retry occurs. DNS lookup uses the server's resolver and timeout
settings. DNS propagation/cache delays may cause a later heartbeat to repeat an
update. If multiple A records exist, any matching address counts as unchanged.

This feature updates DNS only. It does not change attendance GPS/geofence rules,
static office IP settings, or install an office-machine scheduler. The machine
must send over its office IPv4 connection, without a VPN changing its egress IP.

Tests fake HTTP and stub DNS; no live deSEC or DNS requests are needed. When
running tests locally, point `APP_PACKAGES_CACHE` and `APP_SERVICES_CACHE` at
test-specific files under `storage/framework/cache` to avoid modifying the
deployment's bootstrap cache manifests.
