<?php

namespace App\Mcp\Tools;

use App\Mcp\AdminAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** Services own their transactions, especially LeaveService review locking. */
abstract class AttendanceLeaveTool extends Tool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        try {
            $actor = AdminAccess::actor($request);
            return Response::structured($this->execute($request, (string) $actor->id) + [
                'success' => true,
                'actor' => ['id' => (string) $actor->id, 'name' => $actor->name],
            ]);
        } catch (ValidationException $exception) {
            return Response::error('Validation failed for: '.implode(', ', array_keys($exception->errors())).'.');
        } catch (HttpExceptionInterface $exception) {
            return Response::error($exception->getStatusCode() < 500
                ? ($exception->getMessage() ?: 'Entity not found or action denied.')
                : 'Karya is temporarily unavailable. Please retry.');
        } catch (\Throwable $exception) {
            return Response::error('Karya could not complete this operation. Check the application server before retrying.');
        }
    }

    abstract protected function execute(Request $request, string $actorId): array;

    protected function employeeId(string $name): string
    {
        $name = mb_strtolower(trim($name), 'UTF-8');
        abort_if($name === '', 422, 'employee_name must not be blank.');
        // Compare in PHP for consistent case matching across database collations.
        $matches = DB::table('users')->where('role', 'team')->where('role_id', 2)->get(['id', 'name'])
            ->filter(fn ($user) => mb_strtolower(trim($user->name), 'UTF-8') === $name);
        abort_if($matches->isEmpty(), 404, 'No team employee matches employee_name.');
        abort_if($matches->count() > 1, 422, 'Ambiguous employee_name: multiple team employees have this name. Make their names unique in Karya before retrying.');
        return (string) $matches->first()->id;
    }
}
