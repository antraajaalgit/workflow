<?php

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('List team members available in the Karya workflow system. Claude uses this tool to identify the correct team member before assigning or reassigning tasks.')]
class ListTeamMembersTool extends Tool
{
    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response|ResponseFactory
    {
        try {
            $actor = \App\Mcp\AdminAccess::actor($request);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $exception) {
            return Response::error($exception->getMessage());
        }

        $data = $request->validate(['include_admins' => ['sometimes', 'boolean']]);
        $members = DB::table('users')
            ->where(function ($query) use ($data) {
                $query->where('role', 'team');
                if ($data['include_admins'] ?? false) {
                    $query->orWhere(fn ($admins) => $admins->where('role', 'admin')->whereNotNull('email')->whereRaw("TRIM(email) <> ''"));
                }
            })
            ->select([
                'id',
                'name',
                'email',
                'department',
                'role',
                'role_id',
            ])
            ->orderBy('name')
            ->get()
            ->map(fn ($member) => (array) $member)
            ->values()
            ->all();

        return Response::structured([
            'count' => count($members),
            'team_members' => $members,
        ]);
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return ['include_admins' => $schema->boolean()->description('Include admins with email who can receive task assignments. Defaults to false.')];
    }
}
