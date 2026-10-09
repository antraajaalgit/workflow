<?php

namespace App\Http\Controllers;

use App\Services\DashboardTaskOperations;
use App\Services\StateConcurrency;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardTaskController extends Controller
{
    public function __construct(private DashboardTaskOperations $operations) {}

    private function execute(Request $request, string $action, ?string $id = null)
    {
        $state = app(StateConcurrency::class)->run(function () use ($request, $action, $id) {
            $actor = DB::table('users')->where('id', $request->session()->get('nagare_user_id'))->first();
            abort_unless($actor, 401, 'Please sign in.');
            if ($action !== 'brief') {
                abort_unless($actor->role === 'admin' && $actor->role_id === 1, 403, 'Admin access required.');
                app(StateConcurrency::class)->check($request->input('_revision'));
            }
            if (str_starts_with($action, 'delete')) $this->operations->$action($id, $actor);
            else $this->operations->$action($request, $actor, $id);
            return app(StateController::class)->state();
        });
        return response()->json(['state' => $state, 'assignmentMailFailures' => $this->operations->tasks->deferredMailFailures,
            'mailFailures' => $this->operations->mailFailures], $request->isMethod('post') ? 201 : 200);
    }

    public function project(Request $request, ?string $id = null) { return $this->execute($request, 'project', $id); }
    public function deleteProject(Request $request, string $id) { return $this->execute($request, 'deleteProject', $id); }
    public function client(Request $request, ?string $id = null) { return $this->execute($request, 'client', $id); }
    public function deleteClient(Request $request, string $id) { return $this->execute($request, 'deleteClient', $id); }
    public function deleteMember(Request $request, string $id) { return $this->execute($request, 'deleteMember', $id); }
    public function department(Request $request, ?string $id = null) { return $this->execute($request, 'department', $id); }
    public function deleteDepartment(Request $request, string $id) { return $this->execute($request, 'deleteDepartment', $id); }
    public function brief(Request $request) { return $this->execute($request, 'brief'); }
}
