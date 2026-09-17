<?php
namespace Tests\Unit;
use Tests\TestCase;
use App\Core\Export\TableExport;
class TableExportTest extends TestCase
{
    public function test_formula_escaping_long_text_and_pdf_signature(): void
    {
        $export = new TableExport();
        $rows = [
            ['Gast', 'Notizen'],
            ['Änne Müller', str_repeat('Langer Text & <script>', 80)],
            [' =SUM(A1:A2)', 'Ende'],
        ];
        $csv = $export->response($rows, 'csv', 'test', 'Test')->getContent();
        $this->assertStringContainsString("' =SUM", $csv);
        $html = $export->html($rows, 'Test');
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('Änne Müller', $html);
        $this->assertGreaterThan(10, substr_count($html, '<tr>'));
        $pdf = $export->response($rows, 'pdf', 'test', 'Test');
        $this->assertStringStartsWith('%PDF-', $pdf->getContent());
        $this->assertStringContainsString('no-store', $pdf->headers->get('Cache-Control'));
    }
    public function test_pdf_limit_fails_without_silent_truncation(): void
    {
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        (new TableExport())->response(array_fill(0, 502, ['Zeile']), 'pdf', 'test', 'Test');
    }
    public function test_xlsx_supports_more_than_26_columns(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'xlsx-');
        try {
            file_put_contents(
                $file,
                (new TableExport())->xlsx([array_fill(0, 28, 'Spalte'), array_fill(0, 28, '=1+1')]),
            );
            $zip = new \ZipArchive();
            $zip->open($file);
            $xml = $zip->getFromName('xl/worksheets/sheet1.xml');
            $zip->close();
            $this->assertStringContainsString('AB2', $xml);
            $this->assertStringNotContainsString('<f>', $xml);
            $this->assertNotFalse(simplexml_load_string($xml));
        } finally {
            unlink($file);
        }
    }
}
