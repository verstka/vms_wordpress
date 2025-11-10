<?php
/*
Plugin Name: Verstka Backend
Plugin URI: https://github.com/verstka/vms_wordpress
Description: Powerfull design tool & WYSIWYG api on Backend.
Version: 1.2.4
Author: Verstka
Author URI: https://verstka.io
Text Domain: verstka-backend
Domain Path: /languages
*/

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

// Activation and deactivation hooks
register_activation_hook(__FILE__, 'vms_activate');
register_deactivation_hook(__FILE__, 'vms_deactivate');

/**
 * Activation callback.
 */
function vms_activate() {
    global $wpdb;
    $table = $wpdb->prefix . 'posts';
    // Define columns with definitions and positions
    $columns = array(
        'post_isvms' => array(
            'definition' => 'BOOLEAN NOT NULL DEFAULT 0',
            'after'      => 'post_date_gmt',
        ),
        'post_vms_content' => array(
            'definition' => 'LONGTEXT NULL',
            'after'      => 'post_content',
        ),
        'post_vms_content_mobile' => array(
            'definition' => 'LONGTEXT NULL',
            'after'      => 'post_vms_content',
        ),
    );
    foreach ($columns as $column => $attrs) {
        $exists = $wpdb->get_var(
            $wpdb->prepare("SHOW COLUMNS FROM `{$table}` LIKE %s", $column)
        );
        if ($exists !== $column) {
            $definition = $attrs['definition'];
            $after = !empty($attrs['after']) ? " AFTER `{$attrs['after']}`" : "";
            $wpdb->query(
                "ALTER TABLE `{$table}` ADD `{$column}` {$definition}{$after}"
            );
        }
    }
    
    // Flush permalinks to ensure REST API routes work
    flush_rewrite_rules();
}

/**
 * Deactivation callback.
 */
function vms_deactivate() {
    // TODO: Add deactivation tasks.
}

/**
 * Initialize plugin: load textdomain and check compatibility
 */
function vms_init() {
    load_plugin_textdomain('verstka-backend', false, dirname(plugin_basename(__FILE__)) . '/languages');
    
    // Check WordPress version compatibility
    global $wp_version;
    if (version_compare($wp_version, '5.0', '<')) {
        add_action('admin_notices', 'vms_wp_version_notice');
        return;
    }
    
    // Ensure REST API is enabled
    if (!function_exists('rest_url')) {
        add_action('admin_notices', 'vms_rest_api_notice');
        return;
    }
}
add_action('init', 'vms_init');

/**
 * WordPress version compatibility notice
 */
function vms_wp_version_notice() {
    echo '<div class="notice notice-error"><p>';
    echo sprintf(
        __('Verstka Backend requires WordPress 5.0 or higher. You are running version %s.', 'verstka-backend'),
        get_bloginfo('version')
    );
    echo '</p></div>';
}

/**
 * REST API availability notice
 */
function vms_rest_api_notice() {
    echo '<div class="notice notice-error"><p>';
    echo __('Verstka Backend requires WordPress REST API to be enabled.', 'verstka-backend');
    echo '</p></div>';
}

/**
 * Enqueue front-end scripts and styles
 */
function vms_enqueue_assets() {
    $version = '1.2.4';
    wp_enqueue_script('vms-script', plugin_dir_url(__FILE__) . 'assets/js/vms_plugin.js', array('jquery'), $version, true);
}
add_action('wp_enqueue_scripts', 'vms_enqueue_assets');

// Register REST API routes for verstka API v1
add_action('rest_api_init', 'vms_register_routes');

/**
 * Register REST route for verstka API v1 callback.
 */
function vms_register_routes() {
    register_rest_route(
        'verstka/v1',
        '/callback',
        array(
            'methods'             => 'POST',
            'callback'            => 'vms_verstka_callback',
            'permission_callback' => '__return_true',
        )
    );
    
    // Add a test endpoint for diagnostics
    register_rest_route(
        'verstka/v1',
        '/test',
        array(
            'methods'             => 'GET',
            'callback'            => 'vms_test_endpoint',
            'permission_callback' => '__return_true',
        )
    );
}

/**
 * Test endpoint for REST API diagnostics
 */
function vms_test_endpoint( WP_REST_Request $request ) {
    return rest_ensure_response(array(
        'status' => 'success',
        'message' => 'Verstka REST API is working',
        'version' => '1.2.4',
        'php_version' => phpversion(),
        'wordpress_version' => get_bloginfo('version'),
        'rest_url' => rest_url('verstka/v1/'),
        'timestamp' => current_time('mysql')
    ));
}

/**
 * Open the VMS editor session.
 */
