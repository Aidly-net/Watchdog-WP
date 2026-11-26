(function () {
    'use strict';

    function initializeNotificationSettings() {
        var method = document.getElementById('website_watchdog_notification_method');
        if (!method) {
            return;
        }

        var emailRow = document.querySelector('.watchdog-email-only');
        var cloudRows = document.querySelectorAll('.watchdog-cloud-only');

        function setRow(row, visible) {
            if (!row) {
                return;
            }
            row.hidden = !visible;
            row.style.display = visible ? '' : 'none';
            row.querySelectorAll('input, select, textarea').forEach(function (field) {
                field.disabled = !visible;
                if (field.type === 'email') {
                    field.required = visible;
                }
            });
        }

        function updateFields() {
            var useEmail = method.value === 'email';
            setRow(emailRow, useEmail);
            cloudRows.forEach(function (row) {
                setRow(row, !useEmail);
            });
        }

        method.addEventListener('change', updateFields);
        updateFields();
    }

    document.addEventListener('DOMContentLoaded', initializeNotificationSettings);
})();
