<?php $hospital = setting('hospital_name', 'Hospital'); $cur = setting('currency_code', 'USD'); ?>
<table class="header"><tr>
    <td style="width:60%">
        <div style="font-size:14pt;font-weight:bold"><?= e($hospital) ?></div>
        <div class="small muted"><?= e(setting('hospital_address', '')) ?><?= setting('hospital_phone') ? ' · ' . e(setting('hospital_phone')) : '' ?></div>
    </td>
    <td style="width:40%;text-align:right">
        <h1>PURCHASE ORDER</h1>
        <div style="font-size:12pt;font-weight:bold"><?= e($po['po_number']) ?></div>
        <div class="small">Order date: <?= e(format_date($po['order_date'] ?? $po['created_at'])) ?></div>
        <div class="small">Expected delivery: <?= e(format_date($po['expected_delivery_date'])) ?></div>
    </td>
</tr></table>
<br>
<table class="header"><tr>
    <td style="width:49%" class="box"><div class="small muted">SUPPLIER</div><strong><?= e($po['supplier_name']) ?></strong> (<?= e($po['supplier_code']) ?>)<br><?= e($po['supplier_address'] ?? '') ?><br><?= e(trim(($po['contact_person'] ?? '') . ' ' . ($po['supplier_phone'] ?? '') . ' ' . ($po['supplier_email'] ?? ''))) ?></td>
    <td style="width:2%"></td>
    <td style="width:49%" class="box"><div class="small muted">DELIVER TO</div><strong><?= e($hospital) ?></strong><br><?= e($po['delivery_location']) ?> (<?= e($po['delivery_department']) ?>)<br>Payment terms: <?= e($po['payment_terms'] ?? 'As agreed') ?></td>
</tr></table>
<h2>Items</h2>
<table>
    <thead><tr><th>#</th><th>Product</th><th class="num">Quantity</th><th>Unit</th><th class="num">Unit price (<?= e($cur) ?>)</th><th class="num">Total (<?= e($cur) ?>)</th></tr></thead>
    <tbody>
    <?php foreach ($items as $n => $i): ?>
        <tr><td><?= $n + 1 ?></td><td><?= e($i['product_name']) ?><br><span class="small muted"><?= e($i['product_code']) ?><?= (float) $i['unit_factor'] != 1.0 ? ' · 1 ' . e($i['order_unit']) . ' = ' . e(qty($i['unit_factor'])) . ' ' . e($i['unit_code']) : '' ?></span></td><td class="num"><?= e(qty($i['ordered_qty'])) ?></td><td><?= e($i['order_unit_name']) ?></td><td class="num"><?= e(number_format((float) $i['unit_price'], 2)) ?></td><td class="num"><?= e(number_format((float) $i['line_total'], 2)) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
</table>
<table class="totals" style="width:40%;margin-left:60%;margin-top:6pt">
    <tr><td>Subtotal</td><td class="num"><?= e(number_format((float) $po['subtotal'], 2)) ?></td></tr>
    <tr><td>Tax</td><td class="num"><?= e(number_format((float) $po['tax_amount'], 2)) ?></td></tr>
    <tr><td><strong>Total (<?= e($cur) ?>)</strong></td><td class="num"><strong><?= e(number_format((float) $po['total_amount'], 2)) ?></strong></td></tr>
</table>
<?php if ($po['notes']): ?><h2>Notes</h2><p class="small"><?= nl2br(e($po['notes'])) ?></p><?php endif; ?>
<p class="small muted">Please quote the PO number on all delivery notes and invoices. Deliveries must include batch/lot numbers and expiry dates; stock expiring soon may be rejected.</p>
<table class="sign"><tr>
    <td style="width:45%"><div class="line">Prepared by: <?= e($po['created_by_name']) ?></div></td><td style="width:10%"></td>
    <td style="width:45%"><div class="line">Approved by: <?= e($po['approved_by_name'] ?? '') ?> <?= $po['approved_at'] ? '(' . e(format_date($po['approved_at'])) . ')' : '' ?></div></td>
</tr></table>
