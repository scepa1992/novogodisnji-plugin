<?php
/*
Plugin Name: Velora Doček REST API
Description: REST API za automatsko dodavanje dočeka sa Slim SEO poljima, tagom Preporuka, kategorijom Tip dočeka i poljem za custom JS/Schema.
Version: 6.0.3
Author: Velora
X-GitHub Plugin URI: https://github.com/scepa1992/novogodisnji-plugin
*/

if (!defined('ABSPATH')) exit;

// Fallback repo definicija ako je header onemogućen ili uklonjen
if (!defined('VELORA_GITHUB_REPO')) {
    define('VELORA_GITHUB_REPO', 'scepa1992/novogodisnji-plugin');
}

/**
 * ==================================================
 *  1️⃣ Ručno povezivanje taksonomije “tip-doceka” sa CPT “docek-nove-godine”
 * ==================================================
 */
// Pomoćna: pronađi ispravan slug taksonomije za "Tip dočeka"
function velora_detect_tip_taxonomy_slug() {
    // Najčešći slug-ovi koje koristimo ili kreira CPT UI
    $candidates = ['tip-doceka', 'docek-nove-godine', 'tip_doceka'];
    foreach ($candidates as $slug) {
        if (taxonomy_exists($slug)) return $slug;
    }
    // Pokušaj da pronađeš taksonomiju pridruženu CPT-u
    if (post_type_exists('docek-nove-godine')) {
        $tax_objects = get_object_taxonomies('docek-nove-godine', 'objects');
        if (!empty($tax_objects) && is_array($tax_objects)) {
            foreach ($tax_objects as $tax) {
                // Preskoči standardne WP taksonomije ako su pridružene
                if (in_array($tax->name, ['category','post_tag'], true)) continue;
                return $tax->name;
            }
        }
    }
    return null;
}

add_action('init', function() {
    // CPT UI registruje taksonomije ranije, pa čekamo do kasnog prioriteta
    add_action('init', function() {
        $slug = velora_detect_tip_taxonomy_slug();
        if ($slug && post_type_exists('docek-nove-godine')) {
            register_taxonomy_for_object_type($slug, 'docek-nove-godine');
            error_log('✅ Velora: taksonomija povezana sa docek-nove-godine: ' . $slug);
        } else {
            error_log('⚠️ Velora: taksonomija za Tip dočeka ili CPT nisu dostupni prilikom povezivanja.');
        }
    }, 50);
    // Osiguraj podršku za featured image na CPT-u
    add_action('init', function() {
        if (post_type_exists('docek-nove-godine')) {
            add_post_type_support('docek-nove-godine', 'thumbnail');
        }
    }, 60);
}, 5);


/**
 * ==================================================
 *  2️⃣ Registracija REST API rute
 * ==================================================
 */
add_action('rest_api_init', function() {
    register_rest_route('velora/v1', '/create-docek', [
        'methods'  => 'POST',
        'callback' => 'velora_create_docek',
        'permission_callback' => '__return_true',
    ]);
});

/**
 * ==================================================
 *  3️⃣ Glavna funkcija za kreiranje dočeka
 * ==================================================
 */
