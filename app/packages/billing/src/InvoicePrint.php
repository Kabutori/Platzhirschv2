<?php
namespace App\Modules\Billing;
class InvoicePrint
{
    public function render(array $invoice, array $p): string
    {
        $e = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $money = fn($v) => number_format($v / 100, 2, ',', '.') . ' EUR';
        $party = function ($v) use ($e) {
            return '<strong>' .
                $e($v['name']) .
                '</strong><br>' .
                $e($v['street']) .
                '<br>' .
                $e($v['postal_code'] . ' ' . $v['city']) .
                '<br>Deutschland<br>' .
                $e($v['email']) .
                (!empty($v['tax_id']) ? '<br>Steuernummer / USt-ID: ' . $e($v['tax_id']) : '');
        };
        $title = $invoice['kind'] === 'credit' ? 'Stornorechnung' : 'Rechnung';
        $draft = $invoice['status'] === 'draft';
        $html =
            '<!doctype html><html lang="de"><meta charset="utf-8"><title>' .
            $e($invoice['number'] ?? 'Rechnungsentwurf') .
            '</title><style>body{font:15px/1.6 system-ui;color:#172c32;max-width:850px;margin:40px auto;padding:24px}h1{font-size:36px}header{border-bottom:3px solid #315c50;padding-bottom:24px}section{margin:32px 0}table{width:100%;border-collapse:collapse}th,td{padding:12px 0;border-bottom:1px solid #ddd;text-align:left}td:last-child,th:last-child{text-align:right}.totals{text-align:right}.hint{background:#eef3ef;padding:16px}@page{size:A4;margin:18mm}@media print{body{margin:0;padding:0}.hint{display:none}}</style><body><p class="hint">Druckansicht: Im Browser „Drucken“ wählen und bei Bedarf als PDF speichern.</p><header>' .
            $party($p['seller']) .
            '</header><h1>' .
            $title .
            ($draft ? ' · ENTWURF' : '') .
            '</h1><p>Belegnummer: ' .
            $e($invoice['number'] ?? 'Noch nicht vergeben') .
            '<br>Ausstellungsdatum: ' .
            $e($invoice['issued_at'] ? substr($invoice['issued_at'], 0, 10) : 'Noch nicht ausgestellt') .
            '</p><section><small>Rechnungsempfänger</small><br>' .
            $party($p['buyer']) .
            '</section>';
        if ($invoice['status'] === 'cancelled') {
            $html .= '<p><strong>Storniert – zugehörige Stornorechnung beachten.</strong></p>';
        }
        if (isset($p['original_number'])) {
            $html .= '<p>Storno zu ' . $e($p['original_number']) . '<br>Grund: ' . $e($p['reason']) . '</p>';
        }
        $html .=
            '<p>Leistungszeitraum: ' .
            $e($p['service_start']) .
            ' bis einschließlich ' .
            $e((new \DateTimeImmutable($p['service_end']))->modify('-1 day')->format('Y-m-d')) .
            '</p><table><thead><tr><th>Leistung</th><th>Menge</th><th>Netto</th></tr></thead><tbody><tr><td>' .
            $e($p['description']) .
            '</td><td>1</td><td>' .
            $money($p['net_cents']) .
            '</td></tr></tbody></table><section class="totals">Netto: ' .
            $money($p['net_cents']) .
            '<br>Umsatzsteuer ' .
            number_format($p['tax_rate_bps'] / 100, 0, ',', '.') .
            ' %: ' .
            $money($p['tax_cents']) .
            '<br><strong>Gesamt: ' .
            $money($p['gross_cents']) .
            '</strong></section>';
        if (!empty($p['seller']['tax_note'])) {
            $html .= '<p>' . $e($p['seller']['tax_note']) . '</p>';
        }
        $html .=
            $invoice['kind'] === 'credit'
                ? '<p>Dieser Beleg löst keine automatische Erstattung aus.</p>'
                : '<p>Zahlung bereits bestätigt' .
                    (!empty($p['paid_at']) ? ' am ' . $e(substr($p['paid_at'], 0, 10)) : '') .
                    '. Referenz: ' .
                    $e($p['payment_reference']) .
                    '</p>';
        return $html . '<p>' . $e($p['seller']['payment_note'] ?? '') . '</p></body></html>';
    }
}