function vms_editor_open() {
    $is_debug = get_option('vms_dev_mode', 0);
    if (! current_user_can('edit_posts')) {
        wp_die(__('Permission denied', 'verstka-backend'));
    }

    $user_id     = get_current_user_id();
    $material_id = isset($_GET['post']) ? intval($_GET['post']) : 0;
    $mode        = isset($_GET['mode']) && in_array($_GET['mode'], array('desktop','mobile'), true) ? sanitize_key($_GET['mode']) : 'desktop';

    $post = get_post($material_id);
    if (! $post) {
        wp_die(__('Invalid post ID', 'verstka-backend'));
    }

    // Prepare API request to VMS Editor
    $api_key = get_option('vms_api_key');
    if (empty($api_key)) {
        echo '<script>window.onload=function(){alert('.json_encode(__('API Key not set', 'verstka-backend')).');history.back();};</script>';
        return;
    }
    $endpoint = 'https://verstka.org/1/open';
    // Send x-www-form-urlencoded request via cURL

    // Set html_body based on mode using actual table columns
    if ( 'desktop' === $mode ) {
        $html_body = isset( $post->post_vms_content ) ? $post->post_vms_content : '';
        $post_width = get_option('vms_desktop_width', '960');
    } else {
        $html_body = isset( $post->post_vms_content_mobile ) ? $post->post_vms_content_mobile : '';
        $post_width = get_option('vms_mobile_width', '320');
    }
    $host_name    = parse_url( home_url(), PHP_URL_HOST );
    // Custom fields: example additional data
    $custom_fields = array(
        'mobile'        => $mode === 'mobile' ? 'M' : '',
    );

    $vms_fonts_css_url = get_option('vms_fonts_css_url');
    if (!empty($vms_fonts_css_url)) {
        $custom_fields['fonts.css'] = $vms_fonts_css_url;
    }

    $user               = wp_get_current_user();
    $auth_user_email    = ! empty( $user->user_email ) ? $user->user_email : '';
    if (!empty($auth_user_email)) {
        $custom_fields['auth_user_email'] = $auth_user_email;
    }
    
    if (!empty($post_width)) {
        $custom_fields['width'] = $post_width;
    }

    $vms_site_httpauth_user = get_option('vms_site_httpauth_user');
    if (!empty($vms_site_httpauth_user)) {
        $custom_fields['auth_user'] = $vms_site_httpauth_user;
    }

    $vms_site_httpauth_pw = get_option('vms_site_httpauth_pw');
    if (!empty($vms_site_httpauth_pw)) {
        $custom_fields['auth_pw'] = $vms_site_httpauth_pw;
    }
    
    $callback_url  = rest_url( 'verstka/v1/callback' );
    $secret = get_option('vms_secret');
    if ( empty( $secret ) ) {
        echo '<script>window.onload=function(){alert(' . json_encode( __( 'Secret not set', 'verstka-backend' ) ) . ');history.back();};</script>';
        return;
    }
    
    $form_fields = array(
        'user_id'      => $user_id,
        'material_id'  => $material_id,
        'html_body'    => $html_body,
        'host_name'    => $host_name,
        'api-key'      => $api_key,
        'custom_fields'=> json_encode( $custom_fields ),
        'callback_url' => $callback_url,
        'callback_sign'=> $callback_sign,
    );

    $form_fields['callback_sign'] = getRequestSalt($secret, $form_fields, 'api-key, material_id, user_id, callback_url');

    try {
        $res = vms_curl_post($endpoint, $form_fields, 90);
        if ($res === false) {
            throw new Exception('cURL request failed');
        }
    } catch ( \Exception $e ) {
        echo '<script>window.onload=function(){alert(' . json_encode( $e->getMessage() ) . ');history.back();};</script>';
        return;
    }
    $code = $res['http_code'];
    if ( $code !== 200 ) {
        if ( $is_debug ) {
            wp_die(json_encode($form_fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES| JSON_PRETTY_PRINT).PHP_EOL.json_encode(['http_code' => $code]).PHP_EOL.$res['body']);
        }
        if (!empty($res['body'])) {
            $data = json_decode($res['body'], true);
            if (!empty($data['rm'])) {
                $message = $data['rm'];
            } else {
                $message = sprintf('%s '. __( 'HTTP error: %d', 'verstka-backend' ), $endpoint,$code );
            }
        }
        echo '<script>window.onload=function(){alert(' . json_encode( $message ) . ');history.back();};</script>';
        return;
    }
    $data = json_decode( $res['body'], true );

    if (empty($data['rc']) || empty($data['data']['edit_url'])) {
        $message = !empty($data['rm']) ? $data['rm'] : __('Unknown error', 'verstka-backend');
        echo "<script>window.onload=function(){alert('".$message."'); var responseData = ".json_encode($data, JSON_PRETTY_PRINT).";  console.log(responseData);};</script>";
        wp_die('<pre>'.json_encode($data, JSON_PRETTY_PRINT).'</pre>');
    }
    
    $redirect = sprintf('window.location.replace("%s");', $data['data']['edit_url']);
    wp_die("<script>{$redirect}</script>");
}

/**
 * Callback for verstka API v1.
 *
 * @param \WP_REST_Request $request Request object.
 * @return \WP_REST_Response
 */
function vms_verstka_callback( WP_REST_Request $request ) {
    // Retrieve request parameters: JSON first, then form body
    $data = $request->get_body_params();
    $is_debug = get_option('vms_dev_mode', 0);

    $api_key = get_option('vms_api_key');
    if (empty( $api_key ) ) {
        return formJSON( 0, 'API Key not set');
    }

    $secret = get_option('vms_secret');
    if (empty( $secret ) ) {
        return formJSON( 0, 'Secret not set');
    }

    $expected_callback_sign = getRequestSalt($secret, $data, 'session_id, user_id, material_id, download_url');  
    if ( $expected_callback_sign !== $data['callback_sign'] ) {
        if ( $is_debug ) {
            return formJSON( 0, 'Invalid callback sign', array('expected_callback_sign' => $expected_callback_sign, 'data' => $data));
        }
        return formJSON( 0, 'Invalid callback sign');
    }

    // Decode JSON in custom_fields if present
    if ( ! empty( $data['custom_fields'] ) ) {
        $decoded = json_decode( $data['custom_fields'], true );
        if ( null !== $decoded ) {
            $data['custom_fields'] = $decoded;
        } else {
            if ( $is_debug ) {
                return formJSON( 0, 'Invalid custom_fields', $data);
            }
            return formJSON( 0, 'Invalid custom_fields');
        }
    }

    $is_mobile = !empty($data['custom_fields']['mobile']);

    $images_dir = get_option('vms_images_dir');
    if ( empty( $images_dir ) ) {
        return formJSON( 0, 'Images Directory not set');
    }
    $uploadMaterialPathAdding = sprintf(($is_mobile ? '%sm' : '%s'), $data['material_id']);
    $images_rel = trailingslashit(sprintf('%s%s', trailingslashit($images_dir), $uploadMaterialPathAdding));
    $images_abs = wp_normalize_path(trailingslashit(ABSPATH . $images_rel));

    if (!isset($data['material_id'])) {
        return formJSON( 0, 'material_id not set');
    }

    if (!isset($data['html_body'])) {
        return formJSON( 0, 'html_body not set');
    }

    // Fetch list of files from download_url (JSON)
    $download_endpoint = str_replace('http://', 'https://', $data['download_url']);
    
    // Request JSON containing the file list via cURL
    try {
        $list_res = vms_curl_get($download_endpoint, 60);
        if ($list_res === false) {
            throw new Exception('cURL request failed');
        }
    } catch (\Exception $e) {
        return formJSON(0, 'File list request failed: ' . $e->getMessage());
    }
    if ($list_res['http_code'] !== 200) {
        return formJSON(0, sprintf('File list HTTP error: %d', $list_res['http_code']));
    }
    $list_data = json_decode($list_res['body'], true);
    if (!isset($list_data['data'])) {
        return formJSON(0, 'Invalid file list JSON', $list_data);
    }
    $images_list = $list_data['data'];

    wp_mkdir_p($images_abs);
    if (!wp_is_writable($images_abs)) {
        if ( $is_debug ) {
            return formJSON( 0, 'Images Directory not writable', array('images_abs' => $images_abs));
        }
        return formJSON( 0, 'Images Directory not writable');
    }

    // Download files using multi-threaded cURL
    $start_time = microtime(true);
    $download_results = [];
    $download_stats = ['total_files' => 0, 'successful' => 0, 'failed' => 0, 'total_size_mb' => 0, 'success_rate' => 100];
    
    if ( isset($list_data['data']) && is_array($list_data['data']) ) {
        // Prepare download array
        $downloads = [];
        foreach ($list_data['data'] as $image) {
            $file_url = sprintf('%s/%s', $download_endpoint, $image);
            $file_path = trailingslashit($images_abs) . basename($image);
            $downloads[] = [
                'url' => $file_url,
                'path' => $file_path,
                'filename' => $image
            ];
        }
        
        // Execute multi-threaded download (max 20 concurrent)
        $download_results = vms_curl_download_multiple($downloads, 60, 20);
        $download_stats = vms_get_download_stats($download_results);
        
        // Check for errors
        $failed_downloads = array_filter($download_results, function($r) { return !$r['success']; });
        if (!empty($failed_downloads)) {
            $first_error = reset($failed_downloads);
            $error_msg = sprintf('Download failed: %s (Total: %d files, Failed: %d)', 
                $first_error['error'], $download_stats['total_files'], $download_stats['failed']);
            if ( $is_debug ) {
                return formJSON( 0, $error_msg, array(
                    'download_results' => $download_results,
                    'download_stats' => $download_stats
                ));
            }
            return formJSON( 0, $error_msg );
        }
    }

    $source = str_replace('/vms_images/', sprintf('%s', $images_rel), $data['html_body']);

    $db_data = ['post_isvms' => 1];
    if ( $is_mobile ) {
        $db_data['post_vms_content_mobile'] = $source;
    } else {
        $db_data['post_vms_content'] = $source;
    }

    global $wpdb;
    $wpdb->update(
        $wpdb->posts,
        $db_data,
        array('ID' => $data['material_id'])
    );
    // Clear the post cache
    clean_post_cache( $data['material_id'] );
    if (function_exists('wp_cache_clear_cache')) {
        wp_cache_clear_cache();
    }

    $time_real = microtime(true) - $start_time;
    if ( $is_debug ) {
        return formJSON( 1, 'Success', array(
            'images_list' => $images_list,
            'download_results' => $download_results,
            'download_stats' => $download_stats,
            'time_real'   => $time_real,
            'data'        => $data,
        ) );
    }
    return formJSON( 1, 'Success', array(
        'time_real'   => $time_real,
        'download_stats' => $download_stats,
    ) );
}

