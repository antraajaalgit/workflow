<?php

namespace App\Mcp\Tools;

use App\Mcp\TaskFields;
use App\Services\DashboardTaskOperations;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Delete the specified Karya entity by exact ID. Deletes client tasks, projects, conversations and client login accounts using dashboard cleanup.')]
class DeleteClientTool extends AdminTool
{
    protected function execute(Request $request, object $actor, DashboardTaskOperations $operations): array
    {
        $id = $request->validate(['client_id' => ['required', 'string', 'max:40']])['client_id'];
        $deleted = $this->entity('clients', $id, ['id', 'name', 'company']);
        $operations->deleteClient($id, $actor);
        return ['message' => 'Client deleted successfully.', 'deleted' => $deleted];
    }

    public function schema(JsonSchema $schema): array
    {
        return ['client_id' => $schema->string()->required()];
    }
}
