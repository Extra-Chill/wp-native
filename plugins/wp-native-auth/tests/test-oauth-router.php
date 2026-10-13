<?php
/**
 * Router-level tests for the OAuth endpoints (#80).
 *
 * The unit suites (test-oauth-token.php etc.) call the transport-
 * independent cores directly, which cannot catch a broken wire-up —
 * exactly how the missing `wp_native_auth_oauth_handle_register()`
 * dispatch survived review. These tests drive
 * wp_native_auth_oauth_maybe_handle_request() itself for EVERY route
 * in inc/oauth.php, intercepting the terminal send via the
 * `wp_native_auth_oauth_before_response` / `wp_native_auth_oauth_before_redirect`
 * observability actions (a listener throws before the endpoint's
 * terminal exit, so the request never kills the test process).
 *
 * @package WPNativeAuth\Tests
 */

declare(strict_types=1);

if ( ! class_exists( 'WP_UnitTestCase' ) ) {
	// Allows the file to be collected without a WP test harness present; the
	// real run happens in CI where WP_UnitTestCase exists.
	return;
}

require_once __DIR__ . '/wp-native-auth-router-response.php';

/**
 * @group auth
 * @group oauth
 */
class Test_WP_Native_Auth_OAuth_Router extends WP_UnitTestCase {

	/** @var int */
	private $user_id;

	/** @var array<string,mixed> */
	private $client;

	public function set_up(): void {
		parent::set_up();

		wp_native_auth_install_refresh_tokens_table();

		$this->user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $this->user_id );

		$client = wp_native_auth_oauth_register_client(
			array(
				'redirect_uris' => array( 'https://app.example.org/callback' ),
				'client_name'   => 'Router Client',
			),
			'203.0.113.80'
		);
		$this->assertNotWPError( $client );
		$this->client = $client;

