<?php

namespace Tests\Feature;

use App\Services\AttendanceGeofence;
use App\Services\AttendanceOverrideService;
use App\Services\LeaveService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;
use Tests\IsolatedDatabase;

class AttendanceGeofenceTest extends TestCase
{
    private IsolatedDatabase $database;

    private array $inside = ['latitude' => 0.0001, 'longitude' => -0.0001, 'accuracy' => 10.125];

    protected function setUp(): void
    {
        parent::setUp();
        $this->database = new IsolatedDatabase;
        $this->database->connect();
        foreach (glob(database_path('migrations/*.php')) as $file) {
            if (! str_contains($file, 'seed_admin_credentials_and_roles')) {
                (require $file)->up();
            }
        }
        foreach (['one' => ['team', 2], 'admin' => ['admin', 1]] as $id => [$role, $roleId]) {
            DB::table('users')->insert(['id' => $id, 'name' => $id, 'role' => $role, 'role_id' => $roleId,
                'email' => $id.'@example.test', 'password' => 'unused']);
        }
        // Arbitrary geographic test origin; not a proposed office location.
        config(['attendance.geofence' => ['office_latitude' => 0, 'office_longitude' => 0,
            'radius_metres' => 100, 'max_accuracy_metres' => 50]]);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-08 10:30:00', 'Asia/Kolkata'));
        $this->withSession(['nagare_user_id' => 'one']);
    }

    protected function tearDown(): void
    {
        try {
            CarbonImmutable::setTestNow();
            $this->database->cleanup();
        } finally {
            parent::tearDown();
        }
    }

    public function test_office_fallback_accepts_missing_coarse_and_invalid_locations_for_both_operations(): void
    {
        config(['attendance.office_ips' => ['198.51.100.1', '223.178.210.107']]);
        $this->withServerVariables(['REMOTE_ADDR' => '223.178.210.107']);
        foreach ([[], ['accuracy' => 5000] + $this->inside, ['latitude' => 'bad'] + $this->inside] as $location) {
            DB::table('attendance_records')->delete();
            $this->postJson('/api/attendance/check-in', $location)->assertCreated();
            $this->postJson('/api/attendance/check-out', $location)->assertOk();
            $row = DB::table('attendance_records')->first();
            $this->assertNotNull($row->check_out_at);
            foreach (['check_in', 'check_out'] as $prefix) {
                foreach (['latitude', 'longitude', 'accuracy'] as $field) {
                    $this->assertNull($row->{$prefix.'_'.$field});
                }
            }
        }
    }

    public function test_office_ip_cannot_override_an_accurate_outside_location(): void
    {
        config(['attendance.office_ips' => ['223.178.210.107']]);
        $this->withServerVariables(['REMOTE_ADDR' => '223.178.210.107']);
        $outside = ['latitude' => 1] + $this->inside;
        $this->postJson('/api/attendance/check-in', $outside)->assertUnprocessable()->assertJsonValidationErrors('location');
        $this->postJson('/api/attendance/check-in', $this->inside)->assertCreated();
        $this->postJson('/api/attendance/check-out', $outside)->assertUnprocessable()->assertJsonValidationErrors('location');
    }

    public function test_untrusted_forwarding_headers_and_body_cannot_spoof_office_ip(): void
    {
        config(['attendance.office_ips' => ['223.178.210.107'], 'trustedproxy.proxies' => []]);
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.10']);
        $headers = ['X-Forwarded-For' => '223.178.210.107', 'Forwarded' => 'for=223.178.210.107',
            'X-Real-IP' => '223.178.210.107'];
        foreach (['check-in', 'check-out'] as $operation) {
            if ($operation === 'check-out') {
                $this->postJson('/api/attendance/check-in', $this->inside)->assertCreated();
            }
            foreach ([[], ['accuracy' => 5000] + $this->inside] as $location) {
                $this->postJson('/api/attendance/'.$operation, $location + ['requestIp' => '223.178.210.107'], $headers)
                    ->assertUnprocessable()->assertJsonValidationErrors('office_ip');
            }
        }
        $this->assertDatabaseHas('attendance_records', ['check_out_at' => null]);
    }

    public function test_explicit_trusted_proxy_resolves_client_and_rejects_non_office_client(): void
    {
        config(['attendance.office_ips' => ['223.178.210.107'], 'trustedproxy.proxies' => ['192.0.2.10']]);
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.10']);
        $this->postJson('/api/attendance/check-in', [], ['X-Forwarded-For' => '223.178.210.107'])->assertCreated();
        $this->postJson('/api/attendance/check-out', [], ['X-Forwarded-For' => '223.178.210.107, 198.51.100.10'])
            ->assertUnprocessable()->assertJsonValidationErrors('office_ip');
        $this->postJson('/api/attendance/check-out', [], ['X-Forwarded-For' => '223.178.210.107'])->assertOk();
    }

