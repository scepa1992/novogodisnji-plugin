<?php
/**
 * Velora – GitHub auto-update helper (moved under Ponude docek)
 */
if (!defined('ABSPATH')) exit;

function velora_get_github_repo_from_header() {
    $main_file = dirname(__DIR__) . '/plugin-velora-rest-api.php';
    $data = get_file_data($main_file, [ 'github' => 'GitHub Plugin URI' ]);
    $url  = isset($data['github']) ? trim($data['github']) : '';
    if (!$url) return null;
    $parts = wp_parse_url($url);
    if (empty($parts['path'])) return null;
    $segments = array_values(array_filter(explode('/', $parts['path'])));
    if (count($segments) < 2) return null;
    return [ 'owner' => $segments[0], 'repo' => $segments[1] ];
}

add_filter('pre_set_site_transient_update_plugins', function($transient) {
    if (empty($transient->checked)) return $transient;

    $repo = velora_get_github_repo_from_header();
    if (!$repo) return $transient;

    $plugin_main     = dirname(__DIR__) . '/plugin-velora-rest-api.php';
    $plugin_basename = plugin_basename($plugin_main);
    $current_data    = get_plugin_data($plugin_main);
    $current_version = $current_data['Version'] ?? '0.0.0';

    $api_url  = 'https://api.github.com/repos/' . $repo['owner'] . '/' . $repo['repo'] . '/releases/latest';
    $response = wp_remote_get($api_url, [ 'headers' => [ 'User-Agent' => 'WordPress; ' . home_url() ], 'timeout' => 10 ]);
    if (is_wp_error($response)) return $transient;
    if (wp_remote_retrieve_response_code($response) !== 200) return $transient;

    $body = json_decode(wp_remote_retrieve_body($response), true);
    if (!$body || empty($body['tag_name'])) return $transient;

    $latest = ltrim($body['tag_name'], 'vV');
    if (version_compare($latest, $current_version, '>')) {
        $zip = $body['zipball_url'] ?? '';
        $url = $body['html_url'] ?? ('https://github.com/' . $repo['owner'] . '/' . $repo['repo']);
        $obj = (object) [
            'slug'        => dirname($plugin_basename),
            'plugin'      => $plugin_basename,
            'new_version' => $latest,
            'package'     => $zip,
            'url'         => $url,
        ];
        $transient->response[$plugin_basename] = $obj;
    }

    return $transient;
});

add_filter('plugins_api', function($result, $action, $args) {
    if ($action !== 'plugin_information') return $result;
    $plugin_main     = dirname(__DIR__) . '/plugin-velora-rest-api.php';
    $plugin_basename = plugin_basename($plugin_main);
    if (empty($args->slug) || $args->slug !== dirname($plugin_basename)) return $result;

    $repo = velora_get_github_repo_from_header();
    if (!$repo) return $result;

    $api_url  = 'https://api.github.com/repos/' . $repo['owner'] . '/' . $repo['repo'] . '/releases/latest';
    $response = wp_remote_get($api_url, [ 'headers' => [ 'User-Agent' => 'WordPress; ' . home_url() ], 'timeout' => 10 ]);
    $data     = get_plugin_data($plugin_main);
    $version  = $data['Version'] ?? '0.0.0';
    $sections = [ 'description' => 'Velora Doček REST API – automatsko kreiranje objava, SEO i schema.' ];
    if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!empty($body['body'])) {
            $sections['changelog'] = wp_kses_post(nl2br($body['body']));
        }
    }
    return (object) [
        'name'        => 'Velora Doček REST API',
        'slug'        => dirname($plugin_basename),
        'version'     => $version,
        'author'      => '<a href="https://velora.rs">Velora</a>',
        'sections'    => $sections,
        'homepage'    => 'https://github.com/' . $repo['owner'] . '/' . $repo['repo'],
    ];
}, 10, 3);

add_filter('auto_update_plugin', function($update, $item) {
    $plugin_main     = dirname(__DIR__) . '/plugin-velora-rest-api.php';
    $plugin_basename = plugin_basename($plugin_main);
    if (!empty($item->plugin) && $item->plugin === $plugin_basename) {
        return true;
    }
    return $update;
}, 10, 2);


