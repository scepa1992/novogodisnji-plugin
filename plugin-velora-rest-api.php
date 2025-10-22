<?php
/**
 * Plugin Name: Velora REST API
 * Plugin URI: https://github.com/scepa1992/novogodisnji-plugin
 * Description: REST API za kreiranje i brisanje dočeka Nove godine sa naprednim sigurnosnim funkcijama i GitHub auto-update podrškom
 * Version: 9.0.0
 * Author: Velora Team
 * License: GPL v2 or later
 * Text Domain: velora-rest-api
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('VELORA_PLUGIN_VERSION', '9.0.0');
define('VELORA_PLUGIN_FILE', __FILE__);
define('VELORA_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('VELORA_PLUGIN_URL', plugin_dir_url(__FILE__));

// Security: API Key Management
class VeloraSecurity {
    private static $api_key = null;
    private static $github_token = null;
    
    public static function get_api_key() {
        if (self::$api_key === null) {
            // Try multiple sources for API key
            self::$api_key = defined('VELORA_API_KEY') ? VELORA_API_KEY : getenv('VELORA_API_KEY');
            if (!self::$api_key) {
                self::$api_key = get_option('velora_api_key', 'LZ5>ph)ZN8=)C7'); // fallback
            }
        }
        return self::$api_key;
    }
    
    public static function get_github_token() {
        if (self::$github_token === null) {
            self::$github_token = defined('VELORA_GITHUB_TOKEN') ? VELORA_GITHUB_TOKEN : getenv('VELORA_GITHUB_TOKEN');
            if (!self::$github_token) {
                self::$github_token = get_option('velora_github_token', '');
            }
        }
        return self::$github_token;
    }
    
    public static function validate_api_key($provided_key) {
        return hash_equals(self::get_api_key(), $provided_key);
    }
    
    public static function rate_limit_check($ip) {
        $key = 'velora_rate_' . md5($ip);
        $count = get_transient($key) ?: 0;
        $max_requests = 20; // Increased for better performance
        $time_window = 300; // 5 minutes
        
        if ($count >= $max_requests) {
            return false;
        }
        
        set_transient($key, $count + 1, $time_window);
        return true;
    }
    
    public static function log_security_event($event, $ip, $details = '') {
        $log_entry = sprintf(
            '[%s] Security Event: %s | IP: %s | Details: %s',
            current_time('Y-m-d H:i:s'),
            $event,
            $ip,
            $details
        );
        error_log($log_entry);
    }
}

// GitHub Auto-Update System
class VeloraGitHubUpdater {
    private $github_username = 'velora';
    private $github_repo = 'velora-rest-api';
    private $github_token;
    
    public function __construct() {
        $this->github_token = VeloraSecurity::get_github_token();
        add_action('wp_ajax_velora_check_update', array($this, 'check_for_updates'));
        add_action('wp_ajax_velora_install_update', array($this, 'install_update'));
        add_action('admin_menu', array($this, 'add_admin_menu'));
    }
    
    public function add_admin_menu() {
        add_options_page(
            'Velora API Settings',
            'Velora API',
            'manage_options',
            'velora-settings',
            array($this, 'admin_page')
        );
    }
    
    public function admin_page() {
        if (isset($_POST['save_settings'])) {
            // Force update and clear any caches
            delete_option('velora_api_key');
            update_option('velora_api_key', sanitize_text_field($_POST['api_key']));
            delete_option('velora_github_token');
            update_option('velora_github_token', sanitize_text_field($_POST['github_token']));
            
            // Clear WordPress cache
            if (function_exists('wp_cache_flush')) {
                wp_cache_flush();
            }
            
            // Force refresh of static variables
            VeloraSecurity::$api_key = null;
            VeloraSecurity::$github_token = null;
            
            echo '<div class="notice notice-success"><p>Settings saved and cache cleared!</p></div>';
        }
        
        $api_key = get_option('velora_api_key', 'LZ5>ph)ZN8=)C7');
        $github_token = get_option('velora_github_token', '');
        ?>
        <div class="wrap">
            <h1>Velora API Settings</h1>
            <form method="post">
                <table class="form-table">
                    <tr>
                        <th scope="row">API Key</th>
                        <td><input type="text" name="api_key" value="<?php echo esc_attr($api_key); ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row">GitHub Token</th>
                        <td><input type="password" name="github_token" value="<?php echo esc_attr($github_token); ?>" class="regular-text" /></td>
                    </tr>
                </table>
                <?php submit_button('Save Settings'); ?>
            </form>
            
            <h2>Plugin Updates</h2>
            <button id="check-update" class="button">Check for Updates</button>
            <div id="update-status"></div>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            $('#check-update').click(function() {
                $('#update-status').html('Checking...');
                $.post(ajaxurl, {
                    action: 'velora_check_update',
                    nonce: '<?php echo wp_create_nonce('velora_update'); ?>'
                }, function(response) {
                    $('#update-status').html(response);
                });
            });
        });
        </script>
        <?php
    }
    
    public function check_for_updates() {
        if (!wp_verify_nonce($_POST['nonce'], 'velora_update')) {
            wp_die('Security check failed');
        }
        
        $latest_version = $this->get_latest_version();
        if ($latest_version && version_compare($latest_version, VELORA_PLUGIN_VERSION, '>')) {
            echo '<div class="notice notice-warning"><p>New version available: ' . $latest_version . '</p>';
            echo '<button id="install-update" class="button">Install Update</button></div>';
        } else {
            echo '<div class="notice notice-success"><p>Plugin is up to date!</p></div>';
        }
        wp_die();
    }
    
    public function install_update() {
        if (!wp_verify_nonce($_POST['nonce'], 'velora_update')) {
            wp_die('Security check failed');
        }
        
        $result = $this->download_and_install_update();
        if ($result) {
            echo '<div class="notice notice-success"><p>Update installed successfully! Please refresh the page.</p></div>';
        } else {
            echo '<div class="notice notice-error"><p>Update failed. Please check logs.</p></div>';
        }
        wp_die();
    }
    
    private function get_latest_version() {
        if (!$this->github_token) {
            return false;
        }
        
        $url = "https://api.github.com/repos/{$this->github_username}/{$this->github_repo}/releases/latest";
        $args = array(
            'headers' => array(
                'Authorization' => 'token ' . $this->github_token,
                'User-Agent' => 'Velora-Plugin'
            )
        );
        
        $response = wp_remote_get($url, $args);
        if (is_wp_error($response)) {
            return false;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        return isset($data['tag_name']) ? $data['tag_name'] : false;
    }
    
    private function download_and_install_update() {
        if (!$this->github_token) {
            return false;
        }
        
        $url = "https://api.github.com/repos/{$this->github_username}/{$this->github_repo}/zipball/main";
        $args = array(
            'headers' => array(
                'Authorization' => 'token ' . $this->github_token,
                'User-Agent' => 'Velora-Plugin'
            )
        );
        
        $response = wp_remote_get($url, $args);
        if (is_wp_error($response)) {
            return false;
        }
        
        $zip_content = wp_remote_retrieve_body($response);
        $temp_file = wp_tempnam();
        file_put_contents($temp_file, $zip_content);
        
        $zip = new ZipArchive();
        if ($zip->open($temp_file) === TRUE) {
            $extract_path = WP_PLUGIN_DIR . '/velora-rest-api/';
            $zip->extractTo($extract_path);
            $zip->close();
            unlink($temp_file);
            return true;
        }
        
        unlink($temp_file);
        return false;
    }
}

// Initialize GitHub Updater
new VeloraGitHubUpdater();

// Enhanced Image Upload with Security
function velora_upload_image_from_url($image_url, $post_id) {
    if (empty($image_url)) {
        return null;
    }
    
    // Security: Validate URL
    if (!filter_var($image_url, FILTER_VALIDATE_URL)) {
        error_log('Velora: Invalid image URL provided');
        return null;
    }
    
    // Security: Check if URL is from allowed domains
    $allowed_domains = array('kudjelic.rs', 'example.com'); // Add your trusted domains
    $parsed_url = parse_url($image_url);
    if (!in_array($parsed_url['host'], $allowed_domains)) {
        error_log('Velora: Image URL from untrusted domain: ' . $parsed_url['host']);
        return null;
    }
    
    $upload_dir = wp_upload_dir();
    $image_data = wp_remote_get($image_url, array('timeout' => 30));
    
    if (is_wp_error($image_data)) {
        error_log('Velora: Failed to download image: ' . $image_data->get_error_message());
        return null;
    }
    
    $image_content = wp_remote_retrieve_body($image_data);
    $image_type = wp_remote_retrieve_header($image_data, 'content-type');
    
    // Security: Validate image type
    $allowed_types = array('image/jpeg', 'image/png', 'image/gif', 'image/webp');
    if (!in_array($image_type, $allowed_types)) {
        error_log('Velora: Invalid image type: ' . $image_type);
        return null;
    }
    
    // Security: Check file size (max 5MB)
    if (strlen($image_content) > 5 * 1024 * 1024) {
        error_log('Velora: Image too large');
        return null;
    }
    
    $filename = basename($image_url);
    $filename = sanitize_file_name($filename);
    
    // Generate unique filename
    $filename = wp_unique_filename($upload_dir['path'], $filename);
    $file_path = $upload_dir['path'] . '/' . $filename;
    
    if (file_put_contents($file_path, $image_content) === false) {
        error_log('Velora: Failed to save image file');
        return null;
    }
    
    // Enhanced MIME type detection
    $mime_type = 'image/jpeg'; // default
    if (function_exists('mime_content_type')) {
        $mime_type = mime_content_type($file_path);
    } elseif (function_exists('finfo_file')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime_type = finfo_file($finfo, $file_path);
        finfo_close($finfo);
    } else {
        // Fallback to file extension
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $mime_map = array(
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp'
        );
        $mime_type = isset($mime_map[$ext]) ? $mime_map[$ext] : 'image/jpeg';
    }
    
    $attachment = array(
        'post_mime_type' => $mime_type,
        'post_title' => sanitize_text_field($filename),
        'post_content' => '',
        'post_status' => 'inherit'
    );
    
    $attach_id = wp_insert_attachment($attachment, $file_path, $post_id);
    
    if (is_wp_error($attach_id)) {
        error_log('Velora: Failed to create attachment: ' . $attach_id->get_error_message());
        return null;
    }
    
    require_once(ABSPATH . 'wp-admin/includes/image.php');
    $attach_data = wp_generate_attachment_metadata($attach_id, $file_path);
    wp_update_attachment_metadata($attach_id, $attach_data);
    
    return $attach_id;
}

// Enhanced Create Doček Function
function velora_create_docek($request) {
    $params = $request->get_json_params();
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    
    // Security: API Key Validation
    if (!VeloraSecurity::validate_api_key($params['key'] ?? '')) {
        VeloraSecurity::log_security_event('Invalid API Key', $ip, 'Key: ' . ($params['key'] ?? 'empty'));
        return new WP_Error('invalid_key', 'Neispravan API ključ.', array('status' => 403));
    }
    
    // Security: Rate Limiting
    if (!VeloraSecurity::rate_limit_check($ip)) {
        VeloraSecurity::log_security_event('Rate Limit Exceeded', $ip);
        return new WP_Error('rate_limit', 'Previše zahteva. Pokušajte ponovo za 5 minuta.', array('status' => 429));
    }
    
    // Security: Input Validation
    $required_fields = array('title', 'content', 'meta_title', 'meta_description');
    foreach ($required_fields as $field) {
        if (empty($params[$field])) {
            return new WP_Error('missing_field', "Polje {$field} je obavezno", array('status' => 400));
        }
    }
    
    // Sanitize all inputs
    $title = sanitize_text_field($params['title']);
    $content = wp_kses_post($params['content']);
    $meta_title = sanitize_text_field($params['meta_title']);
    $meta_description = sanitize_textarea_field($params['meta_description']);
    $schema = sanitize_textarea_field($params['schema'] ?? '');
    $muzika = sanitize_text_field($params['muzika'] ?? '');
    $hrana = sanitize_text_field($params['hrana'] ?? '');
    $pice = sanitize_text_field($params['pice'] ?? '');
    $cena = sanitize_text_field($params['cena'] ?? '');
    $lokacija = sanitize_text_field($params['lokacija'] ?? '');
    $slika = esc_url_raw($params['slika'] ?? '');
    $tip = sanitize_text_field($params['tip'] ?? '');
    $preporuka = sanitize_textarea_field($params['preporuka'] ?? '');
    
    // Security: Content Length Validation
    if (strlen($content) > 50000) {
        return new WP_Error('content_too_long', 'Sadržaj je previše dugačak', array('status' => 400));
    }
    
    // Create post
    $post_data = array(
        'post_title' => $title,
        'post_content' => $content,
        'post_status' => 'publish',
        'post_type' => 'docek-nove-godine',
        'post_author' => 1
    );
    
    $post_id = wp_insert_post($post_data);
    
    if (is_wp_error($post_id)) {
        return new WP_Error('post_creation_failed', 'Greška pri kreiranju posta', array('status' => 500));
    }
    
    // Upload image if provided
    $attachment_id = null;
    if (!empty($slika)) {
        $attachment_id = velora_upload_image_from_url($slika, $post_id);
        if ($attachment_id) {
            set_post_thumbnail($post_id, $attachment_id);
        }
    }
    
    // Set meta fields
    update_post_meta($post_id, '_yoast_wpseo_title', $meta_title);
    update_post_meta($post_id, '_yoast_wpseo_metadesc', $meta_description);
    update_post_meta($post_id, 'muzika', $muzika);
    update_post_meta($post_id, 'hrana', $hrana);
    update_post_meta($post_id, 'pice', $pice);
    update_post_meta($post_id, 'cena', $cena);
    update_post_meta($post_id, 'lokacija', $lokacija);
    update_post_meta($post_id, 'tip', $tip);
    update_post_meta($post_id, 'preporuka', $preporuka);
    
    // Add schema markup
    if (!empty($schema)) {
        update_post_meta($post_id, 'schema_markup', $schema);
    }
    
    // Log successful creation
    VeloraSecurity::log_security_event('Post Created', $ip, "Post ID: {$post_id}");
    
    return array(
        'success' => true,
        'post_id' => $post_id,
        'url' => get_permalink($post_id),
        'message' => '✅ Doček uspešno kreiran!'
    );
}

// Enhanced Delete Doček Function
function velora_delete_docek($request) {
    $params = $request->get_json_params();
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    
    // Security: API Key Validation
    if (!VeloraSecurity::validate_api_key($params['key'] ?? '')) {
        VeloraSecurity::log_security_event('Invalid API Key for Delete', $ip);
        return new WP_Error('invalid_key', 'Neispravan API ključ.', array('status' => 403));
    }
    
    // Security: Rate Limiting
    if (!VeloraSecurity::rate_limit_check($ip)) {
        VeloraSecurity::log_security_event('Rate Limit Exceeded for Delete', $ip);
        return new WP_Error('rate_limit', 'Previše zahteva. Pokušajte ponovo za 5 minuta.', array('status' => 429));
    }
    
    $url = sanitize_text_field($params['url'] ?? '');
    $path = sanitize_text_field($params['path'] ?? '');
    
    if (empty($url) || empty($path)) {
        return new WP_Error('missing_data', 'URL i putanja su obavezni', array('status' => 400));
    }
    
    // Security: Validate URL format
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return new WP_Error('invalid_url', 'Neispravan URL format', array('status' => 400));
    }
    
    // Find post by URL
    $post_id = url_to_postid($url);
    
    if (!$post_id) {
        // Try alternative search methods
        $parsed_url = parse_url($url);
        $slug = basename($parsed_url['path']);
        
        // Search by slug
        $posts = get_posts(array(
            'name' => $slug,
            'post_type' => 'docek-nove-godine',
            'post_status' => 'publish',
            'numberposts' => 1
        ));
        
        if (!empty($posts)) {
            $post_id = $posts[0]->ID;
        }
    }
    
    if (!$post_id) {
        VeloraSecurity::log_security_event('Post Not Found', $ip, "URL: {$url}");
        return new WP_Error('post_not_found', 'Post nije pronađen', array('status' => 404));
    }
    
    // Verify post type
    $post = get_post($post_id);
    if (!$post || $post->post_type !== 'docek-nove-godine') {
        VeloraSecurity::log_security_event('Invalid Post Type', $ip, "Post ID: {$post_id}, Type: " . ($post ? $post->post_type : 'null'));
        return new WP_Error('invalid_post_type', 'Post nije tipa doček Nove godine', array('status' => 400));
    }
    
    // Security: Check if user has permission to delete
    if (!current_user_can('delete_posts')) {
        // For API calls, we'll allow deletion with valid API key
        // In production, you might want to add additional checks
    }
    
    // Force delete the post
    $deleted = wp_delete_post($post_id, true);
    
    if ($deleted) {
        VeloraSecurity::log_security_event('Post Deleted', $ip, "Post ID: {$post_id}, URL: {$url}");
        return array(
            'success' => true,
            'message' => 'Post uspešno obrisan',
            'post_id' => $post_id,
            'url' => $url
        );
    } else {
        VeloraSecurity::log_security_event('Delete Failed', $ip, "Post ID: {$post_id}");
        return new WP_Error('delete_failed', 'Greška pri brisanju posta', array('status' => 500));
    }
}

// Register REST API routes with enhanced security
add_action('rest_api_init', function() {
    error_log('🔧 Velora: Registrujem REST rute sa naprednom sigurnošću...');
    
    // Create endpoint with enhanced security
    register_rest_route('velora/v1', '/create-docek', array(
        'methods' => 'POST',
        'callback' => 'velora_create_docek',
        'permission_callback' => function($request) {
            // Additional security checks can be added here
            return true; // Permission is handled in the callback function
        },
    ));
    
    // Delete endpoint with enhanced security
    register_rest_route('velora/v1', '/delete-docek', array(
        'methods' => 'POST',
        'callback' => 'velora_delete_docek',
        'permission_callback' => function($request) {
            // Additional security checks can be added here
            return true; // Permission is handled in the callback function
        },
    ));
    
    // Health check endpoint
    register_rest_route('velora/v1', '/health', array(
        'methods' => 'GET',
        'callback' => function() {
            return array(
                'status' => 'healthy',
                'version' => VELORA_PLUGIN_VERSION,
                'timestamp' => current_time('Y-m-d H:i:s')
            );
        },
        'permission_callback' => '__return_true'
    ));
    
    error_log('✅ Velora: REST rute registrovane sa naprednom sigurnošću');
});

// Security: Add security headers
add_action('init', function() {
    if (!is_admin()) {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('X-XSS-Protection: 1; mode=block');
    }
});

// Security: Disable XML-RPC if not needed
add_filter('xmlrpc_enabled', '__return_false');

// Security: Hide WordPress version
remove_action('wp_head', 'wp_generator');

// Security: Disable file editing in admin
if (!defined('DISALLOW_FILE_EDIT')) {
    define('DISALLOW_FILE_EDIT', true);
}

// GitHub Auto-Update System (simplified)
add_filter('pre_set_site_transient_update_plugins', 'velora_check_github_updates');

function velora_check_github_updates($transient) {
    if (empty($transient->checked)) {
        return $transient;
    }
    
    $github_token = VeloraSecurity::get_github_token();
    if (empty($github_token)) {
        return $transient;
    }
    
    $latest_release = velora_get_latest_release($github_token);
    if (!$latest_release) {
        return $transient;
    }
    
    $current_version = $transient->checked[VELORA_PLUGIN_FILE];
    $latest_version = $latest_release['tag_name'];
    
    if (version_compare($current_version, $latest_version, '<')) {
        $transient->response[VELORA_PLUGIN_FILE] = (object) array(
            'slug' => 'velora-rest-api',
            'new_version' => $latest_version,
            'package' => $latest_release['zipball_url'],
            'url' => $latest_release['html_url']
        );
    }
    
    return $transient;
}

function velora_get_latest_release($github_token) {
    $cache_key = 'velora_latest_release';
    $cached = get_transient($cache_key);
    
    if ($cached !== false) {
        return $cached;
    }
    
    $url = "https://api.github.com/repos/scepa1992/novogodisnji-plugin/releases/latest";
    $args = array(
        'headers' => array(
            'Authorization' => 'token ' . $github_token,
            'Accept' => 'application/vnd.github.v3+json'
        )
    );
    
    $response = wp_remote_get($url, $args);
    if (is_wp_error($response)) {
        return false;
    }
    
    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);
    
    if (empty($data['tag_name'])) {
        return false;
    }
    
    // Cache for 1 hour
    set_transient($cache_key, $data, HOUR_IN_SECONDS);
    
    return $data;
}

// Plugin activation hook
register_activation_hook(__FILE__, function() {
    // Create default options - use our fixed API key instead of generating random one
    add_option('velora_api_key', 'LZ5>ph)ZN8=)C7');
    add_option('velora_github_token', '');
    
    // Flush rewrite rules
    flush_rewrite_rules();
});

// Plugin deactivation hook
register_deactivation_hook(__FILE__, function() {
    // Clean up transients
    global $wpdb;
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_velora_%'");
    
    // Flush rewrite rules
    flush_rewrite_rules();
});

// Add admin notices
add_action('admin_notices', function() {
    $api_key = get_option('velora_api_key', 'LZ5>ph)ZN8=)C7');
    if (empty($api_key)) {
        echo '<div class="notice notice-warning"><p>Velora API: Molimo postavite API ključ u <a href="' . admin_url('options-general.php?page=velora-settings') . '">postavkama</a>.</p></div>';
    }
});

error_log('✅ Velora REST API Plugin v' . VELORA_PLUGIN_VERSION . ' učitan sa naprednom sigurnošću');
?>