    public function test_direct_office_peer_ignores_forwarded_headers_when_another_proxy_is_trusted(): void
    {
        config(['attendance.office_ips' => ['223.178.210.107'], 'trustedproxy.proxies' => ['192.0.2.10']]);
        $this->withServerVariables(['REMOTE_ADDR' => '223.178.210.107']);
        $headers = ['X-Forwarded-For' => '198.51.100.10'];
        $this->postJson('/api/attendance/check-in', [], $headers)->assertCreated();
        $this->postJson('/api/attendance/check-out', [], $headers)->assertOk();
    }

    public function test_unlisted_peer_cannot_spoof_office_ip_when_another_proxy_is_trusted(): void
    {
        config(['attendance.office_ips' => ['223.178.210.107'], 'trustedproxy.proxies' => ['192.0.2.10']]);
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.10']);
        $this->postJson('/api/attendance/check-in', [], ['X-Forwarded-For' => '223.178.210.107'])
            ->assertUnprocessable()->assertJsonValidationErrors('office_ip');
        $this->assertDatabaseCount('attendance_records', 0);
    }

    public function test_missing_or_unsafe_proxy_config_cannot_enable_implicit_trust(): void
    {
        config(['attendance.office_ips' => ['223.178.210.107']]);
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.10']);
        \Illuminate\Http\Middleware\TrustProxies::at('*');
        try {
            foreach ([null, [], '*', ['*', 'REMOTE_ADDR']] as $proxies) {
                config(['trustedproxy.proxies' => $proxies]);
                $this->postJson('https://attendance.on-forge.com/api/attendance/check-in', [],
                    ['X-Forwarded-For' => '223.178.210.107'])
                    ->assertUnprocessable()->assertJsonValidationErrors('office_ip');
            }
            $this->assertDatabaseCount('attendance_records', 0);
        } finally {
            \Illuminate\Http\Middleware\TrustProxies::flushState();
        }
    }

    public function test_inside_radius_accepts_accuracy_and_stores_each_operations_location(): void
    {
        $this->postJson('/api/attendance/check-in', $this->inside)->assertCreated()
            ->assertJsonPath('attendance.status', 'present')->assertJsonPath('attendance.is_late', true);
        $row = DB::table('attendance_records')->first();
        foreach ($this->inside as $key => $value) {
            $this->assertEqualsWithDelta($value, (float) $row->{'check_in_'.$key}, 0.00000001);
        }
        $this->assertNull($row->check_out_latitude);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-08 17:55:00', 'Asia/Kolkata'));
        $out = ['latitude' => -0.0002, 'longitude' => 0.0003, 'accuracy' => 50];
        $this->postJson('/api/attendance/check-out', $out)->assertOk()->assertJsonPath('attendance.is_early_checkout', true)
            ->assertJsonPath('attendance.worked_minutes', 445);
        $row = DB::table('attendance_records')->first();
        foreach ($out as $key => $value) {
            $this->assertEqualsWithDelta($value, (float) $row->{'check_out_'.$key}, 0.00000001);
        }
        foreach ($this->inside as $key => $value) {
            $this->assertEqualsWithDelta($value, (float) $row->{'check_in_'.$key}, 0.00000001);
        }
    }

    public function test_outside_radius_rejects_both_operations_without_writes(): void
    {
        $outside = ['latitude' => 0.01, 'longitude' => 0, 'accuracy' => 1, 'inside_geofence' => true, 'distance' => 0];
        $this->postJson('/api/attendance/check-in', $outside)->assertUnprocessable()->assertJsonValidationErrors('location');
        $this->assertDatabaseCount('attendance_records', 0);
        $this->postJson('/api/attendance/check-in', $this->inside)->assertCreated();
        $before = DB::table('attendance_records')->first();
        $this->postJson('/api/attendance/check-out', $outside)->assertUnprocessable()->assertJsonValidationErrors('location');
        $this->assertEquals($before, DB::table('attendance_records')->first());
    }

