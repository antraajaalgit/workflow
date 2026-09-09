<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class HolidayService
{
    public function __construct(private AttendanceAccess $access, private StateConcurrency $writes) {}

    public function listing(string $actor): array
    {
        $this->access->admin($actor);
        return DB::table('holidays')->orderBy('start_date')->orderBy('name')->get()->all();
    }

    public function save(string $actor, ?string $id, array $values): object
    {
        return $this->writes->run(function () use ($actor, $id, $values) {
            $this->access->admin($actor);
            if ($id !== null) abort_unless(DB::table('holidays')->where('id', $id)->exists(), 404, 'Holiday not found.');
            $values['name'] = is_string($values['name'] ?? null) ? trim($values['name']) : ($values['name'] ?? null);
            $data = Validator::make($values, ['name' => ['required', 'string', 'max:255'],
                'start_date' => ['required', 'date_format:Y-m-d'], 'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
                'notes' => ['nullable', 'string', 'max:2000']])->validate();
            $data['notes'] ??= null;
            if ($id === null) {
                $id = (string) Str::uuid();
                DB::table('holidays')->insert($data + ['id' => $id, 'created_at' => now(), 'updated_at' => now()]);
            } else DB::table('holidays')->where('id', $id)->update($data + ['updated_at' => now()]);
            app(LeaveService::class)->recalculateForCalendar();
            return DB::table('holidays')->where('id', $id)->first();
        });
    }

    public function delete(string $actor, string $id): void
    {
        $this->writes->run(function () use ($actor, $id) {
            $this->access->admin($actor);
            abort_unless(DB::table('holidays')->where('id', $id)->delete(), 404, 'Holiday not found.');
            app(LeaveService::class)->recalculateForCalendar();
        });
    }
}
