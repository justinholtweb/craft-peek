/* Peek — CP Scripts */
(function () {
    'use strict';

    // Confirm actions (delete buttons, etc.)
    document.querySelectorAll('[data-confirm]').forEach(function (el) {
        el.addEventListener('click', function (e) {
            if (!confirm(el.dataset.confirm)) {
                e.preventDefault();
            }
        });
    });
})();
