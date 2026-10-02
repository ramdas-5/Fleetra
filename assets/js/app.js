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
       Location autocomplete

       Any text input wrapped in [data-location-autocomplete] becomes a
       combobox that suggests India-wide bus terminals, stands and stops
       from api/locations.php. Manual typing is ALWAYS allowed — the list
       is only a shortcut, never a requirement. No device location
       permission is ever requested automatically.

       Markup:
         <div data-location-autocomplete>
           <input type="text" id="from" name="from" autocomplete="off">
           <ul class="loc-suggest" role="listbox" hidden></ul>
         </div>
       -------------------------------------------------------------- */
    function initLocationAutocomplete() {
        var wrappers = document.querySelectorAll('[data-location-autocomplete]');

        Array.prototype.forEach.call(wrappers, function (wrapper) {
            var input = wrapper.querySelector('input');
            var list = wrapper.querySelector('.loc-suggest');
            var endpoint = wrapper.getAttribute('data-endpoint') || 'api/locations.php';

            if (!input || !list) {
                return;
            }

            var items = [];
            var active = -1;
            var timer = null;
            var controller = null;

            input.setAttribute('role', 'combobox');
            input.setAttribute('aria-autocomplete', 'list');
            input.setAttribute('aria-expanded', 'false');

            function close() {
                list.hidden = true;
                list.innerHTML = '';
                items = [];
                active = -1;
                input.setAttribute('aria-expanded', 'false');
            }

            function highlight(index) {
                active = index;
                Array.prototype.forEach.call(list.children, function (child, i) {
                    child.classList.toggle('is-active', i === index);
                    if (i === index) {
                        child.setAttribute('aria-selected', 'true');
                    } else {
                        child.removeAttribute('aria-selected');
                    }
                });
            }

            function choose(index) {
                var item = items[index];

                if (!item) {
                    return;
                }

                input.value = item.city;
                input.setAttribute('data-location-id', item.id);
                input.dispatchEvent(new Event('change', { bubbles: true }));
                close();
                input.focus();
            }

            function render(results) {
                items = results || [];
                list.innerHTML = '';

                if (items.length === 0) {
                    close();
                    return;
                }

                items.forEach(function (item, index) {
                    var li = document.createElement('li');
                    li.className = 'loc-suggest__item';
                    li.setAttribute('role', 'option');
                    li.setAttribute('data-index', String(index));

                    var main = document.createElement('span');
                    main.className = 'loc-suggest__main';
                    main.textContent = item.city + ' · ' + item.name;

                    var sub = document.createElement('span');
                    sub.className = 'loc-suggest__sub';
                    sub.textContent = item.state + ' · ' + (item.type_label || 'Location');

                    li.appendChild(main);
                    li.appendChild(sub);

                    li.addEventListener('mousedown', function (event) {
                        event.preventDefault();
                        choose(index);
                    });

                    list.appendChild(li);
                });

                list.hidden = false;
                input.setAttribute('aria-expanded', 'true');
                highlight(-1);
            }

            function fetchSuggestions(query) {
                var url = endpoint + '?q=' + encodeURIComponent(query) + '&limit=8';

                if (controller && typeof controller.abort === 'function') {
                    controller.abort();
                }
                controller = window.AbortController ? new AbortController() : null;

                fetch(url, {
                    credentials: 'same-origin',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    signal: controller ? controller.signal : undefined
                })
                    .then(function (response) { return response.json(); })
                    .then(function (payload) {
                        if (payload && payload.success && Array.isArray(payload.locations)) {
                            render(payload.locations);
                        }
                    })
                    .catch(function () { /* Offline or aborted — keep manual entry. */ });
            }

            input.addEventListener('input', function () {
                input.removeAttribute('data-location-id');

                var value = input.value.trim();

                window.clearTimeout(timer);

                if (value.length < 2) {
                    close();
                    return;
                }

                timer = window.setTimeout(function () {
                    fetchSuggestions(value);
                }, 200);
            });

            input.addEventListener('keydown', function (event) {
                if (list.hidden || items.length === 0) {
                    if (event.key === 'Escape') {
                        close();
                    }
                    return;
                }

                if (event.key === 'ArrowDown') {
                    event.preventDefault();
                    highlight((active + 1) % items.length);
                } else if (event.key === 'ArrowUp') {
                    event.preventDefault();
                    highlight((active - 1 + items.length) % items.length);
                } else if (event.key === 'Enter') {
                    if (active >= 0) {
                        event.preventDefault();
                        choose(active);
                    }
                } else if (event.key === 'Escape') {
                    close();
                }
            });

            input.addEventListener('blur', function () {
                window.setTimeout(close, 120);
            });
        });

        /* Optional "use my location" — user initiated, never automatic. */
        Array.prototype.forEach.call(document.querySelectorAll('[data-use-location]'), function (button) {
            button.addEventListener('click', function () {
                var targetId = button.getAttribute('data-target');
                var input = targetId ? document.getElementById(targetId) : null;
                var endpoint = button.getAttribute('data-endpoint') || 'api/locations.php';

                if (!input) {
                    return;
                }

                if (!navigator.geolocation) {
                    toast('This browser cannot share a location. Please type your city or stop.', 'warning');
                    return;
                }

                button.disabled = true;

                navigator.geolocation.getCurrentPosition(function (position) {
                    fetch(endpoint + '?lat=' + encodeURIComponent(position.coords.latitude) +
                          '&lng=' + encodeURIComponent(position.coords.longitude), {
                        credentials: 'same-origin',
                        headers: { 'X-Requested-With': 'XMLHttpRequest' }
                    })
                        .then(function (response) { return response.json(); })
                        .then(function (payload) {
                            button.disabled = false;

                            if (payload && payload.success && payload.location) {
                                input.value = payload.location.city;
                                input.setAttribute('data-location-id', payload.location.id);
                                input.dispatchEvent(new Event('change', { bubbles: true }));
                                toast('Nearest terminal: ' + payload.location.name + ' (' +
                                      payload.location.city + '). You can edit it.', 'success');
                            } else {
                                toast('No known terminal near you. Please type your location.', 'info');
                            }
                        })
                        .catch(function () {
                            button.disabled = false;
                            toast('Could not read your location. Please type it instead.', 'warning');
                        });
                }, function () {
                    button.disabled = false;
                    toast('Location permission denied. Type your city or stop instead.', 'info');
                }, { timeout: 8000, maximumAge: 300000 });
            });
        });
    }

    /* --------------------------------------------------------------
       Search panel: swap the From and To fields.
       Markup: <button data-swap-from="from" data-swap-to="to">
       -------------------------------------------------------------- */
    function initLocationSwap() {
        document.addEventListener('click', function (event) {
            var button = event.target.closest('[data-swap-from][data-swap-to]');

            if (!button) {
                return;
            }

            event.preventDefault();

            var fromInput = document.getElementById(button.getAttribute('data-swap-from'));
            var toInput = document.getElementById(button.getAttribute('data-swap-to'));

            if (!fromInput || !toInput) {
                return;
            }

            var tmp = fromInput.value;
            fromInput.value = toInput.value;
            toInput.value = tmp;

            fromInput.dispatchEvent(new Event('change', { bubbles: true }));
            toInput.dispatchEvent(new Event('change', { bubbles: true }));
            fromInput.focus();
        });
    }

    /* --------------------------------------------------------------
       Available buses: submit the terminal form as soon as a terminal is
       chosen from the suggestion list. Manual typing + Enter still works
       because the form is a normal GET form.
       Markup: <form id="terminalForm"> ... <input name="terminal"> ...
       -------------------------------------------------------------- */
    function initTerminalQuickSubmit() {
        var form = document.getElementById('terminalForm');

        if (!form) {
            return;
        }

        var input = form.querySelector('input[name="terminal"]');

        if (!input) {
            return;
        }

        input.addEventListener('change', function () {
            // Only auto-submit once a real value is present.
            if (input.value.trim().length >= 2) {
                form.submit();
            }
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
        initLocationAutocomplete();
        initLocationSwap();
        initTerminalQuickSubmit();
    });

    window.Fleetra = {
        toast: toast,
        post: post,
        get: get,
        csrfToken: csrfToken
    };
})();
