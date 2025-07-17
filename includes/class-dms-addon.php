<?php

namespace DMS_Addon\Includes;

use DMS\Includes\Services\Request_Params;

class DMS_Addon {
	/**
	 * DMS_Addon instance
	 *
	 * @var DMS_Addon|null
	 */
	private static ?DMS_Addon $_instance = null;

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
		$this->init();
	}

	protected function init(): void {
		DMS_Addon_Uri_Rewriter::get_instance();
	}
}