function velora_create_docek($request) {
    $params = $request->get_json_params();
    $api_key = 'LZ5>ph)ZN8=)C7';

    if (empty($params['key']) || $params['key'] !== $api_key) {
        return new WP_Error('invalid_key', 'Neispravan API ključ.', ['status' => 403]);
    }

    // Idempotency: spreči duplikate za isti request u kratkom periodu
    $request_id = isset($params['request_id']) ? sanitize_text_field($params['request_id']) : '';
    if ($request_id) {
        $lock_key = 'velora_lock_' . md5($request_id);
        if (get_transient($lock_key)) {
            return [ 'success' => true, 'duplicate' => true, 'message' => '⏩ Već obrađeno (idempotent).' ];
        }
        // Zaključaj na 2 minuta
        set_transient($lock_key, 1, 120);
    }

    $post_title = sanitize_text_field($params['title'] ?? 'Bez naslova');
    // Dedupe po naslovu u poslednjih 5 minuta (dodatna zaštita)
    $maybe_existing = get_page_by_title($post_title, OBJECT, 'docek-nove-godine');
    if ($maybe_existing && $maybe_existing->post_status === 'publish') {
        $created = strtotime($maybe_existing->post_date_gmt . ' GMT');
        if ($created && (time() - $created) < 300) {
            return [
                'success' => true,
                'duplicate' => true,
                'post_id' => $maybe_existing->ID,
                'url' => get_permalink($maybe_existing->ID),
                'message' => '⏩ Već postoji skoro identičan doček (naslov) kreiran malopre.'
            ];
        }
    }
    $html = wp_kses_post($params['content'] ?? '');
    $post_type = 'docek-nove-godine';

    $post_id = wp_insert_post([
        'post_title'   => $post_title,
        'post_content' => $html,
        'post_status'  => 'publish',
        'post_type'    => $post_type,
    ]);

    if (is_wp_error($post_id)) {
        return new WP_Error('insert_failed', 'Greška prilikom kreiranja objave.', ['status' => 500]);
    }

    /**
     * Slim SEO
     */
    if (!empty($params['meta_title'])) {
        update_post_meta($post_id, '_slim_seo_title', sanitize_text_field($params['meta_title']));
    }
    if (!empty($params['meta_description'])) {
        update_post_meta($post_id, '_slim_seo_description', sanitize_textarea_field($params['meta_description']));
    }

    /**
     * Schema
     */
    if (!empty($params['schema'])) {
        $schema = wp_kses_post($params['schema']);
        if (strpos($schema, '<script') === false) {
            $schema = '<script type="application/ld+json">' . $schema . '</script>';
        }
        update_post_meta($post_id, '_velora_schema', $schema);
    }

    /**
     * Custom polja
     */
    $fields = ['muzika', 'pice', 'hrana', 'cena', 'lokacija', 'slika'];
    foreach ($fields as $field) {
        if (!empty($params[$field])) {
            update_post_meta($post_id, $field, sanitize_text_field($params[$field]));
        }
    }

    /**
     * Tip dočeka (taksonomija) – automatski detektuj slug
     */
    if (!empty($params['tip'])) {
        $term_name = sanitize_text_field($params['tip']);
        $tip_tax   = velora_detect_tip_taxonomy_slug();
        if ($tip_tax) {
            if (!term_exists($term_name, $tip_tax)) {
                wp_insert_term($term_name, $tip_tax);
            }
            $assign = wp_set_object_terms($post_id, $term_name, $tip_tax, false);
            if (is_wp_error($assign)) {
                error_log('❌ Greška pri dodeli termina (' . $tip_tax . '): ' . $assign->get_error_message());
            } else {
                error_log('✅ Dodeljen termin "' . $term_name . '" u taksonomiji ' . $tip_tax);
            }
        } else {
            error_log('❌ Nije pronađena taksonomija za Tip dočeka. Proveri CPT UI podešavanja.');
        }
    }

    /**
     * Tag “Preporuka”
     */
    if (!empty($params['preporuka'])) {
        wp_set_post_terms($post_id, ['Preporuka'], 'post_tag', true);
    }

    /**
     * Slika (ako postoji URL)
     */
    if (!empty($params['slika']) && filter_var($params['slika'], FILTER_VALIDATE_URL)) {
        $img_id = velora_upload_image_from_url($params['slika'], $post_id);
        if ($img_id) {
            $set = set_post_thumbnail($post_id, $img_id);
            if (!$set || !current_theme_supports('post-thumbnails') || !post_type_supports('docek-nove-godine', 'thumbnail')) {
                // Ako tema ili CPT nemaju podršku za thumbnail, ubaci sliku na početak sadržaja
                $src = wp_get_attachment_image_url($img_id, 'large');
                if ($src) {
                    $html_img = '<p><img src="' . esc_url($src) . '" alt="" style="max-width:100%;height:auto;" /></p>';
                    $current_content = get_post_field('post_content', $post_id);
                    wp_update_post([
                        'ID' => $post_id,
                        'post_content' => $html_img . "\n" . $current_content,
                    ]);
                }
            }
        } else {
            error_log('❌ Velora: Upload slike nije uspeo za URL: ' . $params['slika']);
        }
    }

    return [
        'success' => true,
        'post_id' => $post_id,
        'url'     => get_permalink($post_id),
        'message' => '✅ Doček uspešno kreiran!'
    ];
}

