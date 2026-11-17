<?php
/**
 * Tests for push-token registration and the generic send primitive (#118).
 *
 * @package WPNativeAuth\Tests
 */

declare(strict_types=1);

if ( ! class_exists( 'WP_UnitTestCase' ) ) {
	return;
}

/**
 * @group auth
 * @group push
 */
class Test_WP_Native_Auth_Push_Tokens extends WP_UnitTestCase {

	/**
	 * Device ids are fresh per test: refresh rotation looks rows up by
	 * device id alone, and other suites' DDL can commit fixture rows.
	 *
	 * @var string
	 */
	private $phone;

	/** @var string */
	private $tablet;

	/** @var string */
	private $laptop;

	/** @var string */
	private $other;

	/** @var int */
	private $user_id;

	/** @var int */
	private $other_user_id;

	/** @var array<int,array<string,mixed>> Captured outbound HTTP requests. */
	private $requests = array();

	/** @var array<int,array<int,mixed>> Captured action calls keyed by hook. */
	private $events = array();

	/** @var callable|null Builds the mocked provider response for a request body. */
	private $responder;

	public function set_up(): void {
		parent::set_up();

		wp_native_auth_install_refresh_tokens_table();

		$this->user_id       = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->other_user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->requests      = array();
		$this->events        = array();
		$this->responder     = null;
		$this->phone         = wp_generate_uuid4();
		$this->tablet        = wp_generate_uuid4();
		$this->laptop        = wp_generate_uuid4();
		$this->other         = wp_generate_uuid4();

		foreach ( array( 'wp_native_auth_push_token_registered', 'wp_native_auth_push_token_cleared', 'wp_native_auth_push_sent' ) as $hook ) {
			add_action(
				$hook,
				function ( ...$args ) use ( $hook ) {
					$this->events[ $hook ][] = $args;
				},
				10,
				3
			);
		}

		add_filter( 'pre_http_request', array( $this, 'mock_http' ), 10, 3 );
		add_filter( 'wp_native_auth_push_use_action_scheduler', '__return_false' );
	}

	public function tear_down(): void {
		unset( $_SERVER['HTTP_AUTHORIZATION'] );
		_set_cron_array( array() );
		parent::tear_down();
	}

