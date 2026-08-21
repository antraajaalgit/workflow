<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::table('messages')->whereNotNull('attachments')->orderBy('id')->each(function ($message) {
            $attachments = json_decode($message->attachments, true) ?: [];
            $changed = false;
            foreach ($attachments as &$attachment) {
                $data = $attachment['data'] ?? '';
                if (!str_starts_with($data, '/storage/chat-attachments/')) continue;
                $attachment['data'] = '/api/chat-attachments/'.basename($data);
                $changed = true;
            }
            unset($attachment);
            if ($changed) DB::table('messages')->where('id', $message->id)->update(['attachments' => json_encode($attachments)]);
        });
    }

    public function down(): void {}
};
