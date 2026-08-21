<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

return new class extends Migration {
    public function up(): void
    {
        DB::table('messages')->whereNotNull('attachments')->orderBy('id')->each(function ($message) {
            $attachments = json_decode($message->attachments, true) ?: [];
            $changed = false;
            foreach ($attachments as &$attachment) {
                $data = $attachment['data'] ?? '';
                if (!str_starts_with($data, 'data:') || !str_contains($data, ';base64,')) continue;
                [, $encoded] = explode(';base64,', $data, 2);
                $binary = base64_decode($encoded, true);
                if ($binary === false) continue;
                $extension = pathinfo($attachment['name'] ?? '', PATHINFO_EXTENSION) ?: 'bin';
                $path = 'chat-attachments/'.Str::uuid().'.'.preg_replace('/[^a-z0-9]/i', '', $extension);
                Storage::disk('public')->put($path, $binary);
                $attachment['data'] = Storage::url($path);
                $changed = true;
            }
            unset($attachment);
            if ($changed) DB::table('messages')->where('id', $message->id)->update(['attachments' => json_encode($attachments)]);
        });
    }

    public function down(): void {}
};
