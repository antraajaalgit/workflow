<?php

namespace App\Http\Controllers;

use App\Services\AdminAttendanceService;
use App\Services\AttendanceAccess;
use App\Services\AttendanceOverrideService;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class AdminAttendanceController extends Controller
{
    public function __construct(private AttendanceAccess $access, private AdminAttendanceService $monitor, private AttendanceOverrideService $overrides) {}

    public function index(Request $request): JsonResponse
    {
        return $this->execute($request, function ($actor) use ($request) {
            $data = $request->validate(['date' => ['nullable', 'string'], 'user_id' => ['nullable', 'string', 'max:40']]);

            return $this->monitor->listing($actor, $data['date'] ?? null, $data['user_id'] ?? null);
        });
    }

    public function store(Request $request): JsonResponse
    {
        return $this->execute($request, function ($actor) use ($request) {
            $data = $request->validate(['user_id' => ['required', 'string', 'max:40'], 'work_date' => ['required', 'string'], 'notes' => ['nullable', 'string', 'max:2000']]);
            $override = $this->overrides->authorize($actor, $data['user_id'], $data['work_date'], $data['notes'] ?? null);

            return ['id' => $override->id, 'user_id' => $override->user_id, 'work_date' => $override->work_date, 'message' => 'Overtime authorized.'];
        }, 201);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        return $this->execute($request, function ($actor) use ($request, $id) {
            $data = $request->validate(['notes' => ['nullable', 'string', 'max:2000']]);
            $this->overrides->revoke($actor, $id, $data['notes'] ?? null);

            return ['revoked' => true, 'message' => 'Overtime authorization revoked.'];
        });
    }

    private function execute(Request $request, \Closure $operation, int $status = 200): JsonResponse
    {
        try {
            $actor = $request->session()->get('nagare_user_id');
            abort_unless(is_string($actor) && $actor !== '', 401, 'Please sign in.');
            // Authorize before reading filters or looking up any employee/override.
            $this->access->admin($actor);
            $result = $operation($actor);
        } catch (ValidationException $exception) {
            $result = ['message' => $exception->getMessage(), 'errors' => $exception->errors()];
            $status = 422;
        } catch (HttpExceptionInterface $exception) {
            $result = ['message' => $exception->getMessage() ?: 'Attendance operation unavailable.'];
            $status = $exception->getStatusCode();
        } catch (UniqueConstraintViolationException) {
            $result = ['message' => 'An override already exists for this employee and date.'];
            $status = 409;
        } catch (QueryException $exception) {
            report($exception);
            $result = ['message' => 'Attendance is temporarily unavailable. Refresh and retry.'];
            $status = 503;
        }

        return response()->json($result, $status)->header('Cache-Control', 'no-store, private');
    }
}
