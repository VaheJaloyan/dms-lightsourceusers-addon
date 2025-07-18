<?php

namespace DMS_Addon\Includes;

use DMS\Includes\Data_Objects\Mapping;
use DMS\Includes\Data_Objects\Setting;
use DMS\Includes\Frontend\Handlers\URI_Handler;
use DMS\Includes\Services\Request_Params;
use DMS\Includes\Utils\Helper;

class DMS_Addon_Uri_Rewriter {

	/**
	 * DMS_Addon instance
	 *
	 * @var DMS_Addon_Uri_Rewriter|null
	 */
	private static ?DMS_Addon_Uri_Rewriter $_instance = null;

	/**
	 * DMS_Addon constructor.
	 */
	private function __construct() {
		$this->request_params = new Request_Params();
		$this->init();
	}

	/**
	 * Returns the main instance of DMS_Addon.
	 *
	 * @return DMS_Addon_Uri_Rewriter
	 */
	public static function get_instance(): DMS_Addon_Uri_Rewriter {
		if ( is_null( self::$_instance ) ) {
			self::$_instance = new self();
		}

		return self::$_instance;
	}

	/**
	 * Init rewrite scenario
	 */
	protected function init(): void {
		$this->define_rewrite_options();
		$this->rewrite_const_urls();
		$this->prepare_filters();
	}

	/**
	 * Rewrite BuddyPress constant URLs
	 *
	 * This method rewrites the BuddyPress plugin URL to ensure it matches the current domain mapping.
	 * It is called during the initialization of the DMS_Addon_Uri_Rewriter class.
	 */
	protected function rewrite_const_urls(): void {
		if ( class_exists( 'BuddyPress' ) && method_exists( 'BuddyPress', 'instance' ) ) {
			$bp = \BuddyPress::instance();
			if ( isset( $bp->plugin_url ) ) {
				$bp->plugin_url = $this->rewrite_url( $bp->plugin_url );
			}
		}
	}

	/**
	 * Define rewrite options based on settings
	 *
	 * This method retrieves the URL rewriting settings from the database and sets the rewrite scenario
	 * and allowed authentication domains accordingly.
	 */
	protected function define_rewrite_options() {
		$url_rewrite = Setting::find( 'dms_rewrite_urls_on_mapped_page' )->get_value();
		if ( ! empty( $url_rewrite ) ) {
			$rewrite_scenario       = (int) Setting::find( 'dms_rewrite_urls_on_mapped_page_sc' )->get_value();
			$this->rewrite_scenario = ! empty( $rewrite_scenario ) && in_array( $rewrite_scenario, [
				URI_Handler::REWRITING_GLOBAL,
				URI_Handler::REWRITING_SELECTIVE
			] ) ? $rewrite_scenario : URI_Handler::REWRITING_GLOBAL;
		}

		// Always ensure array_merge gets arrays
		$allowed_sub_domain_ids   = Setting::find( 'dms_subdomain_authentication_mappings' );
		$allowed_alias_domain_ids = Setting::find( 'dms_alias_domain_authentication_mappings' );

		$sub_domains = $allowed_sub_domain_ids && is_array( $allowed_sub_domain_ids->get_value() )
			? $allowed_sub_domain_ids->get_value()
			: [];

		$alias_domains = $allowed_alias_domain_ids && is_array( $allowed_alias_domain_ids->get_value() )
			? $allowed_alias_domain_ids->get_value()
			: [];

		$this->auth_domains = array_merge( $sub_domains, $alias_domains );
	}

	/**
	 * Prepare filters for URL rewriting
	 *
	 * This method adds various filters to rewrite URLs in WordPress, ensuring that they are correctly mapped
	 * to the current domain and path as per the Domain Mapping System settings.
	 */
	public function prepare_filters() {
		add_filter( 'includes_url', [ $this, 'rewrite_urls' ], 999999, 2 );
		add_filter( 'plugins_url', [ $this, 'rewrite_urls' ], 999999, 3 );
		add_filter( 'rest_url', [ $this, 'rewrite_urls_with_trail' ], 999999, 2 );
		add_filter( 'admin_url', [ $this, 'rewrite_urls' ], 999999, 2 );
		add_filter( 'wp_get_attachment_url', [ $this, 'rewrite_attachment_urls' ], 999999, 1 );
		add_filter( 'logout_url', [ $this, 'rewrite_urls' ], 999999, 2 );
		add_filter( 'login_url', [ $this, 'rewrite_urls' ], 999999, 3 );
		add_filter( 'content_url', [ $this, 'rewrite_url' ], 9999 );
		add_filter( 'bp_get_theme_compat_url', [ $this, 'rewrite_url' ], 9999 );
	}

	/**
	 * General URL rewriting method
	 *
	 * @param  string  $url
	 * @param  string|null  $path
	 * @param  string|null  $plugin
	 *
	 * @return string
	 */
	public function rewrite_urls( string $url, ?string $path = null, ?string $plugin = '' ): string {
		return $this->rewrite_url( $url ) ?? $url;
	}

	/**
	 * URL rewriting with trailing slash
	 *
	 * @param  string  $url
	 * @param  string|null  $path
	 *
	 * @return string
	 */
	public function rewrite_urls_with_trail( string $url, ?string $path = null ): string {
		return rtrim( $this->rewrite_url( $url ) ?? $url, '/' ) . '/';
	}

	/**
	 * Attachment URL rewriting
	 *
	 * @param  string  $url
	 *
	 * @return string
	 */
	public function rewrite_attachment_urls( string $url ): string {
		return trim( $this->rewrite_url( $url ) ?? $url, '/' );
	}

	/**
	 * Core URL rewriting logic
	 *
	 * @param  string|null  $url
	 *
	 * @return string|null
	 */
	public function rewrite_url( ?string $url ): ?string {
		if ( empty( $url ) || $this->rewrite_scenario !== URI_Handler::REWRITING_GLOBAL ) {
			return $url;
		}

		if ( empty( $this->request_params->domain ) || ! is_array( $this->auth_domains ) ) {
			return $url;
		}

		$mapping = Helper::matching_mapping_from_db( $this->request_params->domain, $this->request_params->path );

		if ( ! empty( $mapping ) && ! empty( $mapping->id ) && in_array( $mapping->id, $this->auth_domains, true ) ) {
			return $this->get_rewritten_url( $mapping, $url );
		}

		return $url;
	}

	/**
	 * Get rewritten URL
	 *
	 * @param  Mapping|null  $mapping
	 * @param  string|null  $link
	 *
	 * @return string|null
	 */
	public function get_rewritten_url( ?Mapping $mapping, ?string $link ): ?string {
		if ( empty( $link ) || empty( $this->request_params->domain ) ) {
			return $link;
		}

		$host = parse_url( $link, PHP_URL_HOST );
		if ( ! $host ) {
			return $link;
		}

		$link_without_scheme = preg_replace( "~^(https?://)~i", '', $link );

		if ( ! str_starts_with( $link_without_scheme, $this->request_params->domain ) ) {
			$rewrite_link = str_ireplace( $host, $this->request_params->domain, $link );

			return apply_filters( 'dms_rewritten_url', $rewrite_link, $this->rewrite_scenario );
		}

		return apply_filters( 'dms_rewritten_url', $link, $this->rewrite_scenario );
	}
}