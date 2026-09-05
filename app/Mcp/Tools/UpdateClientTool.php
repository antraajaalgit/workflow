<?php

namespace App\Mcp\Tools;

use App\Mcp\TaskFields;
use App\Services\DashboardTaskOperations;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Update a Karya client using existing dashboard validation, relationships and activity. Omitted fields are preserved. Client password changes are not supported.')]
class UpdateClientTool extends AdminTool
{
    protected function execute(Request $request, object $actor, DashboardTaskOperations $operations): array
    {
        $id = $request->validate(['client_id' => ['required', 'string', 'max:40']])['client_id'];
        $current = $this->entity('clients', $id, ['id', 'name', 'company', 'email', 'phone', 'color']);
        $current['project_id'] = DB::table('projects')->where('client_id', $id)->value('id');
        $data = array_diff_key($request->all(), ['client_id' => true]);
        abort_if(! $data || array_diff(array_keys($data), ['name', 'company', 'email', 'phone', 'color', 'project_id']), 422, 'Supply supported editable fields only.');
        $entity = $operations->client(new HttpRequest(array_replace($current, $data)), $actor, $id);
        return ['message' => 'Client updated successfully.', 'client' => array_intersect_key($entity, array_flip(['id', 'name', 'company', 'email', 'phone', 'color']))];
    }

    public function schema(JsonSchema $schema): array
    {
        $fields = ['client_id' => $schema->string()->required()];
        $fields['name'] = $schema->string();
        $fields['company'] = $schema->string();
        $fields['email'] = $schema->string();
        $fields['phone'] = $schema->string();
        $fields['color'] = $schema->string()->nullable()->description('Hex color such as #336699.');
        $fields['project_id'] = $schema->string()->nullable()->description('Project ID to link. Null detaches the first currently linked project, matching the dashboard.');
        return $fields;
    }
}
