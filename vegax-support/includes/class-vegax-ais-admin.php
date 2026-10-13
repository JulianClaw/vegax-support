<?php
/**
 * Admin settings screen (WooCommerce > Vegax Support).
 *
 * @package Vegax_Support
 */

defined( 'ABSPATH' ) || exit;

/**
 * Settings UI + actions.
 */
class Vegax_AIS_Admin {

	const PAGE = 'vegax-support';

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_vegax_ais_save', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_vegax_ais_sync', array( __CLASS__, 'handle_sync' ) );
		add_action( 'admin_post_vegax_ais_test', array( __CLASS__, 'handle_test' ) );
		add_action( 'admin_post_vegax_ais_pair', array( __CLASS__, 'handle_pair' ) );
		add_action( 'admin_post_vegax_ais_disconnect', array( __CLASS__, 'handle_disconnect' ) );
		// 06/10/2026 — Intercom lesson ①: OAuth-style connect (authorize page + callback + state).
		add_action( 'admin_post_vegax_ais_oauth_start', array( __CLASS__, 'handle_oauth_start' ) );
		// The one-click link from the office lands here with ?vegax_pair=<code>.
		add_action( 'admin_init', array( __CLASS__, 'maybe_pair_from_url' ) );
		// the OAuth callback returns here with ?vegax_oauth_cb=1&code=..&state=..
		add_action( 'admin_init', array( __CLASS__, 'maybe_oauth_callback' ) );
	}

	/**
	 * Menu entry under WooCommerce.
	 *
	 * @return void
	 */
	public static function menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Vegax Support', 'vegax-support' ),
			__( 'Vegax Support', 'vegax-support' ),
			'manage_woocommerce',
			self::PAGE,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Capability + nonce guard for the form actions.
	 *
	 * @param string $action Action name.
	 * @return void
	 */
	protected static function guard( $action ) {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You are not allowed to change these settings.', 'vegax-support' ) );
		}
		check_admin_referer( $action );
	}

	/**
	 * Save settings.
	 *
	 * @return void
	 */
	public static function handle_save() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You are not allowed to change these settings.', 'vegax-support' ) );
		}
		check_admin_referer( 'vegax_ais_save' );
		$patch = array(
			'api_base'              => Vegax_AIS_Safe::post_url( 'api_base' ),
			'store_token'           => Vegax_AIS_Safe::post_text( 'store_token' ),
			'store_secret'          => Vegax_AIS_Safe::post_text( 'store_secret' ),
			'agent_name'            => Vegax_AIS_Safe::post_text( 'agent_name', 'Vegax Support' ),
			'brand_color'           => Vegax_AIS_Safe::post_color( 'brand_color', '#07A87F' ),
			'widget_enabled'        => Vegax_AIS_Safe::post_bool( 'widget_enabled' ),
			'order_lookup_enabled'  => Vegax_AIS_Safe::post_bool( 'order_lookup_enabled' ),
			'share_customer_email'  => Vegax_AIS_Safe::post_bool( 'share_customer_email' ),
			'consent_sync'          => Vegax_AIS_Safe::post_bool( 'consent_sync' ),
			'identity_verification' => Vegax_AIS_Safe::post_bool( 'identity_verification' ),
			'max_products'          => Vegax_AIS_Safe::post_int( 'max_products', 300, 1, 2000 ),
			'faqs_text'             => Vegax_AIS_Safe::post_textarea( 'faqs_text' ),
			'custom_rules_text'     => Vegax_AIS_Safe::post_textarea( 'custom_rules_text' ),
			'store_id_override'     => strtolower( Vegax_AIS_Safe::post_text( 'store_id_override' ) ),
		);
		if ( '' === $patch['api_base'] ) {
			$patch['api_base'] = VEGAX_AIS_API_DEFAULT;
		}
		if ( '' === $patch['brand_color'] ) {
			$patch['brand_color'] = '#07A87F';
		}
		// 13/10/2026 — the credential fields no longer print the saved value back into the HTML
		// (any shop_manager could read the secret in the page source). An empty field therefore
		// means "keep the saved value"; Disconnect is the way to erase credentials.
		$current = get_option( VEGAX_AIS_OPTION, array() );
		$current = is_array( $current ) ? $current : array();
		foreach ( array( 'store_token', 'store_secret' ) as $vegax_key ) {
			if ( '' === $patch[ $vegax_key ] && ! empty( $current[ $vegax_key ] ) ) {
				$patch[ $vegax_key ] = (string) $current[ $vegax_key ];
			}
		}
		Vegax_AIS_Settings::update( $patch );
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'      => self::PAGE,
					'vegax_msg' => 'saved',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Redeem a one-time pairing code for this store's credentials (01/10/2026).
	 *
	 * Kept as a plain method that RETURNS a result so it can be exercised outside a browser as well.
	 * The code is single-use, expires in 30 minutes and is bound to the domain it was issued for, so
	 * a code belonging to another site cannot be replayed here.
	 *
	 * @param string $code Pairing code from the office.
	 * @return array{ok:bool,message:string,store?:string}
	 */
	public static function pair( $code ) {
		$code = trim( (string) $code );
		if ( '' === $code ) {
			return array(
				'ok'      => false,
				'message' => __( 'No pairing code was supplied.', 'vegax-support' ),
			);
		}
		return self::redeem(
			'/api/woo/pair',
			array(
				'code'   => $code,
				'site'   => home_url(),
				'plugin' => VEGAX_AIS_VERSION,
			)
		);
	}

	/**
	 * Redeem a connect code / pairing code for this store's credentials.
	 *
	 * ONE implementation shared by BOTH the legacy paste-the-code path and the new OAuth path,
	 * so the two can never drift apart.
	 *
	 * @param string $path    Service path, e.g. /api/woo/pair or /api/woo/oauth/redeem.
	 * @param array  $payload JSON body to POST.
	 * @return array{ok:bool,message:string,store?:string}
	 */
	public static function redeem( $path, array $payload ) {
		$api  = untrailingslashit( Vegax_AIS_Settings::api_base() );
		$resp = wp_remote_post(
			$api . $path,
			array(
				'timeout' => 25,
				'headers' => array(
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				),
				'body'    => wp_json_encode( $payload ),
			)
		);
		if ( is_wp_error( $resp ) ) {
			return array(
				'ok'      => false,
				'message' => $resp->get_error_message(),
			);
		}
		$http = (int) wp_remote_retrieve_response_code( $resp );
		$body = json_decode( (string) wp_remote_retrieve_body( $resp ), true );
		if ( 200 !== $http || ! is_array( $body ) || empty( $body['ok'] ) ) {
			$why = is_array( $body ) && ! empty( $body['detail'] )
				? (string) $body['detail']
				/* translators: %d: HTTP status code. */
				: sprintf( __( 'Vegax answered HTTP %d', 'vegax-support' ), $http );
			return array(
				'ok'      => false,
				'message' => $why,
			);
		}

		$saved = get_option( VEGAX_AIS_OPTION, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		$saved['store_token']       = (string) $body['store_token'];
		$saved['store_secret']      = (string) $body['store_secret'];
		$saved['widget_enabled']    = 1;
		$saved['store_id_override'] = (string) ( $body['store_id'] ?? '' );
		// 13/10/2026 — connecting the store IS the opt-in: the merchant approves the data
		// sharing described on the Vegax authorization page, and the readme states the
		// freshly installed checkbox is unticked until the store connects. The settings
		// checkbox remains the control: unticking it stops every outbound request.
		$saved['consent_sync'] = '1';
		if ( ! empty( $body['agent'] ) ) {
			$saved['agent_name'] = sanitize_text_field( (string) $body['agent'] );
		}
		if ( ! empty( $body['color'] ) && sanitize_hex_color( (string) $body['color'] ) ) {
			$saved['brand_color'] = sanitize_hex_color( (string) $body['color'] );
		}
		update_option( VEGAX_AIS_OPTION, $saved );
		update_option( 'vegax_ais_version', VEGAX_AIS_VERSION );
		// 06/10/2026 — keep the expiring pair the service just issued (expiry read from the
		// response, never assumed). A response without a pair leaves the static credentials
		// untouched, so the old connect path keeps working.
		if ( class_exists( 'Vegax_AIS_Tokens' ) ) {
			Vegax_AIS_Tokens::store( $body );
		}

		return array(
			'ok'      => true,
			'store'   => (string) $body['store_id'],
			'message' => __( 'Connected to Vegax - the widget is live.', 'vegax-support' ),
		);
	}

	/**
	 * The one-click path: ?vegax_pair=<code> in wp-admin.
	 *
	 * @return void
	 */
	public static function maybe_pair_from_url() {
		if ( ! is_admin() || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$code = isset( $_GET['vegax_pair'] ) ? sanitize_text_field( wp_unslash( $_GET['vegax_pair'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- this GET only STAGES the code; the credential write happens on the nonce-protected Connect POST below.
		if ( '' === $code ) {
			return;
		}
		// 13/10/2026 (WordPress.org review: "the use of nonces") — a GET link must not write
		// credentials. The code is only STAGED here; the connect happens when the administrator
		// presses the nonce-protected Connect button on the settings screen.
		set_transient( 'vegax_ais_pair_pending', $code, 10 * MINUTE_IN_SECONDS );
		// The code is single-use, so it must not sit in the URL bar or in browser history.
		wp_safe_redirect( add_query_arg( 'vegax_msg', 'pair_confirm', self::page_url() ) );
		exit;
	}

	/**
	 * Form handler for the paste-the-code fallback.
	 *
	 * @return void
	 */
	public static function handle_pair() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You are not allowed to change these settings.', 'vegax-support' ) );
		}
		check_admin_referer( 'vegax_ais_pair' );
		$code = isset( $_POST['vegax_pair_code'] ) ? sanitize_text_field( wp_unslash( $_POST['vegax_pair_code'] ) ) : '';
		$res  = self::pair( $code );
		set_transient( 'vegax_ais_pair_msg', (string) ( $res['message'] ?? '' ), 180 );
		wp_safe_redirect( add_query_arg( 'vegax_msg', ! empty( $res['ok'] ) ? 'paired' : 'pair_error', self::page_url() ) );
		exit;
	}

	/**
	 * This plugin's OAuth callback URL — where the office sends the merchant back.
	 *
	 * @return string
	 */
	public static function oauth_callback_url() {
		return add_query_arg( 'vegax_oauth_cb', '1', self::page_url() );
	}

	/**
	 * Start the OAuth-style connect (Intercom lesson ①, 06/10/2026).
	 *
	 * Mint a random `state`, remember it for the callback, then send the browser to the office
	 * AUTHORIZE page which clearly NAMES the store before the merchant approves.
	 *
	 * @return void
	 */
	public static function handle_oauth_start() {
		self::guard( 'vegax_ais_oauth_start' );
		$state = bin2hex( random_bytes( 16 ) );
		set_transient( 'vegax_ais_oauth_state', $state, 15 * MINUTE_IN_SECONDS );
		$url = add_query_arg(
			array(
				'store'    => Vegax_AIS_Settings::store_id(),
				'state'    => $state,
				'redirect' => self::oauth_callback_url(),
			),
			trailingslashit( Vegax_AIS_Settings::api_base() ) . 'woo/authorize'
		);
		wp_redirect( $url ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- external authorize page by design.
		exit;
	}

	/**
	 * The office sent the browser back here with ?vegax_oauth_cb=1&code=..&state=..
	 *
	 * The `state` is compared against the one stored before the trip using a constant-time
	 * comparison: a mismatch (CSRF) refuses the connect and nothing is saved. On success the
	 * one-time code + state are exchanged for this store's credentials.
	 *
	 * @return void
	 */
	public static function maybe_oauth_callback() {
		if ( ! is_admin() || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		if ( ! isset( $_GET['vegax_oauth_cb'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OAuth callback; CSRF is covered by the constant-time state check below.
			return;
		}
		$code  = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OAuth callback; CSRF is covered by the constant-time state check below.
		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OAuth callback; CSRF is covered by the constant-time state check below.
		$want  = (string) get_transient( 'vegax_ais_oauth_state' );
		delete_transient( 'vegax_ais_oauth_state' );

		if ( '' === $code || ! self::state_matches( $want, $state ) ) {
			set_transient(
				'vegax_ais_pair_msg',
				__( 'The connect link could not be verified (the security state did not match). Ask for a fresh link.', 'vegax-support' ),
				180
			);
			wp_safe_redirect( add_query_arg( 'vegax_msg', 'pair_error', self::page_url() ) );
			exit;
		}
		$res = self::redeem(
			'/api/woo/oauth/redeem',
			array(
				'code'   => $code,
				'state'  => $state,
				'site'   => home_url(),
				'plugin' => VEGAX_AIS_VERSION,
			)
		);
		set_transient( 'vegax_ais_pair_msg', (string) ( $res['message'] ?? '' ), 180 );
		wp_safe_redirect( add_query_arg( 'vegax_msg', ! empty( $res['ok'] ) ? 'paired' : 'pair_error', self::page_url() ) );
		exit;
	}

	/**
	 * Constant-time comparison of the OAuth `state` we stored against the one returned.
	 *
	 * Extracted so it can be unit-tested on its own and so the callback reads as one idea:
	 * a missing or mismatched state is a CSRF attempt and must never be accepted.
	 *
	 * @param string $expected Value stored before the trip.
	 * @param string $given    Value returned in the callback.
	 * @return bool
	 */
	public static function state_matches( $expected, $given ) {
		$expected = (string) $expected;
		$given    = (string) $given;
		if ( '' === $expected || '' === $given ) {
			return false;
		}
		return hash_equals( $expected, $given );
	}

	/**
	 * Disconnect from the Vegax service: clear credentials and consent.
	 *
	 * @return void
	 */
	public static function handle_disconnect() {
		self::guard( 'vegax_ais_disconnect' );
		$saved = get_option( VEGAX_AIS_OPTION, array() );
		if ( is_array( $saved ) ) {
			unset( $saved['store_token'] );
			unset( $saved['store_secret'] );
			unset( $saved['store_id_override'] );
			// 13/10/2026 — the expiring-token pair is a credential too. Leaving it behind kept
			// the refresh cron talking to the service after "Disconnect", which contradicts the
			// promise that disconnecting stops all outbound traffic.
			unset( $saved['access_token'] );
			unset( $saved['refresh_token'] );
			unset( $saved['access_token_expires_at'] );
			unset( $saved['refresh_token_expires_at'] );
			unset( $saved['token_refresh_failed'] );
			$saved['consent_sync'] = '0';
			update_option( VEGAX_AIS_OPTION, $saved );
		}
		delete_transient( 'vegax_ais_refresh_lock' );
		// Every job this plugin schedules goes, so "disconnect" removes the scheduled jobs
		// exactly as the readme says. The jobs come back on their own once the store is
		// connected again (see the self-heal in vegax-support.php).
		wp_clear_scheduled_hook( 'vegax_ais_cron_refresh' );
		wp_clear_scheduled_hook( 'vegax_ais_cron_heartbeat' );
		wp_clear_scheduled_hook( 'vegax_ais_cron_sync' );
		Vegax_AIS_Settings::set_status( array() );
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'      => self::PAGE,
					'vegax_msg' => 'disconnected',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * URL of this plugin's own admin screen.
	 *
	 * @return string
	 */
	public static function page_url() {
		return admin_url( 'admin.php?page=' . self::PAGE );
	}

	/**
	 * Push a catalogue sync now (form handler).
	 *
	 * @return void
	 */
	public static function handle_sync() {
		self::guard( 'vegax_ais_sync' );
		$out = Vegax_AIS_Cloud::send_knowledge();
		$msg = is_wp_error( $out ) ? 'error' : 'synced';
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'      => self::PAGE,
					'vegax_msg' => $msg,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Send a heartbeat now.
	 *
	 * @return void
	 */
	public static function handle_test() {
		self::guard( 'vegax_ais_test' );
		$out = Vegax_AIS_Cloud::send_heartbeat();
		$msg = is_wp_error( $out ) ? 'error' : 'connected';
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'      => self::PAGE,
					'vegax_msg' => $msg,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Render the screen.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$s         = Vegax_AIS_Settings::all();
		$status    = Vegax_AIS_Settings::status();
		$msg       = isset( $_GET['vegax_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['vegax_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only notice key, whitelisted against $notes and escaped before output.
		$pair_note = (string) get_transient( 'vegax_ais_pair_msg' );
		delete_transient( 'vegax_ais_pair_msg' );
		// 13/10/2026 — saved credentials are never printed back into the HTML. The placeholder
		// only tells the administrator a value is already stored.
		$vegax_mask   = '••••••••';
		$pending_code = (string) get_transient( 'vegax_ais_pair_pending' );
		$notes = array(
			'saved'        => __( 'Settings saved.', 'vegax-support' ),
			'synced'       => __( 'Catalogue synced with Vegax.', 'vegax-support' ),
			'connected'    => __( 'Vegax answered the test connection.', 'vegax-support' ),
			'error'        => __( 'Vegax could not be reached - check the token/secret and the API address.', 'vegax-support' ),
			'paired'       => __( 'Connected in one click - the widget is live on your store.', 'vegax-support' ),
			'pair_error'   => __( 'The pairing link could not be used - ask the office for a fresh one.', 'vegax-support' ),
			'disconnected' => __( 'Disconnected from Vegax. The widget has been removed, the stored credentials were deleted and data sharing has stopped.', 'vegax-support' ),
			'pair_confirm' => __( 'Pairing code received. Press Connect below to finish connecting this store - a link on its own never changes your settings.', 'vegax-support' ),
		);
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Vegax Support', 'vegax-support' ); ?></h1>

			<?php if ( $msg && isset( $notes[ $msg ] ) ) : ?>
				<div class="notice <?php echo 'error' === $msg ? 'notice-error' : 'notice-success'; ?> is-dismissible">
					<p><?php echo esc_html( $notes[ $msg ] ); ?></p>
				</div>
				<?php if ( '' !== $pair_note ) : ?>
				<div class="notice <?php echo 'pair_error' === $msg ? 'notice-error' : 'notice-success'; ?> is-dismissible">
					<p><?php echo esc_html( $pair_note ); ?></p>
				</div>
			<?php endif; ?>
			<?php endif; ?>

			<?php if ( class_exists( 'Vegax_AIS_Tokens' ) && Vegax_AIS_Tokens::failed() ) : ?>
				<div class="notice notice-error">
					<p><strong><?php esc_html_e( 'Vegax connection expired.', 'vegax-support' ); ?></strong>
					<?php esc_html_e( 'The access token could not be renewed automatically, so the store has paused sending data to Vegax. Click “Reconnect with Vegax” below to restore it.', 'vegax-support' ); ?></p>
				</div>
			<?php endif; ?>

			<table class="widefat striped" style="max-width:760px;margin-bottom:20px">
				<tbody>
					<tr><td><strong><?php esc_html_e( 'Store key', 'vegax-support' ); ?></strong></td><td><code><?php echo esc_html( Vegax_AIS_Settings::store_id() ); ?></code></td></tr>
					<tr><td><strong><?php esc_html_e( 'Connected', 'vegax-support' ); ?></strong></td><td><?php echo Vegax_AIS_Settings::is_connected() ? esc_html__( 'Yes', 'vegax-support' ) : esc_html__( 'No - add the token and secret', 'vegax-support' ); ?></td></tr>
					<?php if ( class_exists( 'Vegax_AIS_Tokens' ) && Vegax_AIS_Tokens::has_pair() ) : ?>
						<tr><td><strong><?php esc_html_e( 'Access token', 'vegax-support' ); ?></strong></td><td>
						<?php
						if ( Vegax_AIS_Tokens::failed() ) {
							esc_html_e( 'Expired - needs reconnecting', 'vegax-support' );
						} elseif ( '' !== Vegax_AIS_Tokens::access_token() ) {
							$vegax_exp = (int) Vegax_AIS_Settings::get( 'access_token_expires_at' );
							printf(
								/* translators: %s: date and time the access token expires. */
								esc_html__( 'Valid until %s', 'vegax-support' ),
								esc_html( date_i18n( 'Y-m-d H:i', $vegax_exp ) )
							);
						} else {
							esc_html_e( 'Renewing in the background…', 'vegax-support' );
						}
						?>
						</td></tr>
					<?php endif; ?>
					<tr><td><strong><?php esc_html_e( 'Order storage (HPOS)', 'vegax-support' ); ?></strong></td><td><?php echo Vegax_AIS_Orders::hpos_enabled() ? esc_html__( 'High-Performance Order Storage', 'vegax-support' ) : esc_html__( 'Legacy post tables', 'vegax-support' ); ?></td></tr>
					<tr><td><strong><?php esc_html_e( 'Products in catalogue', 'vegax-support' ); ?></strong></td><td><?php echo (int) Vegax_AIS_Knowledge::product_count(); ?></td></tr>
					<tr><td><strong><?php esc_html_e( 'Last heartbeat', 'vegax-support' ); ?></strong></td><td><?php echo ! empty( $status['last_heartbeat'] ) ? esc_html( date_i18n( 'Y-m-d H:i', (int) $status['last_heartbeat'] ) ) : esc_html__( 'never', 'vegax-support' ); ?></td></tr>
					<tr><td><strong><?php esc_html_e( 'Last catalogue sync', 'vegax-support' ); ?></strong></td><td><?php echo ! empty( $status['last_sync'] ) ? esc_html( date_i18n( 'Y-m-d H:i', (int) $status['last_sync'] ) ) : esc_html__( 'never', 'vegax-support' ); ?></td></tr>
					<?php if ( ! empty( $status['last_error'] ) ) : ?>
						<tr><td><strong><?php esc_html_e( 'Last error', 'vegax-support' ); ?></strong></td><td><?php echo esc_html( (string) $status['last_error'] ); ?></td></tr>
					<?php endif; ?>
				</tbody>
			</table>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="vegax_ais_save" />
				<?php wp_nonce_field( 'vegax_ais_save' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="api_base"><?php esc_html_e( 'Vegax API address', 'vegax-support' ); ?></label></th>
						<td><input name="api_base" id="api_base" type="url" class="regular-text" value="<?php echo esc_attr( (string) $s['api_base'] ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="store_token"><?php esc_html_e( 'Store token', 'vegax-support' ); ?></label></th>
						<td><input name="store_token" id="store_token" type="password" class="regular-text" value="" placeholder="<?php echo esc_attr( $vegax_mask ); ?>" autocomplete="off" />
						<p class="description"><?php esc_html_e( 'Saved values are not shown again. Leave this empty to keep the stored token.', 'vegax-support' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><label for="store_secret"><?php esc_html_e( 'Store secret', 'vegax-support' ); ?></label></th>
						<td><input name="store_secret" id="store_secret" type="password" class="regular-text" value="" placeholder="<?php echo esc_attr( $vegax_mask ); ?>" autocomplete="off" />
						<p class="description"><?php esc_html_e( 'Given to you by Vegax. Used to sign every request in both directions. Saved values are not shown again &mdash; leave this empty to keep the stored secret.', 'vegax-support' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><label for="store_id_override"><?php esc_html_e( 'Shop domain override', 'vegax-support' ); ?></label></th>
						<td><input name="store_id_override" id="store_id_override" type="text" class="regular-text" value="<?php echo esc_attr( (string) $s['store_id_override'] ); ?>" placeholder="shop.example.com" />
						<p class="description"><?php esc_html_e( 'Leave empty to use this site\'s own domain. Set it only when the public shop domain differs from the WordPress address (proxy, CDN or staging).', 'vegax-support' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><label for="agent_name"><?php esc_html_e( 'Agent name', 'vegax-support' ); ?></label></th>
						<td><input name="agent_name" id="agent_name" type="text" class="regular-text" value="<?php echo esc_attr( (string) $s['agent_name'] ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="brand_color"><?php esc_html_e( 'Widget colour', 'vegax-support' ); ?></label></th>
						<td><input name="brand_color" id="brand_color" type="text" class="regular-text" value="<?php echo esc_attr( (string) $s['brand_color'] ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Features', 'vegax-support' ); ?></th>
						<td>
							<label><input type="checkbox" name="widget_enabled" value="1" <?php checked( 1, (int) $s['widget_enabled'] ); ?> /> <?php esc_html_e( 'Show the chat widget on the storefront', 'vegax-support' ); ?></label><br />
							<label><input type="checkbox" name="order_lookup_enabled" value="1" <?php checked( 1, (int) $s['order_lookup_enabled'] ); ?> /> <?php esc_html_e( 'Allow the agent to look up a customer\'s own order status', 'vegax-support' ); ?></label><br />
							<label><input type="checkbox" name="share_customer_email" value="1" <?php checked( 1, (int) $s['share_customer_email'] ); ?> /> <?php esc_html_e( 'Send the customer e-mail with order events (needed for proactive order updates)', 'vegax-support' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="max_products"><?php esc_html_e( 'Products per sync', 'vegax-support' ); ?></label></th>
						<td><input name="max_products" id="max_products" type="number" min="1" max="2000" value="<?php echo (int) $s['max_products']; ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="faqs_text"><?php esc_html_e( 'FAQs (one per line: question | answer)', 'vegax-support' ); ?></label></th>
						<td><textarea name="faqs_text" id="faqs_text" rows="6" class="large-text code"><?php echo esc_textarea( (string) $s['faqs_text'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Answers the assistant will use word for word. Example: Do you ship abroad? | Yes, to the US, Canada, UK and Australia.', 'vegax-support' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><label for="custom_rules_text"><?php esc_html_e( 'Store rules (one per line)', 'vegax-support' ); ?></label></th>
						<td><textarea name="custom_rules_text" id="custom_rules_text" rows="5" class="large-text code"><?php echo esc_textarea( (string) $s['custom_rules_text'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Hard rules the assistant must always follow. Example: Never promise a delivery date. Always offer store credit first.', 'vegax-support' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Data sharing', 'vegax-support' ); ?></th>
						<td>
							<label><input type="checkbox" name="consent_sync" value="1" <?php checked( 1, (int) $s['consent_sync'] ); ?> /> <?php esc_html_e( 'I agree that this store sends its catalogue, its policy pages and order status events to the Vegax service.', 'vegax-support' ); ?></label>
							<p class="description"><?php esc_html_e( 'Tick this to allow your store to send data to Vegax. Nothing is sent until the store is connected here and you agree.', 'vegax-support' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Identity verification', 'vegax-support' ); ?></th>
						<td>
							<label><input type="checkbox" name="identity_verification" value="1" <?php checked( 1, (int) $s['identity_verification'] ); ?> /> <?php esc_html_e( 'Verify the identity of signed-in customers', 'vegax-support' ); ?></label>
							<p class="description"><?php esc_html_e( 'Off by default. Switch it on to stop a chat ever being answered for the wrong person: a signed-in shopper is then tagged with a short-lived signed token so Vegax can prove who the conversation belongs to. Recommended on stores with customer accounts. The signing key is this store\'s own secret (settings above) and is never shown here.', 'vegax-support' ); ?></p>
						</td>
					</tr>
				</table>
				<p><button class="button button-primary" type="submit"><?php esc_html_e( 'Save settings', 'vegax-support' ); ?></button></p>
			</form>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-right:8px">
				<input type="hidden" name="action" value="vegax_ais_test" />
				<?php wp_nonce_field( 'vegax_ais_test' ); ?>
				<button class="button" type="submit"><?php esc_html_e( 'Test connection', 'vegax-support' ); ?></button>
			</form>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block">
				<input type="hidden" name="action" value="vegax_ais_sync" />
				<?php wp_nonce_field( 'vegax_ais_sync' ); ?>
				<button class="button" type="submit"><?php esc_html_e( 'Sync catalogue now', 'vegax-support' ); ?></button>
			</form>

			<hr />

			<h2><?php esc_html_e( 'Connect to Vegax', 'vegax-support' ); ?></h2>
			<p class="description" style="max-width:760px">
				<?php esc_html_e( 'Click Connect with Vegax to approve this store on the Vegax site. You will see which store is asking before you approve, and you come straight back here — no keys to copy.', 'vegax-support' ); ?>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom:14px">
				<input type="hidden" name="action" value="vegax_ais_oauth_start" />
				<?php wp_nonce_field( 'vegax_ais_oauth_start' ); ?>
				<button class="button button-primary" type="submit"><?php echo ( class_exists( 'Vegax_AIS_Tokens' ) && Vegax_AIS_Tokens::failed() ) ? esc_html__( 'Reconnect with Vegax', 'vegax-support' ) : esc_html__( 'Connect with Vegax', 'vegax-support' ); ?></button>
			</form>
			<details style="max-width:760px">
				<summary style="cursor:pointer"><?php esc_html_e( 'Have a pairing code instead? (older stores)', 'vegax-support' ); ?></summary>
				<p class="description"><?php esc_html_e( 'Backward-compatible path: paste a one-time pairing code from Vegax. Stores that connected this way keep working exactly as before.', 'vegax-support' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="vegax_ais_pair" />
					<?php wp_nonce_field( 'vegax_ais_pair' ); ?>
					<input name="vegax_pair_code" type="text" class="regular-text" autocomplete="off" value="<?php echo esc_attr( $pending_code ); ?>" placeholder="<?php echo esc_attr__( 'One-time pairing code', 'vegax-support' ); ?>" />
					<button class="button button-secondary" type="submit"><?php esc_html_e( 'Connect', 'vegax-support' ); ?></button>
				</form>
			</details>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-left:8px">
				<input type="hidden" name="action" value="vegax_ais_disconnect" />
				<?php wp_nonce_field( 'vegax_ais_disconnect' ); ?>
				<button class="button button-link-delete" type="submit" onclick="return confirm('<?php echo esc_js( __( 'Disconnect from Vegax? The store will stop responding until you reconnect.', 'vegax-support' ) ); ?>')"><?php esc_html_e( 'Disconnect', 'vegax-support' ); ?></button>
			</form>

			<p class="description" style="max-width:760px;margin-top:18px">
				<?php esc_html_e( 'Vegax receives your product catalogue, your policy pages and order status events so the agent can answer customers. Customer e-mail addresses are hashed unless you enable the e-mail option above. No order addresses or payment details are ever sent.', 'vegax-support' ); ?>
			</p>
		</div>
		<?php
	}
}
