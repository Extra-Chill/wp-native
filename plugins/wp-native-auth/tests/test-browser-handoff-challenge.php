<?php
/**
 * Browser handoffs honor challenge policies and the minting session's verification.
 *
 * @package WPNativeAuth\Tests
 */

declare(strict_types=1);

if ( ! class_exists( 'WP_UnitTestCase' ) ) {
	return;
}

/** @group auth @group security */
class Test_WP_Native_Auth_Browser_Handoff_Challenge extends WP_UnitTestCase {
	private const DESTINATION = 'https://example.org/account/orders/';

	private int $user_id;
	private string $device_id       = '';
	private string $password        = 'correct horse battery staple';
	private string $original_client = '';
	private int $cookies            = 0;

	/** @var list<int> Cookie `$expire` values; 0 means a browser-session cookie. */
	private array $cookie_expires = array();

	/** @var list<array{0:string,1:WP_User}> */
	private array $logins = array();

	/** @var list<array{0:int,1:WP_Error}> */
	private array $refusals = array();

	public function set_up(): void {
		parent::set_up();
		wp_native_auth_install_refresh_tokens_table();
		$this->user_id = self::factory()->user->create(
			array(
				'user_login' => 'handoff-user-' . wp_generate_uuid4(),
				'user_pass'  => $this->password,
			)
		);

		$this->device_id                              = wp_generate_uuid4();
		$this->original_client                        = isset( $_SERVER['HTTP_WP_NATIVE_CLIENT'] ) ? (string) $_SERVER['HTTP_WP_NATIVE_CLIENT'] : '';
		$_SERVER['HTTP_WP_NATIVE_CLIENT']             = 'test-client';
		$GLOBALS['wp_native_auth_challenge_policies'] = array();
		$this->cookies                                = 0;
		$this->cookie_expires                         = array();
		$this->logins                                 = array();
		$this->refusals                               = array();

		add_filter( 'send_auth_cookies', '__return_false' );
		add_action( 'set_auth_cookie', array( $this, 'count_cookie' ), 10, 2 );
		add_action( 'wp_login', array( $this, 'record_login' ), 10, 2 );
		add_action( 'wp_native_auth_browser_handoff_refused', array( $this, 'record_refusal' ), 10, 2 );
	}