function formJSON($res_code, $res_msg, $data = [])
{
    return rest_ensure_response(
        [
            'rc' => $res_code,
            'rm' => $res_msg,
            'data' => $data
        ]
    );
}

/**
 * @param string $secret
 * @param array  $data
 * @param string $fields
 *
 * @return string
 */
function getRequestSalt(string $secret, array $data, string $fields): string
{
    $fields = array_filter(array_map('trim', explode(',', $fields)));
    $result = $secret;
    foreach ($fields as $field) {
        $result .= $data[$field];
    }

    return md5($result);
}

// Add settings page and register plugin settings
add_action('admin_menu', 'vms_add_admin_menu');
add_action('admin_init', 'vms_register_settings');
add_action('admin_post_vms_reset_api_key', 'vms_reset_api_key_callback');
add_action('admin_post_vms_save_settings', 'vms_save_settings_callback');
add_action('admin_enqueue_scripts', 'vms_enqueue_admin_assets');
add_action('wp_ajax_vms_toggle_dev_mode', 'vms_toggle_dev_mode_callback');
add_action('wp_ajax_vms_flush_permalinks', 'vms_flush_permalinks_callback');

/**
 * Add settings page under Settings menu.
 */
function vms_add_admin_menu() {
    add_options_page(
        __('Verstka Backend Settings', 'verstka-backend'),
        __('Verstka Backend', 'verstka-backend'),
        'manage_options',
        'verstka-backend-settings',
        'vms_render_settings_page'
    );
}

/**
 * Register plugin settings and fields.
 */
function vms_register_settings() {
    register_setting('vms_settings_group', 'vms_api_key', array('sanitize_callback' => 'sanitize_text_field'));
    register_setting('vms_settings_group', 'vms_images_source', array('sanitize_callback' => 'sanitize_text_field'));
    register_setting('vms_settings_group', 'vms_images_dir', array('sanitize_callback' => 'sanitize_text_field'));
    register_setting('vms_settings_group', 'vms_dev_mode', array('sanitize_callback' => 'vms_sanitize_dev_mode'));
    register_setting('vms_settings_group', 'vms_secret', array('sanitize_callback' => 'sanitize_text_field'));
    register_setting('vms_settings_group', 'vms_desktop_width', array('sanitize_callback' => 'sanitize_text_field'));
    register_setting('vms_settings_group', 'vms_mobile_width', array('sanitize_callback' => 'sanitize_text_field'));

    add_settings_section(
        'vms_main_section',
        __('Main Settings', 'verstka-backend'),
        function() { echo __('Configure the Verstka Backend plugin settings.', 'verstka-backend'); },
        'verstka-backend-settings'
    );

    add_settings_field(
        'vms_api_key',
        __('API Key', 'verstka-backend'),
        'vms_render_api_key_field',
        'verstka-backend-settings',
        'vms_main_section'
    );
    add_settings_field(
        'vms_secret',
        __('Secret', 'verstka-backend'),
        'vms_render_secret_field',
        'verstka-backend-settings',
        'vms_main_section'
    );
    add_settings_field(
        'vms_images_source',
        __('Images Source Host', 'verstka-backend'),
        'vms_render_images_source_field',
        'verstka-backend-settings',
        'vms_main_section'
    );
    add_settings_field(
        'vms_images_dir',
        __('Images Directory', 'verstka-backend'),
        'vms_render_images_dir_field',
        'verstka-backend-settings',
        'vms_main_section'
    );
    add_settings_field(
        'vms_dev_mode',
        __('Dev Mode', 'verstka-backend'),
        'vms_render_dev_mode_field',
        'verstka-backend-settings',
        'vms_main_section'
    );
}

/**
 * Render the settings page template.
 */
