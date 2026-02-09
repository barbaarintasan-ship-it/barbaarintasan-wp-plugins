<?php
/**
 * Plugin Name: Barbaarintasan Academy
 * Plugin URI: https://appbarbaarintasan.com
 * Description: Barbaarintasan Academy WordPress integration - Legacy auth migration and user import tools.
 * Version: 1.0.0
 * Author: Barbaarintasan Academy
 * License: GPL-2.0+
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once plugin_dir_path(__FILE__) . 'barbaarintasan-legacy-auth.php';
require_once plugin_dir_path(__FILE__) . 'barbaarintasan-user-import.php';
