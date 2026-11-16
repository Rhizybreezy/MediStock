/* User form: highlight that department-level roles need at least one department. */
(function () {
    'use strict';
    document.addEventListener('DOMContentLoaded', function () {
        var form = document.getElementById('user-form');
        if (!form) { return; }
        var help = document.getElementById('dept-help');
        var update = function () {
            var needsDept = Array.prototype.some.call(form.querySelectorAll('.role-check'), function (c) {
                return c.checked && c.dataset.scope === 'department';
            });
            var hasDept = form.querySelectorAll('.dept-check:checked').length > 0;
            help.classList.toggle('text-danger', needsDept && !hasDept);
        };
        form.addEventListener('change', update);
        update();
        form.addEventListener('submit', function (e) {
            if (!form.querySelector('.role-check:checked')) {
                e.preventDefault();
                e.stopImmediatePropagation();
                MS.toast('Select at least one role.', 'warning');
            }
        }, true);
    });
})();
