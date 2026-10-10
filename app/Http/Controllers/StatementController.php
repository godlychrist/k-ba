<?php

namespace App\Http\Controllers;

use App\Models\BalanceAdjustment;
use App\Models\BankStatement;
use App\Models\MailAccount;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class StatementController extends Controller
{
    private function owned(Request $request, int $id): BankStatement
    {
        $statement = BankStatement::findOrFail($id);
        $mail = DB::table('mail_records')->where('id', $statement->mail_record_id)->first();
        abort_unless($mail && MailAccount::where('id', $mail->mail_account_id)->where('user_id', $request->user()->id)->exists(), 404);

        return $statement;
    }

    public function confirm(Request $request, int $id)
    {
        $statement = $this->owned($request, $id);
        $fields = $request->validate(['account_type' => 'required|in:bank_account,credit_card', 'account_reference' => 'required|regex:/^\d{4}$/', 'currency' => 'required|in:CRC,USD,GTQ,EUR', 'closing_date' => 'required|date_format:Y-m-d|before_or_equal:today', 'closing_balance' => 'required|numeric|min:0|max:999999999999.99|decimal:0,2']);
        $link = $request->validate(['debit_card_reference' => 'nullable|regex:/^\d{4}$/']);
        $fields['debit_card_reference'] = $fields['account_type'] === 'bank_account' ? ($link['debit_card_reference'] ?? null) : null;
        $statement->update(array_merge($fields, ['confirmed' => true, 'status' => 'confirmed']));

        return response()->json(['ok' => true]);
    }

    public function download(Request $request, int $id)
    {
        $statement = $this->owned($request, $id);
        abort_unless($statement->path && Storage::disk('local')->exists($statement->path), 404);

        return Storage::disk('local')->download($statement->path, 'estado-'.$statement->id.'.pdf', ['Content-Type' => 'application/pdf', 'Cache-Control' => 'no-store']);
    }

    public function adjust(Request $request)
    {
        $fields = $request->validate(['account_reference' => 'required|regex:/^\d{4}$/', 'currency' => 'required|in:CRC,USD,GTQ,EUR', 'amount' => 'required|numeric|min:0|max:999999999999.99|decimal:0,2']);
        $period = $request->validate(['basis' => 'sometimes|in:current,month_start', 'month' => 'required_if:basis,month_start|date_format:Y-m']);
        $effective = ($period['basis'] ?? 'current') === 'month_start'
            ? CarbonImmutable::createFromFormat('!Y-m', $period['month'], 'America/Costa_Rica')->startOfMonth()
            : now();
        abort_if($effective->isFuture() || $effective->lt(CarbonImmutable::parse(config('expenses.import_since'), 'America/Costa_Rica')), 422, 'El mes debe estar entre septiembre de 2026 y el mes actual.');
        BalanceAdjustment::create(array_merge($fields, ['user_id' => $request->user()->id, 'effective_at' => $effective->copy()->utc()]));

        return response()->json(['ok' => true]);
    }

    public function linkCard(Request $request)
    {
        $fields = $request->validate(['card_reference' => 'required|regex:/^\d{4}$/', 'account_reference' => 'required|regex:/^\d{4}$/', 'currency' => 'required|in:CRC,USD,GTQ,EUR']);
        $knownAdjustment = BalanceAdjustment::where('user_id', $request->user()->id)->where('account_reference', $fields['account_reference'])->where('currency', $fields['currency'])->exists();
        $records = DB::table('mail_records')->whereIn('mail_account_id', MailAccount::where('user_id', $request->user()->id)->select('id'))->select('id');
        $knownStatement = BankStatement::whereIn('mail_record_id', $records)->where('account_type', 'bank_account')->where('account_reference', $fields['account_reference'])->where('currency', $fields['currency'])->exists();
        abort_unless($knownAdjustment || $knownStatement, 422, 'Primero establece el saldo de esa cuenta.');
        DB::table('debit_card_links')->upsert([array_merge($fields, ['user_id' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()])], ['user_id', 'card_reference', 'currency'], ['account_reference', 'updated_at']);

        return response()->json(['ok' => true]);
    }
}
