<?php
/**
 * Plugin Name: Accessibility Scan Manager
 * Description: Accessibility scan admin, storage, and scanner-service integration for the current WordPress install.
 * Version: 0.1.0
 * Author: WebTecs
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ACC_PLUGIN_VERSION', '0.1.0' );
define( 'ACC_PLUGIN_FILE', __FILE__ );
define( 'ACC_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );

require_once ACC_PLUGIN_PATH . 'includes/class-acc-installer.php';
require_once ACC_PLUGIN_PATH . 'includes/class-acc-db.php';
require_once ACC_PLUGIN_PATH . 'includes/class-acc-violation-classification.php';
require_once ACC_PLUGIN_PATH . 'includes/class-acc-admin.php';
require_once ACC_PLUGIN_PATH . 'includes/class-acc-plugin.php';
require_once ACC_PLUGIN_PATH . 'includes/class-acc-scanner-client.php';
require_once ACC_PLUGIN_PATH . 'includes/class-acc-scan-orchestrator.php';

register_activation_hook( __FILE__, array( 'ACC_Installer', 'activate' ) );

ACC_Plugin::bootstrap();
