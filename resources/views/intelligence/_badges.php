<?php
/** Shared labels for forecast urgency and confidence. */
$urgencyBadge = function (string $u): string {
    [$label, $tone] = [
        'critical' => ['Critical', 'danger'], 'high' => ['Reorder now', 'warning'], 'medium' => ['Reorder soon', 'info'],
        'ok' => ['OK', 'success'], 'insufficient' => ['Not enough data', 'secondary'], 'no_demand' => ['No recent use', 'secondary'],
    ][$u] ?? [label($u), 'secondary'];
    return '<span class="badge text-bg-' . $tone . ' badge-status">' . e($label) . '</span>';
};
$confidenceText = ['high' => 'High', 'medium' => 'Medium', 'low' => 'Low', 'insufficient' => '—', 'no_demand' => '—'];
