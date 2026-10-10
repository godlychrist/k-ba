<?php

namespace Tests\Feature;

use App\Models\MailAccount;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ExpensesTest extends TestCase
{
    use RefreshDatabase;

    private function account(User $user): MailAccount
    {
        return MailAccount::create(['user_id' => $user->id, 'email' => $user->email, 'provider' => 'gmail', 'access_token' => 'secret-token', 'refresh_token' => 'secret-refresh', 'expires_at' => now()->addHour()]);
    }

    public function test_dashboard_requires_authentication(): void
    {
        $this->getJson('/api/dashboard')->assertUnauthorized();
        $this->postJson('/api/sync')->assertUnauthorized();
    }

    public function test_import_is_idempotent_and_tokens_are_encrypted(): void
    {
        $user = User::factory()->create();
        $account = $this->account($user);
        $this->assertNotSame('secret-token', DB::table('mail_accounts')->value('access_token'));
        Http::preventStrayRequests();
        Http::fake([
            'gmail.googleapis.com/gmail/v1/users/me/messages?*' => Http::response(['messages' => [['id' => 'receipt-1']]]),
            'gmail.googleapis.com/gmail/v1/users/me/messages/receipt-1?*' => Http::response(['id' => 'receipt-1', 'internalDate' => '1791046800000', 'payload' => ['mimeType' => 'text/plain', 'headers' => [['name' => 'Subject', 'value' => 'Compra realizada']], 'body' => ['data' => rtrim(strtr(base64_encode("Monto: Q 42.50\nComercio: CAFÉ\nCuenta: ****1234"), '+/', '-_'), '=')]]]),
        ]);
        DB::table('mail_records')->insert(['mail_account_id' => $account->id, 'message_id' => 'receipt-1', 'received_at' => now(), 'status' => 'unrecognized', 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($user)->postJson('/api/sync')->assertOk()->assertJson(['has_more' => false]);
        $this->postJson('/api/sync')->assertOk();
        $this->postJson('/api/sync?rescan=1')->assertOk();
        $this->assertDatabaseCount('transactions', 1);
        $this->assertDatabaseCount('mail_records', 1);
        $this->getJson('/api/dashboard')->assertOk()->assertJsonCount(1, 'transactions')->assertJsonMissing(['access_token' => 'secret-token']);
        $this->assertNotNull($account->fresh()->last_synced_at);
    }

    public function test_gmail_reads_merchant_from_html_when_plain_alternative_omits_it(): void
    {
        $user = User::factory()->create();
        $this->account($user);
        $encode = fn (string $body) => rtrim(strtr(base64_encode($body), '+/', '-_'), '=');
        Http::preventStrayRequests();
        Http::fake([
            'gmail.googleapis.com/gmail/v1/users/me/messages?*' => Http::response(['messages' => [['id' => 'html-receipt']]]),
            'gmail.googleapis.com/gmail/v1/users/me/messages/html-receipt?*' => Http::response([
                'id' => 'html-receipt',
                'internalDate' => '1791046800000',
                'payload' => [
                    'mimeType' => 'multipart/alternative',
                    'headers' => [['name' => 'Subject', 'value' => 'Compra realizada']],
                    'parts' => [
                        ['mimeType' => 'text/plain', 'body' => ['data' => $encode('TOTAL: CRC 1208,00')]],
                        ['mimeType' => 'text/html', 'body' => ['data' => $encode('<p>Banco Nacional</p><p>comprobante de Compra realizada en SUPERMERCADO EJEMPLO ALAJUELA CRI el 3 de Octubre de 2026 a las 2:24 p.m.</p><p>MASTERCARD ************1234</p><p>TOTAL: CRC 1208,00</p>')]],
                    ],
                ],
            ]),
        ]);

        $this->actingAs($user)->postJson('/api/sync')->assertOk();
        $this->assertDatabaseHas('transactions', ['merchant' => 'SUPERMERCADO EJEMPLO ALAJUELA CRI', 'amount' => '1208.00', 'currency' => 'CRC']);
    }

    public function test_gmail_retries_401_with_a_refreshed_access_token(): void
    {
        $user = User::factory()->create();
        $account = $this->account($user);
        Http::preventStrayRequests();
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'renewed-token', 'expires_in' => 3600]),
            'gmail.googleapis.com/*' => Http::sequence()->push(['error' => ['code' => 401]], 401)->push(['messages' => []]),
        ]);
        $this->actingAs($user)->postJson('/api/sync')->assertOk();
        $this->assertSame('renewed-token', $account->fresh()->access_token);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'gmail.googleapis.com') && $request->hasHeader('Authorization', 'Bearer renewed-token'));
    }

    public function test_revoked_gmail_authorization_shows_reconnect_without_losing_checkpoint(): void
    {
        $user = User::factory()->create();
        $account = $this->account($user);
        Http::preventStrayRequests();
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant'], 400),
            'gmail.googleapis.com/*' => Http::response([], 401),
        ]);
        $this->actingAs($user)->postJson('/api/sync')->assertStatus(409)->assertJsonPath('reconnect', true);
        $this->assertNull($account->fresh()->last_synced_at);
    }

    public function test_users_cannot_read_another_mailbox(): void
    {
        $owner = User::factory()->create();
        $this->account($owner);
        $other = User::factory()->create();
        $this->actingAs($other)->getJson('/api/dashboard')->assertOk()->assertJsonCount(0, 'accounts')->assertJsonCount(0, 'transactions');
    }

    public function test_oauth_rejects_missing_or_mismatched_state(): void
    {
        $this->get('/auth/gmail/callback?state=forged&code=fake')->assertForbidden();
    }

    public function test_pagination_keeps_checkpoint_until_final_page(): void
    {
        $user = User::factory()->create();
        $account = $this->account($user);
        Http::fake(['gmail.googleapis.com/*' => Http::response(['messages' => [], 'nextPageToken' => 'next-page'])]);
        $this->actingAs($user)->postJson('/api/sync')->assertOk()->assertJson(['has_more' => true]);
        $this->assertNull($account->fresh()->last_synced_at);
        $this->assertSame('next-page', $account->fresh()->sync_cursor);
    }

    public function test_outlook_refreshes_token_and_imports_income(): void
    {
        config(['expenses.outlook.client_id' => 'test', 'expenses.outlook.client_secret' => 'test']);
        $user = User::factory()->create();
        $account = $this->account($user);
        $account->update(['provider' => 'outlook', 'expires_at' => now()->subMinute()]);
        Http::preventStrayRequests();
        Http::fake([
            'login.microsoftonline.com/*' => Http::response(['access_token' => 'new-token', 'refresh_token' => 'new-refresh', 'expires_in' => 3600]),
            'graph.microsoft.com/*' => Http::response(['value' => [['id' => 'outlook-1', 'subject' => 'Abono recibido', 'receivedDateTime' => '2026-10-03T21:00:00Z', 'body' => ['content' => '<p>Monto: USD 250.00</p><p>Cuenta: ****5678</p>']]]]),
        ]);
        $this->actingAs($user)->postJson('/api/sync')->assertOk();
        $this->assertDatabaseHas('transactions', ['kind' => 'income', 'currency' => 'USD', 'amount' => '250.00']);
        $this->assertSame('new-refresh', $account->fresh()->refresh_token);
    }

    public function test_provider_failure_does_not_advance_checkpoint(): void
    {
        $user = User::factory()->create();
        $account = $this->account($user);
        Http::fake(['gmail.googleapis.com/*' => Http::response([], 503)]);
        $this->actingAs($user)->postJson('/api/sync')->assertServerError();
        $this->assertNull($account->fresh()->last_synced_at);
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_new_import_window_restarts_old_cursor_and_is_kept_on_all_pages(): void
    {
        $user = User::factory()->create();
        $account = $this->account($user);
        $account->update(['sync_cursor' => 'old-unfiltered-page', 'last_synced_at' => '2026-10-01T00:00:00Z']);
        Http::fake(['gmail.googleapis.com/*' => Http::response(['messages' => [], 'nextPageToken' => 'filtered-page'])]);
        $this->actingAs($user)->postJson('/api/sync')->assertOk();
        $this->postJson('/api/sync')->assertOk();
        $expected = 'after:'.(CarbonImmutable::parse('2026-09-01 00:00:00', 'America/Costa_Rica')->timestamp - 1);
        $requests = Http::recorded()->map(fn ($pair) => $pair[0]);
        $this->assertSame($expected, $requests[0]['q']);
        $this->assertFalse(isset($requests[0]['pageToken']));
        $this->assertSame($expected, $requests[1]['q']);
        $this->assertSame('filtered-page', $requests[1]['pageToken']);
        $this->assertSame('2026-09-01', $account->fresh()->sync_since);
    }

    public function test_old_messages_are_not_imported_even_if_provider_returns_them(): void
    {
        $user = User::factory()->create();
        $this->account($user);
        Http::fake([
            'gmail.googleapis.com/gmail/v1/users/me/messages?*' => Http::response(['messages' => [['id' => 'old']]]),
            'gmail.googleapis.com/gmail/v1/users/me/messages/old?*' => Http::response(['id' => 'old', 'internalDate' => (string) CarbonImmutable::parse('2026-08-31T23:59:59-06:00')->getTimestampMs(), 'payload' => ['mimeType' => 'text/plain', 'headers' => [], 'body' => ['data' => base64_encode('Compra. Monto: CRC 1000.00')]]]),
        ]);
        $this->actingAs($user)->postJson('/api/sync')->assertOk();
        $this->assertDatabaseCount('mail_records', 0);
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_dashboard_counts_only_records_inside_import_window(): void
    {
        $user = User::factory()->create();
        $account = $this->account($user);
        DB::table('mail_records')->insert(['mail_account_id' => $account->id, 'message_id' => 'old', 'received_at' => '2026-08-01 10:00:00', 'status' => 'unrecognized', 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($user)->getJson('/api/dashboard')->assertOk()->assertJsonPath('scanned', 0)->assertJsonPath('unrecognized', 0)->assertJsonPath('import_since', '2026-09-01');
        $this->assertDatabaseCount('mail_records', 1);
    }
}
