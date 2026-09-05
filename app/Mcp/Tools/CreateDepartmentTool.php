<?php

namespace App\Mcp\Tools;

use App\Mcp\TaskFields;
use App\Services\DashboardTaskOperations;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Create a Karya department using existing dashboard validation, relationships and activity. Use the returned department name when editing tasks or team members.')]
class CreateDepartmentTool extends AdminTool
{
    protected function execute(Request $request, object $actor, DashboardTaskOperations $operations): array
    {
        $id = null;
        $current = [];
        $data = array_diff_key($request->all(), ['department_id' => true]);
        abort_if(! $data || array_diff(array_keys($data), ['name', 'color']), 422, 'Supply supported editable fields only.');
        $entity = $operations->department(new HttpRequest(array_replace($current, $data)), $actor, $id);
        return ['message' => 'Department created successfully.', 'department' => array_intersect_key($entity, array_flip(['id', 'name', 'color']))];
    }

    public function schema(JsonSchema $schema): array
    {
        $fields = [];
        $fields['name'] = $schema->string()->required();
        $fields['color'] = $schema->string()->nullable()->description('Hex color such as #336699.');
        return $fields;
    }
}

