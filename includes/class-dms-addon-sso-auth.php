<?php

use DMS\Includes\Data_Objects\Mapping;
use DMS\Includes\Data_Objects\Setting;
use DMS\Includes\Services\Request_Params;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class DMS_Addon_Sso_Auth {

	public static ?DMS_Addon_Sso_Auth $_instance = null;
	public static $key = DMS_ADDON_AUTH_SECRET; // Temporary, to be fixed later
	protected ?Request_Params $request_params = null;
	public $host_list = [];

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->request_params = new Request_Params();
		$this->init();
	}

	/**
	 * Get the singleton instance of the class.
	 *
	 * @return DMS_Addon_Sso_Auth
	 */
	public static function get_instance(): DMS_Addon_Sso_Auth {
		if ( is_null( self::$_instance ) ) {
			self::$_instance = new self();
		}

		return self::$_instance;
	}

	/**
	 * Initialize the class.
	 */
	public function init() {
		$this->setAuthDomains();
		$this->define_hooks();
	}

	/**
	 * Define hooks for the class.
	 */
	protected function define_hooks() {
		add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_scripts' ] );
		add_action( 'login_enqueue_scripts', [ $this, 'enqueue_login_scripts' ] );
		add_action( 'wp_login', [ $this, 'handle_login' ], 10, 2 );
	}

	/**
	 * Register REST API routes for the addon.
	 */
	public function register_rest_routes() {
		// Generate token route
		register_rest_route( 'dms-addon-sso/v1', '/generate-token', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'generate_token' ],
			'permission_callback' => function () {
				return is_user_logged_in();
			},
		] );

		register_rest_route( 'dms-addon-sso/v1', '/login', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'login' ],
			'permission_callback' => '__return_true',
		] );

		// Verify token
		register_rest_route( 'dms-addon-sso/v1', '/verify-token', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'verify_token' ],
			'permission_callback' => '__return_true',
		] );

		register_rest_route( 'dms-addon-sso/v1', '/logout', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'logout' ],
			'permission_callback' => '__return_true', // Add nonce/auth as needed
		] );
	}

	public function enqueue_scripts() {
		wp_enqueue_script(
			'cross-domain-auth',
			home_url() . '/' . DMS_ADDON_PLUGIN_URL . 'assets/js/dms-addon-auth.js',
			[],
			'1.0',
			true
		);

		wp_localize_script( 'cross-domain-auth', 'cdaSettings', [
			'ajaxUrl'   => rest_url( 'dms-addon-sso/v1' ),
			'authPopup' => home_url() . '/' . DMS_ADDON_PLUGIN_URL . 'auth/storage.html',
			'domain'    => parse_url( home_url(), PHP_URL_HOST ),
			'nonce'     => wp_create_nonce( 'wp_rest' ),
			'logoutUrl' => wp_logout_url(),
			'host_list' => $this->host_list,
		] );
	}

	public function enqueue_login_scripts() {
		$this->enqueue_scripts();
	}

	/**
	 * Generate a JWT token for the current user.
	 *
	 * @param  WP_REST_Request  $request  The REST request object.
	 *
	 * @return WP_REST_Response
	 */
	public function generate_token( WP_REST_Request $request ) {
		$user = wp_get_current_user();
		if ( ! $user->ID ) {
			return new WP_REST_Response( [ 'success' => false, 'error' => 'Not logged in' ], 401 );
		}

		$payload = [
			'sub' => $user->ID,
			'iat' => time(),
			'exp' => time() + 3600, // 1 hour expiration
		];

		$token = JWT::encode( $payload, self::$key, 'HS256' );

		return new WP_REST_Response( [ 'success' => true, 'token' => $token ], 200 );
	}

	/**
	 * Verify the JWT token and log in the user.
	 *
	 * @param  WP_REST_Request  $request  The REST request object.
	 *
	 * @return WP_REST_Response
	 */
	public function verify_token( WP_REST_Request $request ) {
		$params = $request->get_json_params();
		$token  = $params['token'] ?? null;
		try {
			$decoded = JWT::decode( $token, new Key( self::$key, 'HS256' ) );

			$user_id = $decoded->sub;

			$user = get_user_by( 'id', $user_id );
			if ( $user ) {
				wp_set_current_user( $user_id );
				wp_set_auth_cookie( $user_id, true, is_ssl() );

				return new WP_REST_Response( [ 'success' => true, 'user_id' => $user->id ], 200 );
			}

			return new WP_REST_Response( [ 'success' => false, 'error' => 'Invalid user' ], 401 );
		} catch ( Exception $e ) {
			return new WP_REST_Response( [ 'success' => false, 'error' => 'Invalid token' ], 401 );
		}
	}

	/**
	 * Handle user login to generate a token.
	 *
	 * @param  string  $user_login  The username of the user logging in.
	 * @param  WP_User  $user  The WP_User object of the user logging in.
	 */
	public function handle_login( $user_login, $user ) {
		wp_remote_get( rest_url( 'dms-addon-sso/v1/generate-token' ) );
	}

	public function setAuthDomains() {
		$auth_alias_mapping_ids = Setting::find( 'dms_alias_domain_authentication_mappings' )->get_value();
		$auth_sub_mapping_ids   = Setting::find( 'dms_subdomain_authentication_mappings' )->get_value();
		if ( ! empty( $auth_alias_mapping_ids ) ) {
			$mappings_alias = Mapping::where( [ 'id' => $auth_alias_mapping_ids ] );
			foreach ( $mappings_alias as $mapping ) {
				$this->host_list[] = $mapping->host;
			}
		}
		if ( ! empty( $auth_sub_mapping_ids ) ) {
			$mappings_sub = Mapping::where( [ 'id' => $auth_sub_mapping_ids ] );

			foreach ( $mappings_sub as $mapping ) {
				$this->host_list[] = $mapping->host;
			}
		}
		$this->host_list[] = $this->request_params->base_host;
		$this->host_list[] = $this->request_params->domain;

		$this->host_list = array_unique( $this->host_list );
	}


	public function generate_token_ajax() {
		check_ajax_referer( 'generate_token_nonce' ); // Same nonce you pass in JS

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( [ 'message' => 'User not logged in' ], 401 );
		}

		$user  = wp_get_current_user();
		$token = wp_create_nonce( 'my_token_' . $user->ID );

		wp_send_json_success( [ 'token' => $token ] );
	}

	public function login( WP_REST_Request $request ) {
		$username = $request->get_param( 'log' );
		$password = $request->get_param( 'pwd' );

		if ( empty( $username ) || empty( $password ) ) {
			return new WP_REST_Response( [
				'success' => false,
				'message' => 'Username and password are required.'
			], 400 );
		}

		$user = wp_signon( [
			'user_login'    => $username,
			'user_password' => $password,
			'remember'      => true,
		], false );

		if ( is_wp_error( $user ) ) {
			return new WP_REST_Response( [
				'success' => false,
				'message' => 'Invalid login credentials.',
			], 403 );
		}

		// Authenticate session
		wp_set_current_user( $user->ID );
		wp_set_auth_cookie( $user->ID, true ); // true = remember me

		// Generate JWT token
		$payload = [
			'sub' => $user->ID,
			'iat' => time(),
			'exp' => time() + 3600, // 1 hour
		];

		$token = JWT::encode( $payload, self::$key, 'HS256' );

		return new WP_REST_Response( [
			'success' => true,
			'message' => 'Login successful',
			'nonce'   => wp_create_nonce( 'wp_rest' ),
			'token'   => $token,
			'user'    => [
				'id'       => $user->ID,
				'username' => $user->user_login,
				'email'    => $user->user_email,
			],
		] );
	}

	public function logout() {
		wp_logout();

		// Clear any custom tokens or session data if needed

		return new WP_REST_Response( [
			'success' => true,
			'message' => 'Logged out successfully',
		] );
	}
}

