<?php
/**
 * Plugin Name: Domain Mapping System Addon for Lightsource
 * Description: Adds support for Lightsource Users with active Domain Mapping System plugin. Requires PHP 8.0+.
 * Version: 1.0.0
 * Author: Limb
 * Requires PHP: 8.0
 * Requires Plugins: domain-mapping-system-pro, buddyboss-platform
 */

// Exit if accessed directly.
use DMS_Addon\Includes\DMS_Addon;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Check PHP version.
if ( version_compare( PHP_VERSION, '8.0', '<' ) ) {
	add_action( 'admin_notices', function () {
		echo '<div class="notice notice-error"><p><strong>Domain Mapping System Addon for Lightsource</strong> requires PHP 8.0 or higher.</p></div>';
	} );

	return;
}

// Check if Domain Mapping System plugin is active.
add_action( 'plugins_loaded', function () {
	if (
		! is_plugin_active( 'domain-mapping-system-pro/dms.php' )
	) {
		add_action( 'admin_notices', function () {
			echo '<div class="notice notice-error"><p>';
			echo esc_html__( 'Domain Mapping System Addon for Lightsource requires either Domain Mapping System or Domain Mapping System Pro to be installed and active.',
				'dms-addon' );
			echo '</p></div>';
		} );

		// Deactivate your plugin if desired
		deactivate_plugins( plugin_basename( __FILE__ ) );
	}
} );

// Your plugin logic goes here.


if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

if ( ! function_exists( 'DMS_Addon' ) ) {
	require plugin_dir_path( __FILE__ ) . 'includes/class-dms-addon.php';
	function DMS_Addon() {
		return DMS_Addon::get_instance();
	}

	DMS_Addon();
}