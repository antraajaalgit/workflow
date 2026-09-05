<?php

namespace App\Mcp\Tools;

use App\Mcp\TaskFields;
use App\Services\DashboardTaskOperations;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Delete the specified Karya entity by exact ID. Only team accounts can be deleted; removes their task assignments.')]
class DeleteTeamMemberTool extends AdminTool
{
    protected function execute(Request $request, object $actor, DashboardTaskOperations $operations): array
    {
        $id = $request->validate(['member_id' => ['required', 'string', 'max:40']])['member_id'];
        $deleted = $this->entity('users', $id, ['id', 'name', 'department', 'role']);
        $operations->deleteMember($id, $actor);
        return ['message' => 'TeamMember deleted successfully.', 'deleted' => $deleted];
    }

    public function schema(JsonSchema $schema): array
    {
        return ['member_id' => $schema->string()->required()];
    }
}