function vms_render_settings_page() {
    // Check if API key is saved to conditionally display Save button
    $saved_key = get_option('vms_api_key');
    
    // REST API Diagnostic
    $rest_test_url = rest_url('verstka/v1/test');
    $callback_url = rest_url('verstka/v1/callback');
    ?>
    <div class="wrap">
        <h1><?php echo esc_html__('Verstka Backend Settings', 'verstka-backend'); ?></h1>
        
        <!-- REST API Diagnostics Section -->
        <div class="notice notice-info" style="padding: 15px; margin: 20px 0;">
            <h3><?php _e('REST API Diagnostics', 'verstka-backend'); ?></h3>
            <p><strong><?php _e('Test URL:', 'verstka-backend'); ?></strong> <a href="<?php echo esc_url($rest_test_url); ?>" target="_blank"><?php echo esc_html($rest_test_url); ?></a></p>
            <p><strong><?php _e('Callback URL:', 'verstka-backend'); ?></strong> <?php echo esc_html($callback_url); ?></p>
            <p>
                <button type="button" id="vms-test-api" class="button button-secondary"><?php _e('Test REST API', 'verstka-backend'); ?></button>
                <button type="button" id="vms-flush-permalinks" class="button button-secondary"><?php _e('Reset Permalinks', 'verstka-backend'); ?></button>
                <span id="vms-api-status"></span>
            </p>
        </div>
        
        <form action="options.php" method="post">
            <?php
            settings_fields('vms_settings_group');
            do_settings_sections('verstka-backend-settings');
            if (! $saved_key) {
                submit_button(__('Save Credentials', 'verstka-backend'));
            }
            ?>
        </form>
        <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post" style="display:inline-block; margin-left:10px;">
            <?php wp_nonce_field('vms_reset_api_key_action', 'vms_reset_api_key_nonce'); ?>
            <input type="hidden" name="action" value="vms_reset_api_key">
            <?php submit_button(__('Reset', 'verstka-backend'), 'secondary', 'vms_reset_submit', false); ?>
        </form>
        <h2><?php _e('Widths', 'verstka-backend'); ?></h2>
        <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post">
            <?php wp_nonce_field('vms_save_settings_action', 'vms_save_settings_nonce'); ?>
            <table class="form-table">
                <tr>
                    <th><?php _e('Desktop Width', 'verstka-backend'); ?></th>
                    <td><input type="text" name="vms_desktop_width" value="<?php echo esc_attr(get_option('vms_desktop_width') ?: '960'); ?>" class="regular-text" placeholder="960" /></td>
                </tr>
                <tr>
                    <th><?php _e('Mobile Width', 'verstka-backend'); ?></th>
                    <td><input type="text" name="vms_mobile_width" value="<?php echo esc_attr(get_option('vms_mobile_width') ?: '320'); ?>" class="regular-text" placeholder="320" /></td>
                </tr>
                <tr>
                    <th><?php _e('Fonts CSS URL', 'verstka-backend'); ?></th>
                    <td><input type="text" name="vms_fonts_css_url" value="<?php echo esc_attr(get_option('vms_fonts_css_url') ?: ''); ?>" class="regular-text" placeholder="/vms_fonts.css" /></td>
                </tr>
                <tr>
                    <th><?php _e('Observe Selector', 'verstka-backend'); ?></th>
                    <td><input type="text" name="vms_observe_selector" value="<?php echo esc_attr(get_option('vms_observe_selector') ?: ''); ?>" class="regular-text" placeholder=".banner" /></td>
                </tr>
            </table>
            <input type="hidden" name="action" value="vms_save_settings">
            <?php submit_button(__('Save Settings', 'verstka-backend')); ?>
        </form>
        
        <script>
        jQuery(document).ready(function($) {
            // Test REST API
            $('#vms-test-api').click(function() {
                var button = $(this);
                var status = $('#vms-api-status');
                
                button.prop('disabled', true).text('<?php _e('Testing...', 'verstka-backend'); ?>');
                status.html('<span style="color: orange;">Testing...</span>');
                
                $.get('<?php echo esc_url($rest_test_url); ?>')
                    .done(function(data) {
                        status.html('<span style="color: green;">✓ REST API working correctly</span>');
                        console.log('REST API Test:', data);
                    })
                    .fail(function(xhr) {
                        status.html('<span style="color: red;">✗ REST API error: ' + xhr.status + ' ' + xhr.statusText + '</span>');
                        console.error('REST API Test failed:', xhr);
                    })
                    .always(function() {
                        button.prop('disabled', false).text('<?php _e('Test REST API', 'verstka-backend'); ?>');
                    });
            });
            
            // Flush permalinks
            $('#vms-flush-permalinks').click(function() {
                var button = $(this);
                var status = $('#vms-api-status');
                
                button.prop('disabled', true).text('<?php _e('Resetting...', 'verstka-backend'); ?>');
                status.html('<span style="color: orange;">Resetting permalinks...</span>');
                
                $.post(ajaxurl, {
                    action: 'vms_flush_permalinks',
                    nonce: '<?php echo wp_create_nonce('vms_flush_permalinks'); ?>'
                })
                .done(function(data) {
                    if (data.success) {
                        status.html('<span style="color: green;">✓ Permalinks reset successfully</span>');
                        // Auto-test API after flush
                        setTimeout(function() {
                            $('#vms-test-api').click();
                        }, 1000);
                    } else {
                        status.html('<span style="color: red;">✗ Error: ' + data.data.message + '</span>');
                    }
                })
                .fail(function(xhr) {
                    status.html('<span style="color: red;">✗ AJAX error: ' + xhr.status + '</span>');
                })
                .always(function() {
                    button.prop('disabled', false).text('<?php _e('Reset Permalinks', 'verstka-backend'); ?>');
                });
            });
        });
        </script>
    </div>
    <?php
}

/**
 * Render API Key field.
 */
function vms_render_api_key_field() {
    $val = get_option('vms_api_key', '197b094891a44993b6be96edbcdb9dbc');
    $saved_key = get_option('vms_api_key');
    $disabled  = $saved_key ? 'disabled' : '';
    printf('<input type="text" name="vms_api_key" value="%s" class="regular-text" %s />', esc_attr($val), $disabled);
}

/**
 * Render Secret field.
 */
function vms_render_secret_field() {
    $val      = get_option('vms_secret', '');
    $saved_key = get_option('vms_api_key');
    $disabled = $saved_key ? 'disabled' : '';
    printf(
        '<input type="password" name="vms_secret" value="%s" class="regular-text" %s />',
        esc_attr($val),
        $disabled
    );
}

/**
 * Render Images Source field.
 */
