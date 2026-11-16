<div class="page-header">
    <div>
        <nav aria-label="breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item">Inventory</li><li class="breadcrumb-item active" aria-current="page">Scan</li></ol></nav>
        <h1>Scan</h1>
        <div class="subtitle">Scan a MediStock product or batch label, or a manufacturer barcode. Works with the device camera, a USB barcode scanner, or typed codes.</div>
    </div>
</div>
<div class="row g-3">
    <div class="col-lg-5">
        <div class="card"><div class="card-body" id="scan-mount"></div></div>
        <p class="small text-muted mt-2"><i class="bi bi-info-circle me-1" aria-hidden="true"></i>Product labels start with <code>MSP:</code>, batch labels with <code>MSB:</code>. The camera needs HTTPS when used from another device on the network.</p>
    </div>
    <div class="col-lg-7">
        <div id="scan-result">
            <div class="card"><?= partial('partials/empty', ['icon' => 'bi-upc-scan', 'title' => 'Nothing scanned yet', 'text' => 'Results appear here: product, batch, expiry warnings and where the stock is.']) ?></div>
        </div>
    </div>
</div>
<script type="application/json" id="scan-config"><?= json_encode([
    'canIssue' => can('stock.issue'), 'canConsume' => can('consumption.record'), 'canReceive' => can('goods_receipts.direct'),
    'canPrint' => can('codes.print'),
    'urls' => ['issue' => route('inventory.issues.create'), 'consume' => route('consumption.create'), 'receive' => route('inventory.receipts.create'),
               'labels' => route('codes.labels'), 'forecast' => url('/intelligence/stockout/')],
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
