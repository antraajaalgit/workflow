<?php

namespace App\Mcp\Tools;

use App\Mcp\TaskFields;
use App\Services\DashboardTaskOperations;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Create a Karya client using existing dashboard validation, relationships and activity. Client creation sends the existing welcome emails after commit.')]
class CreateClientTool extends AdminTool
{
    protected function execute(Request $request, object $actor, DashboardTaskOperations $operations): array
    {
        $id = null;
        $current = [];
        $data = array_diff_key($request->all(), ['client_id' => true]);
        abort_if(! $data || array_diff(array_keys($data), ['name', 'company', 'email', 'phone', 'color', 'project_id', 'password']), 422, 'Supply supported editable fields only.');
        $entity = $operations->client(new HttpRequest(array_replace($current, $data)), $actor, $id);
        return ['message' => 'Client created successfully.', 'client' => array_intersect_key($entity, array_flip(['id', 'name', 'company', 'email', 'phone', 'color']))];
    }

    public function schema(JsonSchema $schema): array
    {
        $fields = [];
        $fields['name'] = $schema->string()->required();
        $fields['company'] = $schema->string()->required();
        $fields['email'] = $schema->string()->required();
        $fields['phone'] = $schema->string()->required();
        $fields['color'] = $schema->string()->nullable()->description('Hex color such as #336699.');
        $fields['project_id'] = $schema->string()->nullable()->description('Project ID to link. Null detaches the first currently linked project, matching the dashboard.');
        $fields['password'] = $schema->string()->required()->description('Initial login password, at least 8 characters. Sent by the existing welcome email; never returned by MCP.');
        return $fields;
    }
}
