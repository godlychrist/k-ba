<?php

namespace App\Services;

use App\Models\BankStatement;
use Illuminate\Support\Facades\Storage;

class StatementImporter
{
    public function import(int $record, array $attachments): void
    {
        foreach ($attachments as $attachment) {
            if (BankStatement::where('mail_record_id', $record)->where('attachment_key', $attachment['key'])->exists()) {
                continue;
            }
            $bytes = $attachment['bytes'];
            $path = null;
            $parsed = ['status' => 'unreadable'];
            if ($bytes && str_starts_with(ltrim($bytes), '%PDF-')) {
                $path = 'statements/'.$record.'/'.$attachment['key'].'.pdf';
                Storage::disk('local')->put($path, $bytes);
                $parsed = app(StatementParser::class)->pdf($bytes);
            }
            BankStatement::create(array_merge($parsed, ['mail_record_id' => $record, 'attachment_key' => $attachment['key'], 'filename' => $attachment['filename'], 'path' => $path]));
        }
    }
}
