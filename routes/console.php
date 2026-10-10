<?php

use App\Models\BankStatement;
use App\Models\MailAccount;
use App\Services\MailProvider;
use App\Services\ReceiptParser;
use App\Services\StatementParser;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('expenses:repair-merchants {--limit=100}', function (MailProvider $mail, ReceiptParser $parser) {
    $since = CarbonImmutable::parse(config('expenses.import_since'), 'America/Costa_Rica')->utc()->toDateTimeString();
    $rows = DB::table('transactions')->join('mail_records', 'mail_records.id', '=', 'transactions.mail_record_id')->whereNull('transactions.merchant')->where('transactions.user_edited', false)->where('mail_records.received_at', '>=', $since)->select('transactions.id', 'mail_records.mail_account_id', 'mail_records.message_id')->orderBy('transactions.id')->limit(max(1, min((int) $this->option('limit'), 500)))->get();
    $fixed = 0;
    $remaining = 0;
    foreach ($rows as $row) {
        $account = MailAccount::find($row->mail_account_id);
        if (! $account || $account->provider !== 'gmail') {
            continue;
        }
        try {
            $receipt = $mail->receipt($account, $row->message_id);
            $parsed = $receipt ? $parser->parse($receipt['subject']."\n".$receipt['body'], $receipt['received_at']) : null;
            $merchant = $parsed['transaction']['merchant'] ?? null;
            if ($merchant) {
                $fixed += DB::table('transactions')->where('id', $row->id)->whereNull('merchant')->where('user_edited', false)->update(['merchant' => $merchant, 'updated_at' => now()]);
            } else {
                $remaining++;
            }
        } catch (Throwable) {
            $remaining++;
        }
    }
    $this->info("Comercios recuperados: $fixed. Pendientes en este lote: $remaining.");
})->purpose('Recupera comercios faltantes del HTML de Gmail sin modificar importes ni ediciones manuales');

Artisan::command('expenses:reparse-statements', function (StatementParser $parser) {
    $counts = [];
    foreach (BankStatement::where('confirmed', false)->get() as $statement) {
        $parsed = $parser->text($statement->extracted_text ?? '');
        $statement->fill($parsed)->save();
        $counts[$statement->status] = ($counts[$statement->status] ?? 0) + 1;
    }
    foreach ($counts as $status => $count) {
        $this->info("$status: $count");
    }
})->purpose('Resume automáticamente los estados existentes y distingue los glosarios');
