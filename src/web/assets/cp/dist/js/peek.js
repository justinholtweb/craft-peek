/* Peek — CP Scripts */
(function () {
    'use strict';

    // Confirm actions. Craft confirms `.formsubmit` buttons itself, so they are left to it.
    document.querySelectorAll('[data-confirm]:not(.formsubmit)').forEach(function (el) {
        el.addEventListener('click', function (e) {
            if (!confirm(el.dataset.confirm)) {
                e.preventDefault();
            }
        });
    });
})();
