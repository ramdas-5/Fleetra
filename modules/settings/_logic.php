<?php
/**
 * Fleetra — Settings module logic
 * ------------------------------------------------------------------
 * modules/settings/_logic.php
 *
 * The settings table is a simple key/value store, so the shape of the
 * screen lives here: which groups exist, what each field is called, how it
 * is validated and what it is rendered as.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/permissions.php';

/**
 * Field definitions, grouped by section.
 *
 * type: text | email | tel | number | select | toggle
 *
 * @return array<string, array{label:string, description:string, icon:string, fields:array<string, array<string, mixed>>}>
 */
function settings_schema(): array
{
    return [
        'general' => [
            'label'       => 'Company',
            'description' => 'Identity and contact details shown on tickets, alerts and exports.',
            'icon'        => 'bi-building',
            'fields'      => [
                'company_name' => [
                    'label' => 'Company name',
                    'type'  => 'text',
                    'help'  => 'Printed on tickets and report exports.',
                    'rules' => ['required', 'max:120'],
                ],
                'support_email' => [
                    'label' => 'Support email',
                    'type'  => 'email',
                    'help'  => 'Shown to passengers who need help with a booking.',
                    'rules' => ['required', 'email', 'max:160'],
                ],
                'support_phone' => [
                    'label' => 'Support phone',
                    'type'  => 'tel',
                    'help'  => 'Fleetra support desk number.',
                    'rules' => ['required', 'phone'],
                ],
                'timezone' => [
                    'label' => 'Business timezone',
                    'type'  => 'select',
                    'help'  => 'All schedules, reports and logs are interpreted in this zone.',
                    'options' => [
                        'Asia/Kolkata'   => 'Asia/Kolkata (IST)',
                        'Asia/Dubai'     => 'Asia/Dubai',
                        'Asia/Singapore' => 'Asia/Singapore',
                        'Europe/London'  => 'Europe/London',
                        'UTC'            => 'UTC',
                    ],
                    'rules' => ['required'],
                ],
                'currency_symbol' => [
                    'label' => 'Currency symbol',
                    'type'  => 'text',
                    'help'  => 'Used for every fare, ticket and revenue figure.',
                    'rules' => ['required', 'max:3'],
                ],
            ],
        ],

        'booking' => [
            'label'       => 'Booking rules',
            'description' => 'How seats are laid out and how far ahead passengers may book.',
            'icon'        => 'bi-journal-check',
            'fields'      => [
                'default_seat_layout' => [
                    'label'   => 'Default seat layout',
                    'type'    => 'select',
                    'help'    => 'Seat map used when a bus does not define its own layout.',
                    'options' => [
                        '2x2' => '2 + 2 (four seats per row)',
                        '2x1' => '2 + 1 (three seats per row)',
                    ],
                    'rules' => ['required'],
                ],
                'booking_window_days' => [
                    'label' => 'Booking window (days)',
                    'type'  => 'number',
                    'help'  => 'How many days ahead a passenger can reserve a seat.',
                    'min'   => 1,
                    'max'   => 365,
                    'rules' => ['required', 'int', 'min:1', 'max:365'],
                ],
                'cancellation_hours' => [
                    'label' => 'Cancellation cut-off (hours before departure)',
                    'type'  => 'number',
                    'help'  => 'Minimum notice for a passenger to cancel their own booking.',
                    'min'   => 0,
                    'max'   => 168,
                    'rules' => ['required', 'int', 'min:0', 'max:168'],
                ],
            ],
        ],

        'maintenance' => [
            'label'       => 'Maintenance',
            'description' => 'How far ahead Fleetra warns about servicing.',
            'icon'        => 'bi-tools',
            'fields'      => [
                'maintenance_warning_days' => [
                    'label' => 'Service warning window (days)',
                    'type'  => 'number',
                    'help'  => 'Jobs falling due within this many days appear on the dashboard and diary.',
                    'min'   => 1,
                    'max'   => 120,
                    'rules' => ['required', 'int', 'min:1', 'max:120'],
                ],
            ],
        ],

        'notifications' => [
            'label'       => 'Notifications',
            'description' => 'Extra delivery channels for Fleetra messages.',
            'icon'        => 'bi-bell',
            'fields'      => [
                'sms_notifications' => [
                    'label' => 'Send SMS notifications',
                    'type'  => 'toggle',
                    'help'  => 'Requires an SMS gateway to be configured; SMS is not wired up on a local install.',
                    'rules' => ['bool'],
                ],
                'email_notifications' => [
                    'label' => 'Send email notifications',
                    'type'  => 'toggle',
                    'help'  => 'Requires SMTP to be configured; Fleetra does not send mail on a local install.',
                    'rules' => ['bool'],
                ],
            ],
        ],
    ];
}

