<?php
/**
 * System settings editable by administrators (Admin → Settings).
 * Existing values are never overwritten by re-seeding.
 */
use App\Core\Database;

return function (Database $db): void {
    $settings = [
        // key, value, type, group, label, description, order
        ['hospital_name', 'General Hospital', 'string', 'general', 'Hospital name', 'Shown on reports and printed documents', 1],
        ['hospital_address', '', 'string', 'general', 'Hospital address', 'Printed on purchase orders and reports', 2],
        ['hospital_phone', '', 'string', 'general', 'Hospital phone', null, 3],
        ['currency_code', 'USD', 'string', 'general', 'Currency code', 'ISO currency code used for all money values (e.g. USD, TZS, KES, EUR)', 4],

        ['expiry_warning_days', '90', 'int', 'inventory', 'Expiry warning window (days)', 'Batches expiring within this many days are flagged "expiring soon"', 1],
        ['expiry_critical_days', '30', 'int', 'inventory', 'Expiry critical window (days)', 'Batches expiring within this many days are flagged critical', 2],
        ['safety_stock_days', '7', 'int', 'inventory', 'Safety stock (days of use)', 'Used when there is not enough history to calculate statistical safety stock', 3],
        ['review_period_days', '14', 'int', 'inventory', 'Review period (days)', 'How often stock is reviewed for reordering; used in reorder quantities', 4],
        ['default_lead_time_days', '14', 'int', 'inventory', 'Default supplier lead time (days)', 'Used when neither the supplier nor the product defines a lead time', 5],
        ['transit_alert_hours', '48', 'int', 'inventory', 'Unreceived transfer alert (hours)', 'Alert when dispatched stock is not confirmed received within this time', 6],
        ['internal_lead_time_days', '2', 'int', 'inventory', 'Internal supply time (days)', 'Days for the central store to supply a department; used in department forecasts', 9],
        ['redistribution_cover_days', '90', 'int', 'inventory', 'Surplus threshold (days of cover)', 'A department holding more than this many days of its own usage is considered to have surplus', 10],
        ['overreceipt_tolerance_pct', '0', 'decimal', 'inventory', 'Over-receipt tolerance (%)', 'Allowed quantity above the ordered amount when receiving goods', 7],
        ['allow_negative_adjustment_without_count', '1', 'bool', 'inventory', 'Allow adjustments outside stock counts', 'If off, quantity corrections must come from a stock count', 8],

        ['notify_low_stock', '1', 'bool', 'alerts', 'Low-stock alerts', null, 1],
        ['notify_expiry', '1', 'bool', 'alerts', 'Expiry alerts', null, 2],
        ['notify_delayed_delivery', '1', 'bool', 'alerts', 'Delayed delivery alerts', null, 3],
        ['notify_anomalies', '1', 'bool', 'alerts', 'Anomaly alerts', null, 4],
        ['anomaly_sigma', '3', 'decimal', 'alerts', 'Anomaly sensitivity (σ)', 'Daily consumption above mean + N standard deviations is flagged', 5],

        ['ai_enabled', '1', 'bool', 'ai', 'AI assistant enabled', 'Turns the Gemini inventory assistant on or off for everyone', 1],
        ['ai_daily_message_limit', '100', 'int', 'ai', 'AI messages per user per day', null, 2],
        ['ai_per_minute_limit', '10', 'int', 'ai', 'AI messages per user per minute', null, 3],
    ];
    foreach ($settings as [$key, $value, $type, $group, $label, $desc, $order]) {
        $db->execute(
            'INSERT INTO system_settings (setting_key, setting_value, value_type, setting_group, label, description, sort_order)
             VALUES (:k, :v, :t, :g, :l, :d, :o)
             ON DUPLICATE KEY UPDATE value_type = VALUES(value_type), setting_group = VALUES(setting_group),
                 label = VALUES(label), description = VALUES(description), sort_order = VALUES(sort_order)',
            ['k' => $key, 'v' => $value, 't' => $type, 'g' => $group, 'l' => $label, 'd' => $desc, 'o' => $order]
        );
    }
};
