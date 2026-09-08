<?php

namespace App\Services;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class AttendanceGeofence
{
    // IUGG mean Earth radius; a geographic constant, not an office policy value.
    private const EARTH_RADIUS_METRES = 6371008.8;

    /** Validate and enforce here so non-HTTP callers cannot bypass the fence. */
    public function validate(array $input): array
    {
        $location = Validator::make($input, [
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'accuracy' => ['required', 'numeric', 'gt:0'],
        ])->validate();
        $settings = $this->settings();
        abort_unless($settings, 503, 'Office geofence is not configured. Contact an administrator.');
        $location = array_map(fn ($value) => (float) $value, $location);
        if (! is_finite($location['accuracy']) || $location['accuracy'] > $settings['max_accuracy_metres']) {
            throw ValidationException::withMessages(['accuracy' => 'Location accuracy is insufficient. Obtain a more accurate location and retry.']);
        }
        $distance = $this->distanceMetres($location['latitude'], $location['longitude'],
            $settings['office_latitude'], $settings['office_longitude']);
        if ($distance > $settings['radius_metres']) {
            throw ValidationException::withMessages(['location' => 'You are outside the allowed office attendance radius.']);
        }

        return $location;
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
