<?php
/**
 * Photo uploads for tradie mini-websites.
 *
 * Photos come through the WordPress media library. Only jpg, png and webp are
 * accepted, capped at 5 MB each and 8 files per site. Each photo is resized to
 * a maximum of 1600px wide, re-encoded (which strips EXIF) and saved as WebP
 * when the server supports it.
 */

if (!function_exists('apd_sites_max_upload_bytes')) {
    function apd_sites_max_upload_bytes(): int
    {
        return 5 * 1024 * 1024;
    }
}

if (!function_exists('apd_sites_max_photos')) {
    function apd_sites_max_photos(): int
    {
        return 8;
    }
}

if (!function_exists('apd_sites_photo_max_width')) {
    function apd_sites_photo_max_width(): int
    {
        return 1600;
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

if (!function_exists('apd_sites_process_photo')) {
    /**
     * Resize to the max width, re-encode (drops EXIF) and convert to WebP when
     * the server supports it.
     *
     * @return string Path to the final file (may differ in extension).
     */
    function apd_sites_process_photo(string $path): string
    {
        if (!function_exists('wp_get_image_editor')) {
            return $path;
        }
        $editor = wp_get_image_editor($path);
        if (is_wp_error($editor)) {
            return $path;
        }

        $size = $editor->get_size();
        if (is_array($size) && !empty($size['width']) && (int) $size['width'] > apd_sites_photo_max_width()) {
            $editor->resize(apd_sites_photo_max_width(), null, false);
        }
        $editor->set_quality(82);

        $target = $path;
        if (method_exists($editor, 'set_mime_type') && $editor->supports_mime_type('image/webp')) {
            $editor->set_mime_type('image/webp');
            $target = preg_replace('/\.[^.]+$/', '', $path) . '.webp';
        }

        $saved = $editor->save($target);
        if (is_wp_error($saved) || empty($saved['path'])) {
            return $path;
        }
        if ((string) $saved['path'] !== $path && is_file($path)) {
            @unlink($path);
        }
        return (string) $saved['path'];
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
        $max     = apd_sites_max_photos();

        if ($count > $max) {
            $errors[] = sprintf('You can upload up to %d photos.', $max);
            $count    = $max;
        }

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

            // Resize, re-encode (strips EXIF) and convert to WebP when possible.
            $final = apd_sites_process_photo($moved['file']);
            $final_check = wp_check_filetype($final, $allowed);
            $mime = !empty($final_check['type']) ? $final_check['type'] : $check['type'];

            $attachment_id = wp_insert_attachment(
                array(
                    'post_mime_type' => $mime,
                    'post_title'     => preg_replace('/\.[^.]+$/', '', basename($final)),
                    'post_content'   => '',
                    'post_status'    => 'inherit',
                    'post_parent'    => $listing_id,
                ),
                $final,
                $listing_id
            );
            if (is_wp_error($attachment_id) || !$attachment_id) {
                $errors[] = 'One of your photos could not be attached.';
                continue;
            }

            $meta = wp_generate_attachment_metadata($attachment_id, $final);
            wp_update_attachment_metadata($attachment_id, $meta);

            $url = wp_get_attachment_url($attachment_id);
            if ($url) {
                $urls[] = $url;
            }
        }

        return $urls;
    }
}
