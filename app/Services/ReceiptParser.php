<?php

namespace App\Services;

use Carbon\CarbonImmutable;

class ReceiptParser
{
    public function parse(string $text, string $receivedAt): array
    {
        $text = preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/isu', '', $text);
        $text = preg_replace('/<br\s*\/?>|<\/(?:p|div|tr)>/iu', "\n", $text);
        $text = preg_replace('/<\/(?:td|th)>/iu', ' ', $text);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace(["\u{00A0}", "\u{202F}"], ' ', $text);
        $money = '(CRC|COLONES|₡|GTQ|USD|EUR|Q|US\$|\$|€)\s*([\d.,]+)';
        $result = ['transaction' => null, 'balance' => null];
        preg_match('/(?:cuenta|tarjeta|account|card|mastercard|visa)\s*(?:no\.?|n[úu]mero|number|terminad[ao] en|ending in|[:#*\s-])*\s*([*xX-]*\d{4,})/iu', $text, $account);
        $reference = isset($account[1]) ? substr(preg_replace('/\D/', '', $account[1]), -4) : null;
        if ($reference && preg_match('/(?:saldo disponible|saldo actual|available balance|current balance)\s*[:\s]*'.$money.'/iu', $text, $balance)) {
            $result['balance'] = ['amount' => $this->amount($balance[2]), 'currency' => $this->currency($balance[1]), 'account_reference' => $reference, 'observed_at' => $receivedAt];
        }
        if (! preg_match('/(?:compra|compraste|consumo|pago realizado|pagaste|purchase|payment of|charged|retiro|withdrawal|dep[óo]sito recibido|abono recibido|transferencia recibida|received a deposit)/iu', $text)) {
            return $result;
        }
        if (preg_match('/(?:rechazad[ao]|declined|fallid[ao]|failed|pendiente|pending|reversad[ao]|reversed)/iu', $text)) {
            return $result;
        }
        // An explicit amount label is required: never pick a balance or card number as an expense.
        if (! preg_match('/(?:monto(?:\s+de\s+la\s+(?:compra|transacci[óo]n))?|importe|total|valor|amount|payment of|charged)\s*(?:de|por|:)?\s*'.$money.'/iu', $text, $match)) {
            return $result;
        }
        preg_match('/\b(?:(?:comercio|establecimiento|merchant)\s*:?|(?:en|at)\s*:)\s*([^\r\n]{2,120})/iu', $text, $merchant);
        if (! isset($merchant[1]) && preg_match('/compra\s+realizada\s+en\s+(.+?)\s+el\s+\d{1,2}\s+de\s+/isu', $text, $bnMerchant)) {
            $merchant[1] = trim(preg_replace('/\s+/u', ' ', $bnMerchant[1]));
        }
        $occurred = $receivedAt;
        $fallback = true;
        if (preg_match('/el\s+(\d{1,2})\s+de\s+(enero|febrero|marzo|abril|mayo|junio|julio|agosto|septiembre|setiembre|octubre|noviembre|diciembre)\s+de\s+(\d{4})\s+a las\s+(\d{1,2}):(\d{2})\s*([ap])\.?\s*m\.?/iu', $text, $bnDate)) {
            $months = ['enero' => 1, 'febrero' => 2, 'marzo' => 3, 'abril' => 4, 'mayo' => 5, 'junio' => 6, 'julio' => 7, 'agosto' => 8, 'septiembre' => 9, 'setiembre' => 9, 'octubre' => 10, 'noviembre' => 11, 'diciembre' => 12];
            $month = $months[mb_strtolower($bnDate[2])];
            if (! checkdate($month, (int) $bnDate[1], (int) $bnDate[3]) || (int) $bnDate[4] < 1 || (int) $bnDate[4] > 12 || (int) $bnDate[5] > 59) {
                return $result;
            }
            $hour = (int) $bnDate[4] % 12 + (strtolower($bnDate[6]) === 'p' ? 12 : 0);
            $occurred = CarbonImmutable::create((int) $bnDate[3], $month, (int) $bnDate[1], $hour, (int) $bnDate[5], 0, 'America/Costa_Rica')->toIso8601String();
            $fallback = false;
        }
        if (preg_match('/(?:fecha|date)\s*:\s*(\d{4}-\d{2}-\d{2}|\d{2}\/\d{2}\/\d{4})(?:[ T]+(\d{2}:\d{2}(?::\d{2})?))?/iu', $text, $date)) {
            try {
                $format = str_contains($date[1], '/') ? '!d/m/Y' : '!Y-m-d';
                $parsed = CarbonImmutable::createFromFormat($format, $date[1], 'America/Guatemala');
                if ($parsed->format(str_contains($date[1], '/') ? 'd/m/Y' : 'Y-m-d') !== $date[1]) {
                    return $result;
                }
                if (isset($date[2])) {
                    $parsed = $parsed->setTimeFromTimeString($date[2]);
                }
                $occurred = $parsed->toIso8601String();
                $fallback = ! isset($date[2]);
            } catch (\Throwable) {
                return $result;
            }
        }
        $result['transaction'] = ['kind' => preg_match('/dep[óo]sito recibido|abono recibido|transferencia recibida|received a deposit/iu', $text) ? 'income' : 'expense', 'amount' => $this->amount($match[2]), 'currency' => $this->currency($match[1]), 'merchant' => isset($merchant[1]) ? trim($merchant[1]) : null, 'account_reference' => $reference, 'occurred_at' => $occurred, 'date_from_email' => $fallback];

        if ($result['transaction']) {
            $result['transaction']['instrument_type'] = preg_match('/(?:mastercard|visa|tarjeta)\s*(?:no\.?|n[úu]mero|terminad[ao] en|[:#*\s-])*\s*[*xX-]*\d{4,}/iu', $text) ? 'card' : 'bank_account';
        }

        return $result;
    }

    private function currency(string $value): string
    {
        return match (strtoupper($value)) {
            'CRC', 'COLONES', '₡' => 'CRC',
            'Q', 'GTQ' => 'GTQ', '€', 'EUR' => 'EUR', default => 'USD'
        };
    }

    private function amount(string $value): string
    {
        $lastDot = strrpos($value, '.');
        $lastComma = strrpos($value, ',');
        $position = max($lastDot === false ? -1 : $lastDot, $lastComma === false ? -1 : $lastComma);
        if ($position >= 0 && strlen($value) - $position - 1 === 2) {
            return preg_replace('/\D/', '', substr($value, 0, $position)).'.'.substr($value, $position + 1);
        }

        return preg_replace('/\D/', '', $value).'.00';
    }
}
