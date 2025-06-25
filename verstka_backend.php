<?php
/*
Plugin Name: Verstka Backend
Plugin URI: https://github.com/verstka/vms_wordpress
Description: plugin for verstka api on Backend.
Version: 1.0.0
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
}

/**
 * Deactivation callback.
 */
function vms_deactivate() {
    // TODO: Add deactivation tasks.
}

/**
 * Initialize plugin: load textdomain
 */
function vms_init() {
    load_plugin_textdomain('verstka-backend', false, dirname(plugin_basename(__FILE__)) . '/languages');
}
add_action('init', 'vms_init');

/**
 * Enqueue front-end scripts and styles
 */
function vms_enqueue_assets() {
    $version = '1.0.0';
    wp_enqueue_style('vms-style', plugin_dir_url(__FILE__) . 'assets/css/vms_wordpress.css', array(), $version);
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
}

/**
 * Callback for verstka API v1.
 *
 * @param \WP_REST_Request $request Request object.
 * @return \WP_REST_Response
 */
function vms_verstka_callback( WP_REST_Request $request ) {
    // Get JSON params from request.
    $data = $request->get_json_params();

    // TODO: Implement callback handling logic.

    // Return success response.
    return rest_ensure_response( array(
        'success' => true,
        'data'    => $data,
    ) );
} 

// /**
//  * @param array $data
//  *
//  * @throws ValidationException
//  */
// function validateArticleData(array $data): void
// {
//     $expectCallbackSign = getRequestSalt(
//         $this->secretKey,
//         $data,
//         'session_id, user_id, material_id, download_url'
//     );
//     if (
//         empty($data['download_url'])
//         || $expectCallbackSign !== $data['callback_sign']
//     ) {
//         throw new ValidationException('invalid callback sign');
//     }
// }

// /**
//  * @param string $secret
//  * @param array  $data
//  * @param string $fields
//  *
//  * @return string
//  */
// function getRequestSalt(string $secret, array $data, string $fields): string
//     {
//         $fields = array_filter(array_map('trim', explode(',', $fields)));
//         $result = $secret;
//         foreach ($fields as $field) {
//             $result .= $data[$field];
//         }

//         return md5($result);
//     }

// Add settings page and register plugin settings
add_action('admin_menu', 'vms_add_admin_menu');
add_action('admin_init', 'vms_register_settings');
add_action('admin_post_vms_reset_api_key', 'vms_reset_api_key_callback');
add_action('admin_enqueue_scripts', 'vms_enqueue_admin_assets');
add_action('wp_ajax_vms_toggle_dev_mode', 'vms_toggle_dev_mode_callback');

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
    ?>
    <div class="wrap">
        <h1><?php echo esc_html__('Verstka Backend Settings', 'verstka-backend'); ?></h1>
        <form action="options.php" method="post">
            <?php
            settings_fields('vms_settings_group');
            do_settings_sections('verstka-backend-settings');
            // Show Save Settings only if API key not set
            if (! $saved_key) {
                submit_button();
            }
            ?>
        </form>
        <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post" style="display:inline-block; margin-left:10px;">
            <?php wp_nonce_field('vms_reset_api_key_action', 'vms_reset_api_key_nonce'); ?>
            <input type="hidden" name="action" value="vms_reset_api_key">
            <?php submit_button(__('Reset', 'verstka-backend'), 'secondary', 'vms_reset_submit', false); ?>
        </form>
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
    $default = parse_url($upload['baseurl'], PHP_URL_PATH) . '/vms';
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
    delete_option('vms_images_source');
    delete_option('vms_images_dir');
    wp_redirect(admin_url('options-general.php?page=verstka-backend-settings'));
    exit;
}

/**
 * Enqueue admin script for Dev Mode auto-save.
 *
 * @param string $hook The current admin page.
 */
function vms_enqueue_admin_assets($hook) {
    if ($hook !== 'settings_page_verstka-backend-settings') {
        return;
    }
    $version = '1.0.0';
    wp_enqueue_script('vms-admin-script', plugin_dir_url(__FILE__) . 'assets/js/vms_settings.js', array('jquery'), $version, true);
    wp_localize_script('vms-admin-script', 'vmsSettings', array(
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce'   => wp_create_nonce('vms_dev_mode_nonce'),
    ));
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

// Inline CSS to reduce vertical spacing on settings page
add_action('admin_head-settings_page_verstka-backend-settings', 'vms_admin_inline_styles');
/**
 * Output inline styles for settings page to reduce vertical spacing.
 */
function vms_admin_inline_styles() {
    ?>
    <style>
        /* Reduce spacing above and below section headers */
        body.settings_page_verstka-backend-settings h2 {
            margin: 0.5em 0 0.2em;
        }
        /* Reduce spacing after settings tables */
        body.settings_page_verstka-backend-settings .form-table {
            margin-bottom: 0.5em;
        }
    </style>
    <?php
}