<?php

namespace App\Services;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class AttendanceGeofence
{
    // IUGG mean Earth radius; a geographic constant, not an office policy value.
    private const EARTH_RADIUS_METRES = 6371008.8;

    /** Validate and enforce here so non-HTTP callers cannot bypass the fence. */
    public function validate(array $input, ?string $requestIp = null): array
    {
        $validator = Validator::make($input, [
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'accuracy' => ['required', 'numeric', 'gt:0'],
        ]);
        $settings = $this->settings();
        abort_unless($settings, 503, 'Office geofence is not configured. Contact an administrator.');
        $errors = $validator->errors()->messages();
        $location = $errors ? [] : array_map(fn ($value) => (float) $value, $validator->validated());
        if (! $errors && (! is_finite($location['accuracy']) || $location['accuracy'] > $settings['max_accuracy_metres'])) {
            $errors['accuracy'] = ['Location accuracy is insufficient. Obtain a more accurate location and retry.'];
        }
        if ($errors) {
            if ($this->isOfficeIp($requestIp)) {
                // Do not record unusable coordinates as a verified location.
                return ['latitude' => null, 'longitude' => null, 'accuracy' => null];
            }
            throw ValidationException::withMessages(['office_ip' => ['Location is missing or unusable. Office-network fallback failed: connect to an approved office network or provide an accurate location.']] + $errors);
        }
        $distance = $this->distanceMetres($location['latitude'], $location['longitude'],
            $settings['office_latitude'], $settings['office_longitude']);
        if ($distance > $settings['radius_metres']) {
            throw ValidationException::withMessages(['location' => 'You are outside the allowed office attendance radius.']);
        }

        return $location;
    }

    private function isOfficeIp(?string $requestIp): bool
{
    if ($requestIp === null || filter_var($requestIp, FILTER_VALIDATE_IP) === false) {
        return false;
    }

    foreach (config('attendance.office_ips', []) as $approved) {
        if (
            is_string($approved)
            && filter_var(trim($approved), FILTER_VALIDATE_IP) !== false
            && inet_pton(trim($approved)) === inet_pton($requestIp)
        ) {
            return true;
        }
    }

    foreach (config('attendance.office_hostnames', []) as $hostname) {
        if (! is_string($hostname) || trim($hostname) === '') {
            continue;
        }

        $resolvedIps = @gethostbynamel(trim($hostname));

        if ($resolvedIps === false) {
            continue;
        }

        foreach ($resolvedIps as $resolvedIp) {
            if (
                filter_var($resolvedIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
                && inet_pton($resolvedIp) === inet_pton($requestIp)
            ) {
                return true;
            }
        }
    }

    return false;
}

    /** Great-circle distance using Haversine, clamped against floating-point drift. */
    public function distanceMetres(float $latitude, float $longitude, float $officeLatitude, float $officeLongitude): float
    {
        $latDelta = deg2rad($latitude - $officeLatitude);
        $lonDelta = deg2rad($longitude - $officeLongitude);
        $a = sin($latDelta / 2) ** 2 + cos(deg2rad($latitude)) * cos(deg2rad($officeLatitude)) * sin($lonDelta / 2) ** 2;

        return 2 * self::EARTH_RADIUS_METRES * asin(sqrt(max(0.0, min(1.0, $a))));
    }

    private function settings(): ?array
    {
        $settings = config('attendance.geofence', []);
        $validator = Validator::make($settings, [
            'office_latitude' => ['required', 'numeric', 'between:-90,90'],
            'office_longitude' => ['required', 'numeric', 'between:-180,180'],
            'radius_metres' => ['required', 'numeric', 'gt:0'],
            // Fits the existing decimal(10,3) accuracy columns.
            'max_accuracy_metres' => ['required', 'numeric', 'gt:0', 'max:9999999.999'],
        ]);
        if ($validator->fails()) {
            return null;
        }
        $values = array_map(fn ($value) => (float) $value, $validator->validated());

        return count(array_filter($values, fn ($value) => ! is_finite($value))) ? null : $values;
    }
}
