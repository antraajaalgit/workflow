<?php

namespace App\Http\Controllers;

use App\Services\AttendanceAccess;
use App\Services\LeaveService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class AdminLeaveController extends Controller
{
    public function __construct(
        private AttendanceAccess $access,
        private LeaveService $leave
    ) {}

    public function index(Request $request): JsonResponse
    {
        return $this->execute($request, function (string $actorId) use ($request) {
            $data = $request->validate([
                'status' => ['nullable', 'string', 'in:pending,approved,rejected'],
            ]);

            return [
                'requests' => $this->leave->adminListing(
                    $actorId,
                    $data['status'] ?? null
                ),
            ];
        });
    }

    public function approve(Request $request, string $id): JsonResponse
    {
        return $this->execute($request, function (string $actorId) use ($request, $id) {
            $data = $request->validate([
                'note' => ['nullable', 'string', 'max:2000'],
            ]);

            $leave = $this->leave->approve(
                $actorId,
                $id,
                $data['note'] ?? null
            );

            return [
                'request' => $leave,
                'message' => 'Leave request approved successfully.',
            ];
        });
    }

    public function reject(Request $request, string $id): JsonResponse
    {
        return $this->execute($request, function (string $actorId) use ($request, $id) {
            $data = $request->validate([
                'note' => ['nullable', 'string', 'max:2000'],
            ]);

            $leave = $this->leave->reject(
                $actorId,
                $id,
                $data['note'] ?? null
            );

            return [
                'request' => $leave,
                'message' => 'Leave request rejected.',
            ];
        });
    }

    private function execute(
        Request $request,
        \Closure $operation,
        int $status = 200
    ): JsonResponse {
        try {
            $actorId = $request->session()->get('nagare_user_id');

            abort_unless(
                is_string($actorId) && $actorId !== '',
                401,
                'Please sign in.'
            );

            $this->access->admin($actorId);

            $result = $operation($actorId);
        } catch (ValidationException $exception) {
            $result = [
                'message' => $exception->getMessage(),
                'errors' => $exception->errors(),
            ];

            $status = 422;
        } catch (HttpExceptionInterface $exception) {
            $result = [
                'message' => $exception->getMessage() ?: 'Leave operation unavailable.',
            ];

            $status = $exception->getStatusCode();
        } catch (QueryException $exception) {
            report($exception);

            $result = [
                'message' => 'Leave service is temporarily unavailable. Please retry.',
            ];

            $status = 503;
        }

        return response()
            ->json($result, $status)
            ->header('Cache-Control', 'no-store, private');
    }
}