/** All setting keys defined in the schema. */
function settings_keys(): array
{
    $keys = [];

    foreach (settings_schema() as $group) {
        foreach (array_keys($group['fields']) as $key) {
            $keys[] = $key;
        }
    }

    return $keys;
}

/**
 * Current values, keyed by setting key.
 *
 * @return array<string, string>
 */
function settings_values(): array
{
    $values = [];

    foreach (db_all('SELECT setting_key, setting_value FROM settings') as $row) {
        $values[(string) $row['setting_key']] = (string) ($row['setting_value'] ?? '');
    }

    return $values;
}

/**
 * Validate every submitted setting.
 *
 * @param array<string, mixed> $post
 * @return array{errors:array<string,string>, values:array<string,string>}
 */
function validate_settings(array $post): array
{
    $errors = [];
    $values = [];

    foreach (settings_schema() as $group) {
        foreach ($group['fields'] as $key => $field) {
            $type = (string) $field['type'];

            if ($type === 'toggle') {
                $values[$key] = isset($post[$key]) ? '1' : '0';
                continue;
            }

            $value = trim((string) ($post[$key] ?? ''));
            $rules = $field['rules'] ?? [];

            foreach ($rules as $rule) {
                if ($rule === 'required' && $value === '') {
                    $errors[$key] = $field['label'] . ' is required.';
                    break;
                }

                if ($value === '') {
                    continue;
                }

                if ($rule === 'email' && !is_valid_email($value)) {
                    $errors[$key] = 'Enter a valid email address.';
                    break;
                }

                if ($rule === 'phone' && !is_valid_phone($value)) {
                    $errors[$key] = 'Enter a valid phone number (10 to 15 digits).';
                    break;
                }

                if ($rule === 'int') {
                    if (!ctype_digit($value)) {
                        $errors[$key] = $field['label'] . ' must be a whole number.';
                        break;
                    }
                    if (isset($field['min']) && (int) $value < (int) $field['min']) {
                        $errors[$key] = $field['label'] . ' cannot be lower than ' . (int) $field['min'] . '.';
                        break;
                    }
                    if (isset($field['max']) && (int) $value > (int) $field['max']) {
                        $errors[$key] = $field['label'] . ' cannot be higher than ' . (int) $field['max'] . '.';
                        break;
                    }
                }

                if (str_starts_with($rule, 'max:')) {
                    $max = (int) substr($rule, 4);
                    if (mb_strlen($value) > $max) {
                        $errors[$key] = $field['label'] . ' cannot be longer than ' . $max . ' characters.';
                        break;
                    }
                }
            }

            // Select values must be one of the offered keys.
            if (!isset($errors[$key]) && $type === 'select' && !is_valid_option($field['options'] ?? [], $value)) {
                $errors[$key] = 'Choose a valid ' . strtolower((string) $field['label']) . '.';
            }

            $values[$key] = $value;
        }
    }

    return ['errors' => $errors, 'values' => $values];
}

/**
 * Persist validated settings, inserting any key that does not exist yet.
 *
 * @param array<string, string> $values
 * @return int Number of keys written.
 */
function save_settings(array $values): int
{
    $written = 0;

    foreach ($values as $key => $value) {
        $exists = db_one('SELECT id FROM settings WHERE setting_key = ? LIMIT 1', [$key]);

        if ($exists !== null) {
            db_update('settings', ['setting_value' => $value], ['setting_key' => $key]);
        } else {
            // Keep the key in a sensible group when it is added for the first time.
            $group = 'general';
            foreach (settings_schema() as $groupKey => $definition) {
                if (array_key_exists($key, $definition['fields'])) {
                    $group = $groupKey;
                    break;
                }
            }

            db_insert('settings', [
                'setting_key'   => $key,
                'setting_value' => $value,
                'setting_group' => $group,
            ]);
        }

        $written++;
    }

    return $written;
}

/** True when email delivery is possible (SMTP is not configured locally). */
function settings_mailer_available(): bool
{
    return function_exists('mail') && !in_array(APP_ENV, ['local', 'testing'], true);
}
