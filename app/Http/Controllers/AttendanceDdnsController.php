<?php

namespace App\Http\Controllers;

use App\Services\AttendanceDdnsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AttendanceDdnsController extends Controller
{
    public function __invoke(Request $request, AttendanceDdnsService $ddns): JsonResponse
    {
        $secret = config('attendance.ddns.heartbeat_secret');
        if (! is_string($secret) || trim($secret) === '') {
            return response()->json(['success' => false, 'status' => 'missing_configuration'], 503);
        }
        $provided = $request->bearerToken();
        if (! is_string($provided) || ! hash_equals($secret, $provided)) {
            return response()->json(['success' => false, 'status' => 'unauthorized'], 401);
        }

        // Never read an IP (or credentials) from payload/query parameters.
        $status = $ddns->heartbeat($request->ip());
        $code = match ($status) {
            'updated', 'unchanged' => 200,
            'invalid_ip' => 422,
            'ddns_timeout' => 504,
            'ddns_failure', 'dns_failure' => 502,
            default => 503,
        };

        return response()->json(['success' => $code === 200, 'status' => $status], $code);
    }
}