/**
 * ==================================================
 *  4️⃣ Upload slike po URL-u
 * ==================================================
 */
function velora_upload_image_from_url($url, $post_id) {
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';

    $tmp = download_url($url);
    if (is_wp_error($tmp)) return false;

    $file = [
        'name'     => basename($url),
        'type'     => mime_content_type($tmp),
        'tmp_name' => $tmp,
        'error'    => 0,
        'size'     => filesize($tmp),
    ];

    $id = media_handle_sideload($file, $post_id);
    if (is_wp_error($id)) {
        @unlink($tmp);
        return false;
    }
    return $id;
}

/**
 * ==================================================
 *  5️⃣ Admin meta box – Custom JS / Schema
 * ==================================================
 */
add_action('add_meta_boxes', function() {
    add_meta_box('velora_js_box', 'Velora Custom JS / Schema', function($post) {
        $val = get_post_meta($post->ID, '_velora_custom_js', true);
        echo '<textarea name="velora_custom_js" style="width:100%;height:180px;font-family:monospace;">' . esc_textarea($val) . '</textarea>';
        echo '<p style="color:#666;">Ovde možete dodati sopstveni <strong>&lt;script&gt;</strong> ili JSON-LD koji će biti ubačen u &lt;head&gt; samo za ovaj doček.</p>';
    }, 'docek-nove-godine', 'normal', 'default');
});

add_action('save_post', function($id) {
    if (isset($_POST['velora_custom_js'])) {
        update_post_meta($id, '_velora_custom_js', wp_kses_post($_POST['velora_custom_js']));
    }
});

/**
 * ==================================================
 *  6️⃣ Admin meta box – Slim SEO Meta Tagovi
 * ==================================================
 */
add_action('add_meta_boxes', function() {
    add_meta_box('velora_seo_box', 'Slim SEO Meta Tagovi', function($post) {
        $title = get_post_meta($post->ID, '_slim_seo_title', true);
        $desc  = get_post_meta($post->ID, '_slim_seo_description', true);
        echo '<label><strong>Meta Title:</strong></label><input type="text" name="velora_meta_title" value="' . esc_attr($title) . '" style="width:100%;margin-bottom:10px;">';
        echo '<label><strong>Meta Description:</strong></label><textarea name="velora_meta_description" rows="3" style="width:100%;">' . esc_textarea($desc) . '</textarea>';
    }, 'docek-nove-godine', 'normal', 'default');
});

add_action('save_post', function($id) {
    if (isset($_POST['velora_meta_title'])) {
        update_post_meta($id, '_slim_seo_title', sanitize_text_field($_POST['velora_meta_title']));
    }
    if (isset($_POST['velora_meta_description'])) {
        update_post_meta($id, '_slim_seo_description', sanitize_textarea_field($_POST['velora_meta_description']));
    }
});

/**
 * ==================================================
 *  7️⃣ Ubacivanje schema + JS u <head>
 * ==================================================
 */
add_action('wp_head', function() {
    if (is_singular('docek-nove-godine')) {
        $schema = get_post_meta(get_the_ID(), '_velora_schema', true);
        $custom = get_post_meta(get_the_ID(), '_velora_custom_js', true);
        if ($schema) echo "\n<!-- Velora Schema -->\n" . $schema . "\n<!-- /Velora Schema -->\n";
        if ($custom) echo "\n<!-- Velora Custom JS -->\n" . $custom . "\n<!-- /Velora Custom JS -->\n";
    }
}, 999);

/**
 * ==================================================
 *  Aktivacija / deaktivacija plugina
 * ==================================================
 */
register_activation_hook(__FILE__, function() { flush_rewrite_rules(); });
register_deactivation_hook(__FILE__, function() { flush_rewrite_rules(); });

// Učitaj updater samo u admin-u
if (is_admin()) {
    $updater_path = __DIR__ . '/inc/velora-updater.php';
    if (file_exists($updater_path)) {
        require_once $updater_path;
    }
}


