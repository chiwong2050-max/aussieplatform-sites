<?php
/**
 * Field validator. Pure PHP, no WordPress dependency.
 */

if (!function_exists('apd_site_validate')) {
    /**
     * Validate data against a list of template fields.
     *
     * @param array $fields Field definitions from meta.json.
     * @param array $data   Submitted values.
     * @return array Map of field key => error message. Empty when valid.
     */
    function apd_site_validate(array $fields, array $data): array
    {
        $errors = array();
        foreach ($fields as $field) {
            if (!is_array($field) || !isset($field['key'])) {
                continue;
            }
            $key      = (string) $field['key'];
            $value    = array_key_exists($key, $data) ? $data[$key] : '';
            $required = !empty($field['required']);
            $max      = isset($field['max']) ? (int) $field['max'] : 0;

            if (is_array($value)) {
                if ($required && 0 === count($value)) {
                    $errors[$key] = 'This field is required.';
                }
                continue;
            }

            $text = trim((string) $value);
            if ($required && '' === $text) {
                $errors[$key] = 'This field is required.';
                continue;
            }

            if ('' !== $text && 'email' === (string) ($field['type'] ?? '')) {
                if (function_exists('apd_site_clean_email') && '' === apd_site_clean_email($text)) {
                    $errors[$key] = 'Please enter a valid email address.';
                }
            }

            if ('' !== $text && 'abn' === $key && function_exists('apd_site_abn_valid') && !apd_site_abn_valid($text)) {
                $errors[$key] = 'Please enter a valid 11-digit ABN.';
            }

            if ('' !== $text && 'phone' === $key && function_exists('apd_site_phone_valid') && !apd_site_phone_valid($text)) {
                $errors[$key] = 'Please enter a valid Australian phone number.';
            }

            if ($max > 0 && '' !== $text) {
                $len = function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);
                if ($len > $max) {
                    $errors[$key] = sprintf('Please use %d characters or fewer.', $max);
                }
            }
        }
        return $errors;
    }
}

if (!function_exists('apd_site_moderate')) {
    /**
     * Run every text value through a family-safe checker.
     *
     * @param array    $data    Submitted values.
     * @param callable $checker Receives a string, returns bool.
     * @return array List of field keys rejected by the checker.
     */
    function apd_site_moderate(array $data, callable $checker): array
    {
        $failed = array();
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                foreach ($value as $item) {
                    if (is_array($item)) {
                        foreach ($item as $sub) {
                            if (is_scalar($sub) && !$checker((string) $sub)) {
                                $failed[] = (string) $key;
                                break 2;
                            }
                        }
                    } elseif (is_scalar($item) && !$checker((string) $item)) {
                        $failed[] = (string) $key;
                        break;
                    }
                }
                continue;
            }
            if (is_scalar($value) && !$checker((string) $value)) {
                $failed[] = (string) $key;
            }
        }
        return array_values(array_unique($failed));
    }
}
