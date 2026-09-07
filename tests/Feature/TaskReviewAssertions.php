<?php

namespace Tests\Feature;

use App\Mail\TaskAssignment;
use App\Mail\TaskReadyForReview;
use App\Services\StateConcurrency;
use App\Services\TaskService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;

trait TaskReviewAssertions
{
    public static function reviewTransitions(): array
    {
        return [
            'todo enters review' => ['todo', 'review', 1],
            'in progress enters review' => ['in_progress', 'review', 1],
            'blocked enters review' => ['blocked', 'review', 1],
            'new enters review' => ['new', 'review', 1],
            'review remains review' => ['review', 'review', 0],
            'review becomes done' => ['review', 'done', 0],
        ];
    }

    #[DataProvider('reviewTransitions')]
    public function test_review_notification_only_on_transition(string $before, string $after, int $count): void
    {
        $id = $this->task(['status' => $before, 'description' => 'Preserved']);
        Mail::assertNotSent(TaskReadyForReview::class);
        $this->patchJson('/api/tasks/'.$id.'/status', ['status' => $after])->assertOk()
            ->assertJsonPath('status', $after)->assertJsonPath('description', 'Preserved')
            ->assertJsonPath('owner_ids', ['one']);
        Mail::assertSent(TaskReadyForReview::class, $count);
        if ($count) Mail::assertSent(TaskReadyForReview::class, fn ($mail) => $mail->hasTo('admin@example.test') && $mail->task['id'] === $id);
        Mail::assertSent(TaskAssignment::class, 1);
    }

    public function test_review_notification_selects_real_admins_with_valid_emails(): void
    {
        foreach (['custom_admin' => 'custom@example.test', 'another_admin' => 'another@example.test',
            'missing_email' => null, 'empty_email' => '', 'invalid_email' => 'invalid', 'u_admin' => 'placeholder@example.test'] as $id => $email) {
            DB::table('users')->insert(['id' => $id, 'name' => $id, 'role' => 'admin', 'role_id' => 1, 'email' => $email, 'password' => 'unused']);
        }
        $id = $this->task();
        $this->patchJson('/api/tasks/'.$id, ['status' => 'review'])->assertOk();
        Mail::assertSent(TaskReadyForReview::class, 3);
        foreach (['admin', 'custom', 'another'] as $name) {
            Mail::assertSent(TaskReadyForReview::class, fn ($mail) => $mail->hasTo($name.'@example.test'));
        }
    }

    public function test_review_unrelated_edits_do_not_resend_but_reentry_does(): void
    {
        $id = $this->task();
        $this->patchJson('/api/tasks/'.$id, ['status' => 'review'])->assertOk();
        $this->patchJson('/api/tasks/'.$id, ['title' => 'Edited', 'description' => 'New description'])->assertOk();
        $this->patchJson('/api/tasks/'.$id.'/progress', ['progress' => '75'])->assertOk()->assertJsonPath('status', 'review');
        Mail::assertSent(TaskReadyForReview::class, 1);
        $this->patchJson('/api/tasks/'.$id.'/status', ['status' => 'todo'])->assertOk();
        $this->patchJson('/api/tasks/'.$id.'/status', ['status' => 'review'])->assertOk();
        Mail::assertSent(TaskReadyForReview::class, 2);
    }

    public function test_review_notification_waits_for_outer_commit_and_is_discarded_on_rollback(): void
    {
        $id = $this->task();
        $actor = DB::table('users')->where('id', 'admin')->first();
        try {
            app(StateConcurrency::class)->run(function () use ($id, $actor) {
                app(TaskService::class)->update($id, new Request(['status' => 'review']), $actor);
                Mail::assertNotSent(TaskReadyForReview::class);
                throw new \RuntimeException('Rollback review');
            });
        } catch (\RuntimeException $exception) {
            $this->assertSame('Rollback review', $exception->getMessage());
        }
        $this->assertDatabaseHas('tasks', ['id' => $id, 'status' => 'todo']);
        Mail::assertNotSent(TaskReadyForReview::class);
        app(StateConcurrency::class)->run(function () use ($id, $actor) {
            app(TaskService::class)->update($id, new Request(['status' => 'review']), $actor);
            Mail::assertNotSent(TaskReadyForReview::class);
        });
        Mail::assertSent(TaskReadyForReview::class, 1);
    }

    public function test_review_delivery_failure_preserves_update_and_continues_other_recipients(): void
    {
        $id = $this->task();
        DB::table('users')->insert(['id' => 'second_admin', 'name' => 'Second', 'role' => 'admin', 'role_id' => 1, 'email' => 'second@example.test', 'password' => 'unused']);
        $attempts = 0;
        $mailer = \Mockery::mock();
        Mail::shouldReceive('mailer')->with('chat_smtp')->twice()->andReturn($mailer);
        $mailer->shouldReceive('to')->twice()->andReturnSelf();
        $mailer->shouldReceive('send')->twice()->andReturnUsing(function ($mail) use (&$attempts, $id) {
            $this->assertInstanceOf(TaskReadyForReview::class, $mail);
            $this->assertSame(0, DB::transactionLevel());
            $this->assertDatabaseHas('tasks', ['id' => $id, 'status' => 'review']);
            if (++$attempts === 1) throw new \RuntimeException('Delivery failed');
        });
        Log::shouldReceive('warning')->once()->with('Karya task review email could not be sent.', \Mockery::type('array'));
        $this->patchJson('/api/tasks/'.$id.'/status', ['status' => 'review'])->assertOk();
        $this->assertSame(2, $attempts);
    }

    public function test_review_notification_from_mcp_and_email_content(): void
    {
        DB::table('projects')->insert(['id' => 'review_project', 'name' => 'Review Project', 'client_id' => 'c_test', 'status' => 'active']);
        $id = $this->task(['title' => 'Design & copy', 'description' => 'Please check the layout.',
            'project_id' => 'review_project', 'owner_ids' => ['one', 'two'], 'priority' => 'high', 'due_date_ms' => 1788739200000]);
        $this->oauthAdmin();
        $result = $this->mcpData('UpdateTaskStatusTool', ['task_id' => $id, 'status' => 'review']);
        $this->assertTrue($result['success']);
        Mail::assertSent(TaskReadyForReview::class, 1);
        $mail = Mail::sent(TaskReadyForReview::class)->first();
        $mail->assertHasSubject('Task Ready for Review: Design & copy');
        foreach (['Design & copy', 'Please check the layout.', 'Review Project', 'Client', 'one, two', 'Current status: Review', 'Priority: High', '07 Sep 2026', $id] as $text) {
            $mail->assertSeeInText($text);
        }
    }
}