function vms_render_images_source_field() {
    // Default to the current host if option not set
    $default = parse_url(home_url(), PHP_URL_HOST);
    $val     = get_option('vms_images_source', $default);
    $saved_key = get_option('vms_api_key');
    $disabled  = $saved_key ? 'disabled' : '';
    printf('<input type="text" name="vms_images_source" value="%s" class="regular-text" %s />', esc_attr($val), $disabled);
}

/**
 * Render Images Directory field.
 */
function vms_render_images_dir_field() {
    $upload = wp_upload_dir();
    $default = parse_url($upload['baseurl'], PHP_URL_PATH) . '/vms/';
    $val = get_option('vms_images_dir', $default);
    $saved_key = get_option('vms_api_key');
    $disabled  = $saved_key ? 'disabled' : '';
    printf('<input type="text" name="vms_images_dir" value="%s" class="regular-text" %s />', esc_attr($val), $disabled);
}

/**
 * Render Dev Mode checkbox.
 */
function vms_render_dev_mode_field() {
    $val = get_option('vms_dev_mode', 0);
    // Add id for proper event binding and label association
    printf(
        '<input type="checkbox" id="vms_dev_mode" name="vms_dev_mode" value="1" %s />',
        checked(1, $val, false)
    );
}

/**
 * Sanitize Dev Mode input.
 */
function vms_sanitize_dev_mode($input) {
    // Convert input to integer and ensure it's either 0 or 1
    return intval($input) === 1 ? 1 : 0;
}

/**
 * Handle API Key reset action.
 */
function vms_reset_api_key_callback() {
    if (!current_user_can('manage_options')) {
        wp_die(__('Permission denied', 'verstka-backend'));
    }
    check_admin_referer('vms_reset_api_key_action', 'vms_reset_api_key_nonce');
    // Reset API key and related settings
    delete_option('vms_api_key');
    delete_option('vms_secret');
    delete_option('vms_images_source');
    delete_option('vms_images_dir');
    wp_redirect(admin_url('options-general.php?page=verstka-backend-settings'));
    exit;
}

/**
 * Handle Save Settings action.
 */
function vms_save_settings_callback() {
    if (! current_user_can('manage_options')) {
        wp_die(__('Permission denied', 'verstka-backend'));
    }
    check_admin_referer('vms_save_settings_action', 'vms_save_settings_nonce');
    $desktop = sanitize_text_field($_POST['vms_desktop_width'] ?? '');
    $mobile  = sanitize_text_field($_POST['vms_mobile_width']  ?? '');
    $fonts_css_url = sanitize_text_field($_POST['vms_fonts_css_url'] ?? '');
    $observe_selector = sanitize_text_field($_POST['vms_observe_selector'] ?? '');
    update_option('vms_desktop_width', $desktop);
    update_option('vms_mobile_width', $mobile);
    update_option('vms_fonts_css_url', $fonts_css_url);
    update_option('vms_observe_selector', $observe_selector);
    wp_redirect(admin_url('options-general.php?page=verstka-backend-settings'));
    exit;
}

/**
 * Enqueue admin script for Dev Mode auto-save.
 *
 * @param string $hook The current admin page.
 */
function vms_enqueue_admin_assets($hook) {
    $version = '1.2.4';
    
    // Подключаем CSS для всех админ страниц
    wp_enqueue_style('vms-admin-style', plugin_dir_url(__FILE__) . 'assets/css/vms_admin.css', array(), $version);
    
    // Settings page scripts
    if ($hook === 'settings_page_verstka-backend-settings') {
        wp_enqueue_script('vms-admin-script', plugin_dir_url(__FILE__) . 'assets/js/vms_settings.js', array('jquery'), $version, true);
        wp_localize_script('vms-admin-script', 'vmsSettings', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('vms_dev_mode_nonce'),
        ));
    }
    // Posts list toggle script
    if ($hook === 'edit.php') {
        wp_enqueue_script('vms-toggle-script', plugin_dir_url(__FILE__) . 'assets/js/vms_toggle.js', array('jquery'), '1.0.0', true);
        wp_localize_script('vms-toggle-script', 'vmsToggle', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
        ));
    }
}

/**
 * AJAX callback to toggle Dev Mode.
 */
function vms_toggle_dev_mode_callback() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => __('Permission denied', 'verstka-backend')));
    }
    check_ajax_referer('vms_dev_mode_nonce', 'security');
    $dev_mode = isset($_POST['dev_mode']) && '1' == $_POST['dev_mode'] ? 1 : 0;
    // Ensure option exists: add if not present
    $existing = get_option('vms_dev_mode', null);
    if ($existing === null) {
        $added = add_option('vms_dev_mode', $dev_mode, '', 'yes');
        $updated = $added ? true : false;
    } else {
        $updated = update_option('vms_dev_mode', $dev_mode);
    }
    $saved = get_option('vms_dev_mode', 0);
    wp_send_json_success(array(
        'dev_mode' => $dev_mode,
        'updated'  => $updated,
        'saved'    => $saved,
    ));
}

/**
 * AJAX callback to flush permalinks.
 */
function vms_flush_permalinks_callback() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => __('Permission denied', 'verstka-backend')));
    }
    check_ajax_referer('vms_flush_permalinks', 'nonce');
    // Flush rewrite rules
    flush_rewrite_rules();
    wp_send_json_success(array('message' => __('Permalinks flushed successfully', 'verstka-backend')));
}

/*
    Добавляет в список статей колонку Ѵ признак что это cтатья из verstka
*/
add_filter('manage_edit-post_columns', 'add_post_isvms_column', 4);
add_filter('manage_edit-page_columns', 'add_post_isvms_column', 4);

/**
 * Add VMS column for posts and pages.
 *
 * @param array $columns Columns list.
 * @return array Modified columns with VMS flag.
 */
function add_post_isvms_column($columns) {
    $result = [];
    foreach ($columns as $name => $value) {
        if ('title' === $name) {
            $result['post_isvms'] = 'Ѵ';
        }
        $result[$name] = $value;
    }
    return $result;
}

/*
   Отображает закрашенную звездочку в колонке Ѵ если это статья из verstka
*/
add_filter('manage_post_posts_custom_column', 'fill_post_isvms_column', 5, 2);
add_filter('manage_page_posts_custom_column', 'fill_post_isvms_column', 5, 2);

/**
 * Render VMS toggle star in column for posts and pages.
 *
 * @param string $column_name Column name.
 * @param int $post_id Post ID.
 */
