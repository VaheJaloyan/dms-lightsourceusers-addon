<?php

use DMS\Includes\Data_Objects\Mapping;
use DMS\Includes\Data_Objects\Setting;
use DMS\Includes\Services\Request_Params;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class DMS_Addon_Sso_Auth {

	/**
	 * Singleton instance of the class.
	 *
	 * @var DMS_Addon_Sso_Auth|null
	 */
	private static ?DMS_Addon_Sso_Auth $_instance = null;

	/**
	 * Request parameters instance.
	 *
	 * @var Request_Params|null
	 */
	private ?Request_Params $request_params = null;

	/**
	 * List of allowed host domains for authentication.
	 *
	 * @var array
	 */
	private array $host_list = [];

	private string $version = '';

	/**
	 * Token expiry time in seconds.
	 */
	private const TOKEN_EXPIRY = 3600; // 1 hour

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->request_params = new Request_Params();
		$this->version = defined('DMS_LIGHTSOURCEUSERS_VERSION') ? DMS_LIGHTSOURCEUSERS_VERSION : '1.0';

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
	private function init() {
		$this->setAuthDomains();
		$this->define_hooks();
	}

	/**
	 * Get the encryption key for JWT.
	 *
	 * @return string
	 * @throws Exception
	 */
	private function get_encryption_key(): string {
		if ( ! defined( 'DMS_JWT_SECRET_KEY' ) ) {
			throw new Exception( 'JWT secret key not configured' );
		}

		return DMS_JWT_SECRET_KEY;
	}

	/**
	 * Define hooks for the class.
	 */
	protected function define_hooks(): void {
		add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_scripts' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_scripts' ] );
		add_action( 'login_enqueue_scripts', [ $this, 'enqueue_login_scripts' ] );
	}

	/**
	 * Register REST API routes for the addon.
	 */
	public function register_rest_routes(): void {
		// Generate token route
		register_rest_route( 'dms-addon-sso/v1', '/generate-token', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'generate_token' ],
			'permission_callback' => [ $this, 'check_permissions' ],
		] );

		register_rest_route( 'dms-addon-sso/v1', '/login', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'login' ],
			'permission_callback' => [ $this, 'check_permissions' ],
		] );

		register_rest_route( 'dms-addon-sso/v1', '/verify-token', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'verify_token' ],
			'permission_callback' => '__return_true',
		] );

		register_rest_route( 'dms-addon-sso/v1', '/logout', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'logout' ],
			'permission_callback' => '__return_true',
		] );
	}


	/**
	 * Enqueue Scripts
	 * @return void
	 */
	public function enqueue_scripts(): void {
		if ( ! wp_script_is( 'cross-domain-auth', 'registered' ) ) {
			wp_register_script(
				'cross-domain-auth',
				esc_url( home_url() . '/' . DMS_ADDON_PLUGIN_URL . 'assets/js/dms-addon-auth.js' ),
				[],
				$this->version,
				true
			);

			wp_localize_script( 'cross-domain-auth', 'cdaSettings', [
				'ajaxUrl'             => esc_url_raw( rest_url( 'dms-addon-sso/v1' ) ),
				'authPopup'           => esc_url( home_url() . '/' . DMS_ADDON_PLUGIN_URL . 'auth/storage.html' ),
				'domain'              => esc_js( parse_url( home_url(), PHP_URL_HOST ) ),
				'nonce'               => wp_create_nonce( 'wp_rest' ),
				'host_list'           => array_map( 'esc_js', $this->host_list ),
				'logout_redirect_url' => wp_sanitize_redirect( $this->get_logout_redirect_url() ),
			] );

			wp_enqueue_script( 'cross-domain-auth' );
		}
	}

	/**
	 * Enqueue Scripts
	 * Alias for enqueue_scripts for load scripts on login page
	 * @return void
	 */
	public function enqueue_login_scripts() {
		$this->enqueue_scripts();
	}

	/**
	 * Get Redirect URL after logout
	 * @return string
	 */
	private function get_logout_redirect_url(): string {
		return apply_filters( 'logout_redirect',
			add_query_arg( [
				'loggedout' => 'true',
				'wp_lang'   => get_user_locale(),
			], wp_login_url() ),
			'',
			wp_get_current_user()
		);
	}


	/**
	 * Generate a JWT token for the current user.
	 *
	 * @param  WP_REST_Request  $request  The REST request object.
	 *
	 * @return WP_REST_Response
	 */
	public function generate_token( WP_REST_Request $request ): WP_REST_Response {
		try {
			if ( ! is_user_logged_in() ) {
				throw new Exception( 'Authentication required' );
			}

			$user  = wp_get_current_user();
			$token = $this->create_jwt_token( $user );

			return new WP_REST_Response( [
				'success' => true,
				'token'   => $token,
			], 200 );
		} catch ( Exception $e ) {
			return new WP_REST_Response( [
				'success' => false,
				'error'   => 'Authentication failed'
			], 401 );
		}
	}

	/**
	 * Create a JWT token for the user.
	 *
	 * @param  WP_User  $user  The user object.
	 *
	 * @return string
	 * @throws Exception
	 */
	private function create_jwt_token( WP_User $user ): string {
		$payload = [
			'sub'        => $user->ID,
			'iat'        => time(),
			'exp'        => time() + self::TOKEN_EXPIRY,
			'jti'        => wp_generate_uuid4(),
			'iss'        => site_url(),
			'ip'         => $_SERVER['REMOTE_ADDR'],
			'user_agent' => $_SERVER['HTTP_USER_AGENT'],
			'nonce'      => wp_create_nonce( 'jwt_auth' )
		];

		return JWT::encode( $payload, $this->get_encryption_key(), 'HS512' );
	}

	/**
	 * Verify the JWT token and log in the user.
	 *
	 * @param  WP_REST_Request  $request  The REST request object.
	 *
	 * @return WP_REST_Response
	 */
	public function verify_token( WP_REST_Request $request ): WP_REST_Response {
		try {
			$params = $request->get_json_params();
			$token  = $params['token'] ?? null;

			if ( ! $token ) {
				throw new Exception( 'Token not provided' );
			}

			$decoded = $this->validate_token( $token );
			$user    = $this->authenticate_user( $decoded->sub );

			return new WP_REST_Response( [
				'success' => true,
				'user_id' => $user->ID
			], 200 );
		} catch ( Exception $e ) {
			return new WP_REST_Response( [
				'success' => false,
				'error'   => 'Token validation failed'
			], 401 );
		}
	}

	/**
	 * Validate the JWT token.
	 *
	 * @param  string  $token  The JWT token.
	 *
	 * @return object
	 * @throws Exception
	 */
	private function validate_token( string $token ): object {
		$decoded = JWT::decode( $token, new Key( $this->get_encryption_key(), 'HS512' ) );

		if ( $decoded->exp < time() ) {
			throw new Exception( 'Token expired' );
		}

		if ( $decoded->ip !== $_SERVER['REMOTE_ADDR'] ) {
			throw new Exception( 'Invalid token origin' );
		}

		return $decoded;
	}

	/**
	 * Authenticate the user based on the user ID.
	 *
	 * @param  int  $user_id  The user ID.
	 *
	 * @return WP_User
	 * @throws Exception
	 */
	private function authenticate_user( int $user_id ): WP_User {
		$user = get_user_by( 'id', $user_id );
		if ( ! $user ) {
			throw new Exception( 'Invalid user' );
		}

		wp_set_current_user( $user_id );
		wp_set_auth_cookie( $user_id, true, is_ssl() );
		$this->create_secure_session();

		return $user;
	}

	/**
	 * Create a secure session for the user.
	 *
	 * This method sets secure cookie parameters and starts a session if not already started.
	 */
	private function create_secure_session(): void {
		if ( ! session_id() ) {
			session_set_cookie_params( [
				'lifetime' => 0,
				'path'     => '/',
				'domain'   => $_SERVER['HTTP_HOST'],
				'secure'   => true,
				'httponly' => true,
				'samesite' => 'Strict'
			] );
			session_start();
		}
	}

	/**
	 * Handle user login.
	 *
	 * @param  WP_REST_Request  $request  The REST request object.
	 *
	 * @return WP_REST_Response
	 * @throws Exception
	 */
	public function login( WP_REST_Request $request ): WP_REST_Response {
		try {
			$credentials = $this->validate_login_credentials( $request );
			$user        = wp_authenticate( $credentials['username'], $credentials['password'] );

			if ( is_wp_error( $user ) ) {
				throw new Exception( 'Invalid login credentials' );
			}

			$token = $this->create_jwt_token( $user );
			$this->authenticate_user( $user->ID );

			return new WP_REST_Response( [
				'success' => true,
				'token'   => $token,
				'user'    => $this->get_safe_user_data( $user )
			], 200 );
		} catch ( Exception $e ) {
			return new WP_REST_Response( [
				'success' => false,
				'message' => $e->getMessage()
			], 401 );
		}
	}

	/**
	 * Validate login credentials from the request.
	 *
	 * @param  WP_REST_Request  $request  The REST request object.
	 *
	 * @return array
	 * @throws Exception
	 */
	private function validate_login_credentials( WP_REST_Request $request ): array {
		$username = sanitize_user( $request->get_param( 'log' ) );
		$password = $request->get_param( 'pwd' );

		if ( empty( $username ) || empty( $password ) ) {
			throw new Exception( 'Username and password are required' );
		}

		return [ 'username' => $username, 'password' => $password ];
	}

	/**
	 * Get safe user data to return in the response.
	 *
	 * @param  WP_User  $user  The user object.
	 *
	 * @return array
	 */
	private function get_safe_user_data( WP_User $user ): array {
		return [
			'id'       => $user->ID,
			'username' => $user->user_login,
			'email'    => $user->user_email,
		];
	}

	/**
	 * Handle user logout.
	 *
	 * @return WP_REST_Response
	 */
	public function logout(): WP_REST_Response {
		wp_destroy_current_session();
		wp_clear_auth_cookie();
		wp_set_current_user( 0 );

		if ( session_id() ) {
			session_destroy();
		}

		return new WP_REST_Response( [
			'success' => true,
			'message' => 'Logged out successfully'
		], 200 );
	}

	/**
	 * Check if the request has valid permissions.
	 *
	 * @return bool
	 */
	public function check_permissions(): bool {
		return wp_verify_nonce( $this->get_request_nonce(), 'wp_rest' ) &&
		       $this->validate_request_origin();
	}

	/**
	 * Validate the request origin against the allowed host list.
	 *
	 * @return bool
	 */
	private function validate_request_origin(): bool {
		$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

		return empty( $origin ) || in_array( parse_url( $origin, PHP_URL_HOST ), $this->host_list, true );
	}

	/**
	 * Get the request nonce from the headers.
	 *
	 * @return string
	 */
	private function get_request_nonce(): string {
		return sanitize_text_field( $_SERVER['HTTP_X_WP_NONCE'] ?? '' );
	}

	/**
	 * Set the authentication domains based on the mappings.
	 *
	 * This method retrieves the domains from the settings and sets them in the host list.
	 */
	public function setAuthDomains(): void {
		$domains         = $this->get_auth_domains();
		$this->host_list = array_unique( array_filter( $domains, [ $this, 'is_domain_valid' ] ) );
	}

	/**
	 * Get the list of authentication domains.
	 *
	 * This method retrieves the domains from the settings and returns them as an array.
	 *
	 * @return array
	 */
	private function get_auth_domains(): array {
		$domains = [];

		$auth_mappings = [
			'dms_alias_domain_authentication_mappings',
			'dms_subdomain_authentication_mappings'
		];

		foreach ( $auth_mappings as $mapping_key ) {
			$mapping_ids = Setting::find( $mapping_key )->get_value();
			if ( ! empty( $mapping_ids ) ) {
				$mappings = Mapping::where( [ 'id' => $mapping_ids ] );
				foreach ( $mappings as $mapping ) {
					$domains[] = $mapping->host;
				}
			}
		}


		$domains[] = $this->request_params->base_host;
		$domains[] = $this->request_params->domain;

		return $domains;
	}

	private function is_domain_valid( string $domain ): bool {
		return ! empty( $domain ) && checkdnsrr( $domain, 'A' );
	}
}