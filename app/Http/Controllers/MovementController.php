<?php

namespace App\Http\Controllers;

use App\Models\MailAccount;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MovementController extends Controller
{
    private function fields(Request $request, ?Transaction $existing = null): array
    {
        $fields = $request->validate([
            'kind' => 'required|in:expense,income',
            'amount' => 'required|numeric|gt:0|max:999999999999.99|decimal:0,2',
            'currency' => 'required|in:CRC,USD,GTQ,EUR',
            'merchant' => 'required|string|max:255',
            'notes' => 'nullable|string|max:2000',
            'account_reference' => 'nullable|regex:/^\d{4}$/',
            'instrument_type' => 'required|in:bank_account,card,unknown',
            'occurred_at' => 'required|date_format:Y-m-d\TH:i:sP|before_or_equal:now',
        ]);
        $occurred = CarbonImmutable::parse($fields['occurred_at']);
        abort_if($occurred->lt(CarbonImmutable::parse(config('expenses.import_since'), 'America/Costa_Rica')), 422, 'La fecha debe ser desde el 1 de septiembre de 2026.');
        $fields['occurred_at'] = $occurred->utc()->toDateTimeString();
        $fields['merchant'] = trim($fields['merchant']);
        abort_if($fields['merchant'] === '', 422, 'Escribe el comercio o una descripción.');
        $request->validate(['date_confirmed' => 'sometimes|boolean']);
        $fields['date_from_email'] = $existing?->date_from_email && ! $request->boolean('date_confirmed');
        $fields['user_edited'] = true;

        return $fields;
    }

    public function create(Request $request)
    {
        $fields = $this->fields($request);
        $input = $request->validate(['client_id' => 'required|uuid']);
        $movement = Transaction::firstOrCreate(['user_id' => $request->user()->id, 'client_id' => $input['client_id']], $fields);

        return response()->json(['id' => $movement->id], 201);
    }

    public function update(Request $request, int $id)
    {
        return DB::transaction(function () use ($request, $id) {
            $movement = Transaction::where('id', $id)->lockForUpdate()->firstOrFail();
            $owned = (int) $movement->user_id === (int) $request->user()->id;
            if (! $owned && $movement->mail_record_id) {
                $mail = DB::table('mail_records')->where('id', $movement->mail_record_id)->first();
                $owned = $mail && MailAccount::where('id', $mail->mail_account_id)->where('user_id', $request->user()->id)->exists();
            }
            abort_unless($owned, 404);
            $movement->update($this->fields($request, $movement));

            return response()->json(['ok' => true]);
        });
    }
}
