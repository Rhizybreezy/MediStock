<?php
declare(strict_types=1);

namespace App\Services\Reporting;

use App\Core\Response;
use App\Core\View;
use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * Renders a view with the print layout into a PDF (dompdf).
 * Remote resources are disabled so documents never fetch external content.
 */
final class PdfExporter
{
    public function render(string $view, array $data, string $filename, string $orientation = 'portrait', bool $inline = true): Response
    {
        $html = View::render($view, $data, 'layouts/print');
        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);
        $options->set('chroot', BASE_PATH . '/public');
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('tempDir', BASE_PATH . '/storage/cache');
        $options->set('fontCache', BASE_PATH . '/storage/cache');
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', $orientation);
        $dompdf->render();
        $pdf = (string) $dompdf->output();
        $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename);
        return new Response($pdf, 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => ($inline ? 'inline' : 'attachment') . '; filename="' . $safe . '"',
            'Content-Length'      => (string) strlen($pdf),
        ]);
    }
}
