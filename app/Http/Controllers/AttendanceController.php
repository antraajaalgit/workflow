<?php

namespace App\Http\Controllers;

use App\Services\AttendanceService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class AttendanceController extends Controller
{
    public function __construct(private AttendanceService $attendance) {}

    public function today(Request $request): JsonResponse
    {
        return $this->execute($request, 'today');
    }

    public function checkIn(Request $request): JsonResponse
    {
        return $this->execute($request, 'checkIn', 201);
    }

    public function checkOut(Request $request): JsonResponse
    {
        return $this->execute($request, 'checkOut');
    }

    private function execute(Request $request, string $operation, int $status = 200): JsonResponse
    {
        try {
            $id = $request->session()->get('nagare_user_id');
            abort_unless(is_string($id) && $id !== '', 401, 'Please sign in.');
            // Only location is client-supplied; owner, date, time and flags remain server-owned.
            $result = $operation === 'today' ? $this->attendance->today($id)
                : $this->attendance->$operation($id, $request->only(['latitude', 'longitude', 'accuracy']));
        } catch (ValidationException $exception) {
            $result = ['message' => $exception->getMessage(), 'errors' => $exception->errors()];
            $status = 422;
        } catch (HttpExceptionInterface $exception) {
            $result = ['message' => $exception->getMessage()];
            $status = $exception->getStatusCode();
        } catch (QueryException $exception) {
            report($exception);
            $result = ['message' => 'Attendance is temporarily unavailable. Please retry.'];
            $status = 503;
        }

        return response()->json($result, $status)->header('Cache-Control', 'no-store, private');
    }
}
