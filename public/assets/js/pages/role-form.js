/* Role form: per-module "select all", live count and permission filter. */
(function () {
    'use strict';
    document.addEventListener('DOMContentLoaded', function () {
        var form = document.getElementById('role-form');
        if (!form) { return; }
        var count = document.getElementById('perm-count');

        var sync = function () {
            form.querySelectorAll('[data-module]').forEach(function (mod) {
                var boxes = mod.querySelectorAll('.perm-check');
                var checked = mod.querySelectorAll('.perm-check:checked').length;
                var toggle = mod.querySelector('.module-toggle');
                toggle.checked = checked === boxes.length && boxes.length > 0;
                toggle.indeterminate = checked > 0 && checked < boxes.length;
            });
            count.textContent = form.querySelectorAll('.perm-check:checked').length;
        };

        form.querySelectorAll('.module-toggle').forEach(function (toggle) {
            toggle.addEventListener('change', function () {
                toggle.closest('[data-module]').querySelectorAll('.perm-check').forEach(function (c) { c.checked = toggle.checked; });
                sync();
            });
        });
        form.addEventListener('change', function (e) { if (e.target.classList.contains('perm-check')) { sync(); } });

        var filter = document.getElementById('perm-filter');
        filter.addEventListener('input', function () {
            var q = filter.value.trim().toLowerCase();
            form.querySelectorAll('[data-module]').forEach(function (mod) {
                var visible = 0;
                mod.querySelectorAll('[data-perm-text]').forEach(function (item) {
                    var show = !q || item.dataset.permText.indexOf(q) !== -1;
                    item.hidden = !show;
                    visible += show ? 1 : 0;
                });
                mod.hidden = visible === 0;
            });
        });
        sync();
    });
})();
