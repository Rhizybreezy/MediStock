<?php
/**
 * Seeds hospital departments and their storage locations, plus the single
 * system-wide Transit location and quarantine areas.
 */
use App\Core\Database;

return function (Database $db): void {
    $departments = [
        // code, name, type, is_central
        ['CS',   'Central Store',            'central_store',   1],
        ['PH',   'Pharmacy',                 'pharmacy',        0],
        ['ED',   'Emergency Department',     'clinical',        0],
        ['LAB',  'Laboratory',               'laboratory',      0],
        ['MAT',  'Maternity',                'clinical',        0],
        ['OT',   'Operating Theatre',        'theatre',         0],
        ['OPD',  'Outpatient Department',    'clinical',        0],
        ['IPD',  'Inpatient Wards',          'clinical',        0],
        ['MES',  'Medical Equipment Store',  'equipment_store', 0],
    ];
    foreach ($departments as [$code, $name, $type, $central]) {
        $db->execute(
            'INSERT IGNORE INTO departments (code, name, type, is_central_store) VALUES (:c, :n, :t, :cs)',
            ['c' => $code, 'n' => $name, 't' => $type, 'cs' => $central]
        );
    }

    $deptId = fn (string $code): int => (int) $db->fetchValue('SELECT id FROM departments WHERE code = :c', ['c' => $code]);

    $locations = [
        // dept, code, name, type, cold, min, max
        ['CS',  'CS-MAIN',   'Central Store - Main',        'store',        0, null, null],
        ['CS',  'CS-COLD',   'Central Store - Cold Room',   'refrigerator', 1, 2,    8],
        ['CS',  'CS-QUAR',   'Central Store - Quarantine',  'quarantine',   0, null, null],
        ['CS',  'TRANSIT',   'In Transit',                  'transit',      0, null, null],
        ['PH',  'PH-MAIN',   'Pharmacy - Dispensary',       'store',        0, null, null],
        ['PH',  'PH-FRIDGE', 'Pharmacy - Refrigerator',     'refrigerator', 1, 2,    8],
        ['PH',  'PH-CTRL',   'Pharmacy - Controlled Drugs', 'cabinet',      0, null, null],
        ['PH',  'PH-QUAR',   'Pharmacy - Quarantine',       'quarantine',   0, null, null],
        ['ED',  'ED-MAIN',   'Emergency - Store',           'store',        0, null, null],
        ['LAB', 'LAB-MAIN',  'Laboratory - Store',          'store',        0, null, null],
        ['LAB', 'LAB-FRIDGE','Laboratory - Reagent Fridge', 'refrigerator', 1, 2,    8],
        ['MAT', 'MAT-MAIN',  'Maternity - Store',           'store',        0, null, null],
        ['OT',  'OT-MAIN',   'Theatre - Store',             'store',        0, null, null],
        ['OPD', 'OPD-MAIN',  'Outpatient - Store',          'store',        0, null, null],
        ['IPD', 'IPD-MAIN',  'Inpatient Wards - Store',     'store',        0, null, null],
        ['MES', 'MES-MAIN',  'Equipment Store - Main',      'store',        0, null, null],
    ];
    foreach ($locations as [$dept, $code, $name, $type, $cold, $min, $max]) {
        $db->execute(
            'INSERT IGNORE INTO inventory_locations (department_id, code, name, type, is_cold_chain, min_temp_c, max_temp_c)
             VALUES (:d, :c, :n, :t, :cold, :min, :max)',
            ['d' => $deptId($dept), 'c' => $code, 'n' => $name, 't' => $type, 'cold' => $cold, 'min' => $min, 'max' => $max]
        );
    }
};
