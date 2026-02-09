<?php
/**
 * Plugin Name: Barbaarintasan Legacy Auth
 * Description: Seamless bcrypt password migration from Barbaarintasan Academy app to WordPress.
 *              Allows imported users to login with their existing app passwords.
 *              On first login, bcrypt hash is verified and converted to WordPress native format.
 * Version: 1.0.0
 * Author: Barbaarintasan Academy
 */

if (!defined('ABSPATH')) {
    exit;
}

add_filter('authenticate', 'bsa_legacy_bcrypt_auth', 5, 3);

function bsa_legacy_bcrypt_auth($user, $username, $password) {
    if (empty($username) || empty($password)) {
        return $user;
    }

    $wp_user = get_user_by('login', $username);
    if (!$wp_user) {
        $wp_user = get_user_by('email', $username);
    }

    if (!$wp_user) {
        return $user;
    }

    $legacy_hash = get_user_meta($wp_user->ID, 'legacy_bcrypt', true);

    if (empty($legacy_hash)) {
        return $user;
    }

    if (password_verify($password, $legacy_hash)) {
        wp_set_password($password, $wp_user->ID);
        delete_user_meta($wp_user->ID, 'legacy_bcrypt');
        return get_user_by('ID', $wp_user->ID);
    }

    return new WP_Error(
        'incorrect_password',
        __('<strong>Error:</strong> The password you entered is incorrect.')
    );
}
