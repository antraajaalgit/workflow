<?php

namespace Tests\Feature;

use App\Services\AttendanceDdnsService;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;

class AttendanceDdnsTest extends TestCase
{
    private const URL = '/api/attendance/ddns/heartbeat';

    protected function setUp(): void
    {
        parent::setUp();
        config(['attendance.ddns' => ['enabled' => true, 'hostname' => 'karya-office.dedyn.io',
            'token' => 'test-desec-token', 'heartbeat_secret' => 'test-heartbeat-secret'],
            'trustedproxy.proxies' => []]);
        $this->withServerVariables(['REMOTE_ADDR' => '8.8.4.4']);
        $this->withHeader('Authorization', 'Bearer test-heartbeat-secret');
        Http::preventStrayRequests();
        Http::fake(['https://update.dedyn.io/*' => Http::response('good 8.8.4.4')]);
        $this->resolver(['1.1.1.1']);
    }

    private function resolver(array $addresses, bool $throws = false): void
    {
        $this->app->instance(AttendanceDdnsService::class, new class($addresses, $throws) extends AttendanceDdnsService
        {
            public function __construct(private array $addresses, private bool $throws) {}

            protected function resolveIpv4(string $hostname): array
            {
                if ($hostname !== 'karya-office.dedyn.io' || $this->throws) {
                    throw new \RuntimeException('Resolver failure');
                }

                return $this->addresses;
            }
        });
    }

    public function test_stateless_update_ignores_body_query_and_untrusted_forwarding_headers(): void
    {
        // Enable production CSRF behavior: route must still work without a session/token.
        $this->app->instance('env', 'production');
        $this->postJson(self::URL.'?ip=9.9.9.9', ['ip' => '9.9.9.9', 'myipv4' => '9.9.9.9'],
            ['X-Forwarded-For' => '9.9.9.9', 'X-Real-IP' => '9.9.9.9', 'Forwarded' => 'for=9.9.9.9'])
            ->assertOk()->assertExactJson(['success' => true, 'status' => 'updated']);
        Http::assertSent(function ($request) {
            parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);

            return $request->method() === 'GET'
                && $query === ['hostname' => 'karya-office.dedyn.io', 'myipv4' => '8.8.4.4', 'myipv6' => 'preserve']
                && $request->hasHeader('Authorization', 'Token test-desec-token');
        });
        Http::assertSentCount(1);
    }

    public function test_unchanged_does_not_call_desec(): void
    {
        $this->resolver(['1.1.1.1', '8.8.4.4']);
        $this->postJson(self::URL)->assertOk()->assertJsonPath('status', 'unchanged');
        Http::assertNothingSent();
    }

    public function test_missing_and_wrong_secrets_cannot_use_body_or_query_credentials(): void
    {
        $this->withHeader('Authorization', '');
        $this->postJson(self::URL.'?heartbeat_secret=test-heartbeat-secret', ['heartbeat_secret' => 'test-heartbeat-secret'])
            ->assertUnauthorized()->assertJsonPath('status', 'unauthorized');
        $this->withHeader('Authorization', 'Bearer wrong');
        $this->postJson(self::URL)->assertUnauthorized();
        Http::assertNothingSent();
    }

    #[DataProvider('configurationCases')]
    public function test_configuration_failures(string $key, mixed $value, string $status): void
    {
        config(['attendance.ddns.'.$key => $value]);
        $this->postJson(self::URL)->assertStatus(503)->assertJsonPath('status', $status);
        Http::assertNothingSent();
    }

    public static function configurationCases(): array
    {
        return [['enabled', false, 'disabled'], ['token', '', 'missing_configuration'],
            ['hostname', '', 'missing_configuration'], ['hostname', 'https://evil.test/', 'missing_configuration'],
            ['heartbeat_secret', '', 'missing_configuration']];
    }

    #[DataProvider('invalidIps')]
    public function test_non_public_ipv4_is_rejected(string $ip): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => $ip]);
        $this->postJson(self::URL, ['ip' => '8.8.4.4'])->assertUnprocessable()->assertJsonPath('status', 'invalid_ip');
        Http::assertNothingSent();
    }

    public static function invalidIps(): array
    {
        return array_map(fn ($ip) => [$ip], ['10.0.0.1', '172.16.0.1', '192.168.1.1', '127.0.0.1',
            '169.254.1.1', '100.64.0.1', '0.1.2.3', '192.0.2.1', '198.51.100.1', '203.0.113.1',
            '198.18.0.1', '224.0.0.1', '255.255.255.255', '::1', '2001:4860:4860::8888', 'invalid']);
    }

    public function test_dns_failure_never_updates(): void
    {
        $this->resolver([]);
        $this->postJson(self::URL)->assertStatus(502)->assertJsonPath('status', 'dns_failure');
        $this->resolver([], true);
        $this->postJson(self::URL)->assertStatus(502)->assertJsonPath('status', 'dns_failure');
        Http::assertNothingSent();
    }

    #[DataProvider('upstreamFailures')]
    public function test_upstream_failure_is_sanitized(int $code, string $body): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response($body, $code)]);
        $this->postJson(self::URL)->assertStatus(502)
            ->assertExactJson(['success' => false, 'status' => 'ddns_failure']);
    }

    public static function upstreamFailures(): array
    {
        return [[401, 'test-desec-token'], [500, 'test-heartbeat-secret'], [429, 'abuse'],
            [200, 'badauth'], [200, ''], [302, 'good']];
    }

    public function test_timeout_is_sanitized(): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(fn () => throw new ConnectionException('test-desec-token test-heartbeat-secret'));
        $this->postJson(self::URL)->assertStatus(504)
            ->assertExactJson(['success' => false, 'status' => 'ddns_timeout']);
    }

    public function test_unexpected_client_failure_is_sanitized(): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(fn () => throw new \RuntimeException('test-desec-token test-heartbeat-secret'));
        $this->postJson(self::URL)->assertStatus(502)->assertExactJson(['success' => false, 'status' => 'ddns_failure']);
    }

    public function test_upstream_no_change_is_success(): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response('nochg 8.8.4.4')]);
        $this->postJson(self::URL)->assertOk()->assertJsonPath('status', 'updated');
    }

    public function test_rate_limit_allows_five_minute_heartbeats_and_one_retry(): void
    {
        $this->postJson(self::URL)->assertOk();
        $this->postJson(self::URL)->assertOk();
        $this->postJson(self::URL)->assertStatus(429);
        $this->travel(301)->seconds();
        $this->postJson(self::URL)->assertOk();
        Http::assertSentCount(3);
    }
}
