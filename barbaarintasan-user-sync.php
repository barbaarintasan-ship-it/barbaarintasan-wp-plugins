<?php
/**
 * BSA User Sync - Two-way sync between App and WordPress
 * Included by barbaarintasan.php main plugin
 * 
 * 1. REST endpoint: App notifies WordPress when a new user registers
 * 2. Hook: WordPress notifies App when a new user registers on the website
 */

if (!defined('ABSPATH')) {
    exit;
}

define('BSA_APP_URL', 'https://appbarbaarintasan.com');

add_action('rest_api_init', 'bsa_sync_register_routes');

function bsa_sync_register_routes() {
    register_rest_route('bsa/v1', '/sync-user', array(
        'methods'  => 'POST',
        'callback' => 'bsa_sync_user_from_app',
        'permission_callback' => 'bsa_sync_verify_api_key',
    ));
}

function bsa_sync_verify_api_key($request) {
    $api_key = $request->get_header('X-API-Key');
    $expected = get_option('bsa_sync_api_key', '');
    
    if (empty($expected) || empty($api_key)) {
        return new WP_Error('unauthorized', 'API key not configured or missing', array('status' => 401));
    }
    
    return hash_equals($expected, $api_key);
}

function bsa_sync_user_from_app($request) {
    $params = $request->get_json_params();
    
    $email = sanitize_email($params['email'] ?? '');
    $name  = sanitize_text_field($params['name'] ?? '');
    $phone = sanitize_text_field($params['phone'] ?? '');
    $password_hash = $params['password_hash'] ?? '';
    
    if (empty($email)) {
        return new WP_REST_Response(array('success' => false, 'error' => 'Email required'), 400);
    }
    
    $existing = get_user_by('email', $email);
    if ($existing) {
        return new WP_REST_Response(array(
            'success' => true, 
            'action' => 'already_exists',
            'wp_user_id' => $existing->ID
        ), 200);
    }
    
    $name_parts = explode(' ', $name, 2);
    $first_name = $name_parts[0] ?? '';
    $last_name  = $name_parts[1] ?? '';
    
    $username = sanitize_user(strtolower(str_replace(' ', '', $name)));
    if (empty($username) || username_exists($username)) {
        $username = sanitize_user(strstr($email, '@', true));
    }
    if (username_exists($username)) {
        $username = $username . '_' . wp_rand(100, 999);
    }
    
    $random_pass = wp_generate_password(24, true, true);
    
    $user_id = wp_insert_user(array(
        'user_login'   => $username,
        'user_email'   => $email,
        'user_pass'    => $random_pass,
        'first_name'   => $first_name,
        'last_name'    => $last_name,
        'display_name' => $name,
        'role'         => 'subscriber',
    ));
    
    if (is_wp_error($user_id)) {
        return new WP_REST_Response(array(
            'success' => false, 
            'error' => $user_id->get_error_message()
        ), 500);
    }
    
    if (!empty($password_hash)) {
        update_user_meta($user_id, 'legacy_bcrypt', $password_hash);
    }
    
    if (!empty($phone)) {
        update_user_meta($user_id, 'phone_number', $phone);
    }
    
    update_user_meta($user_id, 'bsa_synced_from_app', true);
    update_user_meta($user_id, 'bsa_sync_date', current_time('mysql'));
    
    return new WP_REST_Response(array(
        'success' => true,
        'action' => 'created',
        'wp_user_id' => $user_id,
        'username' => $username
    ), 201);
}

add_action('user_register', 'bsa_sync_user_to_app', 10, 2);

function bsa_sync_user_to_app($user_id, $userdata = null) {
    if (get_user_meta($user_id, 'bsa_synced_from_app', true)) {
        return;
    }
    
    $user = get_userdata($user_id);
    if (!$user) {
        return;
    }
    
    $api_key = get_option('bsa_sync_api_key', '');
    if (empty($api_key)) {
        error_log('[BSA Sync] API key not configured - skipping sync to app for user: ' . $user->user_email);
        return;
    }
    
    $body = array(
        'email'    => $user->user_email,
        'name'     => $user->display_name ?: ($user->first_name . ' ' . $user->last_name),
        'phone'    => get_user_meta($user_id, 'phone_number', true) ?: '',
        'source'   => 'wordpress',
    );
    
    $response = wp_remote_post(BSA_APP_URL . '/api/wordpress/sync-user', array(
        'headers' => array(
            'Content-Type' => 'application/json',
            'X-API-Key'    => $api_key,
        ),
        'body'    => wp_json_encode($body),
        'timeout' => 15,
    ));
    
    if (is_wp_error($response)) {
        error_log('[BSA Sync] Failed to sync user to app: ' . $response->get_error_message());
        update_user_meta($user_id, 'bsa_sync_to_app_failed', true);
        update_user_meta($user_id, 'bsa_sync_error', $response->get_error_message());
    } else {
        $code = wp_remote_retrieve_response_code($response);
        $result = json_decode(wp_remote_retrieve_body($response), true);
        
        if ($code >= 200 && $code < 300) {
            update_user_meta($user_id, 'bsa_synced_to_app', true);
            update_user_meta($user_id, 'bsa_sync_to_app_date', current_time('mysql'));
            error_log('[BSA Sync] User synced to app: ' . $user->user_email);
        } else {
            error_log('[BSA Sync] App returned error ' . $code . ': ' . wp_json_encode($result));
            update_user_meta($user_id, 'bsa_sync_to_app_failed', true);
        }
    }
}

add_action('admin_menu', 'bsa_sync_admin_menu');

function bsa_sync_admin_menu() {
    add_options_page(
        'BSA Sync Settings',
        'BSA Sync',
        'manage_options',
        'bsa-sync-settings',
        'bsa_sync_settings_page'
    );
}

function bsa_sync_settings_page() {
    if (isset($_POST['bsa_sync_save']) && check_admin_referer('bsa_sync_settings')) {
        update_option('bsa_sync_api_key', sanitize_text_field($_POST['bsa_sync_api_key'] ?? ''));
        echo '<div class="notice notice-success"><p>Settings saved!</p></div>';
    }
    
    $api_key = get_option('bsa_sync_api_key', '');
    ?>
    <div class="wrap">
        <h1>BSA User Sync Settings</h1>
        <p>Configure the API key for syncing users between Barbaarintasan App and WordPress.</p>
        <p><strong>Important:</strong> Use the same API key that is set as <code>WORDPRESS_API_KEY</code> in the app.</p>
        
        <form method="post">
            <?php wp_nonce_field('bsa_sync_settings'); ?>
            <table class="form-table">
                <tr>
                    <th>API Key</th>
                    <td>
                        <input type="text" name="bsa_sync_api_key" value="<?php echo esc_attr($api_key); ?>" class="regular-text" />
                        <p class="description">This must match the WORDPRESS_API_KEY secret in the app.</p>
                    </td>
                </tr>
            </table>
            <p class="submit">
                <input type="submit" name="bsa_sync_save" class="button-primary" value="Save Settings" />
            </p>
        </form>
        
        <hr />
        <h2>How It Works</h2>
        <ul>
            <li><strong>App → WordPress:</strong> When a user registers in the app, their account is automatically created here.</li>
            <li><strong>WordPress → App:</strong> When a user registers on this website, their account is automatically created in the app.</li>
            <li>Users can login to both platforms with the same email and password.</li>
        </ul>
    </div>
    <?php
}