	public function tear_down(): void {
		$GLOBALS['wp_native_auth_challenge_policies'] = array();
		unset( $_SERVER['HTTP_AUTHORIZATION'] );
		$_SERVER['HTTP_WP_NATIVE_CLIENT'] = $this->original_client;
		remove_filter( 'send_auth_cookies', '__return_false' );
		remove_action( 'set_auth_cookie', array( $this, 'count_cookie' ) );
		remove_action( 'wp_login', array( $this, 'record_login' ), 10 );
		remove_action( 'wp_native_auth_browser_handoff_refused', array( $this, 'record_refusal' ), 10 );
		remove_all_filters( 'wp_native_auth_pre_browser_handoff' );
		remove_all_filters( 'wp_native_auth_browser_handoff_remember' );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	public function count_cookie( $auth_cookie = '', $expire = 0 ): void {
		++$this->cookies;
		$this->cookie_expires[] = (int) $expire;
	}

	public function record_login( $user_login, $user ): void {
		$this->logins[] = array( (string) $user_login, $user );
	}

	public function record_refusal( $user_id, $error ): void {
		$this->refusals[] = array( (int) $user_id, $error );
	}

	public function test_unchallenged_user_hands_off_and_fires_wp_login(): void {
		$this->login_and_activate_bearer();
		$token = wp_native_auth_create_handoff_token( $this->user_id );

		$location = $this->consume( $token );

		$this->assertSame( self::DESTINATION, $location );
		$this->assertGreaterThan( 0, $this->cookies );
		$this->assertSame( $this->user_id, get_current_user_id() );
		$this->assertCount( 1, $this->logins );
		$this->assertSame( $this->user_id, (int) $this->logins[0][1]->ID );
		$this->assertSame( array(), $this->refusals );
	}

	public function test_handoff_sets_a_persistent_cookie_by_default(): void {
		$this->login_and_activate_bearer();

		$location = $this->consume( wp_native_auth_create_handoff_token( $this->user_id ) );

		$this->assertSame( self::DESTINATION, $location );
		$this->assertNotEmpty( $this->cookie_expires );
		foreach ( $this->cookie_expires as $expire ) {
			$this->assertGreaterThan( time(), $expire, 'remembered cookies carry an expiry' );
		}
	}

	public function test_remember_filter_can_request_a_session_cookie(): void {
		$seen = array();
		add_filter(
			'wp_native_auth_browser_handoff_remember',
			static function ( $remember, $user, $handoff ) use ( &$seen ) {
				$seen[] = array( $remember, (int) $user->ID, $handoff );
				return false;
			},
			10,
			3
		);
		$this->login_and_activate_bearer();

		$location = $this->consume( wp_native_auth_create_handoff_token( $this->user_id ) );

		$this->assertSame( self::DESTINATION, $location );
		$this->assertCount( 1, $seen );
		$this->assertTrue( $seen[0][0] );
		$this->assertSame( $this->user_id, $seen[0][1] );
		$this->assertSame( $this->user_id, $seen[0][2]['user_id'] );
		$this->assertSame( $this->device_id, $seen[0][2]['device_id'] );
		$this->assertNotEmpty( $this->cookie_expires );
		foreach ( $this->cookie_expires as $expire ) {
			$this->assertSame( 0, $expire, 'session cookies have no expiry' );
		}
	}

	public function test_token_records_the_minting_bearer_session(): void {
		$this->login_and_activate_bearer();
		$token = wp_native_auth_create_handoff_token( $this->user_id );

		$handoff = wp_native_auth_consume_handoff_token( $token );

		$this->assertIsArray( $handoff );
		$this->assertSame( $this->user_id, $handoff['user_id'] );
		$this->assertSame( $this->device_id, $handoff['device_id'] );
		$this->assertNull( wp_native_auth_consume_handoff_token( $token ), 'tokens stay single-use' );
	}

	public function test_bearer_session_of_another_user_is_not_recorded(): void {
		$this->login_and_activate_bearer();
		$other = self::factory()->user->create();

		$handoff = wp_native_auth_consume_handoff_token( wp_native_auth_create_handoff_token( $other ) );

		$this->assertIsArray( $handoff );
		$this->assertNull( $handoff['device_id'] );
	}

	public function test_verified_session_hands_off_when_policy_challenges(): void {
		$this->register_policy();
		$completed = wp_native_auth_continue_login( $this->login()['continuation_token'], $this->device_id, array( 'answer' => 'valid' ) );
		$this->assertIsArray( $completed );
		$this->activate_bearer( $completed['access_token'] );

		$location = $this->consume( wp_native_auth_create_handoff_token( $this->user_id ) );

		$this->assertSame( self::DESTINATION, $location );
		$this->assertGreaterThan( 0, $this->cookies );
		$this->assertCount( 1, $this->logins );
	}

	public function test_session_that_never_passed_the_policy_is_refused(): void {
		// A session from before the policy applied to this user.
		$this->login_and_activate_bearer();
		$this->register_policy();

		$location = $this->consume( wp_native_auth_create_handoff_token( $this->user_id ) );

		$this->assert_refused( $location, 'handoff_challenge_required' );
		$this->assertSame( 'test-policy', $this->refusals[0][1]->get_error_data()['challenge_policy'] );
	}

	public function test_session_verified_by_another_policy_is_refused(): void {
		$this->login_and_activate_bearer();
		$this->assertTrue( wp_native_auth_mark_session_verified( $this->user_id, $this->device_id, 'other-policy' ) );
		$this->register_policy();

		$location = $this->consume( wp_native_auth_create_handoff_token( $this->user_id ) );

		$this->assert_refused( $location, 'handoff_challenge_required' );
	}

	public function test_handoff_without_a_session_is_refused_when_policy_challenges(): void {
		$this->register_policy();

		// Minted from a cookie or server-side call: no bearer session to vouch for it.
		wp_set_current_user( $this->user_id );
		$token = wp_native_auth_create_handoff_token( $this->user_id );
		wp_set_current_user( 0 );

		$location = $this->consume( $token );

		$this->assert_refused( $location, 'handoff_challenge_required' );
	}

	public function test_handoff_without_a_session_still_works_without_policies(): void {
		$location = $this->consume( wp_native_auth_create_handoff_token( $this->user_id ) );

		$this->assertSame( self::DESTINATION, $location );
		$this->assertGreaterThan( 0, $this->cookies );
	}

	public function test_revoked_minting_session_is_refused(): void {
		$this->login_and_activate_bearer();
		$token = wp_native_auth_create_handoff_token( $this->user_id );
		$this->assertTrue( wp_native_auth_revoke_refresh_token( $this->user_id, $this->device_id ) );

		$location = $this->consume( $token );

		$this->assert_refused( $location, 'handoff_session_inactive' );
	}

	public function test_policies_are_probed_with_the_handoff_reason(): void {
		$seen = array();
		wp_native_auth_register_challenge_policy(
			'probe-policy',
			static function ( WP_User $user, array $context ) use ( &$seen ) {
				$seen[] = array( (int) $user->ID, $context );
				return null;
			},
			'__return_true'
		);
		$this->login_and_activate_bearer();

		$this->consume( wp_native_auth_create_handoff_token( $this->user_id ) );

		$this->assertCount( 1, $seen );
		$this->assertSame( $this->user_id, $seen[0][0] );
		$this->assertSame( 'browser_handoff', $seen[0][1]['reason'] );
		$this->assertSame( 'browser_handoff', $seen[0][1]['phase'] );
		$this->assertSame( $this->device_id, $seen[0][1]['device_id'] );
	}

	public function test_policy_error_refuses_the_handoff(): void {
		wp_native_auth_register_challenge_policy(
			'broken-policy',
			static fn() => new WP_Error( 'policy_down', 'Down.' ),
			'__return_true'
		);

		$location = $this->consume( wp_native_auth_create_handoff_token( $this->user_id ) );

		$this->assert_refused( $location, 'policy_down' );
	}

	public function test_filter_can_refuse_but_not_allow(): void {
		$calls = 0;
		add_filter(
			'wp_native_auth_pre_browser_handoff',
			static function ( $blocked, $user, $context ) use ( &$calls ) {
				++$calls;
				return null === $context['challenge_policy'] ? new WP_Error( 'integration_refused', 'No.' ) : $blocked;
			},
			10,
			3
		);

		$this->login_and_activate_bearer();
		$this->assert_refused( $this->consume( wp_native_auth_create_handoff_token( $this->user_id ) ), 'integration_refused' );

		// A policy refusal never reaches the filter, so the filter cannot override it.
		$this->refusals = array();
		$this->register_policy();
		add_filter( 'wp_native_auth_pre_browser_handoff', '__return_null', 99 );
		$this->login_and_activate_bearer();
		$this->assert_refused( $this->consume( wp_native_auth_create_handoff_token( $this->user_id ) ), 'handoff_challenge_required' );
		$this->assertSame( 1, $calls );
	}

	public function test_invalid_token_goes_to_login_without_a_cookie(): void {
		$location = wp_native_auth_process_browser_handoff( 'not-a-real-token', self::DESTINATION );

		$this->assertSame( wp_login_url( self::DESTINATION ), $location );
		$this->assertSame( 0, $this->cookies );
		$this->assertSame( 'invalid_handoff', $this->refusals[0][1]->get_error_code() );
	}

	public function test_refused_handoff_for_signed_in_browser_goes_to_destination(): void {
		$this->login_and_activate_bearer();
		$this->register_policy();
		$token = wp_native_auth_create_handoff_token( $this->user_id );
		unset( $_SERVER['HTTP_AUTHORIZATION'] );

		// This browser already has its own cookie session for the user.
		wp_set_current_user( $this->user_id );
		$location = wp_native_auth_process_browser_handoff( $token, self::DESTINATION );

		$this->assertSame( self::DESTINATION, $location );
		$this->assertSame( 0, $this->cookies, 'no new session is minted' );
		$this->assertSame( array(), $this->logins );
	}

	public function test_refused_redirect_is_filterable(): void {
		$this->register_policy();
		add_filter(
			'wp_native_auth_browser_handoff_refused_redirect',
			static fn( $location, $destination ) => 'https://example.org/sign-in/?to=' . rawurlencode( $destination ),
			10,
			2
		);

		$location = $this->consume( wp_native_auth_create_handoff_token( $this->user_id ) );
		remove_all_filters( 'wp_native_auth_browser_handoff_refused_redirect' );

		$this->assertSame( 'https://example.org/sign-in/?to=' . rawurlencode( self::DESTINATION ), $location );
	}

	public function test_receiver_destination_drops_the_handoff_param(): void {
		$original               = $_SERVER['REQUEST_URI'] ?? '';
		$_SERVER['REQUEST_URI'] = '/shop/cart/?item=7&wp-native-handoff=abc';

		$destination            = wp_native_auth_browser_handoff_destination();
		$_SERVER['REQUEST_URI'] = $original;

		$home = wp_parse_url( home_url() );
		$this->assertSame( $home['scheme'] . '://' . $home['host'] . ( isset( $home['port'] ) ? ':' . $home['port'] : '' ) . '/shop/cart/?item=7', $destination );
	}

	private function assert_refused( string $location, string $code ): void {
		$this->assertSame( wp_login_url( self::DESTINATION ), $location );
		$this->assertSame( 0, $this->cookies, 'a refused handoff sets no cookie' );
		$this->assertSame( array(), $this->logins, 'a refused handoff does not fire wp_login' );
		$this->assertSame( 0, get_current_user_id() );
		$this->assertCount( 1, $this->refusals );
		$this->assertSame( $this->user_id, $this->refusals[0][0] );
		$this->assertSame( $code, $this->refusals[0][1]->get_error_code() );
	}

	/** Consume a token in a fresh, signed-out browser request. */
	private function consume( string $token ): string {
		unset( $_SERVER['HTTP_AUTHORIZATION'] );
		wp_set_current_user( 0 );

		return wp_native_auth_process_browser_handoff( $token, self::DESTINATION );
	}

	private function register_policy(): void {
		wp_native_auth_register_challenge_policy(
			'test-policy',
			static fn() => array( 'type' => 'test' ),
			static fn( $user, $response ) => isset( $response['answer'] ) && 'valid' === $response['answer']
		);
	}

	private function login() {
		$user = get_user_by( 'id', $this->user_id );
		return wp_native_auth_login_with_tokens( $user->user_login, $this->password, $this->device_id );
	}

	/** Password login with no policy registered, then act as that bearer session. */
	private function login_and_activate_bearer(): void {
		$this->device_id = wp_generate_uuid4();
		$saved           = $GLOBALS['wp_native_auth_challenge_policies'];

		$GLOBALS['wp_native_auth_challenge_policies'] = array();
		$tokens                                       = $this->login();
		$GLOBALS['wp_native_auth_challenge_policies'] = $saved;

		$this->assertIsArray( $tokens );
		$this->assertArrayHasKey( 'access_token', $tokens );
		$this->activate_bearer( $tokens['access_token'] );
	}

	private function activate_bearer( string $access_token ): void {
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $access_token;
		wp_set_current_user( $this->user_id );
	}
}