    public function test_missing_location_is_rejected_for_both_operations(): void
    {
        foreach (['check-in', 'check-out'] as $operation) {
            if ($operation === 'check-out') {
                $this->postJson('/api/attendance/check-in', $this->inside)->assertCreated();
            }
            $this->postJson('/api/attendance/'.$operation)->assertUnprocessable()->assertJsonValidationErrors(['latitude', 'longitude', 'accuracy']);
            foreach (array_keys($this->inside) as $field) {
                $input = $this->inside;
                unset($input[$field]);
                $this->postJson('/api/attendance/'.$operation, $input)->assertUnprocessable()->assertJsonValidationErrors($field);
            }
        }
        $this->assertDatabaseHas('attendance_records', ['check_out_at' => null]);
    }

    public function test_bad_accuracy_and_coordinate_ranges_are_rejected(): void
    {
        $invalid = [['accuracy', 0], ['accuracy', -1], ['accuracy', 50.001], ['accuracy', '1e999'],
            ['accuracy', 'NaN'], ['latitude', 90.0001], ['latitude', -90.0001],
            ['longitude', 180.0001], ['longitude', -180.0001], ['latitude', 'not-a-number'], ['longitude', []]];
        foreach (['check-in', 'check-out'] as $operation) {
            if ($operation === 'check-out') {
                $this->postJson('/api/attendance/check-in', $this->inside)->assertCreated();
            }
            foreach ($invalid as [$field, $value]) {
                $this->postJson('/api/attendance/'.$operation, array_replace($this->inside, [$field => $value]))
                    ->assertUnprocessable()->assertJsonValidationErrors($field);
            }
        }
        $this->assertDatabaseHas('attendance_records', ['check_out_at' => null]);
    }

    public function test_valid_coordinate_limits_and_radius_boundary_are_accepted(): void
    {
        $geofence = app(AttendanceGeofence::class);
        foreach ([[90, 180], [-90, -180]] as [$latitude, $longitude]) {
            config(['attendance.geofence.office_latitude' => $latitude, 'attendance.geofence.office_longitude' => $longitude]);
            $this->assertSame((float) $latitude, $geofence->validate(['latitude' => $latitude, 'longitude' => $longitude, 'accuracy' => 1])['latitude']);
        }
        config(['attendance.geofence.office_latitude' => 0, 'attendance.geofence.office_longitude' => 0]);
        // Independent known distance: one equatorial degree is about 111.195 km.
        $this->assertEqualsWithDelta(111195.08, $geofence->distanceMetres(0, 1, 0, 0), 0.01);
        $boundary = $geofence->distanceMetres(0.0001, -0.0001, 0, 0);
        config(['attendance.geofence.radius_metres' => $boundary]);
        $this->postJson('/api/attendance/check-in', $this->inside)->assertCreated();
    }

    public function test_missing_or_invalid_office_configuration_fails_closed(): void
    {
        foreach (['office_latitude', 'office_longitude', 'radius_metres', 'max_accuracy_metres'] as $key) {
            $original = config('attendance.geofence.'.$key);
            config(['attendance.geofence.'.$key => null]);
            $this->postJson('/api/attendance/check-in', $this->inside)->assertStatus(503);
            config(['attendance.geofence.'.$key => $original]);
        }
        $this->assertDatabaseCount('attendance_records', 0);
        $this->postJson('/api/attendance/check-in', $this->inside)->assertCreated();
        config(['attendance.geofence.radius_metres' => -100]);
        $this->postJson('/api/attendance/check-out', $this->inside)->assertStatus(503);
        $this->assertDatabaseHas('attendance_records', ['check_out_at' => null]);
    }

    public function test_overtime_requires_the_same_geofence_for_both_operations(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-13 10:30:00', 'Asia/Kolkata'));
        $this->postJson('/api/attendance/check-in', $this->inside)->assertConflict()->assertJsonPath('message', 'Office Closed – Weekly Off');
        app(AttendanceOverrideService::class)->authorize('admin', 'one', '2026-09-13');
        $outside = array_replace($this->inside, ['latitude' => 0.01]);
        $this->postJson('/api/attendance/check-in', $outside)->assertUnprocessable();
        $this->postJson('/api/attendance/check-in', $this->inside)->assertCreated()->assertJsonPath('attendance.is_late', true);
        $this->postJson('/api/attendance/check-out', $outside)->assertUnprocessable();
        $this->postJson('/api/attendance/check-out', $this->inside)->assertOk();
    }

    public function test_approved_leave_still_blocks_inside_office(): void
    {
        $leave = app(LeaveService::class);
        $request = $leave->submit('one', '2026-09-08', '2026-09-08', 'Leave');
        $leave->approve('admin', $request->id);
        $this->postJson('/api/attendance/check-in', $this->inside)->assertConflict()->assertJsonPath('message', 'Approved Paid Leave');
        $this->assertDatabaseCount('attendance_records', 0);
    }
}
