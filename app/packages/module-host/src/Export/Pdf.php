<?php
namespace App\Core\Export;
use Dompdf\Dompdf;
use Dompdf\Options;
class Pdf
{
    // Only server-owned, escaped templates may be passed here. Never expose an HTML-to-PDF endpoint.
    public function render(string $html, bool $landscape = false): string
    {
        $options = new Options();
        $options->setIsRemoteEnabled(false);
        $options->setIsPhpEnabled(false);
        $options->setIsJavascriptEnabled(false);
        $options->setChroot(__DIR__);
        $options->setDefaultFont('DejaVu Sans');
        $options->setDefaultMediaType('print');
        $pdf = new Dompdf($options);
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->setPaper('A4', $landscape ? 'landscape' : 'portrait');
        $pdf->render();
        $pdf->getCanvas()->page_text(
            36,
            $landscape ? 568 : 815,
            'Platzhirsch · {PAGE_NUM} / {PAGE_COUNT}',
            null,
            8,
            [0.35, 0.35, 0.35],
        );
        return $pdf->output();
    }
    public function response(string $html, string $filename, bool $landscape = false)
    {
        return response($this->render($html, $landscape), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' =>
                'attachment; filename="' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename) . '.pdf"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
