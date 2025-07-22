<?php

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class DMS_Addon_Sso_Auth {

	public static ?DMS_Addon_Sso_Auth $_instance = null;
	public static $key = 'secret';

	public function __construct() {
		$this->init();
	}

	public static function get_instance() {
		if ( is_null( self::$_instance ) ) {
			self::$_instance = new self();
		}

		return self::$_instance;
	}

	public function init() {
		$this->define_hooks();
		$this->enqueue_scripts();
	}

	protected function define_hooks() {
		add_action( 'init', [ $this, 'add_rewrite_rules' ] );
		add_filter( 'query_vars', [ $this, 'add_query_vars' ] );
		add_action( 'template_redirect', [ $this, 'show_iframe' ] );
		add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );
		// Hook into login to generate token
		add_action( 'wp_login', [ $this, 'handle_login' ], 10, 2 );
		add_action('wp_logout', [$this, 'handle_logout']);
	}

	public function register_rest_routes() {
		register_rest_route( 'dms-addon-sso/v1', '/generate-token', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'generate_token' ],
			'permission_callback' => function () { return is_user_logged_in(); }
		] );

		register_rest_route( 'dms-addon-sso/v1', '/verify-token', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'verify_token' ],
			'permission_callback' => '__return_true',
		] );
	}

	public function enqueue_scripts() {
		wp_enqueue_script(
			'cross-domain-auth',
			DMS_ADDON_PLUGIN_URL . 'assets/js/dms-addon-auth.js',
			[],
			'1.0',
			true
		);

		wp_localize_script('cross-domain-auth', 'cdaSettings', [
			'ajaxUrl'   => rest_url('dms-addon-sso/v1'),
			'iframeUrl' => home_url('/auth-iframe'),
			'domain'    => parse_url(home_url(), PHP_URL_HOST),
			'nonce'     => wp_create_nonce('wp_rest'),
		]);
	}

	// Generate JWT for logged-in user
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

	// Verify JWT and set session
	public function verify_token( WP_REST_Request $request ) {
		$token = $request->get_param( 'token' );
		try {
			$decoded = JWT::decode( $token, new Key( self::$key, 'HS256' ) );
			$user_id = $decoded->sub;

			$user = get_user_by( 'id', $user_id );
			if ( $user ) {
				wp_set_current_user( $user_id );
				wp_set_auth_cookie( $user_id, true, is_ssl() );

				return new WP_REST_Response( [ 'success' => true ], 200 );
			}

			return new WP_REST_Response( [ 'success' => false, 'error' => 'Invalid user' ], 401 );
		} catch ( Exception $e ) {
			return new WP_REST_Response( [ 'success' => false, 'error' => 'Invalid token' ], 401 );
		}
	}

	public function add_rewrite_rules() {
		add_rewrite_rule(
			'auth-iframe/?$',
			'index.php?cross_domain_auth_iframe=1',
			'top'
		);
	}

	public function add_query_vars( $vars ) {
		$vars[] = 'cross_domain_auth_iframe';

		return $vars;
	}

	public function show_iframe() {
		if ( get_query_var( 'cross_domain_auth_iframe' ) ) {
			header( 'Content-Type: text/html' );
			?>
            <!DOCTYPE html>
            <html>
            <body>
            <script>
                // Handle postMessage communication
                window.addEventListener('message', function (event) {
                    // Validate origin (replace with your mapped domains)
                    const allowedOrigins = ['https://test.net', 'https://test2.com', 'https://mapped.com'];
                    if (!allowedOrigins.includes(event.origin)) return;

                    const {action} = event.data;

                    if (action === 'storeToken') {
                        localStorage.setItem('auth_token', event.data.token);
                        event.source.postMessage({action: 'tokenStored', success: true}, event.origin);
                    } else if (action === 'getToken') {
                        const token = localStorage.getItem('auth_token');
                        event.source.postMessage({action: 'tokenResponse', token}, event.origin);
                    } else if (action === 'clearToken') {
                        localStorage.removeItem('auth_token');
                        event.source.postMessage({action: 'tokenCleared', success: true}, event.origin);
                    }
                });
            </script>
            </body>
            </html>
			<?php
			exit;
		}
	}

	public function handle_login( $user_login, $user ) {
		$response = wp_remote_post( rest_url( 'dms-addon-sso/v1/generate-token' ), [
			'headers' => [ 'Authorization' => 'Bearer ' . wp_create_nonce( 'wp_rest' ) ],
		] );
		if ( ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) === 200 ) {
			// Token is generated and will be stored via JavaScript
		}
	}

	public function handle_logout() {
		// JavaScript will handle clearing the token via postMessage
	}
}

