<?php

namespace App\Http\Controllers;

use App\Services\TaskService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TaskController extends Controller
{
    public function __construct(private TaskService $tasks) {}

    private function actor(Request $request): object
    {
        // Same database-backed custom session as StateController.
        $id = $request->session()->get('nagare_user_id');
        abort_unless($id && ($user = DB::table('users')->where('id', $id)->first()), 401, 'Please sign in.');

        return $user;
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $this->actor($request);

        // StateController also allows every signed-in user to read tasks.
        return response()->json($this->tasks->find($id));
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json($this->tasks->create($request, $this->actor($request)), 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        return response()->json($this->tasks->update($id, $request, $this->actor($request)));
    }

    public function status(Request $request, string $id): JsonResponse
    {
        return response()->json($this->tasks->update($id, $request, $this->actor($request), 'status'));
    }

    public function progress(Request $request, string $id): JsonResponse
    {
        return response()->json($this->tasks->update($id, $request, $this->actor($request), 'progress'));
    }

    public function assignees(Request $request, string $id): JsonResponse
    {
        return response()->json($this->tasks->update($id, $request, $this->actor($request), 'assignees'));
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->tasks->delete($id, $this->actor($request));

        return response()->json(['deleted' => true]);
    }
}
