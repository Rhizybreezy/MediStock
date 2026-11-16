/* Location form: show temperature fields for cold-chain locations; fridges/freezers imply cold chain. */
(function () {
    'use strict';
    document.addEventListener('DOMContentLoaded', function () {
        var form = document.getElementById('location-form');
        if (!form) { return; }
        var cold = form.querySelector('#is_cold_chain');
        var type = form.querySelector('#type');
        var sync = function () {
            if (type.value === 'refrigerator' || type.value === 'freezer') { cold.checked = true; }
            form.querySelectorAll('[data-cold-field]').forEach(function (el) { el.hidden = !cold.checked; });
        };
        type.addEventListener('change', function () {
            if (type.value === 'refrigerator' && !form.querySelector('#min_temp_c').value) {
                form.querySelector('#min_temp_c').value = '2';
                form.querySelector('#max_temp_c').value = '8';
            }
            if (type.value === 'freezer' && !form.querySelector('#min_temp_c').value) {
                form.querySelector('#min_temp_c').value = '-25';
                form.querySelector('#max_temp_c').value = '-15';
            }
            sync();
        });
        cold.addEventListener('change', sync);
        sync();
    });
})();
