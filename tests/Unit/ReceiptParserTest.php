<?php

namespace Tests\Unit;

use App\Services\ReceiptParser;
use PHPUnit\Framework\TestCase;

class ReceiptParserTest extends TestCase
{
    public function test_bn_purchase_extracts_merchant_card_and_actual_time(): void
    {
        $text = "Banco Nacional\nComprobante de Compra realizada en SUPERMERCADO EJEMPLO ALAJUELA CRI el 3 de Octubre de 2026 a las 2:24 p.m.\nMASTERCARD\t************1234\nNRO. AUT: 111111\nREF: 22222222\nTOTAL:\tCRC 1208,00";
        $result = (new ReceiptParser)->parse($text, '2026-10-04T10:00:00Z');
        $this->assertSame('1208.00', $result['transaction']['amount']);
        $this->assertSame('SUPERMERCADO EJEMPLO ALAJUELA CRI', $result['transaction']['merchant']);
        $this->assertSame('1234', $result['transaction']['account_reference']);
        $this->assertSame('2026-10-03T14:24:00-06:00', $result['transaction']['occurred_at']);
        $this->assertFalse($result['transaction']['date_from_email']);
    }

    public function test_purchase_and_balance_are_independent(): void
    {
        $result = (new ReceiptParser)->parse("Compra realizada\nMonto: Q 1,234.50\nComercio: SUPERMERCADO\nCuenta: ****1234\nFecha: 03/10/2026 14:32\nSaldo disponible: Q 8,000.00", '2026-10-03T21:00:00Z');
        $this->assertSame('1234.50', $result['transaction']['amount']);
        $this->assertSame('GTQ', $result['transaction']['currency']);
        $this->assertSame('SUPERMERCADO', $result['transaction']['merchant']);
        $this->assertFalse($result['transaction']['date_from_email']);
        $this->assertSame('8000.00', $result['balance']['amount']);
        $this->assertSame('1234', $result['balance']['account_reference']);
    }

    public function test_ambiguous_and_declined_messages_are_not_expenses(): void
    {
        $parser = new ReceiptParser;
        foreach (['Compra rechazada. Monto: USD 20.00', 'Compra pendiente. Monto: Q 20.00', 'Compra. Saldo actual: Q 400.00', 'Oferta especial. Total: Q 100.00'] as $message) {
            $this->assertNull($parser->parse($message, '2026-10-03T21:00:00Z')['transaction']);
        }
    }

    public function test_european_amount_and_income(): void
    {
        $result = (new ReceiptParser)->parse('Abono recibido. Monto: EUR 1.234,56', '2026-10-03T21:00:00Z');
        $this->assertSame('1234.56', $result['transaction']['amount']);
        $this->assertSame('income', $result['transaction']['kind']);
        $this->assertTrue($result['transaction']['date_from_email']);
    }

    public function test_colones_in_html_table_are_recognized(): void
    {
        $result = (new ReceiptParser)->parse('Compra realizada<table><tr><td>Monto:</td><td>₡&nbsp;12.345,67</td></tr><tr><td>Comercio:</td><td>SUPERMERCADO</td></tr></table>', '2026-10-03T21:00:00Z');
        $this->assertSame('CRC', $result['transaction']['currency']);
        $this->assertSame('12345.67', $result['transaction']['amount']);
        $this->assertSame('SUPERMERCADO', $result['transaction']['merchant']);
    }

    public function test_crc_and_colones_labels(): void
    {
        foreach (['CRC', 'COLONES'] as $currency) {
            $result = (new ReceiptParser)->parse('Compra realizada. Monto de la transacción: '.$currency.' 4,500.00', '2026-10-03T21:00:00Z');
            $this->assertSame('CRC', $result['transaction']['currency']);
            $this->assertSame('4500.00', $result['transaction']['amount']);
        }
    }
}
