<?php
namespace App\Core\Export;
class TableExport
{
    private function column(int $index): string
    {
        $name = '';
        do {
            $name = chr(65 + ($index % 26)) . $name;
            $index = intdiv($index, 26) - 1;
        } while ($index >= 0);
        return $name;
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
                    '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Export" sheetId="1" r:id="rId1"/></sheets></workbook>',
                'xl/_rels/workbook.xml.rels' =>
                    '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>',
            ];
            $sheet =
                '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><cols><col min="1" max="1" width="10" customWidth="1"/><col min="2" max="' .
                count($rows[0]) .
                '" width="25" customWidth="1"/></cols><sheetData>';
            foreach ($rows as $i => $row) {
                $sheet .= '<row r="' . ($i + 1) . '">';
                foreach ($row as $j => $value) {
                    $ref = $this->column($j) . ($i + 1);
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
            $sheet .=
                '</sheetData><autoFilter ref="A1:' .
                $this->column(count($rows[0]) - 1) .
                count($rows) .
                '"/></worksheet>';
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

    public function html(array $rows, string $title): string
    {
        $e = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $html =
            '<!doctype html><html lang="de"><meta charset="utf-8"><title>' .
            $e($title) .
            '</title><style>@page{size:A4 landscape;margin:12mm 12mm 18mm}body{font-family:"DejaVu Sans",sans-serif;font-size:9px;color:#172c32}h1{font-size:18px}table{border-collapse:collapse;width:100%;table-layout:fixed}th,td{border:1px solid #ccc;padding:5px;text-align:left;vertical-align:top;word-wrap:break-word}th{background:#edf3ef}thead{display:table-header-group}</style><body><h1>Platzhirsch · ' .
            $e($title) .
            '</h1><table><thead><tr>';
        foreach (array_shift($rows) as $cell) {
            $html .= '<th>' . $e($cell) . '</th>';
        }
        $html .= '</tr></thead><tbody>';
        foreach ($rows as $row) {
            // Dompdf cannot split one table row over pages: bounded continuation rows preserve all text.
            $parts = array_map(fn($v) => mb_str_split((string) $v, 120) ?: [''], $row);
            $count = max(array_map('count', $parts));
            for ($i = 0; $i < $count; $i++) {
                $html .= '<tr>';
                foreach ($parts as $part) {
                    $html .= '<td>' . nl2br($e($part[$i] ?? '')) . '</td>';
                }
                $html .= '</tr>';
            }
        }
        return $html . '</tbody></table></body></html>';
    }
    public function response(array $rows, string $format, string $filename, string $title)
    {
        abort_unless(in_array($format, ['csv', 'xlsx', 'pdf'], true), 422, 'Ungültiges Exportformat.');
        abort_if(
            count($rows) > ($format === 'pdf' ? 501 : 10001),
            422,
            'Zu viele Datensätze. Zeitraum oder Filter einschränken (PDF: 500, Tabelle: 10.000).',
        );
        if ($format === 'pdf') {
            return (new Pdf())->response($this->html($rows, $title), $filename, true);
        }
        if ($format === 'xlsx') {
            $bytes = $this->xlsx($rows);
        } else {
            $out = fopen('php://temp', 'w+');
            fwrite($out, "\xEF\xBB\xBF");
            foreach ($rows as $row) {
                fputcsv(
                    $out,
                    array_map(
                        fn($v) => is_string($v) && preg_match('/^[\s]*[=+@\-]|^[\t\r\n]/u', $v)
                            ? "'" . $v
                            : $v,
                        $row,
                    ),
                    ';',
                    '"',
                    '',
                );
            }
            rewind($out);
            $bytes = stream_get_contents($out);
            fclose($out);
        }
        return response($bytes, 200, [
            'Content-Type' =>
                $format === 'csv'
                    ? 'text/csv; charset=UTF-8'
                    : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' =>
                'attachment; filename="' .
                preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename) .
                '.' .
                $format .
                '"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
