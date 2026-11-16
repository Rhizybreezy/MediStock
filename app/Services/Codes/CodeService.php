<?php
declare(strict_types=1);

namespace App\Services\Codes;

use App\Core\Database;
use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Picqer\Barcode\BarcodeGeneratorPNG;
use Picqer\Barcode\BarcodeGeneratorSVG;

/**
 * QR codes and barcodes.
 *
 * Payload formats (a product code can never be mistaken for a batch code):
 *   MSP:<product_code>   e.g. MSP:MED-000001  → product
 *   MSB:<batch_code>     e.g. MSB:B26-000781  → specific batch
 * Scans are also resolved from bare product/batch codes and from manufacturer
 * GTIN/EAN barcodes registered on product pack units.
 */
final class CodeService
{
    public const PRODUCT_PREFIX = 'MSP:';
    public const BATCH_PREFIX = 'MSB:';

    public function __construct(private Database $db)
    {
    }

    public static function productPayload(string $productCode): string
    {
        return self::PRODUCT_PREFIX . $productCode;
    }

    public static function batchPayload(string $batchCode): string
    {
        return self::BATCH_PREFIX . $batchCode;
    }

    /** QR code as inline SVG markup (for web pages). */
    public function qrSvg(string $payload): string
    {
        $options = new QROptions([
            'outputType' => 'svg', 'outputBase64' => false, 'eccLevel' => EccLevel::M,
            'addQuietzone' => true, 'quietzoneSize' => 2, 'svgAddXmlHeader' => false,
            'drawLightModules' => false, 'svgUseFillAttributes' => true,
        ]);
        return (new QRCode($options))->render($payload);
    }

    /** QR code as a PNG data URI (for PDFs; dompdf handles PNG reliably). */
    public function qrPngDataUri(string $payload, int $scale = 5): string
    {
        $options = new QROptions(['outputType' => 'png', 'outputBase64' => true, 'eccLevel' => EccLevel::M, 'scale' => $scale, 'quietzoneSize' => 1]);
        return (new QRCode($options))->render($payload);
    }

    /** Code 128 barcode as SVG markup. */
    public function barcodeSvg(string $payload, float $width = 1.4, int $height = 36): string
    {
        $g = new BarcodeGeneratorSVG();
        return $g->getBarcode($payload, $g::TYPE_CODE_128, $width, $height);
    }

    public function barcodePngDataUri(string $payload, int $width = 2, int $height = 40): string
    {
        $g = new BarcodeGeneratorPNG();
        return 'data:image/png;base64,' . base64_encode($g->getBarcode($payload, $g::TYPE_CODE_128, $width, $height));
    }

    /**
     * Identify a scanned or typed code.
     * @return array{type: 'product'|'batch'|'unknown', product_id?: int, batch_id?: int, matched_by?: string, code: string}
     */
    public function resolve(string $raw): array
    {
        $code = trim(preg_replace('/[\x00-\x1F\x7F]/', '', $raw) ?? '');
        $code = mb_substr($code, 0, 120);
        $upper = strtoupper($code);
        if ($code === '') {
            return ['type' => 'unknown', 'code' => $code];
        }
        if (str_starts_with($upper, self::BATCH_PREFIX)) {
            $id = $this->db->fetchValue('SELECT id FROM product_batches WHERE batch_code = :c', ['c' => substr($upper, 4)]);
            return $id ? ['type' => 'batch', 'batch_id' => (int) $id, 'matched_by' => 'MediStock batch label', 'code' => $code] : ['type' => 'unknown', 'code' => $code];
        }
        if (str_starts_with($upper, self::PRODUCT_PREFIX)) {
            $id = $this->db->fetchValue('SELECT id FROM products WHERE product_code = :c', ['c' => substr($upper, 4)]);
            return $id ? ['type' => 'product', 'product_id' => (int) $id, 'matched_by' => 'MediStock product label', 'code' => $code] : ['type' => 'unknown', 'code' => $code];
        }
        if ($id = $this->db->fetchValue('SELECT id FROM product_batches WHERE batch_code = :c', ['c' => $upper])) {
            return ['type' => 'batch', 'batch_id' => (int) $id, 'matched_by' => 'batch code', 'code' => $code];
        }
        if ($id = $this->db->fetchValue('SELECT id FROM products WHERE product_code = :c', ['c' => $upper])) {
            return ['type' => 'product', 'product_id' => (int) $id, 'matched_by' => 'product code', 'code' => $code];
        }
        if ($row = $this->db->fetchOne('SELECT pu.product_id, u.name AS unit FROM product_units pu JOIN units_of_measure u ON u.id = pu.unit_id WHERE pu.gtin_barcode = :c', ['c' => $code])) {
            return ['type' => 'product', 'product_id' => (int) $row['product_id'], 'matched_by' => 'manufacturer barcode (' . $row['unit'] . ')', 'code' => $code];
        }
        return ['type' => 'unknown', 'code' => $code];
    }
}