	/**
	 * Mocked transport: record the request and answer with $this->responder.
	 *
	 * @param false|array|WP_Error $pre  Short-circuit value.
	 * @param array<string,mixed>  $args Request args.
	 * @param string               $url  Request URL.
	 * @return array<string,mixed>
	 */
	public function mock_http( $pre, array $args, string $url ): array {
		$messages         = json_decode( (string) $args['body'], true );
		$this->requests[] = array(
			'url'      => $url,
			'messages' => $messages,
		);

		$tickets = null !== $this->responder
			? call_user_func( $this->responder, $messages )
			: array_map(
				static fn() => array(
					'status' => 'ok',
					'id'     => wp_generate_uuid4(),
				),
				$messages
			);

		return array(
			'headers'  => array(),
			'body'     => (string) wp_json_encode( array( 'data' => $tickets ) ),
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	// ---------------------------------------------------------------------
	// Migration.
	// ---------------------------------------------------------------------

	public function test_schema_v7_adds_nullable_push_columns(): void {
		$this->assertGreaterThanOrEqual( 7, WP_NATIVE_AUTH_SCHEMA_VERSION );
		$this->assertSame( WP_NATIVE_AUTH_SCHEMA_VERSION, (int) get_site_option( WP_NATIVE_AUTH_SCHEMA_VERSION_OPTION ) );

		$columns = $this->push_columns();

		$this->assertArrayHasKey( 'push_token', $columns );
		$this->assertArrayHasKey( 'push_provider', $columns );
		$this->assertSame( 'varchar(255)', strtolower( (string) $columns['push_token']->Type ) );
		$this->assertSame( 'varchar(32)', strtolower( (string) $columns['push_provider']->Type ) );
		$this->assertSame( 'YES', $columns['push_token']->Null );
		$this->assertSame( 'YES', $columns['push_provider']->Null );
	}

	// ---------------------------------------------------------------------
	// Register / replace / unregister.
	// ---------------------------------------------------------------------

	public function test_register_binds_token_to_the_device_session_row(): void {
		wp_native_auth_issue_refresh_token( $this->user_id, $this->phone );

		$result = wp_native_auth_register_push_token( $this->user_id, $this->phone, 'token-phone' );

		$this->assertSame(
			array(
				'registered' => true,
				'device_id'  => $this->phone,
				'provider'   => 'expo',
			),
			$result
		);
		$this->assertSame( array( 'token-phone', 'expo' ), $this->stored( $this->user_id, $this->phone ) );
		$this->assertSame( array( array( $this->user_id, $this->phone, 'expo' ) ), $this->events['wp_native_auth_push_token_registered'] );
	}

	public function test_reregistering_replaces_the_previous_token(): void {
		wp_native_auth_issue_refresh_token( $this->user_id, $this->phone );
		wp_native_auth_register_push_token( $this->user_id, $this->phone, 'token-old' );

		wp_native_auth_register_push_token( $this->user_id, $this->phone, 'token-new' );

		$this->assertSame( array( 'token-new', 'expo' ), $this->stored( $this->user_id, $this->phone ) );
		$this->assertSame( 0, $this->count_token( 'token-old' ) );
	}

	public function test_same_token_on_another_user_device_is_cleared_there_first(): void {
		wp_native_auth_issue_refresh_token( $this->other_user_id, $this->other );
		wp_native_auth_issue_refresh_token( $this->user_id, $this->phone );
		wp_native_auth_register_push_token( $this->other_user_id, $this->other, 'shared-handset' );

		wp_native_auth_register_push_token( $this->user_id, $this->phone, 'shared-handset' );

		$this->assertSame( array( null, null ), $this->stored( $this->other_user_id, $this->other ) );
		$this->assertSame( array( 'shared-handset', 'expo' ), $this->stored( $this->user_id, $this->phone ) );
		$this->assertSame( 1, $this->count_token( 'shared-handset' ) );
		$this->assertContains( array( $this->other_user_id, $this->other, 'reassigned' ), $this->events['wp_native_auth_push_token_cleared'] );
	}

	public function test_unregister_clears_the_token(): void {
		wp_native_auth_issue_refresh_token( $this->user_id, $this->phone );
		wp_native_auth_register_push_token( $this->user_id, $this->phone, 'token-phone' );

		$this->assertTrue( wp_native_auth_clear_push_token( $this->user_id, $this->phone, 'unregistered' ) );
		$this->assertFalse( wp_native_auth_clear_push_token( $this->user_id, $this->phone, 'unregistered' ), 'Idempotent.' );

		$this->assertSame( array( null, null ), $this->stored( $this->user_id, $this->phone ) );
		$this->assertSame( array( array( $this->user_id, $this->phone, 'unregistered' ) ), $this->events['wp_native_auth_push_token_cleared'] );
	}

	public function test_register_rejects_inactive_sessions_bad_tokens_and_unknown_providers(): void {
		$this->assertSame( 'session_not_found', wp_native_auth_register_push_token( $this->user_id, $this->phone, 'tok' )->get_error_code() );

		wp_native_auth_issue_refresh_token( $this->user_id, $this->phone );
		$this->assertSame( 'invalid_push_token', wp_native_auth_register_push_token( $this->user_id, $this->phone, 'has space' )->get_error_code() );
		$this->assertSame( 'invalid_push_token', wp_native_auth_register_push_token( $this->user_id, $this->phone, str_repeat( 'a', 256 ) )->get_error_code() );
		$this->assertSame( 'unsupported_push_provider', wp_native_auth_register_push_token( $this->user_id, $this->phone, 'tok', 'nope' )->get_error_code() );

		wp_native_auth_revoke_refresh_token( $this->user_id, $this->phone );
		$this->assertSame( 'session_not_found', wp_native_auth_register_push_token( $this->user_id, $this->phone, 'tok' )->get_error_code() );
	}

	public function test_custom_transport_registered_through_the_filter_is_accepted(): void {
		add_filter(
			'wp_native_auth_push_transports',
			static function ( array $transports ): array {
				$transports['custom'] = static fn( array $deliveries ): array => array_fill( 0, count( $deliveries ), array( 'status' => 'ok' ) );
				return $transports;
			}
		);
		wp_native_auth_issue_refresh_token( $this->user_id, $this->phone );

		$this->assertSame( 'custom', wp_native_auth_register_push_token( $this->user_id, $this->phone, 'tok', 'custom' )['provider'] );
	}

	// ---------------------------------------------------------------------
	// Abilities (bearer session context).
	// ---------------------------------------------------------------------

	public function test_abilities_bind_to_the_calling_bearer_session(): void {
		$register   = wp_get_ability( 'wp-native-auth/register-push-token' );
		$unregister = wp_get_ability( 'wp-native-auth/unregister-push-token' );
		$this->assertNotNull( $register );
		$this->assertNotNull( $unregister );
		$this->assertTrue( $register->get_meta_item( 'show_in_rest' ) );
		$this->assertTrue( $unregister->get_meta_item( 'show_in_rest' ) );

		wp_native_auth_issue_refresh_token( $this->user_id, $this->phone );
		wp_native_auth_issue_refresh_token( $this->user_id, $this->tablet );
		$this->act_as_bearer( $this->user_id, $this->tablet );

		$result = $register->execute( array( 'token' => 'ExponentPushToken[tablet]' ) );

		$this->assertSame( $this->tablet, $result['device_id'] );
		$this->assertSame( 'expo', $result['provider'] );
		$this->assertSame( array( 'ExponentPushToken[tablet]', 'expo' ), $this->stored( $this->user_id, $this->tablet ) );
		$this->assertSame( array( null, null ), $this->stored( $this->user_id, $this->phone ) );

		$this->assertSame( array( 'cleared' => true ), $unregister->execute() );
		$this->assertSame( array( null, null ), $this->stored( $this->user_id, $this->tablet ) );
	}

	public function test_abilities_require_a_bearer_device_session(): void {
		wp_native_auth_issue_refresh_token( $this->user_id, $this->phone );
		wp_set_current_user( $this->user_id ); // Cookie-style login, no bearer header.

		$result = wp_get_ability( 'wp-native-auth/register-push-token' )->execute( array( 'token' => 'tok' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'bearer_session_required', $result->get_error_code() );
	}

	public function test_oauth_bound_sessions_cannot_register_push_tokens(): void {
		wp_native_auth_issue_refresh_token( $this->user_id, $this->phone, 'Client', array( 'oauth_client_id' => 'https://client.example.org/meta' ) );

		$this->assertSame( 'push_requires_native_session', wp_native_auth_register_push_token( $this->user_id, $this->phone, 'tok' )->get_error_code() );
	}

	// ---------------------------------------------------------------------
	// Session lifecycle clears tokens.
	// ---------------------------------------------------------------------

	public function test_logout_and_remote_revoke_clear_the_device_token(): void {
		$this->register_devices();

		// Logout (current device) and revoke-session (another device) share the primitive.
		wp_native_auth_revoke_refresh_token( $this->user_id, $this->phone );

		$this->assertSame( array( null, null ), $this->stored( $this->user_id, $this->phone ) );
		$this->assertSame( array( 'token-tablet', 'expo' ), $this->stored( $this->user_id, $this->tablet ) );
		$this->assertContains( array( $this->user_id, $this->phone, 'logout' ), $this->events['wp_native_auth_push_token_cleared'] );
	}

	public function test_logout_and_revoke_session_ability_callbacks_clear_tokens(): void {
		$this->register_devices();
		$this->act_as_bearer( $this->user_id, $this->tablet );

		$this->assertTrue( wp_native_auth_execute_revoke_session_ability( array( 'device_id' => $this->phone ) ) );
		$this->assertTrue( wp_native_auth_execute_logout_ability( array( 'device_id' => $this->tablet ) ) );

		$this->assertSame( array( null, null ), $this->stored( $this->user_id, $this->phone ) );
		$this->assertSame( array( null, null ), $this->stored( $this->user_id, $this->tablet ) );
	}

	public function test_user_wide_revocation_clears_every_user_token_only(): void {
		$this->register_devices();

		wp_set_password( 'Changed-password-118!', $this->user_id );

		$this->assertSame( array( null, null ), $this->stored( $this->user_id, $this->phone ) );
		$this->assertSame( array( null, null ), $this->stored( $this->user_id, $this->tablet ) );
		$this->assertSame( array( 'token-other', 'expo' ), $this->stored( $this->other_user_id, $this->other ) );
	}

	public function test_refresh_family_revocation_clears_the_token(): void {
		$issued = wp_native_auth_issue_refresh_token( $this->user_id, $this->phone );
		wp_native_auth_register_push_token( $this->user_id, $this->phone, 'token-phone' );

		$rotated = wp_native_auth_refresh_tokens( $issued['token'], $this->phone );
		$this->assertIsArray( $rotated );
		$this->assertSame( array( 'token-phone', 'expo' ), $this->stored( $this->user_id, $this->phone ), 'Rotation keeps the destination.' );

		delete_transient( 'wp_native_auth_refresh_' . md5( $this->phone ) );
		$replay = wp_native_auth_refresh_tokens( $issued['token'], $this->phone );

		$this->assertSame( 'refresh_token_reused', $replay->get_error_code() );
		$this->assertSame( array( null, null ), $this->stored( $this->user_id, $this->phone ) );
		$this->assertContains( array( $this->user_id, $this->phone, 'family_revoked' ), $this->events['wp_native_auth_push_token_cleared'] );
	}

	// ---------------------------------------------------------------------
	// Send: queueing, fan-out, invalidation.
	// ---------------------------------------------------------------------

	public function test_send_queues_a_background_job_without_contacting_the_provider(): void {
		$this->register_devices();

		$summary = wp_native_auth_send_push( $this->user_id, array( 'title' => 'Hello' ) );

		$this->assertTrue( $summary['queued'] );
		$this->assertSame( array(), $this->requests, 'Sending must not block on the provider.' );
		$this->assertCount( 1, $this->queued_jobs() );
		$this->assertSame( $summary['job_id'], $this->queued_jobs()[0]['job_id'] );
		$this->assertEqualsCanonicalizing(
			array( $this->phone, $this->tablet ),
			array_column( $summary['devices'], 'device_id' )
		);
		$this->assertSame( array( 'queued' ), array_values( array_unique( array_column( $summary['devices'], 'status' ) ) ) );
	}

	public function test_identical_messages_queue_distinct_jobs(): void {
		$this->register_devices();

		wp_native_auth_send_push( $this->user_id, array( 'title' => 'Same' ) );
		wp_native_auth_send_push( $this->user_id, array( 'title' => 'Same' ) );

		$this->assertCount( 2, $this->queued_jobs() );
	}

	public function test_fan_out_reaches_only_active_sessions_with_tokens(): void {
		global $wpdb;

		$this->register_devices();
		wp_native_auth_issue_refresh_token( $this->user_id, $this->laptop ); // Active, no token.

		// An expired session that still holds a token at rest.
		$wpdb->update(
			wp_native_auth_refresh_tokens_table_name(),
			array( 'expires_at' => wp_native_auth_mysql_gmt( time() - 60 ) ),
			array(
				'user_id'   => $this->user_id,
				'device_id' => $this->tablet,
			)
		);

		$summary = wp_native_auth_send_push(
			$this->user_id,
			array(
				'title'       => 'Title',
				'body'        => 'Body',
				'data'        => array( 'route' => '/inbox' ),
				'collapse_id' => 'thread-7',
			)
		);
		$this->assertSame( array( $this->phone ), array_column( $summary['devices'], 'device_id' ) );
		$this->assertSame( array( null, null ), $this->stored( $this->user_id, $this->tablet ), 'Expired sessions keep no token.' );

		$delivered = $this->run_queued_jobs();

		$this->assertCount( 1, $this->requests );
		$this->assertSame( WP_NATIVE_AUTH_EXPO_PUSH_ENDPOINT, $this->requests[0]['url'] );
		$this->assertSame( array( 'token-phone' ), array_column( $this->requests[0]['messages'], 'to' ) );
		$this->assertSame( 'Title', $this->requests[0]['messages'][0]['title'] );
		$this->assertSame( 'Body', $this->requests[0]['messages'][0]['body'] );
		$this->assertSame(
			array(
				'route'       => '/inbox',
				'collapse_id' => 'thread-7',
			),
			$this->requests[0]['messages'][0]['data']
		);
		$this->assertSame( 1, $delivered[0]['sent'] );
		$this->assertSame( 'ok', $delivered[0]['devices'][0]['status'] );
		$this->assertCount( 1, $this->events['wp_native_auth_push_sent'] );
		$this->assertSame( $this->user_id, $this->events['wp_native_auth_push_sent'][0][0] );
	}

	public function test_session_revoked_after_queueing_receives_nothing(): void {
		$this->register_devices();
		wp_native_auth_send_push( $this->user_id, array( 'title' => 'Late' ) );

		wp_native_auth_revoke_refresh_token( $this->user_id, $this->phone );
		$this->run_queued_jobs();

		$this->assertCount( 1, $this->requests );
		$this->assertSame( array( 'token-tablet' ), array_column( $this->requests[0]['messages'], 'to' ) );
	}

	public function test_send_without_deliverable_devices_or_title_queues_nothing(): void {
		$this->assertSame( 'no_devices', wp_native_auth_send_push( $this->user_id, array( 'title' => 'x' ) )['error'] );

		$this->register_devices();
		$this->assertSame( 'invalid_message', wp_native_auth_send_push( $this->user_id, array( 'body' => 'x' ) )['error'] );
		$this->assertSame( array(), $this->queued_jobs() );
	}

	public function test_device_not_registered_clears_the_token(): void {
		$this->register_devices();
		$this->responder = static function ( array $messages ): array {
			return array_map(
				static function ( array $message ): array {
					if ( 'token-phone' === $message['to'] ) {
						return array(
							'status'  => 'error',
							'message' => 'not a registered push notification recipient',
							'details' => array( 'error' => 'DeviceNotRegistered' ),
						);
					}
					return array(
						'status' => 'ok',
						'id'     => 'ticket-1',
					);
				},
				$messages
			);
		};

		wp_native_auth_send_push( $this->user_id, array( 'title' => 'Hi' ) );
		$delivered = $this->run_queued_jobs()[0];

		$this->assertSame( 1, $delivered['sent'] );
		$this->assertSame( 1, $delivered['failed'] );
		$this->assertSame( 1, $delivered['invalidated'] );
		$this->assertSame( array( null, null ), $this->stored( $this->user_id, $this->phone ) );
		$this->assertSame( array( 'token-tablet', 'expo' ), $this->stored( $this->user_id, $this->tablet ) );
		$this->assertContains( array( $this->user_id, $this->phone, 'invalid_token' ), $this->events['wp_native_auth_push_token_cleared'] );

		$by_device = array_column( $delivered['devices'], null, 'device_id' );
		$this->assertSame( 'invalid_token', $by_device[ $this->phone ]['status'] );
		$this->assertSame( 'ticket-1', $by_device[ $this->tablet ]['id'] );
	}

	public function test_other_provider_errors_keep_the_token(): void {
		$this->register_devices();
		$this->responder = static fn( array $messages ): array => array_fill(
			0,
			count( $messages ),
			array(
				'status'  => 'error',
				'details' => array( 'error' => 'MessageRateExceeded' ),
			)
		);

		wp_native_auth_send_push( $this->user_id, array( 'title' => 'Hi' ) );
		$delivered = $this->run_queued_jobs()[0];

		$this->assertSame( 0, $delivered['invalidated'] );
		$this->assertSame( 'MessageRateExceeded', $delivered['devices'][0]['error'] );
		$this->assertSame( array( 'token-phone', 'expo' ), $this->stored( $this->user_id, $this->phone ) );
	}

	public function test_transport_batches_at_most_one_hundred_messages(): void {
		$deliveries = array();
		for ( $i = 0; $i < 150; $i++ ) {
			$deliveries[] = array(
				'device_id' => wp_generate_uuid4(),
				'token'     => 'token-' . $i,
			);
		}

		$receipts = wp_native_auth_expo_push_transport( $deliveries, wp_native_auth_normalize_push_message( array( 'title' => 'Bulk' ) ) );

		$this->assertCount( 2, $this->requests );
		$this->assertCount( 100, $this->requests[0]['messages'] );
		$this->assertCount( 50, $this->requests[1]['messages'] );
		$this->assertCount( 150, $receipts );
		$this->assertSame( 'token-149', $this->requests[1]['messages'][49]['to'] );
	}

	// ---------------------------------------------------------------------
	// Genericity.
	// ---------------------------------------------------------------------

	public function test_vendor_name_is_confined_to_its_transport(): void {
		$root = dirname( __DIR__ );
		foreach ( array( 'inc/push.php', 'inc/abilities/push-tokens.php' ) as $file ) {
			$source = (string) file_get_contents( $root . '/' . $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local fixture read.
			$this->assertDoesNotMatchRegularExpression( '/\bexpo\b|exp\.host/i', $source, $file );
			$this->assertDoesNotMatchRegularExpression( '/extra ?chill|forum|artist/i', $source, $file );
		}
	}

	// ---------------------------------------------------------------------
	// Helpers.
	// ---------------------------------------------------------------------

	/** Issue sessions and register tokens: two for the user, one for another user. */
	private function register_devices(): void {
		wp_native_auth_issue_refresh_token( $this->user_id, $this->phone );
		wp_native_auth_issue_refresh_token( $this->user_id, $this->tablet );
		wp_native_auth_issue_refresh_token( $this->other_user_id, $this->other );
		wp_native_auth_register_push_token( $this->user_id, $this->phone, 'token-phone' );
		wp_native_auth_register_push_token( $this->user_id, $this->tablet, 'token-tablet' );
		wp_native_auth_register_push_token( $this->other_user_id, $this->other, 'token-other' );
	}

	/** Authenticate the request the way bearer-auth does. */
	private function act_as_bearer( int $user_id, string $device_id ): void {
		$access                        = wp_native_auth_generate_access_token( $user_id, $device_id );
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $access['token'];
		wp_set_current_user( $user_id );
	}

	/**
	 * @return array{0:?string,1:?string} Stored push_token and push_provider.
	 */
	private function stored( int $user_id, string $device_id ): array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT push_token, push_provider FROM %i WHERE user_id = %d AND device_id = %s',
				wp_native_auth_refresh_tokens_table_name(),
				$user_id,
				$device_id
			),
			ARRAY_A
		);

		return array( $row['push_token'] ?? null, $row['push_provider'] ?? null );
	}

	private function count_token( string $token ): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE push_token = %s', wp_native_auth_refresh_tokens_table_name(), $token )
		);
	}

	/** @return array<string,object> Column descriptions keyed by name. */
	private function push_columns(): array {
		global $wpdb;

		$columns = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SHOW COLUMNS FROM %i', wp_native_auth_refresh_tokens_table_name() ) ) as $column ) {
			$columns[ $column->Field ] = $column; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- MySQL column name.
		}

		return $columns;
	}

	/** @return array<int,array<string,mixed>> Queued delivery jobs in schedule order. */
	private function queued_jobs(): array {
		$jobs = array();
		foreach ( (array) _get_cron_array() as $hooks ) {
			foreach ( (array) ( $hooks[ WP_NATIVE_AUTH_PUSH_DELIVERY_HOOK ] ?? array() ) as $event ) {
				$jobs[] = $event['args'][0];
			}
		}

		return $jobs;
	}

	/** Run every queued delivery job as WP-Cron would. @return array<int,array<string,mixed>> */
	private function run_queued_jobs(): array {
		$results = array();
		foreach ( $this->queued_jobs() as $job ) {
			$results[] = wp_native_auth_deliver_push( $job );
		}

		return $results;
	}
}
