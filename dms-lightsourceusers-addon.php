<?php
/**
 * Plugin Name: Domain Mapping System Addon for Lightsource
 * Description: Adds support for Lightsource Users with active Domain Mapping System plugin. Requires PHP 8.0+.
 * Version:     1.0.0
 * Author:      Limb
 * Requires PHP: 8.0
 * Requires Plugins: domain-mapping-system-pro
 */

defined( 'ABSPATH' ) || exit;

define( 'DMS_ADDON_PLUGIN_URL', 'wp-content/plugins/dms-lightsourceusers-addon/' );
define( 'DMS_ADDON_AUTH_SECRET', 'B67FCEBD-1789-4E38-84A2-B31DAA72513E' );

// Check PHP version.
if ( version_compare( PHP_VERSION, '8.0', '<' ) ) {
	add_action( 'admin_notices', function () {
		echo '<div class="notice notice-error"><p>';
		echo esc_html__( 'Domain Mapping System Addon for Lightsource requires PHP 8.0 or higher.', 'dms-addon' );
		echo '</p></div>';
	} );

	return;
}

// Initialize plugin.
add_action( 'plugins_loaded', 'dms_addon_lightsource_init', 20 );

function dms_addon_lightsource_init() {
	if ( ! function_exists( 'is_plugin_active' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	if ( ! is_plugin_active( 'domain-mapping-system-pro/dms.php' ) ) {
		add_action( 'admin_notices', function () {
			echo '<div class="notice notice-error"><p>';
			echo esc_html__( 'Domain Mapping System Addon for Lightsource requires the Domain Mapping System Pro plugin to be active.',
				'dms-addon' );
			echo '</p></div>';
		} );

		deactivate_plugins( plugin_basename( __FILE__ ) );

		return;
	}

	// Load classes and initialize plugin.
	dms_addon_lightsource_load_classes();

	if ( ! function_exists( 'DMS_Addon' ) ) {
		function DMS_Addon() {
			return DMS_Addon\Includes\DMS_Addon::get_instance();
		}
	}

	DMS_Addon();
}

/**
 * Load required classes for the DMS Addon.
 */
function dms_addon_lightsource_load_classes() {
	if ( ! class_exists( 'DMS_Addon\Includes\DMS_Addon' ) ) {
		require_once plugin_dir_path( __FILE__ ) . 'vendor/autoload.php';
		require_once plugin_dir_path( __FILE__ ) . 'includes/class-dms-addon.php';
		require_once plugin_dir_path( __FILE__ ) . 'includes/class-dms-addon-uri-rewriter.php';
		require_once plugin_dir_path( __FILE__ ) . 'includes/class-dms-addon-sso-auth.php';
	}
}