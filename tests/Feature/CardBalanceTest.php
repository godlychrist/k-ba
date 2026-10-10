<?php

namespace Tests\Feature;

use App\Models\BalanceAdjustment;
use App\Models\User;
use App\Services\BalanceProjector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CardBalanceTest extends TestCase
{
    use RefreshDatabase;

    private function purchase(array $fields = []): object
    {
        return (object) array_merge(['account_reference' => '9495', 'currency' => 'CRC', 'instrument_type' => 'card', 'date_from_email' => false, 'occurred_at' => '2026-10-04T19:29:00-06:00', 'kind' => 'expense', 'amount' => '20762.00'], $fields);
    }

    public function test_linked_card_reduces_manual_balance_without_a_statement(): void
    {
        $user = User::factory()->create();
        $base = BalanceAdjustment::create(['user_id' => $user->id, 'account_reference' => '9314', 'currency' => 'CRC', 'amount' => '106000.00', 'effective_at' => '2026-10-04T09:55:19Z']);
        $this->actingAs($user)->postJson('/api/debit-card-links', ['card_reference' => '9495', 'account_reference' => '9314', 'currency' => 'CRC'])->assertOk();
        $links = \Illuminate\Support\Facades\DB::table('debit_card_links')->get();
        $transactions = collect([
            $this->purchase(),
            $this->purchase(['amount' => '1000.00', 'kind' => 'income']),
            $this->purchase(['amount' => '5000.00', 'occurred_at' => '2026-10-01T12:00:00-06:00']),
            $this->purchase(['amount' => '8000.00', 'currency' => 'USD']),
            $this->purchase(['amount' => '9000.00', 'account_reference' => '8888']),
        ]);
        $result = (new BalanceProjector)->project(collect(), collect([$base]), $transactions, $links);
        $this->assertSame('86238.00', $result[0]['amount']);
        $this->assertSame(2, $result[0]['applied_movements']);
        $this->assertSame($result, (new BalanceProjector)->project(collect(), collect([$base]), $transactions, $links));
        $other = User::factory()->create();
        $this->actingAs($other)->postJson('/api/debit-card-links', ['card_reference' => '9495', 'account_reference' => '9314', 'currency' => 'CRC'])->assertUnprocessable();
        $this->getJson('/api/dashboard')->assertJsonCount(0, 'debit_card_links');
    }

    public function test_start_of_month_balance_replaces_previous_manual_base_and_counts_monthly_spending(): void
    {
        $this->travelTo(\Carbon\Carbon::parse('2026-10-05T02:00:00Z'));
        $user = User::factory()->create();
        BalanceAdjustment::create(['user_id' => $user->id, 'account_reference' => '9314', 'currency' => 'CRC', 'amount' => '106000.00', 'effective_at' => '2026-10-04T09:55:19Z']);
        $this->actingAs($user)->postJson('/api/balance-adjustments', ['account_reference' => '9314', 'currency' => 'CRC', 'amount' => '106000.00', 'basis' => 'month_start', 'month' => '2026-10'])->assertOk();
        $bases = BalanceAdjustment::where('user_id', $user->id)->get();
        $links = collect([(object) ['card_reference' => '9495', 'account_reference' => '9314', 'currency' => 'CRC']]);
        $result = (new BalanceProjector)->project(collect(), $bases, collect([$this->purchase(), $this->purchase(['amount' => '1390.00', 'occurred_at' => '2026-10-01T21:19:44-06:00']), $this->purchase(['amount' => '999.00', 'occurred_at' => '2026-09-30T23:59:59-06:00'])]), $links);
        $this->assertSame('83848.00', $result[0]['amount']);
        $this->assertSame('2026-10-01T06:00:00+00:00', $result[0]['base_at']);
        $this->postJson('/api/balance-adjustments', ['account_reference' => '9314', 'currency' => 'CRC', 'amount' => '106000.00', 'basis' => 'month_start', 'month' => '2026-11'])->assertUnprocessable();
        $this->travelBack();
    }
}
