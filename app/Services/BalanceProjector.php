<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class BalanceProjector
{
    public function project(Collection $statements, Collection $adjustments, Collection $transactions, ?Collection $links = null): array
    {
        $anchors = [];
        $cards = [];
        foreach ($statements->sortByDesc('closing_date') as $statement) {
            if ($statement->confirmed && $statement->account_type === 'bank_account') {
                $cardKey = $statement->account_reference.':'.$statement->currency;
                if (! array_key_exists($cardKey, $cards)) {
                    $cards[$cardKey] = array_filter([$statement->debit_card_reference]);
                }
            }
        }
        foreach ($links ?? collect() as $link) {
            foreach ($cards as $key => $references) {
                if (str_ends_with($key, ':'.$link->currency)) $cards[$key] = array_diff($references, [$link->card_reference]);
            }
            $cards[$link->account_reference.':'.$link->currency][] = $link->card_reference;
        }
        foreach ($statements as $statement) {
            if (! $statement->confirmed || $statement->account_type !== 'bank_account') {
                continue;
            }
            $time = CarbonImmutable::parse($statement->closing_date->format('Y-m-d'), 'America/Costa_Rica')->endOfDay();
            $anchors[] = ['account_reference' => $statement->account_reference, 'currency' => $statement->currency, 'amount' => (string) $statement->closing_balance, 'at' => $time, 'source' => 'statement', 'statement_id' => $statement->id];
        }
        $adjustedAccounts = [];
        foreach ($adjustments->sortByDesc('id') as $adjustment) {
            $adjustedKey = $adjustment->account_reference.':'.$adjustment->currency;
            if (isset($adjustedAccounts[$adjustedKey])) continue;
            $adjustedAccounts[$adjustedKey] = true;
            $anchors[] = ['account_reference' => $adjustment->account_reference, 'currency' => $adjustment->currency, 'amount' => (string) $adjustment->amount, 'at' => CarbonImmutable::parse($adjustment->effective_at), 'source' => 'manual', 'statement_id' => null];
        }
        usort($anchors, fn ($a, $b) => $b['at']->timestamp <=> $a['at']->timestamp);
        $result = [];
        foreach ($anchors as $anchor) {
            $key = $anchor['account_reference'].':'.$anchor['currency'];
            if (isset($result[$key])) {
                continue;
            }
            $cents = $this->cents($anchor['amount']);
            $count = 0;
            $expenses = 0;
            $income = 0;
            foreach ($transactions as $transaction) {
                // Ambiguous dates and unidentified accounts are shown, but never change the projected balance.
                $isCard = ($transaction->instrument_type ?? 'bank_account') === 'card';
                $matchesAccount = $isCard ? in_array($transaction->account_reference, $cards[$key] ?? [], true) : $transaction->account_reference === $anchor['account_reference'];
                if (! $matchesAccount || $transaction->currency !== $anchor['currency'] || $transaction->date_from_email || ! CarbonImmutable::parse($transaction->occurred_at)->gt($anchor['at'])) {
                    continue;
                }
                $movement = $this->cents((string) $transaction->amount);
                if ($transaction->kind === 'income') $income += $movement;
                else $expenses += $movement;
                $cents += ($transaction->kind === 'income' ? 1 : -1) * $movement;
                $count++;
            }
            $result[$key] = ['account_reference' => $anchor['account_reference'], 'currency' => $anchor['currency'], 'amount' => $this->decimal($cents), 'base_amount' => $anchor['amount'], 'base_at' => $anchor['at']->toIso8601String(), 'source' => $anchor['source'], 'statement_id' => $anchor['statement_id'], 'applied_movements' => $count, 'applied_expenses' => $this->decimal($expenses), 'applied_income' => $this->decimal($income)];
        }

        return array_values($result);
    }

    private function cents(string $amount): int
    {
        $negative = str_starts_with($amount, '-');
        [$whole, $fraction] = array_pad(explode('.', ltrim($amount, '-'), 2), 2, '00');

        return ($negative ? -1 : 1) * ((int) $whole * 100 + (int) str_pad(substr($fraction, 0, 2), 2, '0'));
    }

    private function decimal(int $cents): string
    {
        return ($cents < 0 ? '-' : '').intdiv(abs($cents), 100).'.'.str_pad((string) (abs($cents) % 100), 2, '0', STR_PAD_LEFT);
    }
}
