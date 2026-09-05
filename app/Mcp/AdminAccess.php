<?php

namespace App\Mcp;

use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;

final class AdminAccess
{
    public static function run(Request $request, \Closure $operation): mixed
    {
        return app(\App\Services\StateConcurrency::class)->run(fn () => $operation(self::actor($request)));
    }

    public static function actor(Request $request): object
    {
        $authenticated = $request->user('api');
        abort_unless($authenticated, 401, 'Authentication is required to use this MCP tool.');
        $actor = DB::table('users')->where('id', $authenticated->getAuthIdentifier())->first();
        abort_unless($actor, 401, 'Authentication is required to use this MCP tool.');
        abort_unless($actor->role === 'admin' && (int) $actor->role_id === 1, 403, 'Only Karya administrators may use this MCP tool.');
        $allowed = array_values(array_filter(array_map('trim', explode(',', (string) env('KARYA_MCP_ALLOWED_ADMIN_IDS', ''))), fn ($id) => $id !== ''));
        abort_unless(! $allowed || in_array((string) $actor->id, $allowed, true), 403, 'This administrator is not allowed to use Karya MCP.');

        return $actor;
    }
}
