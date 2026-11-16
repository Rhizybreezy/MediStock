<style>
    .label-toolbar { position: sticky; top: 0; z-index: 10; background: var(--ms-surface); border-bottom: 1px solid var(--ms-border); padding: .6rem 1rem; display: flex; gap: .5rem; align-items: center; flex-wrap: wrap; }
    .label-sheet { display: grid; grid-template-columns: repeat(3, 63.5mm); grid-auto-rows: 33.9mm; gap: 0 2.5mm; padding: 10mm 7mm; width: 210mm; margin: 1rem auto; background: #fff; color: #111; box-shadow: var(--ms-shadow-lg); }
    .label { box-sizing: border-box; padding: 2mm 2.5mm; display: grid; grid-template-columns: 22mm 1fr; gap: 2mm; overflow: hidden; border: 1px dashed #d0d7de; }
    .label .qr { width: 22mm; height: 22mm; }
    .label .t { font: 700 8pt/1.15 system-ui, sans-serif; max-height: 2.3em; overflow: hidden; }
    .label .l { font: 7pt/1.25 system-ui, sans-serif; color: #333; }
    .label .c { font: 700 7.5pt ui-monospace, monospace; margin-top: .5mm; }
    .label .bc { grid-column: 1 / -1; height: 6.5mm; width: 100%; object-fit: fill; }
    .label .kind { font: 600 6pt system-ui, sans-serif; text-transform: uppercase; letter-spacing: .05em; color: #0f766e; }
    @media print {
        @page { size: A4; margin: 0; }
        body { background: #fff !important; }
        .label-toolbar { display: none; }
        .label-sheet { margin: 0; box-shadow: none; }
        .label { border-color: transparent; }
    }
</style>
<div class="label-toolbar">
    <strong class="me-2">Labels</strong>
    <span class="small text-muted"><?= (int) $unique ?> item(s) × <?= (int) $copies ?> copies = <?= count($labels) ?> labels · A4, 3 × 8 (63.5 × 33.9 mm)</span>
    <form method="get" class="d-flex gap-2 align-items-center ms-auto">
        <?php foreach ($query as $k => $v): if ($k === 'copies') { continue; } ?><input type="hidden" name="<?= e($k) ?>" value="<?= e((string) $v) ?>"><?php endforeach; ?>
        <label for="copies" class="small">Copies</label>
        <input type="number" id="copies" name="copies" min="1" max="50" value="<?= (int) $copies ?>" class="form-control form-control-sm" style="width:5rem">
        <button class="btn btn-sm btn-outline-secondary" type="submit">Update</button>
    </form>
    <a class="btn btn-sm btn-outline-secondary" href="<?= e(route('codes.labels', [], $query + ['format' => 'pdf'])) ?>" target="_blank" rel="noopener"><i class="bi bi-filetype-pdf me-1" aria-hidden="true"></i>PDF</a>
    <button type="button" class="btn btn-sm btn-primary" data-print><i class="bi bi-printer me-1" aria-hidden="true"></i>Print</button>
</div>
<div class="label-sheet">
    <?php foreach ($labels as $l): ?>
    <div class="label">
        <img class="qr" src="<?= e($l['qr']) ?>" alt="QR <?= e($l['payload']) ?>">
        <div class="min-w-0">
            <div class="kind"><?= $l['kind'] === 'batch' ? 'Batch' : 'Product' ?></div>
            <div class="t"><?= e($l['title']) ?></div>
            <?php foreach ($l['lines'] as $line): if (trim($line) === '') { continue; } ?><div class="l"><?= e($line) ?></div><?php endforeach; ?>
            <div class="c"><?= e($l['code']) ?></div>
        </div>
        <img class="bc" src="<?= e($l['barcode']) ?>" alt="Barcode <?= e($l['payload']) ?>">
    </div>
    <?php endforeach; ?>
</div>
<script src="<?= e(asset('js/pages/labels.js')) ?>"></script>
