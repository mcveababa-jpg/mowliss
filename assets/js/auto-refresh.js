(function () {
    // Reloads the page automatically so admins/users never have to manually
    // refresh to see changes made elsewhere (a new message, an approval, an
    // updated device status, etc). Skips the reload while the tab is hidden
    // or while the user is actively editing a field, so an in-progress reply
    // or form never gets wiped out mid-edit.
    function snapshotOriginalValues() {
        var fields = document.querySelectorAll('textarea, input[type="text"]');
        for (var i = 0; i < fields.length; i++) {
            fields[i].dataset.mowlissOriginal = fields[i].value;
        }
    }

    function isEditingSomething() {
        var el = document.activeElement;
        if (el) {
            var tag = el.tagName;
            if (tag === 'TEXTAREA' || tag === 'INPUT' || tag === 'SELECT') return true;
        }

        // Also treat any field the user has changed from its original value as
        // "editing", even if it briefly lost focus (e.g. the moment between
        // typing a reply and clicking Send) - a reload right then would
        // otherwise wipe it. Fields pre-filled by the server and never
        // touched are excluded, so they don't block refresh forever.
        var fields = document.querySelectorAll('textarea, input[type="text"]');
        for (var i = 0; i < fields.length; i++) {
            if (fields[i].value !== (fields[i].dataset.mowlissOriginal || '')) return true;
        }

        return false;
    }

    window.mowlissAutoRefresh = function (intervalMs) {
        var ms = intervalMs || 20000;
        snapshotOriginalValues();
        setInterval(function () {
            if (document.visibilityState === 'visible' && !isEditingSomething()) {
                window.location.reload();
            }
        }, ms);
    };
})();
