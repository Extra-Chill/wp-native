<?php
/**
 * Tests for per-session challenge verification and verifier re-challenges (#120).
 *
 * @package WPNativeAuth\Tests
 */

declare(strict_types=1);

if ( ! class_exists( 'WP_UnitTestCase' ) ) {
	return;
}

/** @group auth @group security */
class Test_WP_Native_Auth_Session_Challenge_Verification extends WP_UnitTestCase {
	private int $user_id;
	private string $device_id       = '';
	private string $password        = 'correct horse battery staple';
	private string $original_client = '';

	public function set_up(): void {
		parent::set_up();
		wp_native_auth_install_refresh_tokens_table();
		$this->user_id = self::factory()->user->create(
			array(
				'user_login' => 'session-user-' . wp_generate_uuid4(),
				'user_pass'  => $this->password,
			)
		);

		// Refresh resolves sessions by device_id alone and the custom table
		// outlives per-test transactions, so every test gets its own device.
		$this->device_id                              = wp_generate_uuid4();
		$this->original_client                        = isset( $_SERVER['HTTP_WP_NATIVE_CLIENT'] ) ? (string) $_SERVER['HTTP_WP_NATIVE_CLIENT'] : '';
		$_SERVER['HTTP_WP_NATIVE_CLIENT']             = 'test-client';
		$GLOBALS['wp_native_auth_challenge_policies'] = array();
	}

	public function tear_down(): void {
		$GLOBALS['wp_native_auth_challenge_policies'] = array();
		unset( $_SERVER['HTTP_AUTHORIZATION'] );
		$_SERVER['HTTP_WP_NATIVE_CLIENT'] = $this->original_client;
		parent::tear_down();
	}

	public function test_schema_adds_nullable_challenge_columns(): void {
		global $wpdb;

		$columns = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SHOW COLUMNS FROM %i', wp_native_auth_refresh_tokens_table_name() ) ) as $column ) {
			$columns[ $column->Field ] = $column; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- MySQL column name.
		}

