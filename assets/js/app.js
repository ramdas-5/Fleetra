/* ==================================================================
   Fleetra — Smart Transport Management System
   assets/js/app.js  |  Global interface behaviour
   ------------------------------------------------------------------
   Progressive enhancement only. All business rules are enforced by PHP
   on the server; nothing here is a security boundary.
   ================================================================== */

(function () {
    'use strict';

    var STORAGE_KEY = 'fleetra.sidebar.collapsed';

    /* --------------------------------------------------------------
       Sidebar: desktop collapse + mobile drawer
       -------------------------------------------------------------- */
    function initSidebar() {
        var body = document.body;
        var toggle = document.getElementById('sidebarToggle');
        var collapse = document.getElementById('sidebarCollapse');
        var backdrop = document.getElementById('sidebarBackdrop');
        var sidebar = document.getElementById('appSidebar');

        if (!sidebar) {
            return;
        }

        var isMobile = function () {
            return window.matchMedia('(max-width: 1024px)').matches;
        };

        // Restore the desktop collapse preference.
        if (!isMobile() && window.localStorage.getItem(STORAGE_KEY) === '1') {
            body.classList.add('sidebar-collapsed');
        }

        function openDrawer() {
            body.classList.add('sidebar-open');
            if (backdrop) {
                backdrop.hidden = false;
            }
            if (toggle) {
                toggle.setAttribute('aria-expanded', 'true');
            }
        }

        function closeDrawer() {
            body.classList.remove('sidebar-open');
            if (backdrop) {
                backdrop.hidden = true;
            }
            if (toggle) {
                toggle.setAttribute('aria-expanded', 'false');
            }
        }

        if (toggle) {
            toggle.addEventListener('click', function () {
                if (isMobile()) {
                    body.classList.contains('sidebar-open') ? closeDrawer() : openDrawer();
                    return;
                }

                body.classList.toggle('sidebar-collapsed');
                window.localStorage.setItem(
                    STORAGE_KEY,
                    body.classList.contains('sidebar-collapsed') ? '1' : '0'
                );
            });
        }

        if (collapse) {
            collapse.addEventListener('click', function () {
                body.classList.add('sidebar-collapsed');
                window.localStorage.setItem(STORAGE_KEY, '1');
            });
        }

        if (backdrop) {
            backdrop.addEventListener('click', closeDrawer);
        }

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                closeDrawer();
            }
        });

        window.addEventListener('resize', function () {
            if (!isMobile()) {
                closeDrawer();
                body.classList.toggle('sidebar-collapsed', window.localStorage.getItem(STORAGE_KEY) === '1');
            }
        });
    }

    /* --------------------------------------------------------------
       Flash alerts: auto dismiss after a few seconds
       -------------------------------------------------------------- */
    function initAlerts() {
        var alerts = document.querySelectorAll('.app-alert');

        Array.prototype.forEach.call(alerts, function (alert) {
            window.setTimeout(function () {
                if (typeof window.bootstrap === 'undefined') {
                    return;
                }

                var instance = window.bootstrap.Alert.getOrCreateInstance(alert);
                instance.close();
            }, 7000);
        });
    }

    /* --------------------------------------------------------------
       Toasts — used by AJAX actions instead of browser alert()
       -------------------------------------------------------------- */
    var TOAST_ICONS = {
        success: 'bi-check-circle',
        danger: 'bi-exclamation-octagon',
        warning: 'bi-exclamation-triangle',
        info: 'bi-info-circle'
    };

    function toast(message, type) {
        var container = document.getElementById('toastContainer');

        if (!container) {
            return;
        }

        type = TOAST_ICONS[type] ? type : 'info';

        var element = document.createElement('div');
        element.className = 'toast toast--' + type;
        element.setAttribute('role', 'status');
        element.setAttribute('aria-live', 'polite');
        element.innerHTML =
            '<div class="toast__body">' +
            '<i class="bi ' + TOAST_ICONS[type] + '" aria-hidden="true"></i>' +
            '<span></span>' +
            '</div>';

        element.querySelector('span').textContent = message;
        container.appendChild(element);

        if (typeof window.bootstrap === 'undefined') {
            window.setTimeout(function () { element.remove(); }, 5000);
            return;
        }

        var instance = window.bootstrap.Toast.getOrCreateInstance(element, { delay: 4500 });
        element.addEventListener('hidden.bs.toast', function () { element.remove(); });
        instance.show();
    }

    /* --------------------------------------------------------------
       Confirmation dialog for destructive actions

       Forms opt in with:
         data-confirm="Delete bus FLT-105?"
         data-confirm-title="Delete bus?"
         data-confirm-button="Delete bus"
         data-confirm-variant="primary"   (defaults to danger styling)

       Falls back to window.confirm() only when Bootstrap is unavailable.
       -------------------------------------------------------------- */
    function resubmit(form) {
        form.dataset.confirmed = '1';

        if (typeof form.requestSubmit === 'function') {
            form.requestSubmit();
        } else {
            form.submit();
        }
    }

    function initConfirmForms() {
        var modalElement = document.getElementById('confirmModal');
        var pendingForm = null;

        document.addEventListener('submit', function (event) {
            var form = event.target;

            if (!(form instanceof HTMLFormElement) || !form.dataset.confirm) {
                return;
            }

            if (form.dataset.confirmed === '1') {
                return;
            }

            event.preventDefault();

            // Native fallback when Bootstrap has not loaded.
            if (!modalElement || typeof window.bootstrap === 'undefined') {
                if (window.confirm(form.dataset.confirm)) {
                    resubmit(form);
                }
                return;
            }

            pendingForm = form;

            document.getElementById('confirmModalTitle').textContent =
                form.dataset.confirmTitle || 'Are you sure?';
            document.getElementById('confirmModalBody').textContent =
                form.dataset.confirm || 'This action cannot be undone.';

            var confirmButton = document.getElementById('confirmModalConfirm');
            confirmButton.textContent = form.dataset.confirmButton || 'Confirm';
            confirmButton.className = 'btn ' +
                (form.dataset.confirmVariant === 'primary' ? 'btn-primary' : 'btn-danger');

            window.bootstrap.Modal.getOrCreateInstance(modalElement).show();
        });

        if (modalElement) {
            document.getElementById('confirmModalConfirm').addEventListener('click', function () {
                if (!pendingForm) {
                    return;
                }

                window.bootstrap.Modal.getOrCreateInstance(modalElement).hide();

                var form = pendingForm;
                pendingForm = null;
                resubmit(form);
            });
        }
    }

    /* --------------------------------------------------------------
       Loading feedback: disable submit buttons on submit
       -------------------------------------------------------------- */
    function initSubmitStates() {
        document.addEventListener('submit', function (event) {
            var form = event.target;

            if (!(form instanceof HTMLFormElement)) {
                return;
            }

            // A confirmation dialog is open — do not show a loading state yet.
            if (event.defaultPrevented) {
                return;
            }

            var button = form.querySelector('[type="submit"]');
            if (!button || button.disabled) {
                return;
            }

            window.setTimeout(function () {
                button.disabled = true;
                button.classList.add('is-loading');

                var label = button.getAttribute('data-loading-text') || 'Working…';
                if (button.dataset.originalHtml === undefined) {
                    button.dataset.originalHtml = button.innerHTML;
                }
                button.innerHTML = '<span class="spinner-fl"></span> ' + label;
            }, 0);
        });
    }

    /* --------------------------------------------------------------
       Password visibility toggle
       Markup: <button data-toggle-password="passwordFieldId">
       -------------------------------------------------------------- */
    function initPasswordToggles() {
        document.addEventListener('click', function (event) {
            var button = event.target.closest('[data-toggle-password]');

            if (!button) {
                return;
            }

            event.preventDefault();

            var input = document.getElementById(button.getAttribute('data-toggle-password'));

            if (!input) {
                return;
            }

            var revealing = input.type === 'password';
            input.type = revealing ? 'text' : 'password';
            button.setAttribute('aria-label', revealing ? 'Hide password' : 'Show password');

            var icon = button.querySelector('i');
            if (icon) {
                icon.className = 'bi ' + (revealing ? 'bi-eye-slash' : 'bi-eye');
            }
        });
    }

    /* --------------------------------------------------------------
       Demo credential fill (login screen, local builds only)
       Markup: <button data-demo-fill="email" data-demo-password="...">
       -------------------------------------------------------------- */
    function initDemoFills() {
        document.addEventListener('click', function (event) {
            var button = event.target.closest('[data-demo-fill]');

            if (!button) {
                return;
            }

            event.preventDefault();

            var email = document.getElementById('email');
            var password = document.getElementById('password');

            if (email) {
                email.value = button.getAttribute('data-demo-fill') || '';
            }
            if (password) {
                password.value = button.getAttribute('data-demo-password') || '';
            }

            if (email) {
                email.focus();
            }
            if (typeof toast === 'function') {
                toast('Demo credentials filled in. Press Sign in to continue.', 'info');
            }
        });
    }

    /* --------------------------------------------------------------
       Bootstrap tooltips (opt in with data-bs-toggle="tooltip")
       -------------------------------------------------------------- */
    function initTooltips() {
        if (typeof window.bootstrap === 'undefined') {
            return;
        }

        var triggers = document.querySelectorAll('[data-bs-toggle="tooltip"]');
        Array.prototype.forEach.call(triggers, function (element) {
            window.bootstrap.Tooltip.getOrCreateInstance(element);
        });
    }

    /* --------------------------------------------------------------
       AJAX helpers — every endpoint still validates the session,
       permission and CSRF token on the server.
       -------------------------------------------------------------- */
    function csrfToken() {
        var meta = document.querySelector('meta[name="csrf-token"]');

        return meta ? meta.getAttribute('content') : '';
    }

    /**
     * POST to a Fleetra endpoint and parse the JSON response.
     * @param {string} endpoint
     * @param {Object} payload
     * @returns {Promise<Object>}
     */
    function post(endpoint, payload) {
        var body = new FormData();

        Object.keys(payload || {}).forEach(function (key) {
            body.append(key, payload[key]);
        });

        body.append('csrf_token', csrfToken());

        return fetch(endpoint, {
            method: 'POST',
            body: body,
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function (response) {
            return response.json().catch(function () {
                return { success: false, message: 'Unexpected server response.' };
            });
        });
    }

    /**
     * GET JSON from a Fleetra endpoint.
     * @param {string} endpoint
     * @returns {Promise<Object>}
     */
    function get(endpoint) {
        return fetch(endpoint, {
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function (response) {
            return response.json().catch(function () {
                return { success: false, message: 'Unexpected server response.' };
            });
        });
    }

    /**
     * Schedule planner: fill the arrival time in from the route's typical
     * duration. Only writes while the operator has not typed their own
     * arrival time, so a manual value is never overwritten.
     */
    function initSchedulePlanner() {
        var planner = document.querySelector('[data-schedule-planner]');

        if (!planner) {
            return;
        }

        var routeSelect = planner.querySelector('[data-route-select]');
        var departure = planner.querySelector('[data-departure-input]');
        var arrival = planner.querySelector('[data-arrival-input]');

        if (!routeSelect || !departure || !arrival) {
            return;
        }

        var durations = {};

        Array.prototype.forEach.call(routeSelect.options, function (option) {
            var minutes = parseInt(option.getAttribute('data-duration'), 10);

            if (!isNaN(minutes) && minutes > 0) {
                durations[option.value] = minutes;
            }
        });

        function suggest() {
            var minutes = durations[routeSelect.value];
            var parts = (departure.value || '').split(':');

            if (!minutes || parts.length < 2 || arrival.dataset.touched === '1') {
                return;
            }

            var total = (parseInt(parts[0], 10) * 60) + parseInt(parts[1], 10) + minutes;
            var hours = Math.floor((total % 1440) / 60);
            var mins = total % 60;

            arrival.value = ('0' + hours).slice(-2) + ':' + ('0' + mins).slice(-2);
        }

        routeSelect.addEventListener('change', suggest);
        departure.addEventListener('change', suggest);
        arrival.addEventListener('input', function () {
            arrival.dataset.touched = '1';
        });

        suggest();
    }

    /**
     * Seat picker on the booking page.
     *
     * Enforces the number of seats the passenger asked for, keeps the
     * running total in step and re-loads the page when the boarding or
     * destination stop changes, because the fare is priced on the server.
     * Seats already ticked are carried across that reload.
     */
    function initSeatPicker() {
        var picker = document.querySelector('[data-seat-picker]');

        if (!picker) {
            return;
        }

        var maxSeats = parseInt(picker.getAttribute('data-max-seats'), 10) || 1;
        var currency = picker.getAttribute('data-currency') || '';
        var boxes = Array.prototype.slice.call(picker.querySelectorAll('.seat input[type="checkbox"]'));
        var countEl = document.querySelector('[data-seat-count]');
        var totalEl = document.querySelector('[data-seat-total]');
        var listEl = document.querySelector('[data-seat-list]');

        function selected() {
            return boxes.filter(function (box) {
                return box.checked && box.dataset.taken !== '1';
            });
        }

        function formatAmount(value) {
            return currency + value.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        }

        function refresh() {
            var chosen = selected();

            boxes.forEach(function (box) {
                var seat = box.closest('.seat');

                if (seat) {
                    seat.classList.toggle('seat--selected', box.checked);
                }

                if (box.dataset.taken !== '1') {
                    box.disabled = !box.checked && chosen.length >= maxSeats;
                }
            });

            var total = chosen.reduce(function (sum, box) {
                return sum + (parseFloat(box.dataset.fare) || 0);
            }, 0);

            if (countEl) {
                countEl.textContent = String(chosen.length);
            }

            if (totalEl) {
                totalEl.textContent = formatAmount(total);
            }

            if (listEl) {
                listEl.textContent = chosen.length === 0
                    ? 'No seats selected yet.'
                    : 'Seats: ' + chosen.map(function (box) { return box.dataset.seat; }).join(', ');
            }
        }

        boxes.forEach(function (box) {
            box.addEventListener('change', refresh);
        });

        Array.prototype.forEach.call(
            picker.querySelectorAll('#boarding_stop_id, #destination_stop_id'),
            function (select) {
                select.addEventListener('change', function () {
                    var url = new URL(window.location.href);
                    var boarding = picker.querySelector('#boarding_stop_id');
                    var destination = picker.querySelector('#destination_stop_id');

                    if (boarding) {
                        url.searchParams.set('boarding_stop_id', boarding.value);
                    }
                    if (destination) {
                        url.searchParams.set('destination_stop_id', destination.value);
                    }

                    url.searchParams.delete('from');
                    url.searchParams.delete('to');
                    url.searchParams.set(
                        'seats',
                        selected().map(function (box) { return box.dataset.seat; }).join(',')
                    );

                    window.location.href = url.toString();
                });
            }
        );

        refresh();
    }

    /** Buttons marked with data-print open the browser's print dialog. */
    function initPrintButtons() {
        Array.prototype.forEach.call(document.querySelectorAll('[data-print]'), function (button) {
            button.addEventListener('click', function () {
                window.print();
            });
        });
    }

    /* --------------------------------------------------------------
       Bootstrap
       -------------------------------------------------------------- */
    document.addEventListener('DOMContentLoaded', function () {
        initSidebar();
        initAlerts();
        initConfirmForms();
        initSubmitStates();
        initPasswordToggles();
        initDemoFills();
        initTooltips();
        initSchedulePlanner();
        initSeatPicker();
        initPrintButtons();
    });

    window.Fleetra = {
        toast: toast,
        post: post,
        get: get,
        csrfToken: csrfToken
    };
})();
