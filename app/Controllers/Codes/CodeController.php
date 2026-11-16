<?php
declare(strict_types=1);

namespace App\Controllers\Codes;

use App\Controllers\Controller;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\BusinessRuleException;
use App\Repositories\BatchRepository;
use App\Repositories\ProductRepository;
use App\Services\Codes\CodeService;
use App\Services\Codes\ScanService;
use App\Services\Reporting\PdfExporter;

/** Code images, printable label sheets, the scanner page and the scan lookup API. */
final class CodeController extends Controller
{
    public function __construct(
        private CodeService $codes,
        private ScanService $scan,
        private ProductRepository $products,
        private BatchRepository $batches,
        private PdfExporter $pdf,
        private Database $db,
    ) {
    }

    private function svg(string $svg): Response
    {
        return new Response($svg, 200, ['Content-Type' => 'image/svg+xml', 'Cache-Control' => 'private, max-age=86400']);
    }

    /** GET /codes/product/{id}?type=qr|barcode */
    public function productImage(Request $request, int $id): Response
    {
        $p = $this->findOr404($this->products->find($id), 'Product');
        $payload = CodeService::productPayload($p['product_code']);
        return $this->svg($request->query('type') === 'barcode' ? $this->codes->barcodeSvg($payload) : $this->codes->qrSvg($payload));
    }

    /** GET /codes/batch/{id}?type=qr|barcode */
    public function batchImage(Request $request, int $id): Response
    {
        $b = $this->findOr404($this->batches->find($id), 'Batch');
        $payload = CodeService::batchPayload($b['batch_code']);
        return $this->svg($request->query('type') === 'barcode' ? $this->codes->barcodeSvg($payload) : $this->codes->qrSvg($payload));
    }

    /**
     * GET /labels?products=1,2&batches=3,4&receipt=7&copies=2[&format=pdf]
     * Printable label sheet (A4, 3 × 8 labels). PDF uses PNG images for reliable rendering.
     */
    public function labels(Request $request): Response
    {
        $ids = fn (string $key) => array_values(array_unique(array_filter(array_map('intval', explode(',', (string) $request->query($key, '')))))) ;
        $productIds = array_slice($ids('products'), 0, 200);
        $batchIds = array_slice($ids('batches'), 0, 200);
        if ($receipt = (int) $request->query('receipt')) {
            $batchIds = array_merge($batchIds, array_map('intval', $this->db->fetchColumn(
                'SELECT DISTINCT batch_id FROM goods_receipt_items WHERE goods_receipt_id = :g AND batch_id IS NOT NULL', ['g' => $receipt]
            )));
        }
        if (!$productIds && !$batchIds) {
            throw new BusinessRuleException('Choose at least one product or batch to print labels for.');
        }
        $copies = max(1, min(50, (int) $request->query('copies', 1)));
        $pdf = $request->query('format') === 'pdf';
        $labels = [];
        foreach ($productIds as $id) {
            if ($p = $this->products->find($id)) {
                $payload = CodeService::productPayload($p['product_code']);
                $labels[] = ['kind' => 'product', 'title' => $p['name'], 'lines' => [trim(($p['strength'] ?? '') . ' ' . ($p['dosage_form'] ?? '')), 'Unit: ' . $p['unit_name']],
                    'code' => $p['product_code'], 'payload' => $payload,
                    'qr' => $pdf ? $this->codes->qrPngDataUri($payload) : route('codes.product', ['id' => $id]),
                    'barcode' => $pdf ? $this->codes->barcodePngDataUri($payload) : route('codes.product', ['id' => $id], ['type' => 'barcode'])];
            }
        }
        foreach (array_unique($batchIds) as $id) {
            if ($b = $this->batches->find($id)) {
                $payload = CodeService::batchPayload($b['batch_code']);
                $labels[] = ['kind' => 'batch', 'title' => $b['product_name'], 'lines' => ['Lot ' . $b['lot_number'], 'Exp ' . ($b['expiry_date'] ? date('d M Y', strtotime($b['expiry_date'])) : '—')],
                    'code' => $b['batch_code'], 'payload' => $payload,
                    'qr' => $pdf ? $this->codes->qrPngDataUri($payload) : route('codes.batch', ['id' => $id]),
                    'barcode' => $pdf ? $this->codes->barcodePngDataUri($payload) : route('codes.batch', ['id' => $id], ['type' => 'barcode'])];
            }
        }
        $sheet = [];
        foreach ($labels as $l) {
            for ($i = 0; $i < $copies; $i++) {
                $sheet[] = $l;
            }
        }
        if ($pdf) {
            return $this->pdf->render('codes/labels_pdf', ['title' => 'Labels', 'labels' => $sheet], 'medistock-labels.pdf');
        }
        return view('codes/labels', [
            'title' => 'Print labels', 'labels' => $sheet, 'copies' => $copies, 'unique' => count($labels),
            'query' => array_filter(['products' => implode(',', $productIds), 'batches' => implode(',', array_unique($batchIds)), 'copies' => $copies]),
            'scripts' => ['js/pages/labels.js'],
        ], 'layouts/minimal');
    }

    public function scanner(Request $request): Response
    {
        return view('codes/scan', [
            'title'   => 'Scan',
            'scripts' => ['vendor/html5-qrcode/html5-qrcode.min.js', 'js/components/scanner.js', 'js/pages/scan.js'],
        ]);
    }

    /** GET /api/scan/resolve?code=... */
    public function resolve(Request $request): Response
    {
        return json($this->scan->lookup((string) $request->query('code', '')));
    }
}
