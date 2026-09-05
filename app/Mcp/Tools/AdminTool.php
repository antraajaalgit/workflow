<?php

namespace App\Mcp\Tools;

use App\Mcp\AdminAccess;
use App\Services\DashboardTaskOperations;
use App\Services\StateConcurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** Small transport adapter; domain writes belong to the existing services. */
abstract class AdminTool extends Tool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        try {
            AdminAccess::actor($request);
            $operations = app(DashboardTaskOperations::class);
            $operations->mailFailures = [];
            $operations->tasks->deferredMailFailures = [];
            $result = app(StateConcurrency::class)->run(function () use ($request, $operations) {
                $actor = AdminAccess::actor($request);
                return $this->execute($request, $actor, $operations) + [
                    'actor' => ['id' => (string) $actor->id, 'name' => $actor->name],
                ];
            });
            return Response::structured($result + [
                'success' => true,
                'assignment_mail_failures' => $operations->tasks->deferredMailFailures,
                'mail_failures' => $operations->mailFailures,
            ]);
        } catch (ValidationException $exception) {
            // Never echo submitted values (especially client credentials).
            return Response::error('Validation failed for: '.implode(', ', array_keys($exception->errors())).'.');
        } catch (HttpExceptionInterface $exception) {
            return Response::error($exception->getStatusCode() < 500 ? ($exception->getMessage() ?: 'Entity not found or action denied.') : 'Karya is temporarily unavailable. Please retry.');
        } catch (\Throwable $exception) {
            // SQL and mail exceptions can contain secrets. Do not serialize them.
            return Response::error('Karya could not complete this operation. Check the application server before retrying.');
        }
    }

    abstract protected function execute(Request $request, object $actor, DashboardTaskOperations $operations): array;

    protected function entity(string $table, string $id, array $fields): array
    {
        $row = DB::table($table)->where('id', $id)->first($fields);
        abort_unless($row, 404, 'Entity not found.');
        return (array) $row;
    }
}
