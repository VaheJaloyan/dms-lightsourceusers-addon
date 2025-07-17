<?php

namespace DMS_Addon\Includes;

use DMS\Includes\Data_Objects\Mapping;
use DMS\Includes\Data_Objects\Setting;
use DMS\Includes\Frontend\Handlers\URI_Handler;
use DMS\Includes\Services\Request_Params;
use DMS\Includes\Utils\Helper;

class DMS_Addon {
	/**
	 * DMS_Addon instance
	 *
	 * @var DMS_Addon|null
	 */
	private static ?DMS_Addon $_instance = null;

	public $rewrite_scenario = null;
	public Request_Params $request_params;

	/**
	 * Returns the main instance of DMS_Addon.
	 *
	 * @return DMS_Addon
	 */
	public static function get_instance(): DMS_Addon {
		if ( is_null( self::$_instance ) ) {
			self::$_instance = new self();
		}

		return self::$_instance;
	}

	/**
	 * DMS_Addon constructor.
	 */
	private function __construct() {
		$this->request_params = new Request_Params();
		$this->init();
	}

	protected function init(): void {
		$this->define_rewrite_options();
		$this->rewrite_const_urls();
		$this->prepare_filters();
	}

	protected function rewrite_const_urls(): void {
		if ( class_exists( 'BuddyPress' ) ) {
			\BuddyPress::instance()->plugin_url = $this->rewrite_url( \BuddyPress::instance()->plugin_url );
		}
	}

	protected function define_rewrite_options() {
		$url_rewrite = Setting::find( 'dms_rewrite_urls_on_mapped_page' )->get_value();
		if ( ! empty( $url_rewrite ) ) {
			$rewrite_scenario       =  (int) Setting::find( 'dms_rewrite_urls_on_mapped_page_sc' )->get_value();
			$this->rewrite_scenario = ! empty( $rewrite_scenario ) && in_array( $rewrite_scenario, [
				URI_Handler::REWRITING_GLOBAL,
				URI_Handler::REWRITING_SELECTIVE
			] ) ? $rewrite_scenario : URI_Handler::REWRITING_GLOBAL;
		}
	}

	public
	function prepare_filters() {
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
		return $this->rewrite_url( $url );
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
		return trim( $this->rewrite_url( $url ), '/' ) . '/';
	}

	/**
	 * Attachment URL rewriting
	 *
	 * @param  string  $url
	 *
	 * @return string
	 */
	public function rewrite_attachment_urls( string $url ): string {
		return trim( $this->rewrite_url( $url ), '/' );
	}

	/**
	 * Core URL rewriting logic
	 *
	 * @param  string|null  $url
	 *
	 * @return string|null
	 */
	public function rewrite_url( ?string $url ): ?string {
		if ( $this->rewrite_scenario !== URI_Handler::REWRITING_GLOBAL || ! $url ) {
			return $url;
		}

		$mapping = Helper::matching_mapping_from_db( $this->request_params->domain, $this->request_params->path );

		return $this->get_rewritten_url( $mapping, $url );
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
		if ( ! $link ) {
			return $link;
		}

		$host                = parse_url( $link, PHP_URL_HOST );
		$link_without_scheme = preg_replace( "~^(https?://)~i", '', $link );

		if ( ! str_starts_with( $link_without_scheme, $this->request_params->domain ) ) {
			$rewrite_link = str_ireplace( $host, $this->request_params->domain, $link );

			return apply_filters( 'dms_rewritten_url', $rewrite_link, $this->rewrite_scenario );
		}

		return apply_filters( 'dms_rewritten_url', $link, $this->rewrite_scenario );
	}
}