function fill_post_isvms_column($column_name, $post_id) {
    if ('post_isvms' !== $column_name) {
        return;
    }
    $post = get_post($post_id);
    $star = $post->post_isvms == 1 ? '&#9733;' : '&#9734;';
    $nonce = wp_create_nonce('vms_toggle_vms');
    printf(
        '<a href="#" class="vms-toggle" data-post-id="%d" data-nonce="%s" title="%s">%s</a>',
        $post_id,
        esc_attr($nonce),
        esc_attr__('Toggle VMS', 'verstka-backend'),
        $star
    );
}

// CSS стили теперь подключаются через отдельный файл vms_admin.css

// Register hidden editor page for Verstka
add_action('admin_menu', 'vms_add_editor_page');
/**
 * Register a hidden admin page to render VMS editor.
 */
function vms_add_editor_page() {
    add_submenu_page(
        null,
        __('VMS Editor', 'verstka-backend'),
        __('VMS Editor', 'verstka-backend'),
        'edit_posts',
        'vms-editor',
        'vms_editor_open'
    );
}

// Add custom row actions for Verstka editing
add_filter('post_row_actions', 'vms_add_row_actions', 10, 2);
// Also add actions for pages
add_filter('page_row_actions', 'vms_add_row_actions', 10, 2);
/**
 * Add "Edit in Verstka Desktop" and "Edit in Mobile" links to post row actions.
 */
function vms_add_row_actions($actions, $post) {
    if ( in_array($post->post_type, array('post','page'), true) ) {
        // URL for desktop editing via hidden vms-editor page
        $desktop_url = add_query_arg(
            array('page' => 'vms-editor', 'mode' => 'desktop', 'post' => $post->ID),
            admin_url('admin.php')
        );
        // URL for mobile editing via hidden vms-editor page
        $mobile_url  = add_query_arg(
            array('page' => 'vms-editor', 'mode' => 'mobile', 'post' => $post->ID),
            admin_url('admin.php')
        );
        $actions['vms_edit_desktop'] = sprintf(
            '<a href="%s">%s</a>',
            esc_url($desktop_url),
            'Verstka [ D'
        );
        $actions['vms_edit_mobile'] = sprintf(
            '<a href="%s">%s</a>',
            esc_url($mobile_url),
            'M ]'
        );
    }
    return $actions;
}

add_filter('manage_edit-post_sortable_columns', 'verstka_sortable_vms_column');
// Make the "Ѵ" column sortable by the post_isvms field
function verstka_sortable_vms_column($columns) {
    $columns['post_isvms'] = 'post_isvms';
    return $columns;
}

/**
 * Apply sorting by VMS flag on posts and pages list.
 *
 * @param WP_Query $query The current query object.
 */
function verstka_vms_orderby($query) {
    // Only modify admin queries
    if (! is_admin()) {
        return;
    }
    // Only apply when ordering by our VMS column
    if ('post_isvms' !== $query->get('orderby')) {
        return;
    }
    // Ensure for posts or pages lists
    $post_type = $query->get('post_type');
    if (! in_array($post_type, array('post', 'page'), true)) {
        return;
    }
    // Set custom orderby
    $query->set('orderby', 'post_isvms');
    $order = strtoupper($query->get('order')) === 'ASC' ? 'ASC' : 'DESC';
    $query->set('order', $order);
}

// AJAX endpoint to toggle VMS flag
add_action('wp_ajax_vms_toggle_vms', 'vms_ajax_toggle_vms');
function vms_ajax_toggle_vms() {
    check_ajax_referer('vms_toggle_vms', 'nonce');
    if (!current_user_can('edit_posts')) {
        wp_send_json_error(array('message' => __('Permission denied', 'verstka-backend')));
    }
    $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
    if (!$post_id) {
        wp_send_json_error(array('message' => __('Invalid post ID', 'verstka-backend')));
    }
    global $wpdb;
    $current = $wpdb->get_var($wpdb->prepare("SELECT post_isvms FROM {$wpdb->posts} WHERE ID = %d", $post_id));
    $new = $current ? 0 : 1;
    $wpdb->update(
        $wpdb->posts,
        array('post_isvms' => $new),
        array('ID' => $post_id)
    );
    clean_post_cache($post_id);
    wp_send_json_success(array('post_isvms' => $new));
}

// Support sorting by VMS flag on posts and pages
add_action('pre_get_posts', 'verstka_vms_orderby');

// Replace default edit post link with Verstka desktop/mobile buttons for VMS posts
add_filter('edit_post_link', 'vms_replace_edit_post_link', 10, 3);
function vms_replace_edit_post_link($link, $post_id, $text) {
    $post = get_post($post_id);
    if (!empty($post->post_isvms)) {
        $desktop_url = add_query_arg(
            array('page' => 'vms-editor', 'mode' => 'desktop', 'post' => $post_id),
            admin_url('admin.php')
        );
        $mobile_url = add_query_arg(
            array('page' => 'vms-editor', 'mode' => 'mobile', 'post' => $post_id),
            admin_url('admin.php')
        );
        $desktop_label = __('Desktop', 'verstka-backend');
        $mobile_label  = __('Mobile', 'verstka-backend');
        return sprintf(
            '<a href="%s" class="vms-edit-button vms-edit-desktop">%s</a> <a href="%s" class="vms-edit-button vms-edit-mobile">%s</a>',
            esc_url($desktop_url),
            esc_html($desktop_label),
            esc_url($mobile_url),
            esc_html($mobile_label)
        );
    }
    return $link;
}

add_action('enqueue_block_editor_assets', 'vms_enqueue_block_editor_buttons');
function vms_enqueue_block_editor_buttons() {
    // Get the post ID being edited from the query parameter
    $post_id = isset($_GET['post']) ? intval($_GET['post']) : 0;
    if (!$post_id) {
        return;
    }
    
    $post = get_post($post_id);
    
    $desktop_url = add_query_arg(
        array('page' => 'vms-editor', 'mode' => 'desktop', 'post' => $post_id),
        admin_url('admin.php')
    );
    $mobile_url = add_query_arg(
        array('page' => 'vms-editor', 'mode' => 'mobile', 'post' => $post_id),
        admin_url('admin.php')
    );
    wp_enqueue_script(
        'vms-block-editor',
        plugin_dir_url(__FILE__) . 'assets/js/vms_block_editor.js',
        array('wp-plugins', 'wp-edit-post', 'wp-components', 'wp-element', 'wp-i18n'),
        '1.2.4',
        true
    );
    wp_localize_script(
        'vms-block-editor',
        'vmsBlockEditor',
        array(
            'desktopUrl' => esc_url($desktop_url),
            'mobileUrl'  => esc_url($mobile_url),
        )
    );
}

