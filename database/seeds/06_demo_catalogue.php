<?php
/**
 * OPTIONAL sample catalogue (only with: php bin/console db:seed --demo-data).
 * Registers common hospital medicines and supplies with pack sizes and stock
 * levels so the inventory screens can be tried out. No stock is created;
 * receive stock through Inventory → Goods receipts.
 */
use App\Core\Database;
use App\Services\Support\DocumentNumberService;

return function (Database $db, array $ctx): void {
    if (empty($ctx['demo_data'])) {
        return;
    }
    $numbers = new DocumentNumberService($db);
    $cat = fn (string $c) => (int) $db->fetchValue('SELECT id FROM inventory_categories WHERE code = :c', ['c' => $c]);
    $unit = fn (string $c) => (int) $db->fetchValue('SELECT id FROM units_of_measure WHERE code = :c', ['c' => $c]);

    // name, generic, cat, base, form, strength, storage, critical, controlled, min, reorder, max, [pack unit, factor]...
    $items = [
        ['Paracetamol 500 mg tablets', 'Paracetamol', 'MED', 'TAB', 'Tablet', '500 mg', 'room_temp', 0, 0, 2000, 5000, 30000, [['BOX', 100], ['CTN', 5000]]],
        ['Amoxicillin 500 mg capsules', 'Amoxicillin', 'MED', 'CAP', 'Capsule', '500 mg', 'room_temp', 0, 0, 1000, 3000, 20000, [['BOX', 100]]],
        ['Ceftriaxone 1 g injection', 'Ceftriaxone', 'MED', 'VIAL', 'Injection', '1 g', 'room_temp', 1, 0, 100, 300, 2000, [['BOX', 10]]],
        ['Metformin 500 mg tablets', 'Metformin', 'MED', 'TAB', 'Tablet', '500 mg', 'room_temp', 0, 0, 1000, 3000, 20000, [['BOX', 100]]],
        ['Amlodipine 5 mg tablets', 'Amlodipine', 'MED', 'TAB', 'Tablet', '5 mg', 'room_temp', 0, 0, 500, 1500, 10000, [['BOX', 100]]],
        ['Oral rehydration salts', 'ORS', 'MED', 'SACHET', 'Powder', '20.5 g', 'room_temp', 1, 0, 200, 600, 5000, [['BOX', 50]]],
        ['Oxytocin 10 IU/ml injection', 'Oxytocin', 'MED', 'AMP', 'Injection', '10 IU', 'refrigerated', 1, 0, 100, 300, 2000, [['BOX', 10]]],
        ['Insulin (soluble) 100 IU/ml', 'Insulin human', 'MED', 'VIAL', 'Injection', '100 IU/ml', 'refrigerated', 1, 0, 30, 80, 500, []],
        ['Morphine 10 mg/ml injection', 'Morphine sulfate', 'MED', 'AMP', 'Injection', '10 mg', 'controlled', 1, 1, 20, 50, 300, [['BOX', 10]]],
        ['Diazepam 10 mg/2 ml injection', 'Diazepam', 'MED', 'AMP', 'Injection', '10 mg', 'controlled', 1, 1, 20, 50, 300, [['BOX', 10]]],
        ['Adrenaline 1 mg/ml injection', 'Epinephrine', 'MED', 'AMP', 'Injection', '1 mg', 'room_temp', 1, 0, 50, 100, 600, [['BOX', 10]]],
        ['Sodium chloride 0.9% 500 ml', 'Normal saline', 'MED', 'BTL', 'Infusion', '0.9%', 'room_temp', 1, 0, 200, 500, 3000, [['CTN', 20]]],
        ['Ringer\'s lactate 500 ml', 'Compound sodium lactate', 'MED', 'BTL', 'Infusion', '500 ml', 'room_temp', 1, 0, 200, 500, 3000, [['CTN', 20]]],
        ['Artemether/Lumefantrine 20/120 mg', 'Artemether + Lumefantrine', 'MED', 'TAB', 'Tablet', '20/120 mg', 'room_temp', 1, 0, 480, 1440, 9600, [['BOX', 24]]],
        ['Ibuprofen 400 mg tablets', 'Ibuprofen', 'MED', 'TAB', 'Tablet', '400 mg', 'room_temp', 0, 0, 1000, 2000, 15000, [['BOX', 100]]],
        ['Examination gloves (medium)', 'Nitrile gloves', 'PPE', 'PCS', 'Consumable', 'Medium', 'room_temp', 0, 0, 2000, 5000, 40000, [['BOX', 100], ['CTN', 1000]]],
        ['Sterile surgical gloves 7.5', 'Latex surgical gloves', 'PPE', 'PAIR', 'Consumable', 'Size 7.5', 'room_temp', 0, 0, 200, 500, 4000, [['BOX', 50]]],
        ['Surgical face masks', '3-ply mask', 'PPE', 'PCS', 'Consumable', null, 'room_temp', 0, 0, 1000, 3000, 20000, [['BOX', 50]]],
        ['N95 respirators', 'N95 mask', 'PPE', 'PCS', 'Consumable', null, 'room_temp', 0, 0, 100, 300, 2000, [['BOX', 20]]],
        ['Disposable syringe 5 ml', 'Syringe', 'GMC', 'PCS', 'Device', '5 ml', 'room_temp', 0, 0, 1000, 3000, 20000, [['BOX', 100]]],
        ['IV cannula 20G', 'Cannula', 'GMC', 'PCS', 'Device', '20G', 'room_temp', 0, 0, 200, 500, 4000, [['BOX', 50]]],
        ['Gauze swabs 10x10 cm', 'Gauze', 'SUR', 'PKT', 'Consumable', '10x10 cm', 'room_temp', 0, 0, 200, 500, 4000, [['CTN', 100]]],
        ['Absorbable suture 2-0', 'Polyglactin 910', 'SUR', 'PCS', 'Consumable', '2-0', 'room_temp', 0, 0, 50, 150, 1000, [['BOX', 12]]],
        ['Surgical blade No. 22', 'Scalpel blade', 'SUR', 'PCS', 'Consumable', 'No. 22', 'room_temp', 0, 0, 100, 300, 2000, [['BOX', 100]]],
        ['Malaria rapid diagnostic test', 'mRDT Pf/Pan', 'LAB', 'KIT', 'Test kit', null, 'room_temp', 1, 0, 100, 300, 2000, [['BOX', 25]]],
        ['HIV 1/2 rapid test', 'HIV RDT', 'LAB', 'KIT', 'Test kit', null, 'cool', 0, 0, 50, 150, 1000, [['BOX', 20]]],
        ['EDTA blood collection tubes', 'EDTA tube', 'LAB', 'PCS', 'Consumable', '4 ml', 'room_temp', 0, 0, 500, 1000, 8000, [['BOX', 100]]],
        ['Alcohol hand rub 500 ml', 'Ethanol 70%', 'CLN', 'BTL', 'Liquid', '500 ml', 'room_temp', 0, 0, 50, 150, 1000, [['CTN', 24]]],
        ['Sodium hypochlorite 5 L', 'Chlorine solution', 'CLN', 'L', 'Liquid', '5%', 'room_temp', 0, 0, 20, 50, 400, []],
        ['Digital thermometer', 'Clinical thermometer', 'EQP', 'PCS', 'Device', null, 'room_temp', 0, 0, 10, 20, 100, []],
    ];

    $created = 0;
    foreach ($items as [$name, $generic, $c, $base, $form, $strength, $storage, $critical, $controlled, $min, $reorder, $max, $packs]) {
        if ($db->fetchValue('SELECT 1 FROM products WHERE name = :n', ['n' => $name])) {
            continue;
        }
        $id = $db->insert('products', [
            'product_code' => $numbers->next('P-' . $c, $c, false),
            'name' => $name, 'generic_name' => $generic, 'category_id' => $cat($c), 'base_unit_id' => $unit($base),
            'dosage_form' => $form, 'strength' => $strength, 'storage_condition' => $storage,
            'is_batch_tracked' => 1, 'is_expiry_tracked' => $c === 'EQP' ? 0 : 1,
            'is_critical' => $critical, 'is_controlled' => $controlled,
            'min_level' => $min, 'reorder_level' => $reorder, 'max_level' => $max, 'is_active' => 1,
        ]);
        foreach ($packs as [$pu, $factor]) {
            $db->insert('product_units', ['product_id' => $id, 'unit_id' => $unit($pu), 'factor_to_base' => $factor, 'is_purchase_unit' => 1, 'is_issue_unit' => 1]);
        }
        $created++;
    }
    $ctx['console']->line("  registered $created demo product(s)");
};
