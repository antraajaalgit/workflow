<?php

namespace App\Http\Controllers;

use App\Services\AttendanceAccess;
use App\Services\HolidayService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class AdminHolidayController extends Controller
{
    public function __construct(private AttendanceAccess $access, private HolidayService $holidays) {}
    public function index(Request $request) { return $this->execute($request, fn ($actor) => ['holidays' => $this->holidays->listing($actor)]); }
    public function store(Request $request) { return $this->execute($request, fn ($actor) => ['holiday' => $this->holidays->save($actor, null, $request->all())], 201); }
    public function update(Request $request, string $id) { return $this->execute($request, fn ($actor) => ['holiday' => $this->holidays->save($actor, $id, $request->all())]); }
    public function destroy(Request $request, string $id) { return $this->execute($request, function ($actor) use ($id) { $this->holidays->delete($actor, $id); return ['deleted' => true]; }); }

    private function execute(Request $request, \Closure $operation, int $status = 200)
    {
        try {
            $actor = $request->session()->get('nagare_user_id');
            abort_unless(is_string($actor) && $actor !== '', 401, 'Please sign in.');
            $this->access->admin($actor);
            $result = $operation($actor);
        } catch (ValidationException $e) {
            $result = ['message' => $e->getMessage(), 'errors' => $e->errors()]; $status = 422;
        } catch (HttpExceptionInterface $e) {
            $result = ['message' => $e->getMessage() ?: 'Holiday operation unavailable.']; $status = $e->getStatusCode();
        } catch (\Illuminate\Database\QueryException $e) {
            report($e); $result = ['message' => 'Holidays are temporarily unavailable. Please retry.']; $status = 503;
        }
        return response()->json($result, $status)->header('Cache-Control', 'no-store, private');
    }
}