add_action('media_buttons', 'vms_add_classic_editor_buttons', 11);
function vms_add_classic_editor_buttons($editor_id) {
    global $post;
    
    $desktop_url = add_query_arg(
        array('page' => 'vms-editor', 'mode' => 'desktop', 'post' => $post->ID),
        admin_url('admin.php')
    );
    $mobile_url = add_query_arg(
        array('page' => 'vms-editor', 'mode' => 'mobile', 'post' => $post->ID),
        admin_url('admin.php')
    );
    printf(
        '<a href="%s" class="button vms-edit-desktop" target="_blank">%s</a> ',
        esc_url($desktop_url), esc_html__('Verstka Desktop', 'verstka-backend')
    );
    printf(
        '<a href="%s" class="button vms-edit-mobile" target="_blank">%s</a>',
        esc_url($mobile_url), esc_html__('Mobile', 'verstka-backend')
    );
}

// Enqueue Verstka API script for front-end articles
// add_action('wp_enqueue_scripts', 'vms_enqueue_frontend_script');
// function vms_enqueue_frontend_script() {
//     wp_enqueue_script('verstka-api', 'https://go.verstka.org/api.js', [], null, true);
// }

// Enqueue Verstka critical CSS with high priority
add_action('wp_head', 'vms_enqueue_critical_css', 1);
function vms_enqueue_critical_css()
{
    ?>
    <link rel="stylesheet" href="https://go.verstka.org/critical.css" type="text/css" media="all">
    <?php
}

// Enqueue Verstka API script with low priority
add_action('wp_head', 'vms_enqueue_api_script');
function vms_enqueue_api_script()
{
    ?>
    <script src="https://go.verstka.org/api.js" async type="text/javascript"></script>
    <?php
}

// Add meta viewport tag for Verstka articles
add_action('wp_head', 'vms_add_viewport_meta');
function vms_add_viewport_meta() {
    if ( is_singular('post') ) {
        $post = get_queried_object();
        if ( ! empty( $post->post_isvms ) ) {
            echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
        }
    }
}

/**
 * Replace article content with the appropriate version
 */
add_filter('the_content', 'apply_vms_content_after', 9999);
function apply_vms_content_after($content)
{
    $post = get_post();
    $post_id = $post ? $post->ID : 0;

	if ($post->post_isvms != 1) { // it's not an Verstka article
        return $content;
	}

	if (post_password_required($post)) { // in case of post password protected
		return $content;
	}

    $mobile = empty($post->post_vms_content_mobile) ? $post->post_vms_content : $post->post_vms_content_mobile;

    $desktop = base64_encode($post->post_vms_content);
    $mobile = base64_encode($mobile);
	
    // Get observe_selector from settings
    $observe_selector = get_option('vms_observe_selector', '');
    $observe_selector_line = !empty($observe_selector) ? ",\n\t\t\t\tobserve_selector: '{$observe_selector}'" : '';
    
    $content = "<div class=\"verstka-article-{$post_id}\">{$post->post_vms_content}</div>
		<script type=\"text/javascript\" id=\"verstka-init\">
		window.onVMSAPIReady = function (api) {
			api.Article.enable({
				display_mode: 'desktop'{$observe_selector_line}
			});

		};

		function decodeHtml(base64) {
		    return decodeURIComponent(atob(base64).split('').map(function(c) {return '%' + ('00' + c.charCodeAt(0).toString(16)).slice(-2);}).join(''))
        }

		var htmls_{$post_id} = {
			desktop: decodeHtml(`{$desktop}`),
			mobile: decodeHtml(`{$mobile}`),
		};
		var isMobile = false;
		var prev = null;

		function switchHtml_{$post_id}(html) {
			var article = document.querySelector('.verstka-article-{$post_id}')

			if (window.VMS_API) {
				window.VMS_API.Article.disable()
			}

			article.innerHTML = html;

			if (window.VMS_API) {
				window.VMS_API.Article.enable({display_mode: 'desktop'})
			}
		}

		function onResize_{$post_id}() {
			var w = document.documentElement.clientWidth;

			isMobile = w < 768;

			if (prev !== isMobile) {
				prev = isMobile
				switchHtml_{$post_id}(htmls_{$post_id}[isMobile ? 'mobile' : 'desktop'])
			}
		}

		onResize_{$post_id}()

		window.addEventListener('resize', onResize_{$post_id});

	</script>";

    return $content;
}

/**
 * cURL GET request wrapper
 *
 * @param string $url The URL to request
 * @param int $timeout Timeout in seconds
 * @return array|false Response array with 'body' and 'http_code' keys or false on failure
 */
function vms_curl_get($url, $timeout = 30) {
    $ch = curl_init();
    $is_dev_mode = get_option('vms_dev_mode', 0);
    
    $curl_options = [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_USERAGENT => 'verstka wordpress 1.2',
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
    ];
    
    // Отключаем SSL проверку только в dev режиме
    if ($is_dev_mode) {
        $curl_options[CURLOPT_SSL_VERIFYPEER] = false;
        $curl_options[CURLOPT_SSL_VERIFYHOST] = false;
    }
    
    curl_setopt_array($ch, $curl_options);
    
    $body = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($body === false || !empty($error)) {
        return false;
    }
    
    return [
        'body' => $body,
        'http_code' => $http_code
    ];
}

/**
 * cURL POST request wrapper
 *
 * @param string $url The URL to request
 * @param array $data Post data
 * @param int $timeout Timeout in seconds
 * @return array|false Response array with 'body' and 'http_code' keys or false on failure
 */
function vms_curl_post($url, $data = [], $timeout = 30) {
    $ch = curl_init();
    $is_dev_mode = get_option('vms_dev_mode', 0);
    
    $curl_options = [
        CURLOPT_URL => $url,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($data),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_USERAGENT => 'verstka wordpress 1.2',
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/x-www-form-urlencoded'
        ],
    ];
    
    // Отключаем SSL проверку только в dev режиме
    if ($is_dev_mode) {
        $curl_options[CURLOPT_SSL_VERIFYPEER] = false;
        $curl_options[CURLOPT_SSL_VERIFYHOST] = false;
    }
    
    curl_setopt_array($ch, $curl_options);
    
    $body = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($body === false || !empty($error)) {
        return false;
    }
    
    return [
        'body' => $body,
        'http_code' => $http_code
    ];
}

