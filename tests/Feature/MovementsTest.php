<?php

namespace Tests\Feature;

use App\Models\BalanceAdjustment;
use App\Models\MailAccount;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class MovementsTest extends TestCase
{
    use RefreshDatabase;

    private function fields(): array
    {
        return ['kind' => 'expense', 'amount' => '1208.00', 'currency' => 'CRC', 'merchant' => 'SUPERMERCADO EJEMPLO ALAJUELA CRI', 'notes' => 'Compra semanal', 'account_reference' => '1234', 'instrument_type' => 'bank_account', 'occurred_at' => '2026-09-03T14:24:13-06:00', 'client_id' => (string) Str::uuid()];
    }

    public function test_manual_movements_are_owned_and_retries_do_not_duplicate_them(): void
    {
        $user = User::factory()->create();
        $fields = $this->fields();
        $this->actingAs($user)->postJson('/api/movements', $fields)->assertCreated();
        $this->postJson('/api/movements', $fields)->assertCreated();
        $this->assertDatabaseCount('transactions', 1);
        $this->getJson('/api/dashboard')->assertJsonCount(1, 'transactions')->assertJsonPath('transactions.0.merchant', $fields['merchant']);
        $other = User::factory()->create();
        $this->actingAs($other)->getJson('/api/dashboard')->assertJsonCount(0, 'transactions');
        $this->postJson('/api/movements/'.Transaction::first()->id, $fields)->assertNotFound();
    }

    public function test_imported_merchant_edits_survive_resynchronization(): void
    {
        $user = User::factory()->create();
        $account = MailAccount::create(['user_id' => $user->id, 'email' => $user->email, 'provider' => 'gmail', 'access_token' => 'token', 'expires_at' => now()->addHour()]);
        $record = DB::table('mail_records')->insertGetId(['mail_account_id' => $account->id, 'message_id' => 'purchase', 'received_at' => '2026-09-03 21:00:00', 'status' => 'parsed', 'created_at' => now(), 'updated_at' => now()]);
        $transaction = Transaction::create(['mail_record_id' => $record, 'kind' => 'expense', 'amount' => '1208.00', 'currency' => 'CRC', 'occurred_at' => '2026-09-03 20:24:13', 'date_from_email' => true]);
        $fields = $this->fields();
        $this->actingAs($user)->postJson('/api/movements/'.$transaction->id, $fields)->assertOk();
        $this->assertTrue($transaction->fresh()->date_from_email);
        Http::fake([
            'gmail.googleapis.com/gmail/v1/users/me/messages?*' => Http::response(['messages' => [['id' => 'purchase']]]),
            'gmail.googleapis.com/gmail/v1/users/me/messages/purchase?*' => Http::response(['id' => 'purchase', 'internalDate' => '1788469200000', 'payload' => ['mimeType' => 'text/plain', 'headers' => [], 'body' => ['data' => base64_encode("Compra\nMonto: CRC 5000.00\nComercio: NOMBRE DEL CORREO")]]]),
        ]);
        $this->postJson('/api/sync')->assertOk();
        $this->assertSame($fields['merchant'], $transaction->fresh()->merchant);
        $this->assertSame('1208.00', $transaction->fresh()->amount);
        $fields['date_confirmed'] = true;
        $this->postJson('/api/movements/'.$transaction->id, $fields)->assertOk();
        $this->assertFalse($transaction->fresh()->date_from_email);
    }

    public function test_invalid_movements_are_rejected(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/api/movements', array_merge($this->fields(), ['amount' => '-2']))->assertUnprocessable();
        $this->postJson('/api/movements', array_merge($this->fields(), ['occurred_at' => '2026-08-03T14:24:00-06:00']))->assertUnprocessable();
        $this->postJson('/api/movements', array_merge($this->fields(), ['merchant' => '   ']))->assertUnprocessable();
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_manual_income_and_expense_update_projected_balance(): void
    {
        $user = User::factory()->create();
        BalanceAdjustment::create(['user_id' => $user->id, 'account_reference' => '1234', 'currency' => 'CRC', 'amount' => '10000.00', 'effective_at' => '2026-09-01 06:00:00']);
        $this->actingAs($user)->postJson('/api/movements', $this->fields())->assertCreated();
        $this->postJson('/api/movements', array_merge($this->fields(), ['kind' => 'income', 'amount' => '2000.00']))->assertCreated();
        $this->getJson('/api/dashboard')->assertJsonPath('projected_balances.0.amount', '10792.00');
    }
}
