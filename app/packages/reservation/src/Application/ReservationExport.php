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
    private function xml(string $value): string
    {
        return htmlspecialchars(
            preg_replace(
                '/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u',
                '',
                $value,
            ),
            ENT_XML1 | ENT_QUOTES,
            'UTF-8',
        );
    }
    public function xlsx(array $rows): string
    {
        abort_unless(class_exists(\ZipArchive::class), 503, 'Die PHP-ZIP-Erweiterung fehlt.');
        $file = tempnam(storage_path('framework/cache'), 'export-');
        if ($file === false) {
            throw new \RuntimeException('Exportverzeichnis nicht beschreibbar.');
        }
        try {
            $zip = new \ZipArchive();
            if ($zip->open($file, \ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('Export konnte nicht erstellt werden.');
            }
            $parts = [
                '[Content_Types].xml' =>
                    '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>',
                '_rels/.rels' =>
                    '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
                'xl/workbook.xml' =>
                    '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Reservierungen" sheetId="1" r:id="rId1"/></sheets></workbook>',
                'xl/_rels/workbook.xml.rels' =>
                    '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>',
            ];
            $sheet =
                '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><cols><col min="1" max="1" width="10" customWidth="1"/><col min="2" max="8" width="25" customWidth="1"/></cols><sheetData>';
            foreach ($rows as $i => $row) {
                $sheet .= '<row r="' . ($i + 1) . '">';
                foreach ($row as $j => $value) {
                    $ref = chr(65 + $j) . ($i + 1);
                    // All guest-controlled values are inline strings, never formulas or links.
                    $sheet .= is_int($value)
                        ? '<c r="' . $ref . '"><v>' . $value . '</v></c>'
                        : '<c r="' .
                            $ref .
                            '" t="inlineStr"><is><t xml:space="preserve">' .
                            $this->xml((string) $value) .
                            '</t></is></c>';
                }
                $sheet .= '</row>';
            }
            $sheet .= '</sheetData><autoFilter ref="A1:H' . count($rows) . '"/></worksheet>';
            $parts['xl/worksheets/sheet1.xml'] = $sheet;
            foreach ($parts as $name => $xml) {
                if (
                    !$zip->addFromString(
                        $name,
                        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . $xml,
                    )
                ) {
                    throw new \RuntimeException('Export konnte nicht geschrieben werden.');
                }
            }
            if (!$zip->close()) {
                throw new \RuntimeException('Export konnte nicht abgeschlossen werden.');
            }
            $result = file_get_contents($file);
            if ($result === false) {
                throw new \RuntimeException('Export konnte nicht gelesen werden.');
            }
            return $result;
        } finally {
            @unlink($file);
        }
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
