<?php
/**
 * Dynamic client registration must accept every grant the server advertises.
 *
 * Discovery and registration previously hardcoded the supported grant list
 * separately. Adding the RFC 8628 device grant to discovery alone meant the
 * server advertised a grant that registration then rejected: a compliant
 * device client, announcing the grant it actually uses, could not register
 * at all. These tests pin the two surfaces together.
 */

class Test_WP_Native_Auth_OAuth_Client_Registration extends WP_UnitTestCase {

	/**
	 * Registration is rate-limited per IP and the counter is a transient, so
	 * it does not roll back with the test transaction. Each registration
	 * needs its own IP or the suite starts failing as a function of how many
	 * tests ran rather than of anything under test.
	 *
	 * @var int
	 */
	private static $seq = 0;

	/**
	 * @param array<string,mixed> $overrides Client metadata to merge.
	 * @return array<string,mixed>|WP_Error
	 */
	private function register( array $overrides = array() ) {
		++self::$seq;

		$body = array_merge(
			array(
				'client_name'                => 'Registration Test Client',
				'redirect_uris'              => array( 'https://example.com/callback' ),
				'token_endpoint_auth_method' => 'none',
			),
			$overrides
		);

		return wp_native_auth_oauth_register_client( $body, '198.51.100.' . ( 10 + self::$seq ) );
	}

	/**
	 * The regression: a device client announcing the device grant.
	 */
	public function test_device_grant_client_can_register(): void {
		$client = $this->register(
			array(
				'grant_types'    => array( WP_NATIVE_AUTH_OAUTH_DEVICE_GRANT_TYPE, 'refresh_token' ),
				'response_types' => array( 'code' ),
			)
		);

		$this->assertIsArray( $client, 'a device client must be able to register for the grant it uses' );
		$this->assertNotEmpty( $client['client_id'] );
	}

	/**
	 * A device-only client has no authorization response to declare.
	 */
	public function test_device_only_client_may_register_empty_response_types(): void {
		$client = $this->register(
			array(
				'grant_types'    => array( WP_NATIVE_AUTH_OAUTH_DEVICE_GRANT_TYPE ),
				'response_types' => array(),
			)
		);

		$this->assertIsArray( $client );
	}

	/**
	 * A device-only client has no redirect and may omit redirect_uris (RFC 7591 §2).
	 */
	public function test_device_only_client_may_omit_redirect_uris(): void {
		$body = array(
			'client_name'                => 'Device Only',
			'token_endpoint_auth_method' => 'none',
			'grant_types'                => array( WP_NATIVE_AUTH_OAUTH_DEVICE_GRANT_TYPE, 'refresh_token' ),
		);
		$client = wp_native_auth_oauth_register_client( $body, '198.51.100.250' );

		$this->assertIsArray( $client, 'a device-only client must register without redirect_uris' );
		$this->assertSame( array(), $client['redirect_uris'] );

		$stored = wp_native_auth_oauth_find_registered_client( $client['client_id'] );
		$this->assertIsArray( $stored );
		$this->assertFalse( wp_native_auth_oauth_validate_redirect_uri( $stored['redirect_uris'], 'https://example.com/callback' ), 'a client without redirect URIs can never be redirected to' );
	}

	/**
	 * A redirect-based client still needs at least one redirect URI.
	 */
	public function test_authorization_code_client_still_requires_redirect_uris(): void {
		$body = array(
			'client_name'                => 'Code Client',
			'token_endpoint_auth_method' => 'none',
			'grant_types'                => array( 'authorization_code', WP_NATIVE_AUTH_OAUTH_DEVICE_GRANT_TYPE ),
		);
		$this->assertWPError( wp_native_auth_oauth_register_client( $body, '198.51.100.251' ) );
		$this->assertWPError( $this->register( array( 'redirect_uris' => array() ) ), 'default grants include authorization_code' );
	}

	/**
	 * Supplied redirect URIs are still validated for a device-only client.
	 */
	public function test_device_only_client_redirect_uris_are_still_validated(): void {
		$this->assertWPError(
			$this->register(
				array(
					'grant_types'   => array( WP_NATIVE_AUTH_OAUTH_DEVICE_GRANT_TYPE ),
					'redirect_uris' => array( 'http://evil.example.com/cb' ),
				)
			)
		);
	}

	/**
	 * RFC 7591 attaches no meaning to the order of grant_types.
	 */
	public function test_grant_type_order_is_not_significant(): void {
		$client = $this->register(
			array( 'grant_types' => array( 'refresh_token', 'authorization_code' ) )
		);

		$this->assertIsArray( $client );
	}

	/**
	 * A client may want only part of what the server offers.
	 */
	public function test_a_subset_of_supported_grants_is_accepted(): void {
		$client = $this->register( array( 'grant_types' => array( 'authorization_code' ) ) );

		$this->assertIsArray( $client );
	}

	/**
	 * Widening the accepted set must not admit the grants this server
	 * deliberately does not implement.
	 *
	 * @dataProvider unsupported_grants
	 */
	public function test_unsupported_grants_are_still_refused( string $grant ): void {
		$client = $this->register( array( 'grant_types' => array( 'authorization_code', $grant ) ) );

		$this->assertWPError( $client, "{$grant} must not be registrable" );
		$this->assertSame( 'invalid_client_metadata', $client->get_error_code() );
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public function unsupported_grants(): array {
		return array(
			'implicit'           => array( 'implicit' ),
			'password'           => array( 'password' ),
			'client credentials' => array( 'client_credentials' ),
		);
	}

	public function test_empty_grant_types_is_refused(): void {
		$this->assertWPError( $this->register( array( 'grant_types' => array() ) ) );
	}

	public function test_unsupported_response_type_is_refused(): void {
		$client = $this->register( array( 'response_types' => array( 'token' ) ) );

		$this->assertWPError( $client, 'the implicit response type must not be registrable' );
	}

	/**
	 * Registration and discovery must not drift apart again.
	 *
	 * This is the assertion that would have caught the original bug: every
	 * grant the server advertises has to be one a client can register for.
	 */
	public function test_every_advertised_grant_is_registrable(): void {
		$advertised = wp_native_auth_oauth_server_metadata()['grant_types_supported'];

		foreach ( $advertised as $grant ) {
			$client = $this->register( array( 'grant_types' => array( $grant ) ) );

			$this->assertIsArray(
				$client,
				"discovery advertises {$grant}, so registration must accept it"
			);
		}
	}
}
