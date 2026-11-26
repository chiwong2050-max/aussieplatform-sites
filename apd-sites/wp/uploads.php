<?php
/**
 * Photo uploads for tradie mini-websites.
 *
 * Photos come through the WordPress media library. Only jpg, png and webp are
 * accepted, capped at 5 MB each, and EXIF metadata is stripped by re-encoding.
 */

if (!function_exists('apd_sites_max_upload_bytes')) {
    function apd_sites_max_upload_bytes(): int
    {
        return 5 * 1024 * 1024;
    }
}

if (!function_exists('apd_sites_allowed_mimes')) {
    function apd_sites_allowed_mimes(): array
    {
        return array(
            'jpg|jpeg|jpe' => 'image/jpeg',
            'png'          => 'image/png',
            'webp'         => 'image/webp',
        );
    }
}

if (!function_exists('apd_sites_strip_exif')) {
    /**
     * Re-encode an image in place, which drops EXIF and other metadata.
     */
    function apd_sites_strip_exif(string $path): void
    {
        if (!function_exists('wp_get_image_editor')) {
            return;
        }
        $editor = wp_get_image_editor($path);
        if (is_wp_error($editor)) {
            return;
        }
        $editor->set_quality(85);
        $editor->save($path);
    }
}

if (!function_exists('apd_sites_handle_photos')) {
    /**
     * Process uploaded files from an input named $field.
     *
     * @param string $field      $_FILES key.
     * @param int    $listing_id Attachment parent.
     * @param array  $errors     Collected error messages (by reference).
     * @return array Attachment URLs.
     */
    function apd_sites_handle_photos(string $field, int $listing_id, array &$errors): array
    {
        $urls = array();
        if (empty($_FILES[$field]) || empty($_FILES[$field]['name'])) {
            return $urls;
        }
        if (!function_exists('wp_handle_upload')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        if (!function_exists('wp_insert_attachment')) {
            require_once ABSPATH . 'wp-admin/includes/media.php';
        }
        if (!function_exists('wp_generate_attachment_metadata')) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }

        $files   = $_FILES[$field];
        $count   = is_array($files['name']) ? count($files['name']) : 1;
        $allowed = apd_sites_allowed_mimes();

        for ($i = 0; $i < $count; $i++) {
            $name = is_array($files['name']) ? $files['name'][$i] : $files['name'];
            if ('' === (string) $name) {
                continue;
            }

            $single = array(
                'name'     => $name,
                'type'     => is_array($files['type']) ? $files['type'][$i] : $files['type'],
                'tmp_name' => is_array($files['tmp_name']) ? $files['tmp_name'][$i] : $files['tmp_name'],
                'error'    => is_array($files['error']) ? $files['error'][$i] : $files['error'],
                'size'     => is_array($files['size']) ? $files['size'][$i] : $files['size'],
            );

            if (!empty($single['error'])) {
                $errors[] = 'One of your photos could not be uploaded.';
                continue;
            }
            if ((int) $single['size'] > apd_sites_max_upload_bytes()) {
                $errors[] = 'Photos must be 5 MB or smaller.';
                continue;
            }

            $check = wp_check_filetype($single['name'], $allowed);
            if (empty($check['type']) || !in_array($check['type'], $allowed, true)) {
                $errors[] = 'Only jpg, png or webp photos are allowed.';
                continue;
            }

            $overrides = array('test_form' => false, 'mimes' => $allowed);
            $moved     = wp_handle_upload($single, $overrides);
            if (!is_array($moved) || isset($moved['error'])) {
                $errors[] = 'One of your photos could not be saved.';
                continue;
            }

            apd_sites_strip_exif($moved['file']);

            $attachment_id = wp_insert_attachment(
                array(
                    'post_mime_type' => $check['type'],
                    'post_title'     => preg_replace('/\.[^.]+$/', '', basename($moved['file'])),
                    'post_content'   => '',
                    'post_status'    => 'inherit',
                    'post_parent'    => $listing_id,
                ),
                $moved['file'],
                $listing_id
            );
            if (is_wp_error($attachment_id) || !$attachment_id) {
                $errors[] = 'One of your photos could not be attached.';
                continue;
            }

            $meta = wp_generate_attachment_metadata($attachment_id, $moved['file']);
            wp_update_attachment_metadata($attachment_id, $meta);

            $url = wp_get_attachment_url($attachment_id);
            if ($url) {
                $urls[] = $url;
            }
        }

        return $urls;
    }
}
