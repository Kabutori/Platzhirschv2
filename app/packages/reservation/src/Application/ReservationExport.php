<?php
namespace App\Modules\Reservation\Application;
class ReservationExport
{
    public function rows(iterable $reservations, string $timezone): array
    {
        $rows = [
            [
                'ID',
                'Gast',
                'Tische',
                'Personen',
                'Beginn (' . $timezone . ')',
                'Ende (' . $timezone . ')',
                'Status',
                'Notizen',
            ],
        ];
        foreach ($reservations as $r) {
            $rows[] = [
                (int) $r->id,
                $r->guest_name,
                $r->table_name,
                (int) $r->party_size,
                \Carbon\CarbonImmutable::parse($r->starts_at, 'UTC')
                    ->setTimezone($timezone)
                    ->format('d.m.Y H:i'),
                \Carbon\CarbonImmutable::parse($r->ends_at, 'UTC')
                    ->setTimezone($timezone)
                    ->format('d.m.Y H:i'),
                $r->status,
                $r->notes ?? '',
            ];
        }
        return $rows;
    }
    public function xlsx(array $rows): string
    {
        return (new \App\Core\Export\TableExport())->xlsx($rows);
    }
    public function printable(array $rows, string $date): string
    {
        $escape = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $html =
            '<!doctype html><html lang="de"><meta charset="utf-8"><meta http-equiv="Content-Security-Policy" content="default-src &#39;none&#39;; style-src &#39;unsafe-inline&#39;"><title>Reservierungen ' .
            $escape($date) .
            '</title><style>@page{size:A4 landscape;margin:12mm}body{font:11px Arial;color:#111}h1{font-size:20px}table{border-collapse:collapse;width:100%}th,td{border:1px solid #bbb;padding:6px;text-align:left;overflow-wrap:anywhere}th{background:#eee}tr{break-inside:avoid}thead{display:table-header-group}@media print{.hint{display:none}}</style><h1>Platzhirsch · Reservierungen ' .
            $escape($date) .
            '</h1><p class="hint">Im Druckdialog „Als PDF speichern“ wählen. Falls er nicht öffnet: Strg+P.</p><table><thead><tr>';
        foreach (array_shift($rows) as $value) {
            $html .= '<th>' . $escape($value) . '</th>';
        }
        $html .= '</tr></thead><tbody>';
        foreach ($rows as $row) {
            $html .= '<tr>';
            foreach ($row as $value) {
                $html .= '<td>' . $escape($value) . '</td>';
            }
            $html .= '</tr>';
        }
        return $html . '</tbody></table></html>';
    }
}
