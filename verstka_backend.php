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
    $version = '1.2.0';
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
 * Open the VMS editor session.
 */
function vms_editor_open() {
    if (! current_user_can('edit_posts')) {
        wp_die(__('Permission denied', 'verstka-backend'));
    }
    $user_id      = get_current_user_id();
    $material_id = isset($_GET['post']) ? intval($_GET['post']) : 0;
    $mode    = isset($_GET['mode']) && in_array($_GET['mode'], array('desktop','mobile'), true)
               ? sanitize_key($_GET['mode'])
               : 'desktop';
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
    // Send x-www-form-urlencoded request via Requests library
    if ( ! class_exists( '\WpOrg\Requests\Requests' ) ) {
        require_once ABSPATH . WPINC . '/Requests/Requests.php';
        \WpOrg\Requests\Requests::register_autoloader();
    }

    // Set html_body based on mode using actual table columns
    if ( 'desktop' === $mode ) {
        $html_body = isset( $post->post_vms_content ) ? $post->post_vms_content : '';
        $post_width = get_option('vms_desktop_width');
    } else {
        $html_body = isset( $post->post_vms_content_mobile ) ? $post->post_vms_content_mobile : '';
        $post_width = get_option('vms_mobile_width');
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

    $options = array(
        'timeout'     => 90,
        'data_format' => 'body',
    );

    try {
        $res = \WpOrg\Requests\Requests::post( $endpoint, array(), $form_fields, $options );
    } catch ( \Exception $e ) {
        echo '<script>window.onload=function(){alert(' . json_encode( $e->getMessage() ) . ');history.back();};</script>';
        return;
    }
    $code = $res->status_code;
    if ( $code !== 200 ) {
        $message = sprintf('%s '. __( 'HTTP error: %d', 'verstka-backend' ), $endpoint,$code );
        echo '<script>window.onload=function(){alert(' . json_encode( $message ) . ');history.back();};</script>';
        return;
    }
    $data = json_decode( $res->body, true );

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
    // Инициализируем массив запросов
    $requests = [];
    // Получаем параметры запроса: сначала JSON, затем тело формы
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

    // Распаковываем JSON в custom_fields, если он есть
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

    // Получаем список файлов по download_url (JSON)
    $download_endpoint = str_replace('http://', 'https://', $data['download_url']);
    // Подключаем и регистрируем автозагрузчик Requests
    if ( ! class_exists( '\WpOrg\Requests\Requests' ) ) {
        require_once ABSPATH . WPINC . '/Requests/Requests.php';
        \WpOrg\Requests\Requests::register_autoloader();
    }
    // Запрашиваем JSON со списком файлов
    try {
        $list_res = \WpOrg\Requests\Requests::get(
            $download_endpoint,
            [],
            [],
            ['timeout' => 60, 'data_format' => 'body']
        );
    } catch (\Exception $e) {
        return formJSON(0, 'File list request failed: ' . $e->getMessage());
    }
    if ($list_res->status_code !== 200) {
        return formJSON(0, sprintf('File list HTTP error: %d', $list_res->status_code));
    }
    $list_data = json_decode($list_res->body, true);
    if (empty($list_data['data']) || !is_array($list_data['data'])) {
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

    // Формируем массив URL для скачивания
    if ( isset($list_data['data']) && is_array($list_data['data']) ) {
        foreach ($list_data['data'] as $image) {
            $requests[$image] = [
                'url'     => sprintf('%s/%s', $download_endpoint, $image),
                'type'    => 'GET',
                'options' => [
                    'timeout'         => 60,
                    'connect_timeout' => 3.14,
                    'useragent'       => 'verstka wordpress 1.2',
                    'stream'          => true,
                    'filename'        => trailingslashit($images_abs) . basename($image),
                ],
            ];
        }
    }

    // Выполняем параллельные запросы и замеряем время
    $start_time = microtime(true);
    $results = \WpOrg\Requests\Requests::request_multiple($requests);
    foreach ( $results as $result ) {
        if ( $result->status_code !== 200 ) {
            return formJSON( 0, sprintf( 'Download %s HTTP error: %d', $result->url, $result->status_code ) );
        }
    }

    $source = str_replace('/vms_images/', sprintf('%s/', $images_rel), $data['html_body']);


    $db_data = ['post_isvms' => 1];
    if ( $is_mobile ) {
        $db_data['post_vms_content_mobile'] = $source;
    } else {
        $db_data['post_vms_content'] = $source;
    }
    // Устанавливаем флаг VMS
    global $wpdb;
    $wpdb->update(
        $wpdb->posts,
        $db_data,
        array('ID' => $data['material_id'])
    );
    // Сбросим кэш поста
    clean_post_cache( $data['material_id'] );
    if (function_exists('wp_cache_clear_cache')) {
        wp_cache_clear_cache();
    }

    // Формируем метрики: суммарное и реальное время
    $time_real = microtime(true) - $start_time;
    if ( $is_debug ) {
        return formJSON( 1, 'Success', array(
            'images_list' => $images_list,
            'time_real'   => $time_real,
            'results'     => $results,
            'data'        => $data,
        ) );
    }
    return formJSON( 1, 'Success', array(
        'time_real'   => $time_real,
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
    register_setting('vms_settings_group', 'vms_secret', array('sanitize_callback' => 'sanitize_text_field'));

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
 * Enqueue admin script for Dev Mode auto-save.
 *
 * @param string $hook The current admin page.
 */
function vms_enqueue_admin_assets($hook) {
    $version = '1.0.0';
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

/*
    Добавляет в список статей колонку Ѵ признак что это cтатья из verstka
*/
add_filter('manage_edit-post_columns', 'add_is_vms_column', 4);
function add_is_vms_column($columns)
{
    $result = [];
    foreach ($columns as $name => $value) {
        if ($name == 'title') {
            $result['is_vms'] = 'Ѵ';
        }
        $result[$name] = $value;
    }

    return $result;
}

/*
   Отображает закрашенную звездочку в колонке Ѵ если это статья из verstka
*/
add_filter('manage_post_posts_custom_column', 'fill_is_vms_column', 5, 2); // wp-admin/includes/class-wp-posts-list-table.php
function fill_is_vms_column($column_name, $post_id) {
    if ('is_vms' !== $column_name) {
        return;
    }
    $post = get_post($post_id);
    // Clickable star to toggle VMS flag
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

add_action('admin_head', 'add_is_vms_column_css');
function add_is_vms_column_css()
{
    echo '<style type="text/css">.column-is_vms{width:3%;}</style>';
}

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
/**
 * Add "Редактировать в Verstka Desktop" and "Редактировать в Mobile" links to post row actions.
 *
 * @param array   $actions Existing action links.
 * @param WP_Post $post    Current post object.
 * @return array Modified action links.
 */
function vms_add_row_actions($actions, $post) {
    if ('post' === $post->post_type) {
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
            __('Редактировать в Verstka Desktop', 'verstka-backend')
        );
        $actions['vms_edit_mobile'] = sprintf(
            '<a href="%s">%s</a>',
            esc_url($mobile_url),
            __('Mobile', 'verstka-backend')
        );
    }
    return $actions;
}

/**
 * Подменяет содержимое статьи на нужную версию контента
 */
add_filter('the_content', 'apply_vms_content_after', 9999);
function apply_vms_content_after($content)
{
    $post = get_post();

	if ($post->post_isvms != 1) { // it's not an Verstka article
        return $content;
	}

	if (post_password_required($post)) { // in case of post password protected
		return $content;
	}

    $mobile = empty($post->post_vms_content_mobile) ? $post->post_vms_content : $post->post_vms_content_mobile;

    $desktop = base64_encode($post->post_vms_content);
    $mobile = base64_encode($mobile);
	
    $content = "<div class=\"verstka-article\">{$post->post_vms_content}</div>
		<script type=\"text/javascript\" id=\"verstka-init\">
		window.onVMSAPIReady = function (api) {
			api.Article.enable({
				display_mode: 'desktop'
			});

			document.querySelectorAll('article')[0].classList.add('shown');
		};

		function decodeHtml(base64) {
		    return decodeURIComponent(atob(base64).split('').map(function(c) {return '%' + ('00' + c.charCodeAt(0).toString(16)).slice(-2);}).join(''))
        }

		var htmls = {
			desktop: decodeHtml(`{$desktop}`),
			mobile: decodeHtml(`{$mobile}`),
		};
		var isMobile = false;
		var prev = null;

		function switchHtml(html) {
			var article = document.querySelector('.verstka-article')

			if (window.VMS_API) {
				window.VMS_API.Article.disable()
			}

			article.innerHTML = html;

			if (window.VMS_API) {
				window.VMS_API.Article.enable({display_mode: 'desktop'})
			}
		}

		function onResize() {
			var w = document.documentElement.clientWidth;

			isMobile = w < 768;

			if (prev !== isMobile) {
				prev = isMobile
				switchHtml(htmls[isMobile ? 'mobile' : 'desktop'])
			}
		}

		onResize()

		window.onresize = onResize;

	</script>

	";

    return $content;
}

/**
 * Активирует отображение анимаций
 */
add_action('wp_head', 'add_this_script_footer');
function add_this_script_footer()
{
    $is_debug = get_option('vms_dev_mode', 0); ?>

    <meta name="viewport" content="width=device-width, initial-scale=1">
    <script src="https://<?php echo $is_debug ? 'dev' : 'go'; ?>.verstka.org/api.js" async type="text/javascript"></script>

    <?php
}

// Сделать колонку "Ѵ" сортируемой по столбцу post_isvms
add_filter('manage_edit-post_sortable_columns', 'verstka_sortable_vms_column');
function verstka_sortable_vms_column($columns) {
    $columns['is_vms'] = 'is_vms';
    return $columns;
}

// Поддержка сортировки списка записей по post_isvms
add_action('pre_get_posts', 'verstka_vms_orderby');
function verstka_vms_orderby($query) {
    if (!is_admin() || !$query->is_main_query()) {
        return;
    }
    if ('is_vms' === $query->get('orderby')) {
        $query->set('orderby', 'post_isvms');
        $order = strtoupper($query->get('order')) === 'ASC' ? 'ASC' : 'DESC';
        $query->set('order', $order);
    }
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
