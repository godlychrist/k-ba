<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Smalot\PdfParser\Config;
use Smalot\PdfParser\Parser;

class StatementParser
{
    public function pdf(string $bytes): array
    {
        try {
            $config = new Config;
            $config->setDecodeMemoryLimit(10000000);
            $text = (new Parser([], $config))->parseContent($bytes)->getText();

            return $this->text($text);
        } catch (\Throwable) {
            return ['status' => 'unreadable', 'extracted_text' => null];
        }
    }

    public function text(string $text): array
    {
        $text = str_replace(["\r\n", "\r", "\u{00A0}"], ["\n", "\n", ' '], $text);
        $result = ['status' => trim($text) ? 'incomplete' : 'unreadable', 'confirmed' => false, 'summary' => null, 'extracted_text' => mb_substr($text, 0, 50000), 'account_type' => 'unknown'];
        if (preg_match('/glosario/iu', $text) && ! preg_match('/fecha\s+(?:(?:éste|este)\s+estado|de\s+corte)\s*:?\s*\d{2}\/\d{2}\/\d{4}/iu', $text)) {
            return array_merge($result, ['status' => 'reference']);
        }
        if (preg_match('/Estado\s+de\s+Cuenta\s+Electr[oó]nica\s+(Colones|D[oó]lares)/iu', $text, $currency)
            && preg_match('/Saldo\s+Anterior\s+D[eé]bitos\s+Cr[eé]ditos\s+Saldo\s+Actual\s+Cantidad\s+Monto\s+Cantidad\s+Monto\s+(-?[\d,.]+)\s+(\d+)\s+([\d,.]+)\s+(\d+)\s+([\d,.]+)\s+(-?[\d,.]+)/iu', $text, $totals)) {
            $result['account_type'] = 'bank_account';
            $result['currency'] = mb_strtolower($currency[1]) === 'colones' ? 'CRC' : 'USD';
            $result['closing_balance'] = $this->amount($totals[6]);
            $summary = ['opening_balance' => $this->amount($totals[1]), 'debit_count' => (int) $totals[2], 'debits' => $this->amount($totals[3]), 'credit_count' => (int) $totals[4], 'credits' => $this->amount($totals[5]), 'movements' => []];
            if (preg_match('/Saldo\s+Congelado\s+Saldo\s+Bloqueado\s+Sobregiro\s+Autorizado\s+Saldo\s+Disponible\s+(-?[\d,.]+)\s+(-?[\d,.]+)\s+(-?[\d,.]+)\s+(-?[\d,.]+)/iu', $text, $available)) {
                foreach (['frozen', 'blocked', 'overdraft', 'available_balance'] as $index => $key) {
                    $summary[$key] = $this->amount($available[$index + 1]);
                }
            }
            if (preg_match('/Fecha\s+[uú]ltimo\s+estado\s*:\s*(\d{2}\/\d{2}\/\d{4})/iu', $text, $period)) {
                $summary['period_start'] = $this->date($period[1]);
            }
            $result['summary'] = $summary;
        }
        if (preg_match('/tarjeta\s+de\s+cr[ée]dito/iu', $text)) {
            $result['account_type'] = 'credit_card';
        } elseif (preg_match('/cuenta\s+(?:de\s+ahorros|corriente|de\s+ahorro)/iu', $text)) {
            $result['account_type'] = 'bank_account';
        }
        if (preg_match('/(?:n[úu]mero\s+de\s+cuenta\s*:|(?:cuenta|iban)\s*(?:n[úu]mero|no\.?|:)?)\s*((?:CR)?[\d*xX][\d*xX -]{3,40})/iu', $text, $match)) {
            $digits = preg_replace('/\D/', '', $match[1]);
            if (strlen($digits) >= 4) {
                $result['account_reference'] = substr($digits, -4);
            }
        }
        if (preg_match('/(?:fecha\s+de\s+corte|fecha\s+de\s+cierre|fecha\s+(?:éste|este)\s+estado|al\s+corte)\s*:?\s*(\d{2}\/\d{2}\/\d{4}|\d{4}-\d{2}-\d{2})/iu', $text, $match)) {
            try {
                $format = str_contains($match[1], '/') ? 'd/m/Y' : 'Y-m-d';
                $date = CarbonImmutable::createFromFormat('!'.$format, $match[1], 'America/Costa_Rica');
                if ($date->format($format) === $match[1]) {
                    $result['closing_date'] = $date->format('Y-m-d');
                }
            } catch (\Throwable) {
            }
        }
        if (preg_match('/(?:saldo\s+(?:final|al\s+corte|al\s+cierre))\s*:?\s*(CRC|₡|COLONES|USD|US\$|\$)\s*([\d.,]+)/iu', $text, $match)) {
            $result['currency'] = in_array(mb_strtoupper($match[1]), ['CRC', '₡', 'COLONES']) ? 'CRC' : 'USD';
            $value = $match[2];
            $pos = max(strrpos($value, '.') === false ? -1 : strrpos($value, '.'), strrpos($value, ',') === false ? -1 : strrpos($value, ','));
            $result['closing_balance'] = $pos >= 0 && strlen($value) - $pos - 1 === 2 ? preg_replace('/\D/', '', substr($value, 0, $pos)).'.'.substr($value, $pos + 1) : preg_replace('/\D/', '', $value).'.00';
        }

        if (isset($result['account_reference'], $result['currency'], $result['closing_balance'], $result['closing_date']) && $result['account_type'] !== 'unknown') {
            $result['status'] = 'ready';
            $result['confirmed'] = true;
        }
        if ($result['summary'] && isset($result['closing_date'])) {
            $year = (int) substr($result['closing_date'], 0, 4);
            $cutoff = CarbonImmutable::parse($result['closing_date'], 'America/Costa_Rica');
            preg_match_all('/^(\d{2}-\d{2})\s+\d+\s+(.+?)\s+([\d,.]+)([+-])\s+(-?[\d,.]+)\s*$/mu', $text, $rows, PREG_SET_ORDER);
            foreach ($rows as $row) {
                $date = $this->date(str_replace('-', '/', $row[1]).'/'.$year);
                if (! $date) {
                    continue;
                }
                if (CarbonImmutable::parse($date)->gt($cutoff)) {
                    $date = $this->date(str_replace('-', '/', $row[1]).'/'.($year - 1));
                }
                $result['summary']['movements'][] = ['date' => $date, 'description' => trim($row[2]), 'amount' => $this->amount($row[3]), 'kind' => $row[4] === '+' ? 'income' : 'expense', 'balance' => $this->amount($row[5])];
            }
        }

        return $result;
    }

    private function amount(string $value): string
    {
        $negative = str_starts_with($value, '-');
        $value = ltrim($value, '-');
        $position = max(strrpos($value, '.') === false ? -1 : strrpos($value, '.'), strrpos($value, ',') === false ? -1 : strrpos($value, ','));

        return ($negative ? '-' : '').($position >= 0 && strlen($value) - $position - 1 === 2 ? preg_replace('/\D/', '', substr($value, 0, $position)).'.'.substr($value, $position + 1) : preg_replace('/\D/', '', $value).'.00');
    }

    private function date(string $value): ?string
    {
        try {
            $date = CarbonImmutable::createFromFormat('!d/m/Y', $value, 'America/Costa_Rica');

            return $date->format('d/m/Y') === $value ? $date->format('Y-m-d') : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