/**
 * cURL file download wrapper
 *
 * @param string $url The URL to download from
 * @param string $file_path Local file path to save to
 * @param int $timeout Timeout in seconds
 * @return array|false Response array with 'http_code' key or false on failure
 */
function vms_curl_download($url, $file_path, $timeout = 60) {
    $ch = curl_init();
    $fp = fopen($file_path, 'w+');
    $is_dev_mode = get_option('vms_dev_mode', 0);
    
    if (!$fp) {
        return false;
    }
    
    $curl_options = [
        CURLOPT_URL => $url,
        CURLOPT_FILE => $fp,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_USERAGENT => 'verstka wordpress 1.2',
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
    ];
    
    // Отключаем SSL проверку только в dev режиме
    if ($is_dev_mode) {
        $curl_options[CURLOPT_SSL_VERIFYPEER] = false;
        $curl_options[CURLOPT_SSL_VERIFYHOST] = false;
    }
    
    curl_setopt_array($ch, $curl_options);
    
    $result = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    
    curl_close($ch);
    fclose($fp);
    
    if ($result === false || !empty($error) || $http_code !== 200) {
        unlink($file_path); // Remove incomplete file
        return false;
    }
    
    return [
        'http_code' => $http_code,
        'file_path' => $file_path
    ];
}

/**
 * Multi-threaded cURL file download wrapper
 *
 * @param array $downloads Array of downloads with 'url', 'path', 'filename' keys
 * @param int $timeout Timeout in seconds
 * @param int $max_concurrent Maximum concurrent connections (default: 20)
 * @return array Array of results with success status and details
 */
function vms_curl_download_multiple($downloads, $timeout = 60, $max_concurrent = 20) {
    if (empty($downloads)) {
        return [];
    }
    
    $is_dev_mode = get_option('vms_dev_mode', 0);
    $all_results = [];
    
    // Process downloads in batches of max_concurrent
    $download_batches = array_chunk($downloads, $max_concurrent, true);
    $batch_count = count($download_batches);
    
    foreach ($download_batches as $batch_index => $batch) {
        if ($is_dev_mode) {
            error_log(sprintf('VMS: Processing batch %d/%d with %d files', 
                $batch_index + 1, $batch_count, count($batch)));
        }
        
        $batch_results = vms_curl_download_batch($batch, $timeout, $is_dev_mode);
        $all_results = array_merge($all_results, $batch_results);
        
        if ($is_dev_mode) {
            $batch_stats = vms_get_download_stats($batch_results);
            error_log(sprintf('VMS: Batch %d/%d completed - Success: %d, Failed: %d, Size: %s MB', 
                $batch_index + 1, $batch_count, 
                $batch_stats['successful'], $batch_stats['failed'], 
                $batch_stats['total_size_mb']));
        }
    }
    
    return $all_results;
}

/**
 * Process a single batch of downloads
 *
 * @param array $downloads Batch of downloads to process
 * @param int $timeout Timeout in seconds
 * @param bool $is_dev_mode Development mode flag
 * @return array Array of results for this batch
 */
function vms_curl_download_batch($downloads, $timeout, $is_dev_mode) {
    $multi_handle = curl_multi_init();
    $curl_handles = [];
    $file_handles = [];
    $results = [];
    
    // Set maximum concurrent connections
    curl_multi_setopt($multi_handle, CURLMOPT_MAXCONNECTS, count($downloads));
    
    // Initialize all cURL handles for this batch
    foreach ($downloads as $index => $download) {
        $ch = curl_init();
        $fp = fopen($download['path'], 'w+');
        
        if (!$fp) {
            $results[$index] = [
                'success' => false,
                'url' => $download['url'],
                'path' => $download['path'],
                'filename' => $download['filename'],
                'error' => 'Failed to open file for writing: ' . $download['path']
            ];
            continue;
        }
        
        $curl_options = [
            CURLOPT_URL => $download['url'],
            CURLOPT_FILE => $fp,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT => 'verstka wordpress 1.2',
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
        ];
        
        // Отключаем SSL проверку только в dev режиме
        if ($is_dev_mode) {
            $curl_options[CURLOPT_SSL_VERIFYPEER] = false;
            $curl_options[CURLOPT_SSL_VERIFYHOST] = false;
        }
        
        curl_setopt_array($ch, $curl_options);
        curl_multi_add_handle($multi_handle, $ch);
        
        $curl_handles[$index] = $ch;
        $file_handles[$index] = $fp;
    }
    
    // Execute all handles
    $running = null;
    do {
        curl_multi_exec($multi_handle, $running);
        curl_multi_select($multi_handle);
    } while ($running > 0);
    
    // Collect results
    foreach ($curl_handles as $index => $ch) {
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        $download = $downloads[$index];
        
        curl_multi_remove_handle($multi_handle, $ch);
        curl_close($ch);
        fclose($file_handles[$index]);
        
        if (!empty($error) || $http_code !== 200) {
            // Remove incomplete file
            if (file_exists($download['path'])) {
                unlink($download['path']);
            }
            
            $results[$index] = [
                'success' => false,
                'url' => $download['url'],
                'path' => $download['path'],
                'filename' => $download['filename'],
                'http_code' => $http_code,
                'error' => !empty($error) ? $error : "HTTP error: $http_code"
            ];
        } else {
            $results[$index] = [
                'success' => true,
                'url' => $download['url'],
                'path' => $download['path'],
                'filename' => $download['filename'],
                'http_code' => $http_code,
                'file_size' => filesize($download['path'])
            ];
        }
    }
    
    curl_multi_close($multi_handle);
    
    return $results;
}

/**
 * Get statistics for multi-threaded downloads
 *
 * @param array $results Results from vms_curl_download_multiple
 * @return array Statistics summary
 */
function vms_get_download_stats($results) {
    $total = count($results);
    $successful = array_filter($results, function($r) { return $r['success']; });
    $failed = array_filter($results, function($r) { return !$r['success']; });
    
    $total_size = array_sum(array_column($successful, 'file_size'));
    
    return [
        'total_files' => $total,
        'successful' => count($successful),
        'failed' => count($failed),
        'total_size_bytes' => $total_size,
        'total_size_mb' => round($total_size / 1024 / 1024, 2),
        'success_rate' => $total > 0 ? round((count($successful) / $total) * 100, 1) : 0
    ];
}