		add_action( 'wp_native_auth_oauth_before_response', array( $this, 'intercept_response' ), 10, 3 );
		add_action( 'wp_native_auth_oauth_before_redirect', array( $this, 'intercept_redirect' ), 10, 1 );
	}

	public function tear_down(): void {
		remove_action( 'wp_native_auth_oauth_before_response', array( $this, 'intercept_response' ), 10 );
		remove_action( 'wp_native_auth_oauth_before_redirect', array( $this, 'intercept_redirect' ), 10 );
		unset( $_SERVER['REQUEST_URI'], $_SERVER['REQUEST_METHOD'], $_SERVER['REMOTE_ADDR'] );
		$_POST  = array();
		$_GET   = array();
		$this->set_404( false );

		parent::tear_down();
	}

	/** Listener: throws before send_json()'s terminal exit. */
	public function intercept_response( $data, $status, $headers ): void {
		// Built via property assignment (not constructor args) so the
		// EscapeOutput sniff has nothing to treat as thrown output.
		$exception          = new WP_Native_Auth_Router_Response();
		$exception->payload = is_array( $data ) ? $data : array();
		$exception->status  = (int) $status;
		$exception->headers = is_array( $headers ) ? $headers : array();

		throw $exception;
	}

	/** Listener: throws before the terminal exit after wp_redirect(). */
	public function intercept_redirect( $url ): void {
		$exception          = new WP_Native_Auth_Router_Response();
		$exception->payload = array( 'redirect' => (string) $url );
		$exception->status  = 302;

		throw $exception;
	}

	/**
	 * Make the router's guard believe WordPress resolved nothing for
	 * this request (that is when the OAuth paths take over).
	 */
	private function set_404( bool $value ): void {
		global $wp_query;
		$wp_query->is_404 = $value;
	}

	private function route( string $method, string $uri ): void {
		$this->set_404( true );
		$_SERVER['REQUEST_METHOD'] = $method;
		$_SERVER['REQUEST_URI']    = $uri;
	}

	private function run_router(): WP_Native_Auth_Router_Response {
		try {
			wp_native_auth_oauth_maybe_handle_request();
		} catch ( WP_Native_Auth_Router_Response $e ) {
			return $e;
		}

		$this->fail( 'The router did not terminate with a response — the route was not claimed.' );
	}

	private function pkce_pair(): array {
		$verifier  = rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' );
		$challenge = rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );

		return array( $verifier, $challenge );
	}

	private function consent_form( array $bundle, string $decision ): void {
		$_POST = array(
			'_wpnonce'        => wp_create_nonce( 'wp_native_auth_oauth_consent' ),
			'oauth_request'   => wp_native_auth_oauth_encode_bundle( $bundle ),
			'oauth_signature' => wp_native_auth_oauth_sign_request( $bundle ),
			'oauth_decision'  => $decision,
		);
	}

	// ------------------------------------------------------------------
	// POST /register — the route whose handler was missing entirely.
	// ------------------------------------------------------------------

	public function test_register_route_creates_client_through_router(): void {
		$this->route( 'POST', '/register' );
		$_POST = array(
			'redirect_uris' => array( 'https://router.example.org/callback' ),
			'client_name'   => 'Registered Via Router',
		);

		$response = $this->run_router();

		$this->assertSame( 201, $response->status, 'RFC 7591 registration responds 201 Created.' );
		$this->assertSame( 'none', $response->payload['token_endpoint_auth_method'] );
		$this->assertSame( array( 'https://router.example.org/callback' ), $response->payload['redirect_uris'] );
		$this->assertNotEmpty( $response->payload['client_id'] );

		$stored = wp_native_auth_oauth_find_registered_client( (string) $response->payload['client_id'] );
		$this->assertIsArray( $stored, 'The registered client is actually persisted.' );
		$this->assertSame( 'Registered Via Router', $stored['client_name'] );
	}

	public function test_register_route_rejects_bad_metadata_through_router(): void {
		$this->route( 'POST', '/register' );
		$_POST = array( 'redirect_uris' => array( 'http://router.example.org/callback' ) );

		$response = $this->run_router();

		$this->assertSame( 400, $response->status );
		$this->assertSame( 'invalid_redirect_uri', $response->payload['error'] );
	}

	public function test_register_route_rejects_get_method(): void {
		$this->route( 'GET', '/register' );

		$response = $this->run_router();

		$this->assertSame( 405, $response->status );
		$this->assertSame( 'POST', $response->headers['Allow'] ?? '' );
	}

	// ------------------------------------------------------------------
	// Discovery routes.
	// ------------------------------------------------------------------

	public function test_server_metadata_route_dispatches_through_router(): void {
		$this->route( 'GET', '/.well-known/oauth-authorization-server' );

		$response = $this->run_router();

		$this->assertSame( 200, $response->status );
		$this->assertSame( array( 'S256' ), $response->payload['code_challenge_methods_supported'] );
		$this->assertContains( 'none', $response->payload['token_endpoint_auth_methods_supported'] );
		$this->assertTrue( $response->payload['client_id_metadata_document_supported'] );
		$this->assertTrue( $response->payload['authorization_response_iss_parameter_supported'] );
	}

	public function test_protected_resource_route_dispatches_through_router(): void {
		$this->route( 'GET', '/.well-known/oauth-protected-resource' );

		$response = $this->run_router();

		$this->assertSame( 200, $response->status );
		$this->assertSame( untrailingslashit( home_url() ), $response->payload['resource'] );
		$this->assertSame( array( untrailingslashit( home_url() ) ), $response->payload['authorization_servers'] );
	}

	/**
	 * A foreign rewrite rule (e.g. one left behind by another plugin) can
	 * resolve a `.well-known` path to a non-404 query. The metadata must
	 * still be served (#99).
	 */
	public function test_protected_resource_served_when_query_is_not_404(): void {
		$this->route( 'GET', '/.well-known/oauth-protected-resource' );
		$this->set_404( false );

		$response = $this->run_router();

		$this->assertSame( 200, $response->status );
		$this->assertSame( untrailingslashit( home_url() ), $response->payload['resource'] );
	}

	public function test_server_metadata_served_when_query_is_not_404(): void {
		$this->route( 'GET', '/.well-known/oauth-authorization-server' );
		$this->set_404( false );

		$response = $this->run_router();

		$this->assertSame( 200, $response->status );
		$this->assertSame( untrailingslashit( home_url() ), $response->payload['issuer'] );
	}

	public function test_trailing_slash_metadata_path_is_answered_directly(): void {
		$this->route( 'GET', '/.well-known/oauth-protected-resource/' );
		$this->set_404( false );

		$response = $this->run_router();

		$this->assertSame( 200, $response->status );
	}

	public function test_non_metadata_endpoint_still_defers_to_resolved_content(): void {
		$this->route( 'POST', '/register' );
		$this->set_404( false );

		try {
			wp_native_auth_oauth_maybe_handle_request();
		} catch ( WP_Native_Auth_Router_Response $e ) {
			$this->fail( '/register was claimed even though WordPress resolved real content.' );
		}

		$this->assertTrue( true );
	}

	// ------------------------------------------------------------------
	// GET /authorize.
	// ------------------------------------------------------------------

	public function test_authorize_route_redirects_logged_out_user_to_login(): void {
		wp_set_current_user( 0 );
		$this->route( 'GET', '/authorize' );
		$_GET = array(
			'response_type'         => 'code',
			'client_id'             => (string) $this->client['client_id'],
			'redirect_uri'          => 'https://app.example.org/callback',
			'code_challenge'        => $this->pkce_pair()[1],
			'code_challenge_method' => 'S256',
			'state'                 => 'router-state',
		);

		$response = $this->run_router();

		$this->assertSame( 302, $response->status );
		$this->assertStringContainsString( 'wp-login.php', $response->payload['redirect'] );
		$this->assertStringContainsString( 'redirect_to=', $response->payload['redirect'] );
		$this->assertStringContainsString( '%2Fauthorize', $response->payload['redirect'], 'The login redirect preserves the full /authorize request URL.' );
	}

	public function test_authorize_route_rejects_unknown_client_with_page_error(): void {
		$this->route( 'GET', '/authorize' );
		$_GET = array(
			'response_type'         => 'code',
			'client_id'             => 'unknown-client',
			'redirect_uri'          => 'https://app.example.org/callback',
			'code_challenge'        => $this->pkce_pair()[1],
			'code_challenge_method' => 'S256',
		);

		$this->expectException( WPDieException::class );
		wp_native_auth_oauth_maybe_handle_request();
	}

	// ------------------------------------------------------------------
	// The consent screen is a real page, not a missing one.
	// ------------------------------------------------------------------

	/**
	 * Rendering consent must clear the 404 the main query already set.
	 *
	 * The router only claims a request WordPress resolved to nothing, so
	 * is_404 is true on entry. Leaving it set serves a valid consent
	 * prompt as "Page not found" under a 404 status.
	 */
	public function test_render_consent_clears_the_404_state(): void {
		global $wp_query;

		$this->set_404( true );

		$observed = null;
		add_filter(
			'wp_native_auth_oauth_consent_template',
			static function () use ( &$observed ) {
				global $wp_query;
				$observed = $wp_query->is_404;

				throw new WP_Native_Auth_Router_Response();
			}
		);

		$reached_template = false;

		try {
			wp_native_auth_oauth_render_consent( array( 'client_name' => 'Test Client' ) );
		} catch ( WP_Native_Auth_Router_Response $e ) {
			// The template seam is where we observe; rendering stops there.
			$reached_template = true;
		}

		$this->assertTrue( $reached_template, 'Rendering never reached the consent template.' );

		$this->assertFalse(
			$observed,
			'The consent screen rendered while the query was still flagged as a 404.'
		);
		$this->assertFalse( $wp_query->is_404 );
	}

	// ------------------------------------------------------------------
	// POST /authorize — the consent decision, end to end.
	// ------------------------------------------------------------------

	private function pending_bundle( string $challenge ): array {
		return array(
			'client_id'      => (string) $this->client['client_id'],
			'redirect_uri'   => 'https://app.example.org/callback',
			'code_challenge' => $challenge,
			'scope'          => WP_NATIVE_AUTH_OAUTH_SCOPE,
			'resource'       => 'https://api.example.org/mcp',
			'state'          => 'decision-state',
			'user_id'        => $this->user_id,
			'issued'         => time(),
		);
	}

	public function test_authorize_decision_deny_redirects_with_access_denied(): void {
		list( , $challenge ) = $this->pkce_pair();
		$this->route( 'POST', '/authorize' );
		$this->consent_form( $this->pending_bundle( $challenge ), 'deny' );

		$response = $this->run_router();

		$this->assertSame( 302, $response->status );
		$this->assertStringContainsString( 'error=access_denied', $response->payload['redirect'] );
		$this->assertStringContainsString( 'iss=', $response->payload['redirect'] );
		$this->assertStringContainsString( 'state=decision-state', $response->payload['redirect'] );
	}

	public function test_authorize_decision_approve_issues_redeemable_code(): void {
		list( $verifier, $challenge ) = $this->pkce_pair();
		$this->route( 'POST', '/authorize' );
		$this->consent_form( $this->pending_bundle( $challenge ), 'approve' );

		$response = $this->run_router();

		$this->assertSame( 302, $response->status );
		$this->assertStringContainsString( 'iss=', $response->payload['redirect'] );

		parse_str( (string) wp_parse_url( $response->payload['redirect'], PHP_URL_QUERY ), $query );
		$this->assertNotEmpty( $query['code'] ?? '', 'The approval redirect carries an authorization code.' );
		$this->assertSame( 'decision-state', $query['state'] ?? '' );

		// Full round trip: the minted code redeems through the token core
		// with the consented PKCE verifier, resource audience intact.
		$grant = wp_native_auth_oauth_exchange_code(
			(string) $query['code'],
			(string) $this->client['client_id'],
			'https://app.example.org/callback',
			$verifier
		);
		$this->assertIsArray( $grant );
		$this->assertSame( 'Bearer', $grant['token_type'] );
		$this->assertSame( 'https://api.example.org/mcp', wp_native_auth_get_access_token_payload( (string) $grant['access_token'] )['meta']['resource'] ?? '' );
	}

	public function test_authorize_decision_rejects_bad_nonce(): void {
		list( , $challenge ) = $this->pkce_pair();
		$this->route( 'POST', '/authorize' );
		$bundle = $this->pending_bundle( $challenge );
		$_POST  = array(
			'_wpnonce'        => 'garbage-nonce',
			'oauth_request'   => wp_native_auth_oauth_encode_bundle( $bundle ),
			'oauth_signature' => wp_native_auth_oauth_sign_request( $bundle ),
			'oauth_decision'  => 'approve',
		);

		$this->expectException( WPDieException::class );
		wp_native_auth_oauth_maybe_handle_request();
	}

	// ------------------------------------------------------------------
	// POST /token.
	// ------------------------------------------------------------------

	public function test_token_route_rejects_missing_grant_type_through_router(): void {
		$this->route( 'POST', '/token' );
		$_POST = array( 'client_id' => (string) $this->client['client_id'] );

		$response = $this->run_router();

		$this->assertSame( 400, $response->status );
		$this->assertSame( 'invalid_request', $response->payload['error'] );
	}

	public function test_token_route_completes_code_exchange_through_router(): void {
		list( $verifier, $challenge ) = $this->pkce_pair();

		$code = wp_native_auth_oauth_create_authorization_code(
			$this->user_id,
			(string) $this->client['client_id'],
			'https://app.example.org/callback',
			$challenge,
			'',
			WP_NATIVE_AUTH_OAUTH_SCOPE
		)['code'];

		$this->route( 'POST', '/token' );
		$_POST = array(
			'grant_type'    => 'authorization_code',
			'code'          => $code,
			'client_id'     => (string) $this->client['client_id'],
			'redirect_uri'  => 'https://app.example.org/callback',
			'code_verifier' => $verifier,
		);

		$response = $this->run_router();

		$this->assertSame( 200, $response->status );
		$this->assertSame( 'Bearer', $response->payload['token_type'] );
		$this->assertNotEmpty( $response->payload['access_token'] );
		$this->assertNotEmpty( $response->payload['refresh_token'] );
		$this->assertSame( WP_NATIVE_AUTH_OAUTH_SCOPE, $response->payload['scope'] );
	}

	public function test_token_route_rejects_get_method(): void {
		$this->route( 'GET', '/token' );

		$response = $this->run_router();

		$this->assertSame( 405, $response->status );
		$this->assertSame( 'POST', $response->headers['Allow'] ?? '' );
	}

	// ------------------------------------------------------------------
	// POST /revoke.
	// ------------------------------------------------------------------

	public function test_revoke_route_acknowledges_unknown_token_through_router(): void {
		$this->route( 'POST', '/revoke' );
		$_POST = array(
			'token'     => 'totally-unknown-token-value',
			'client_id' => (string) $this->client['client_id'],
		);

		$response = $this->run_router();

		$this->assertSame( 200, $response->status, 'RFC 7009: unknown tokens are acknowledged with 200.' );
		$this->assertSame( array(), $response->payload );
	}

	public function test_revoke_route_rejects_get_method(): void {
		$this->route( 'GET', '/revoke' );

		$response = $this->run_router();

		$this->assertSame( 405, $response->status );
		$this->assertSame( 'POST', $response->headers['Allow'] ?? '' );
	}

	// ------------------------------------------------------------------
	// The router must not claim paths WordPress actually resolved.
	// ------------------------------------------------------------------

	public function test_router_ignores_request_when_not_404(): void {
		$this->set_404( false );
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_SERVER['REQUEST_URI']    = '/register';
		$_POST                     = array( 'redirect_uris' => array( 'https://router.example.org/callback' ) );

		// No exception, no response: the router returns and WordPress
		// keeps serving the resolved content.
		wp_native_auth_oauth_maybe_handle_request();
		$this->assertTrue( true, 'Router yielded to resolved content.' );
	}
}
