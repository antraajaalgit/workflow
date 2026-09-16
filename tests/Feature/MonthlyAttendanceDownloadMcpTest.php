<?php

namespace Tests\Feature;

use App\Exports\MonthlyAttendanceExport;
use App\Mcp\Servers\KaryaServer;
use App\Mcp\Tools\DownloadMonthlyAttendanceExcelTool;
use App\Models\User;
use App\Services\MonthlyAttendanceDownload;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Passport\Passport;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\IsolatedDatabase;

class MonthlyAttendanceDownloadMcpTest extends TestCase
{
    private IsolatedDatabase $database;
    private mixed $allowlist;

    protected function setUp(): void
    {
        parent::setUp();
        $this->database = new IsolatedDatabase;
        $this->database->connect();
        foreach (glob(database_path('migrations/*.php')) as $file) {
            if (! str_contains($file, 'seed_admin_credentials_and_roles')) (require $file)->up();
        }
        foreach (['admin' => ['admin', 1], 'other' => ['admin', 1], 'one' => ['team', 2], 'client' => ['client', 0], 'wrong' => ['admin', 2]] as $id => [$role, $roleId]) {
            DB::table('users')->insert(['id' => $id, 'name' => $id, 'role' => $role, 'role_id' => $roleId, 'email' => $id.'@example.test', 'password' => 'unused']);
        }
        $this->allowlist = env('KARYA_MCP_ALLOWED_ADMIN_IDS');
        Env::getRepository()->set('KARYA_MCP_ALLOWED_ADMIN_IDS', 'admin');
        $disk = Storage::fake('attendance-download-tests');
        $this->partialMock(MonthlyAttendanceDownload::class, fn ($mock) => $mock->shouldReceive('disk')->andReturn($disk));
    }

    protected function tearDown(): void
    {
        try {
            $this->allowlist === null ? Env::getRepository()->clear('KARYA_MCP_ALLOWED_ADMIN_IDS') : Env::getRepository()->set('KARYA_MCP_ALLOWED_ADMIN_IDS', $this->allowlist);
            $this->database->cleanup();
        } finally {
            parent::tearDown();
        }
    }

    private function callTool(array $arguments = [])
    {
        return app(DownloadMonthlyAttendanceExcelTool::class)->handle(new Request($arguments));
    }

    private function login(string $id = 'admin'): void
    {
        Passport::actingAs(User::findOrFail($id), [], 'api');
    }

    private function export(): array
    {
        $this->login();
        $response = $this->callTool(['month' => 9, 'year' => 2026]);
        $this->assertNotInstanceOf(Response::class, $response, $response instanceof Response ? (string) $response->content() : '');
        return $response->getStructuredContent();
    }

    public function test_registration_schema_and_authorization_before_validation(): void
    {
        $this->assertContains(DownloadMonthlyAttendanceExcelTool::class, (new \ReflectionProperty(KaryaServer::class, 'tools'))->getDefaultValue());
        $schema = app(DownloadMonthlyAttendanceExcelTool::class)->toArray()['inputSchema'];
        $this->assertSame(['month', 'year'], $schema['required']);
        $this->withSession(['nagare_user_id' => 'admin']);
        $this->assertStringContainsString('Authentication', (string) $this->callTool()->content());
        foreach (['one', 'client', 'wrong', 'other'] as $id) {
            $this->login($id);
            $response = $this->callTool(['month' => 9, 'year' => 2026]);
            $this->assertTrue($response->isError());
        }
        $this->assertSame([], app(MonthlyAttendanceDownload::class)->disk()->files());
    }

    public function test_required_and_invalid_month_year_do_not_generate_files(): void
    {
        $this->login();
        foreach ([[], ['month' => 9], ['year' => 2026], ['month' => null, 'year' => null]] as $arguments) {
            $this->assertTrue($this->callTool($arguments)->isError());
        }
        foreach ([0, 13, -1, 1.5, 'September', [], null] as $month) {
            $this->assertStringContainsString('month', (string) $this->callTool(['month' => $month, 'year' => 2026])->content());
        }
        foreach ([1999, 2101, -1, 2026.5, 'invalid', [], null] as $year) {
            $this->assertStringContainsString('year', (string) $this->callTool(['month' => 9, 'year' => $year])->content());
        }
        $this->assertSame([], app(MonthlyAttendanceDownload::class)->disk()->files());
    }

    public function test_export_download_contains_existing_attendance_sheets_and_filename(): void
    {
        $data = $this->export();
        $this->assertTrue($data['success']);
        $this->assertSame('Karya-Attendance-September-2026.xlsx', $data['filename']);
        $this->assertArrayNotHasKey('contents', $data);
        $this->assertStringNotContainsString(storage_path(), json_encode($data));
        app('auth')->forgetGuards();
        $response = $this->get($data['download_url'])->assertOk()->assertDownload($data['filename']);
        $response->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $disk = app(MonthlyAttendanceDownload::class)->disk();
        $file = $disk->files()[0];
        $this->assertSame($disk->get($file), $response->streamedContent());
        $workbook = IOFactory::load($disk->path($file));
        $sheets = (new MonthlyAttendanceExport('admin', 2026, 9))->sheets();
        $this->assertSame(2, $workbook->getSheetCount());
        foreach ($sheets as $index => $sheet) {
            $this->assertSame($sheet->title(), $workbook->getSheet($index)->getTitle());
            $this->assertEquals(array_merge([$sheet->headings()], $sheet->array()), $workbook->getSheet($index)->toArray());
        }
        $workbook->disconnectWorksheets();
    }

    public function test_download_rejects_unsigned_tampered_expired_and_missing_files(): void
    {
        $data = $this->export();
        $url = $data['download_url'];
        $this->get(strtok($url, '?'))->assertForbidden();
        $this->get(str_replace('month=9', 'month=8', $url))->assertForbidden();
        $this->get($url.'&path=../../.env')->assertForbidden();
        $this->travel(11)->minutes();
        $this->get($url)->assertForbidden();
        $this->travelBack();
        $missing = URL::temporarySignedRoute('attendance.monthly-download', now()->addMinutes(10), ['id' => (string) \Illuminate\Support\Str::uuid(), 'month' => 9, 'year' => 2026]);
        $this->get($missing)->assertNotFound();
    }

    public function test_mcp_transport_generates_download_link(): void
    {
        $this->login();
        $this->postJson('/mcp/karya', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => [
            'name' => 'download-monthly-attendance-excel-tool', 'arguments' => ['month' => 9, 'year' => 2026],
        ]])->assertOk()->assertJsonPath('result.structuredContent.filename', 'Karya-Attendance-September-2026.xlsx')
            ->assertJsonPath('result.structuredContent.success', true);
    }

    public function test_pruning_removes_old_exports_but_keeps_active_downloads(): void
    {
        $this->export();
        $downloads = app(MonthlyAttendanceDownload::class);
        $downloads->prune();
        $this->assertCount(1, $downloads->disk()->files());
        $this->travel(2)->hours();
        $downloads->prune();
        $this->assertSame([], $downloads->disk()->files());
    }
}
