<?php
use App\Core\Database;

return function (Database $db): void {
    $categories = [
        // code, name, requires_expiry, description
        ['MED', 'Medicines',                         1, 'Pharmaceutical products and drugs'],
        ['SUR', 'Surgical Supplies',                 1, 'Sutures, blades, dressings and surgical consumables'],
        ['LAB', 'Laboratory Supplies',               1, 'Reagents, test kits and laboratory consumables'],
        ['PPE', 'Personal Protective Equipment',     1, 'Gloves, masks, gowns, face shields'],
        ['GMC', 'General Medical Consumables',       1, 'Syringes, cannulas, cotton, gauze and similar'],
        ['EQP', 'Medical Equipment',                 0, 'Devices and durable medical equipment'],
        ['CLN', 'Cleaning and Sanitation Supplies',  1, 'Disinfectants, detergents and sanitation items'],
        ['OTH', 'Other Hospital Supplies',           0, 'Stationery, linen and other supplies'],
    ];
    foreach ($categories as [$code, $name, $expiry, $desc]) {
        $db->execute(
            'INSERT IGNORE INTO inventory_categories (code, name, requires_expiry, description) VALUES (:c, :n, :e, :d)',
            ['c' => $code, 'n' => $name, 'e' => $expiry, 'd' => $desc]
        );
    }

    $units = [
        ['TAB', 'Tablets', 0], ['CAP', 'Capsules', 0], ['BTL', 'Bottles', 0], ['BOX', 'Boxes', 0],
        ['PCS', 'Pieces', 0], ['PKT', 'Packets', 0], ['L', 'Litres', 1], ['ML', 'Millilitres', 1],
        ['CTN', 'Cartons', 0], ['VIAL', 'Vials', 0], ['AMP', 'Ampoules', 0], ['PAIR', 'Pairs', 0],
        ['ROLL', 'Rolls', 0], ['KIT', 'Kits', 0], ['TUBE', 'Tubes', 0], ['SACHET', 'Sachets', 0],
        ['KG', 'Kilograms', 1], ['G', 'Grams', 1], ['SET', 'Sets', 0], ['UNIT', 'Units', 0],
    ];
    foreach ($units as [$code, $name, $dec]) {
        $db->execute(
            'INSERT IGNORE INTO units_of_measure (code, name, allows_decimal) VALUES (:c, :n, :d)',
            ['c' => $code, 'n' => $name, 'd' => $dec]
        );
    }
};
