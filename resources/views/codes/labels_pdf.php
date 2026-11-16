<style>
    @page { margin: 10mm 7mm; }
    table.sheet { width: 100%; border-collapse: collapse; table-layout: fixed; }
    table.sheet td { width: 33.33%; height: 33.9mm; padding: 1.5mm 2mm; vertical-align: top; border: 0; }
    .qr { width: 21mm; height: 21mm; float: left; margin-right: 2mm; }
    .kind { font-size: 6pt; color: #0f766e; text-transform: uppercase; font-weight: bold; }
    .t { font-size: 7.5pt; font-weight: bold; line-height: 1.1; }
    .l { font-size: 6.5pt; color: #333; }
    .c { font-size: 7pt; font-weight: bold; font-family: "DejaVu Sans Mono", monospace; }
    .bc { width: 58mm; height: 6mm; clear: both; display: block; margin-top: 1mm; }
</style>
<table class="sheet">
<?php foreach (array_chunk($labels, 3) as $row): ?>
    <tr>
    <?php foreach ($row as $l): ?>
        <td>
            <img class="qr" src="<?= e($l['qr']) ?>" alt="">
            <div class="kind"><?= $l['kind'] === 'batch' ? 'Batch' : 'Product' ?></div>
            <div class="t"><?= e(mb_strimwidth($l['title'], 0, 48, '…')) ?></div>
            <?php foreach ($l['lines'] as $line): if (trim($line) === '') { continue; } ?><div class="l"><?= e($line) ?></div><?php endforeach; ?>
            <div class="c"><?= e($l['code']) ?></div>
            <img class="bc" src="<?= e($l['barcode']) ?>" alt="">
        </td>
    <?php endforeach; ?>
    <?php for ($i = count($row); $i < 3; $i++): ?><td></td><?php endfor; ?>
    </tr>
<?php endforeach; ?>
</table>
