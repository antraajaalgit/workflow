<?php

namespace App\Mcp\Tools;

use App\Mcp\TaskFields;
use App\Services\DashboardTaskOperations;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('List Karya clients and IDs before admin edits or deletions. Only allowed OAuth admins may read this directory.')]
class ListClientsTool extends AdminTool
{
    protected function execute(Request $request, object $actor, DashboardTaskOperations $operations): array
    {
        $rows = DB::table('clients')->orderBy('name')->get(['id', 'name', 'company', 'email', 'phone', 'color'])->map(fn ($row) => (array) $row)->all();
        return ['message' => 'Directory loaded.', 'clients' => $rows, 'count' => count($rows)];
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