		$this->assertGreaterThanOrEqual( 8, WP_NATIVE_AUTH_SCHEMA_VERSION );
		$this->assertArrayHasKey( 'challenge_policy', $columns );
		$this->assertArrayHasKey( 'challenge_verified_at', $columns );
		$this->assertSame( 'YES', $columns['challenge_verified_at']->Null );
	}

	public function test_password_only_session_is_not_verified(): void {
		$this->assertIsArray( $this->login() );

		$session = wp_native_auth_get_session( $this->user_id, $this->device_id );
		$this->assertIsArray( $session );
		$this->assertNull( $session['challenge_policy'] );
		$this->assertNull( $session['challenge_verified_at'] );
	}

	public function test_continued_login_records_policy_and_verification_time(): void {
		$this->register_policy();
		$before    = time();
		$completed = $this->continue( $this->login(), array( 'answer' => 'valid' ) );
		$this->assertIsArray( $completed );
		$this->assertArrayHasKey( 'access_token', $completed );

		$session = wp_native_auth_get_session( $this->user_id, $this->device_id );
		$this->assertSame( 'test-policy', $session['challenge_policy'] );
		$this->assertGreaterThanOrEqual( $before, $session['challenge_verified_at'] );
		$this->assertLessThanOrEqual( time(), $session['challenge_verified_at'] );
	}

	public function test_fresh_unchallenged_login_clears_previous_verification(): void {
		$this->register_policy();
		$this->assertIsArray( $this->continue( $this->login(), array( 'answer' => 'valid' ) ) );

		wp_native_auth_unregister_challenge_policy( 'test-policy' );
		$this->assertArrayHasKey( 'access_token', $this->login() );

		$session = wp_native_auth_get_session( $this->user_id, $this->device_id );
		$this->assertNull( $session['challenge_policy'] );
		$this->assertNull( $session['challenge_verified_at'] );
	}

	public function test_refresh_rotation_preserves_verification(): void {
		$this->register_policy();
		$completed = $this->continue( $this->login(), array( 'answer' => 'valid' ) );
		$verified  = wp_native_auth_get_session( $this->user_id, $this->device_id )['challenge_verified_at'];

		$rotated = wp_native_auth_refresh_tokens( $completed['refresh_token'], $this->device_id );
		$this->assertIsArray( $rotated );

		$session = wp_native_auth_get_session( $this->user_id, $this->device_id );
		$this->assertSame( 'test-policy', $session['challenge_policy'] );
		$this->assertSame( $verified, $session['challenge_verified_at'] );
	}

	public function test_current_session_resolves_only_for_bearer_requests(): void {
		$completed = $this->login();

		wp_set_current_user( $this->user_id );
		$this->assertNull( wp_native_auth_get_current_session(), 'Cookie-style requests have no device session.' );
		$this->assertFalse( wp_native_auth_mark_current_session_verified( 'test-policy' ) );

		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $completed['access_token'];
		$session                       = wp_native_auth_get_current_session();
		$this->assertIsArray( $session );
		$this->assertSame( $this->device_id, $session['device_id'] );
		$this->assertSame( $this->user_id, $session['user_id'] );
	}

	public function test_bearer_for_another_user_is_ignored(): void {
		$completed = $this->login();
		$other     = self::factory()->user->create();

		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $completed['access_token'];
		wp_set_current_user( $other );
		$this->assertNull( wp_native_auth_get_current_session() );
		$this->assertFalse( wp_native_auth_mark_current_session_verified( 'test-policy' ) );
	}

	public function test_mark_current_session_verified_stamps_bearer_session(): void {
		$completed = $this->login();
		wp_set_current_user( $this->user_id );
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $completed['access_token'];

		$events = array();
		add_action(
			'wp_native_auth_session_verified',
			static function ( ...$args ) use ( &$events ) {
				$events[] = $args;
			},
			10,
			3
		);

		$this->assertTrue( wp_native_auth_mark_current_session_verified( 'step-up' ) );
		$this->assertTrue( wp_native_auth_mark_current_session_verified( 'step-up' ), 'A same-second re-stamp still succeeds.' );

		$session = wp_native_auth_get_current_session();
		$this->assertSame( 'step-up', $session['challenge_policy'] );
		$this->assertGreaterThanOrEqual( time() - 5, $session['challenge_verified_at'] );
		$this->assertSame( array( $this->user_id, $this->device_id, 'step-up' ), $events[0] );
	}

	public function test_mark_rejects_invalid_policy_and_revoked_sessions(): void {
		$this->login();
		$this->assertFalse( wp_native_auth_mark_session_verified( $this->user_id, $this->device_id, 'Not A Policy' ) );

		wp_native_auth_revoke_refresh_token( $this->user_id, $this->device_id );
		$this->assertFalse( wp_native_auth_mark_session_verified( $this->user_id, $this->device_id, 'test-policy' ) );
		$this->assertNull( wp_native_auth_get_session( $this->user_id, $this->device_id ) );
	}

	public function test_rechallenge_keeps_continuation_redeemable(): void {
		$this->register_policy(
			static function ( $user, $response ) {
				if ( ! empty( $response['resend'] ) ) {
					return array(
						'type'   => 'test',
						'status' => 'code_sent',
					);
				}

				return isset( $response['answer'] ) && 'valid' === $response['answer'];
			}
		);

		$pending     = $this->login();
		$rechallenge = $this->continue( $pending, array( 'resend' => true ) );

		$this->assertIsArray( $rechallenge );
		$this->assertTrue( $rechallenge['challenge_required'] );
		$this->assertSame( 'test-policy', $rechallenge['challenge_policy'] );
		$this->assertSame( 'code_sent', $rechallenge['challenge']['status'] );
		$this->assertSame( $pending['continuation_token'], $rechallenge['continuation_token'] );
		$this->assertSame( $pending['continuation_expires_at'], $rechallenge['continuation_expires_at'] );
		$this->assertSame( 0, $this->refresh_token_count() );

		$completed = $this->continue( $rechallenge, array( 'answer' => 'valid' ) );
		$this->assertIsArray( $completed );
		$this->assertArrayHasKey( 'access_token', $completed );
	}

	public function test_rechallenge_counts_against_attempt_budget(): void {
		$this->register_policy(
			static fn() => array( 'type' => 'test' )
		);

		$pending = $this->login();
		for ( $attempt = 1; $attempt < WP_NATIVE_AUTH_CONTINUATION_MAX_ATTEMPTS; $attempt++ ) {
			$this->assertTrue( $this->continue( $pending, array() )['challenge_required'] );
		}

		$last = $this->continue( $pending, array() );
		$this->assertWPError( $last );
		$this->assertSame( 'continuation_rate_limited', $last->get_error_code() );
	}

	public function test_malformed_rechallenge_is_a_contract_error_without_burning_state(): void {
		$calls = 0;
		$this->register_policy(
			static function () use ( &$calls ) {
				++$calls;
				return 1 === $calls ? array( 'no-type' => true ) : true;
			}
		);

		$pending = $this->login();
		$error   = $this->continue( $pending, array() );
		$this->assertWPError( $error );
		$this->assertSame( 'invalid_challenge_contract', $error->get_error_code() );
		$this->assertIsArray( $this->continue( $pending, array() ) );
	}

	public function test_continue_schema_allows_rechallenge_shape(): void {
		if ( ! wp_get_ability( 'wp-native/auth-continue-login' ) ) {
			wp_native_auth_register_continue_login_ability();
		}

		$schema = wp_get_ability( 'wp-native/auth-continue-login' )->get_output_schema();
		$this->assertCount( 2, $schema['oneOf'] );
		$this->assertContains( 'continuation_token', $schema['oneOf'][1]['required'] );
	}

	public function test_external_primary_authenticator_runs_the_policy_loop(): void {
		$user = get_user_by( 'id', $this->user_id );

		$this->assertNull( wp_native_auth_challenge_login( $user, $this->device_id, array() ), 'No policy, no challenge.' );

		$seen = array();
		wp_native_auth_register_challenge_policy(
			'test-policy',
			static function ( WP_User $challenged, array $context ) use ( &$seen ) {
				$seen              = $context;
				$seen['user_seen'] = (int) $challenged->ID;
				return array( 'type' => 'test' );
			},
			static fn( $verified_user, $response ) => isset( $response['answer'] ) && 'valid' === $response['answer']
		);

		$pending = wp_native_auth_challenge_login( $user, $this->device_id, array( 'device_name' => 'Phone' ), array( 'reason' => 'identity-provider' ) );
		$this->assertIsArray( $pending );
		$this->assertTrue( $pending['challenge_required'] );
		$this->assertSame( 'test-policy', $pending['challenge_policy'] );
		$this->assertSame( 'identity-provider', $seen['reason'] );
		$this->assertSame( 'primary', $seen['phase'] );
		$this->assertSame( $this->device_id, $seen['device_id'] );
		$this->assertSame( $this->user_id, $seen['user_seen'] );

		$completed = $this->continue( $pending, array( 'answer' => 'valid' ) );
		$this->assertArrayHasKey( 'access_token', $completed );

		$session = wp_native_auth_get_session( $this->user_id, $this->device_id );
		$this->assertSame( 'test-policy', $session['challenge_policy'] );
		$this->assertSame( 'Phone', $session['device_name'] );
	}

	public function test_external_primary_authenticator_rejects_invalid_device(): void {
		$error = wp_native_auth_challenge_login( get_user_by( 'id', $this->user_id ), 'not-a-uuid', array() );
		$this->assertWPError( $error );
		$this->assertSame( 'invalid_device_id', $error->get_error_code() );
	}

	private function register_policy( $verify = null ): void {
		wp_native_auth_register_challenge_policy(
			'test-policy',
			static fn() => array( 'type' => 'test' ),
			$verify ?? static fn( $user, $response ) => isset( $response['answer'] ) && 'valid' === $response['answer']
		);
	}

	private function login() {
		$user = get_user_by( 'id', $this->user_id );
		return wp_native_auth_login_with_tokens( $user->user_login, $this->password, $this->device_id );
	}

	private function continue( array $pending, array $response ) {
		return wp_native_auth_continue_login( $pending['continuation_token'], $this->device_id, $response );
	}

	private function refresh_token_count(): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE user_id = %d', wp_native_auth_refresh_tokens_table_name(), $this->user_id ) );
	}
}
