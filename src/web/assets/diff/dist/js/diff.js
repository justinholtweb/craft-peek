/* Peek — Diff Scripts */
(function () {
    'use strict';

    // Tab switching
    document.querySelectorAll('.peek-tab').forEach(function (tab) {
        tab.addEventListener('click', function () {
            var targetId = 'tab-' + tab.dataset.tab;

            // Deactivate all tabs
            document.querySelectorAll('.peek-tab').forEach(function (t) {
                t.classList.remove('active');
            });

            // Hide all tab content
            document.querySelectorAll('.peek-tab-content').forEach(function (content) {
                content.style.display = 'none';
            });

            // Activate clicked tab
            tab.classList.add('active');

            // Show target content
            var target = document.getElementById(targetId);
            if (target) {
                target.style.display = '';
            }
        });
    });

    // Synchronized iframe scrolling
    var canonical = document.getElementById('peek-iframe-canonical');
    var draft = document.getElementById('peek-iframe-draft');

    if (canonical && draft) {
        var syncing = false;

        function syncScroll(source, target) {
            if (syncing) return;
            syncing = true;

            try {
                var sourceDoc = source.contentDocument || source.contentWindow.document;
                var targetDoc = target.contentDocument || target.contentWindow.document;

                targetDoc.documentElement.scrollTop = sourceDoc.documentElement.scrollTop;
                targetDoc.documentElement.scrollLeft = sourceDoc.documentElement.scrollLeft;
            } catch (e) {
                // Cross-origin — can't sync
            }

            syncing = false;
        }

        canonical.addEventListener('load', function () {
            try {
                canonical.contentWindow.addEventListener('scroll', function () {
                    syncScroll(canonical, draft);
                });
            } catch (e) {}
        });

        draft.addEventListener('load', function () {
            try {
                draft.contentWindow.addEventListener('scroll', function () {
                    syncScroll(draft, canonical);
                });
            } catch (e) {}
        });
    }
})();
