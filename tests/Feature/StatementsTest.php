<?php

namespace Tests\Feature;

use App\Models\BalanceAdjustment;
use App\Models\BankStatement;
use App\Models\MailAccount;
use App\Models\User;
use App\Services\BalanceProjector;
use App\Services\StatementParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StatementsTest extends TestCase
{
    use RefreshDatabase;

    private function statement(User $user, array $fields = []): BankStatement
    {
        $account = MailAccount::create(['user_id' => $user->id, 'provider' => 'gmail', 'email' => $user->email, 'access_token' => 'token', 'expires_at' => now()->addHour()]);
        $record = DB::table('mail_records')->insertGetId(['mail_account_id' => $account->id, 'message_id' => 'statement', 'received_at' => now(), 'status' => 'unrecognized', 'created_at' => now(), 'updated_at' => now()]);

        return BankStatement::create(array_merge(['mail_record_id' => $record, 'attachment_key' => hash('sha256', 'attachment'), 'filename' => 'estado.pdf', 'status' => 'needs_review', 'account_type' => 'bank_account', 'account_reference' => '1234', 'currency' => 'CRC', 'closing_date' => '2026-09-30', 'closing_balance' => '10000.00', 'confirmed' => true, 'extracted_text' => 'Texto privado'], $fields));
    }

    public function test_only_post_cutoff_movements_of_same_account_are_applied(): void
    {
        $user = User::factory()->create();
        $statement = $this->statement($user);
        $base = ['account_reference' => '1234', 'currency' => 'CRC', 'date_from_email' => false];
        $tx = collect([
            (object) array_merge($base, ['kind' => 'expense', 'amount' => '1000.00', 'occurred_at' => '2026-09-30T23:59:00-06:00']),
            (object) array_merge($base, ['kind' => 'expense', 'amount' => '1208.00', 'occurred_at' => '2026-10-03T14:24:00-06:00']),
            (object) array_merge($base, ['kind' => 'income', 'amount' => '2000.00', 'occurred_at' => '2026-10-04T10:00:00-06:00']),
            (object) array_merge($base, ['kind' => 'expense', 'amount' => '5000.00', 'occurred_at' => '2026-10-04T10:00:00-06:00', 'currency' => 'USD']),
            (object) array_merge($base, ['kind' => 'expense', 'amount' => '5000.00', 'occurred_at' => '2026-10-04T10:00:00-06:00', 'date_from_email' => true]),
        ]);
        $result = (new BalanceProjector)->project(collect([$statement]), collect(), $tx);
        $this->assertSame('10792.00', $result[0]['amount']);
        $this->assertSame(2, $result[0]['applied_movements']);
    }

    public function test_credit_cards_and_unconfirmed_statements_are_not_cash(): void
    {
        $user = User::factory()->create();
        $statement = $this->statement($user, ['account_type' => 'credit_card']);
        $this->assertSame([], (new BalanceProjector)->project(collect([$statement]), collect(), collect()));
        $statement->account_type = 'bank_account';
        $statement->confirmed = false;
        $this->assertSame([], (new BalanceProjector)->project(collect([$statement]), collect(), collect()));
    }

    public function test_manual_adjustment_replaces_older_statement_base(): void
    {
        $user = User::factory()->create();
        $statement = $this->statement($user);
        $adjustment = BalanceAdjustment::create(['user_id' => $user->id, 'account_reference' => '1234', 'currency' => 'CRC', 'amount' => '8888.25', 'effective_at' => '2026-10-05T20:00:00Z']);
        $result = (new BalanceProjector)->project(collect([$statement]), collect([$adjustment]), collect());
        $this->assertSame('8888.25', $result[0]['amount']);
        $this->assertSame('manual', $result[0]['source']);
    }

    public function test_statement_data_is_encrypted_and_other_users_cannot_edit_or_download(): void
    {
        $user = User::factory()->create();
        $statement = $this->statement($user);
        $this->assertNotSame('Texto privado', DB::table('bank_statements')->value('extracted_text'));
        $other = User::factory()->create();
        $this->actingAs($other)->postJson('/api/statements/'.$statement->id.'/confirm', [])->assertNotFound();
        $this->getJson('/api/statements/'.$statement->id.'/download')->assertNotFound();
        $this->getJson('/api/dashboard')->assertJsonCount(0, 'statements')->assertJsonCount(0, 'projected_balances');
    }

    public function test_manual_balance_is_validated_and_shown_in_dashboard(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/api/balance-adjustments', ['account_reference' => '1234', 'currency' => 'CRC', 'amount' => '5000.25'])->assertOk();
        $this->getJson('/api/dashboard')->assertJsonPath('projected_balances.0.amount', '5000.25');
        $this->postJson('/api/balance-adjustments', ['account_reference' => '1', 'currency' => 'CRC', 'amount' => '-1'])->assertUnprocessable();
    }

    public function test_generic_statement_summary_is_automatic(): void
    {
        $parsed = (new StatementParser)->text("Estado de cuenta de ahorros\nCuenta: CR00123456781234\nFecha de corte: 30/09/2026\nSaldo final: CRC 10.000,00");
        $this->assertSame('bank_account', $parsed['account_type']);
        $this->assertSame('1234', $parsed['account_reference']);
        $this->assertSame('10000.00', $parsed['closing_balance']);
        $this->assertSame('2026-09-30', $parsed['closing_date']);
        $this->assertSame('ready', $parsed['status']);
        $this->assertTrue($parsed['confirmed']);
        $this->assertSame('unreadable', (new StatementParser)->pdf('invalid')['status']);
    }

    public function test_bn_table_is_summarized_and_glossary_is_not_a_statement(): void
    {
        $parser = new StatementParser;
        $parsed = $parser->text("Saldo Anterior\tDébitos\tCréditos\tSaldo Actual\nCantidad Monto Cantidad Monto\n450.00 2\t1,208.00 1\t2,000.00\t1,242.00\nSaldo Congelado\tSaldo Bloqueado\tSobregiro Autorizado\tSaldo Disponible\n0.00\t200.00\t0.00\t1,042.00\nEstado de Cuenta\nElectrónica Colones\nNúmero de cuenta:\n200-01-123-001234-5\nFecha último estado:21/08/2026\nFecha éste estado: 18/09/2026\n04-09 12345 COMERCIO EJEMPLO\t1,208.00-\t1,242.00");
        $this->assertSame('ready', $parsed['status']);
        $this->assertTrue($parsed['confirmed']);
        $this->assertSame('2345', $parsed['account_reference']);
        $this->assertSame('2026-09-18', $parsed['closing_date']);
        $this->assertSame('1242.00', $parsed['closing_balance']);
        $this->assertSame('1042.00', $parsed['summary']['available_balance']);
        $this->assertSame('1208.00', $parsed['summary']['debits']);
        $this->assertSame('COMERCIO EJEMPLO', $parsed['summary']['movements'][0]['description']);
        $this->assertSame('2026-09-04', $parsed['summary']['movements'][0]['date']);
        $glossary = $parser->text('GLOSARIO: Saldo actual: saldo anterior más créditos menos débitos. Saldo anterior: saldo registrado a la fecha de corte del último estado de cuenta.');
        $this->assertSame('reference', $glossary['status']);
        $this->assertFalse($glossary['confirmed']);
    }

    public function test_dashboard_orders_latest_statement_and_excludes_glossary(): void
    {
        $user = User::factory()->create();
        $old = $this->statement($user, ['closing_date' => '2026-09-10']);
        $new = $old->replicate();
        $new->fill(['attachment_key' => hash('sha256', 'new'), 'closing_date' => '2026-09-18', 'summary' => ['available_balance' => '9000.00', 'movements' => []]])->save();
        $glossary = $old->replicate();
        $glossary->fill(['attachment_key' => hash('sha256', 'glossary'), 'status' => 'reference', 'confirmed' => false, 'closing_date' => null])->save();
        $this->assertNotSame('9000.00', DB::table('bank_statements')->where('id', $new->id)->value('summary'));
        $this->actingAs($user)->getJson('/api/dashboard')->assertOk()->assertJsonCount(2, 'statements')->assertJsonPath('statements.0.id', $new->id)->assertJsonPath('statements.1.id', $old->id)->assertJsonPath('statements.0.summary.available_balance', '9000.00');
    }

    public function test_debit_purchases_require_explicit_card_link(): void
    {
        $user = User::factory()->create();
        $statement = $this->statement($user);
        $purchase = (object) ['account_reference' => '9999', 'instrument_type' => 'card', 'currency' => 'CRC', 'date_from_email' => false, 'kind' => 'expense', 'amount' => '1208.00', 'occurred_at' => '2026-10-03T14:24:00-06:00'];
        $service = new BalanceProjector;
        $this->assertSame('10000.00', $service->project(collect([$statement]), collect(), collect([$purchase]))[0]['amount']);
        $statement->debit_card_reference = '9999';
        $this->assertSame('8792.00', $service->project(collect([$statement]), collect(), collect([$purchase]))[0]['amount']);
    }

    public function test_gmail_pdf_attachment_is_imported_and_can_be_downloaded_by_owner(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        MailAccount::create(['user_id' => $user->id, 'provider' => 'gmail', 'email' => $user->email, 'access_token' => 'token', 'expires_at' => now()->addHour()]);
        $stream = 'BT /F1 12 Tf 50 750 Td (Estado de cuenta de ahorros) Tj 0 -20 Td (Cuenta: CR00123456781234) Tj 0 -20 Td (Fecha de corte: 30/09/2026) Tj 0 -20 Td (Saldo final: CRC 10000.00) Tj ET';
        $objects = ['<< /Type /Catalog /Pages 2 0 R >>', '<< /Type /Pages /Kids [3 0 R] /Count 1 >>', '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 600 800] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>', '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>', '<< /Length '.strlen($stream)." >>\nstream\n".$stream."\nendstream"];
        $bytes = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $index => $object) {
            $offsets[] = strlen($bytes);
            $bytes .= ($index + 1)." 0 obj\n".$object."\nendobj\n";
        }
        $xref = strlen($bytes);
        $bytes .= "xref\n0 6\n0000000000 65535 f \n";
        foreach (array_slice($offsets, 1) as $offset) {
            $bytes .= sprintf("%010d 00000 n \n", $offset);
        }
        $bytes .= "trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF";
        Http::preventStrayRequests();
        Http::fake([
            'gmail.googleapis.com/gmail/v1/users/me/messages?*' => Http::response(['messages' => [['id' => 'statement-1']]]),
            'gmail.googleapis.com/gmail/v1/users/me/messages/statement-1?*' => Http::response(['id' => 'statement-1', 'internalDate' => '1791046800000', 'payload' => ['mimeType' => 'multipart/mixed', 'headers' => [['name' => 'Subject', 'value' => 'Estado de cuenta BN']], 'parts' => [['filename' => 'estado.pdf', 'mimeType' => 'application/pdf', 'body' => ['attachmentId' => 'attachment-1', 'size' => strlen($bytes)]]]]]),
            'gmail.googleapis.com/gmail/v1/users/me/messages/statement-1/attachments/attachment-1' => Http::response(['data' => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=')]),
        ]);
        $this->actingAs($user)->postJson('/api/sync')->assertOk();
        $this->postJson('/api/sync')->assertOk();
        $this->assertDatabaseCount('bank_statements', 1);
        $statement = BankStatement::firstOrFail();
        $this->assertSame('10000.00', $statement->closing_balance);
        $this->assertTrue($statement->confirmed);
        Storage::disk('local')->assertExists($statement->path);
        $this->get('/api/statements/'.$statement->id.'/download')->assertOk();
        $this->postJson('/api/statements/'.$statement->id.'/confirm', ['account_type' => 'bank_account', 'account_reference' => '1234', 'currency' => 'CRC', 'closing_date' => '2026-09-30', 'closing_balance' => '10000.00', 'debit_card_reference' => '9999'])->assertOk();
        $this->getJson('/api/dashboard')->assertJsonPath('projected_balances.0.amount', '10000.00');
    }
}
