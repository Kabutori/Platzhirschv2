<?php
namespace Tests\Unit;
use Tests\TestCase;
use App\Modules\Reservation\Application\ReservationExport;
class ReservationExportTest extends TestCase
{
    public function test_xlsx_has_inline_strings_not_formulas_and_print_escapes_html(): void
    {
        $service = app(ReservationExport::class);
        $rows = [['ID', 'Gast'], [1, '=HYPERLINK("https://example.test")'], [2, '<script>alert(1)</script>']];
        $file = tempnam(sys_get_temp_dir(), 'xlsx-test-');
        try {
            file_put_contents($file, $service->xlsx($rows));
            $zip = new \ZipArchive();
            $this->assertTrue($zip->open($file));
            $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
            $this->assertStringContainsString('t="inlineStr"', $sheet);
            $this->assertStringNotContainsString('<f>', $sheet);
            $this->assertStringContainsString('&lt;script&gt;', $sheet);
            $this->assertNotFalse(simplexml_load_string($sheet));
            $this->assertNotFalse($zip->getFromName('[Content_Types].xml'));
            $zip->close();
        } finally {
            unlink($file);
        }
        $html = $service->printable($rows, '2026-09-10');
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }
}
