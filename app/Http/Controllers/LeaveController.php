<?php

namespace App\Http\Controllers;

use App\Services\LeaveService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class LeaveController extends Controller
{
    public function __construct(private LeaveService $leave) {}

    public function mine(Request $request): JsonResponse
    {
        return $this->execute($request, function (string $actorId) {
            return [
                'requests' => $this->leave->mine($actorId),
            ];
        });
    }

    public function balance(Request $request): JsonResponse
    {
        return $this->execute($request, function (string $actorId) use ($request) {
            $data = $request->validate([
                'year' => ['nullable', 'integer', 'min:1000', 'max:9999'],
            ]);

            $year = (int) ($data['year'] ?? now('Asia/Kolkata')->year);

            return $this->leave->balance($actorId, $actorId, $year);
        });
    }

    public function store(Request $request): JsonResponse
    {
        return $this->execute($request, function (string $actorId) use ($request) {
            $data = $request->validate([
                'start_date' => ['required', 'date_format:Y-m-d'],
                'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
                'reason' => ['required', 'string', 'max:2000'],
            ]);

            $leave = $this->leave->submit(
                $actorId,
                $data['start_date'],
                $data['end_date'],
                $data['reason']
            );

            return [
                'request' => $leave,
                'message' => 'Leave request submitted successfully.',
            ];
        }, 201);
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