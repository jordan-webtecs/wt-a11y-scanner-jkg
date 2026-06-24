<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ACC_Admin {
	const CAPABILITY = 'manage_options';
	const MENU_SLUG       = 'acc-site-settings';
	const SCAN_JOBS_SLUG  = 'acc-scan-jobs';
	const VIOLATIONS_SLUG = 'acc-violations';

	public static function bootstrap() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_requests' ) );
	}

	public static function register_menu() {
		add_menu_page(
			__( 'Accessibility Scans', 'accessibility-scan-manager' ),
			__( 'Accessibility Scans', 'accessibility-scan-manager' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			array( __CLASS__, 'render_site_settings_page' ),
			'dashicons-universal-access',
			58
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Scan Jobs', 'accessibility-scan-manager' ),
			__( 'Scan Jobs', 'accessibility-scan-manager' ),
			self::CAPABILITY,
			self::SCAN_JOBS_SLUG,
			array( __CLASS__, 'render_scan_jobs_page' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Violations', 'accessibility-scan-manager' ),
			__( 'Violations', 'accessibility-scan-manager' ),
			self::CAPABILITY,
			self::VIOLATIONS_SLUG,
			array( __CLASS__, 'render_violations_page' )
		);
	}

	public static function handle_requests() {
		if ( ! is_admin() || ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$page   = isset( $_REQUEST['page'] ) ? sanitize_key( wp_unslash( $_REQUEST['page'] ) ) : '';
		$action = isset( $_REQUEST['acc_action'] ) ? sanitize_key( wp_unslash( $_REQUEST['acc_action'] ) ) : '';

		if ( ! in_array( $page, array( self::MENU_SLUG, self::SCAN_JOBS_SLUG, self::VIOLATIONS_SLUG ), true ) ) {
			return;
		}

		if ( 'save_site_settings' === $action && 'POST' === strtoupper( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			self::handle_save_site_settings();
		}

		if ( 'save_scanner_settings' === $action && 'POST' === strtoupper( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			self::handle_save_scanner_settings();
		}

		if ( self::SCAN_JOBS_SLUG === $page && 'submit_scan_job' === $action && 'POST' === strtoupper( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			self::handle_submit_scan_job();
		}

		if ( self::SCAN_JOBS_SLUG === $page && 'refresh_scan_status' === $action && 'POST' === strtoupper( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			self::handle_refresh_scan_status();
		}

		if ( self::SCAN_JOBS_SLUG === $page && 'fetch_scan_results' === $action && 'POST' === strtoupper( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			self::handle_fetch_scan_results();
		}

		if ( self::VIOLATIONS_SLUG === $page && 'save_violation_workflow' === $action && 'POST' === strtoupper( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			self::handle_save_violation_workflow();
		}
	}

	private static function handle_save_site_settings() {
		check_admin_referer( 'acc_save_site_settings' );

		$site = self::get_or_create_local_site();
		$data = self::sanitize_site_input( $_POST );

		if ( empty( $data['name'] ) || empty( $data['base_url'] ) ) {
			self::redirect_with_notice( 'site_error' );
		}

		$result = ACC_DB::update_site( (int) $site['id'], $data );

		self::redirect_with_notice( is_wp_error( $result ) ? 'site_error' : 'site_updated' );
	}

	private static function handle_save_scanner_settings() {
		check_admin_referer( 'acc_save_scanner_settings' );

		$data = self::sanitize_scanner_settings_input( $_POST );
		$api_key_to_store = $data['scanner_api_key'];

		if ( ! ACC_Plugin::uses_constant_scanner_api_key() && '' === $api_key_to_store ) {
			$api_key_to_store = get_option( ACC_Plugin::SCANNER_API_KEY_OPTION, '' );
		}

		update_option( ACC_Plugin::SCANNER_BASE_URL_OPTION, $data['scanner_base_url'] );

		if ( ! ACC_Plugin::uses_constant_scanner_api_key() ) {
			update_option( ACC_Plugin::SCANNER_API_KEY_OPTION, $api_key_to_store );
		}

		self::redirect_with_notice( 'scanner_settings_saved', self::MENU_SLUG );
	}

	private static function handle_submit_scan_job() {
		check_admin_referer( 'acc_submit_scan_job' );

		$site        = self::get_or_create_local_site();
		$scan_input  = self::sanitize_scan_trigger_input( $_POST );
		$payload     = self::build_scan_request_payload( $site, $scan_input );

		if ( is_wp_error( $payload ) ) {
			self::redirect_with_notice( 'scan_submission_invalid', self::SCAN_JOBS_SLUG );
		}

		$scan_id = ACC_DB::store_scan(
			array(
				'site_id'              => (int) $site['id'],
				'status'               => 'submitting',
				'scan_mode'            => $scan_input['scan_mode'],
				'initiated_by_user_id' => get_current_user_id(),
				'started_at'           => current_time( 'mysql' ),
				'finished_at'          => null,
				'error_message'        => null,
				'remote_job_id'        => null,
			)
		);

		if ( is_wp_error( $scan_id ) ) {
			self::redirect_with_notice( 'scan_submission_failed', self::SCAN_JOBS_SLUG );
		}

		$response = ACC_Scanner_Client::create_scan_job( $payload );

		if ( is_wp_error( $response ) ) {
			$update_result = ACC_DB::update_scan(
				(int) $scan_id,
				array(
					'status'        => 'failed',
					'finished_at'   => current_time( 'mysql' ),
					'error_message' => $response->get_error_message(),
				)
			);

			if ( is_wp_error( $update_result ) ) {
				self::redirect_with_notice( 'scan_submission_failed', self::SCAN_JOBS_SLUG );
			}

			self::redirect_with_notice(
				'scan_submission_failed',
				self::SCAN_JOBS_SLUG,
				array(
					'acc_view' => 'detail',
					'scan_id'  => (int) $scan_id,
				)
			);
		}

		$update_result = ACC_DB::update_scan(
			(int) $scan_id,
			array(
				'status'        => $response['status'],
				'remote_job_id' => $response['job_id'],
				'error_message' => null,
			)
		);

		if ( is_wp_error( $update_result ) ) {
			self::redirect_with_notice(
				'scan_submission_failed',
				self::SCAN_JOBS_SLUG,
				array(
					'acc_view' => 'detail',
					'scan_id'  => (int) $scan_id,
				)
			);
		}

		$schedule_result = ACC_Scan_Orchestrator::schedule_poll_for_scan( (int) $scan_id );

		if ( is_wp_error( $schedule_result ) ) {
			ACC_DB::update_scan(
				(int) $scan_id,
				array(
					'error_message' => $schedule_result->get_error_message(),
				)
			);

			self::redirect_with_notice(
				'scan_poll_schedule_failed',
				self::SCAN_JOBS_SLUG,
				array(
					'acc_view' => 'detail',
					'scan_id'  => (int) $scan_id,
				)
			);
		}

		self::redirect_with_notice(
			'scan_submitted',
			self::SCAN_JOBS_SLUG,
			array(
				'acc_view' => 'detail',
				'scan_id'  => (int) $scan_id,
			)
		);
	}

	private static function handle_refresh_scan_status() {
		check_admin_referer( 'acc_refresh_scan_status' );

		$site    = self::get_or_create_local_site();
		$scan_id = isset( $_POST['scan_id'] ) ? absint( wp_unslash( $_POST['scan_id'] ) ) : 0;

		if ( $scan_id <= 0 ) {
			self::redirect_with_notice( 'scan_status_refresh_failed', self::SCAN_JOBS_SLUG );
		}

		$scan = ACC_DB::get_scan_detail_for_site( (int) $site['id'], $scan_id );

		if ( empty( $scan ) || empty( $scan['remote_job_id'] ) ) {
			self::redirect_with_notice(
				'scan_status_refresh_failed',
				self::SCAN_JOBS_SLUG,
				array(
					'acc_view' => 'detail',
					'scan_id'  => $scan_id,
				)
			);
		}

		$refresh_result = ACC_Scan_Orchestrator::refresh_scan_status( $scan_id );

		if ( is_wp_error( $refresh_result ) ) {
			self::redirect_with_notice(
				'scan_status_refresh_failed',
				self::SCAN_JOBS_SLUG,
				array(
					'acc_view' => 'detail',
					'scan_id'  => $scan_id,
				)
			);
		}

		self::redirect_with_notice(
			'scan_status_refreshed',
			self::SCAN_JOBS_SLUG,
			array(
				'acc_view' => 'detail',
				'scan_id'  => $scan_id,
			)
		);
	}

	private static function handle_fetch_scan_results() {
		check_admin_referer( 'acc_fetch_scan_results' );

		$site    = self::get_or_create_local_site();
		$scan_id = isset( $_POST['scan_id'] ) ? absint( wp_unslash( $_POST['scan_id'] ) ) : 0;

		if ( $scan_id <= 0 ) {
			self::redirect_with_notice( 'scan_results_fetch_failed', self::SCAN_JOBS_SLUG );
		}

		$scan = ACC_DB::get_scan_detail_for_site( (int) $site['id'], $scan_id );

		if ( empty( $scan ) || empty( $scan['remote_job_id'] ) || 'completed' !== (string) $scan['status'] ) {
			self::redirect_with_notice(
				'scan_results_fetch_failed',
				self::SCAN_JOBS_SLUG,
				array(
					'acc_view' => 'detail',
					'scan_id'  => $scan_id,
				)
			);
		}

		$fetch_result = ACC_Scan_Orchestrator::fetch_scan_results( $scan_id );

		if ( is_wp_error( $fetch_result ) ) {
			self::redirect_with_notice(
				'scan_results_fetch_failed',
				self::SCAN_JOBS_SLUG,
				array(
					'acc_view' => 'detail',
					'scan_id'  => $scan_id,
				)
			);
		}

		self::redirect_with_notice(
			'scan_results_fetched',
			self::SCAN_JOBS_SLUG,
			array(
				'acc_view' => 'detail',
				'scan_id'  => $scan_id,
			)
		);
	}

	private static function handle_save_violation_workflow() {
		check_admin_referer( 'acc_save_violation_workflow' );

		$site         = self::get_or_create_local_site();
		$violation_id = isset( $_POST['violation_id'] ) ? absint( wp_unslash( $_POST['violation_id'] ) ) : 0;

		if ( $violation_id <= 0 ) {
			self::redirect_with_notice( 'violation_update_failed', self::VIOLATIONS_SLUG );
		}

		$violation = ACC_DB::get_violation_detail_for_site( (int) $site['id'], $violation_id );

		if ( empty( $violation ) ) {
			self::redirect_with_notice(
				'violation_update_failed',
				self::VIOLATIONS_SLUG,
				array(
					'acc_view'     => 'violation',
					'violation_id' => $violation_id,
				)
			);
		}

		$data = self::sanitize_violation_workflow_input( $_POST );

		if ( ! in_array( $data['workflow_status'], self::get_workflow_status_options(), true ) ) {
			self::redirect_with_notice(
				'violation_update_failed',
				self::VIOLATIONS_SLUG,
				array(
					'acc_view'     => 'violation',
					'violation_id' => $violation_id,
				)
			);
		}

		$update_result = ACC_DB::update_violation_workflow(
			$violation_id,
			$data['workflow_status'],
			$data['notes'],
			get_current_user_id()
		);

		self::redirect_with_notice(
			is_wp_error( $update_result ) ? 'violation_update_failed' : 'violation_updated',
			self::VIOLATIONS_SLUG,
			array(
				'acc_view'     => 'violation',
				'violation_id' => $violation_id,
			)
		);
	}

	private static function sanitize_site_input( array $source ) {
		$name        = isset( $source['name'] ) ? sanitize_text_field( wp_unslash( $source['name'] ) ) : '';
		$base_url    = isset( $source['base_url'] ) ? esc_url_raw( wp_unslash( $source['base_url'] ) ) : '';
		$sitemap_url = isset( $source['sitemap_url'] ) ? esc_url_raw( wp_unslash( $source['sitemap_url'] ) ) : '';

		return array(
			'name'        => $name,
			'base_url'    => $base_url,
			'sitemap_url' => $sitemap_url,
			'is_active'   => ! empty( $source['is_active'] ) ? 1 : 0,
		);
	}

	private static function sanitize_scanner_settings_input( array $source ) {
		$scanner_base_url = isset( $source['scanner_base_url'] ) ? untrailingslashit( esc_url_raw( wp_unslash( $source['scanner_base_url'] ) ) ) : '';
		$scanner_api_key  = isset( $source['scanner_api_key'] ) ? sanitize_text_field( wp_unslash( $source['scanner_api_key'] ) ) : '';

		return array(
			'scanner_base_url' => $scanner_base_url,
			'scanner_api_key'  => $scanner_api_key,
		);
	}

	private static function sanitize_scan_trigger_input( array $source ) {
		$scan_mode = isset( $source['scan_mode'] ) ? sanitize_key( wp_unslash( $source['scan_mode'] ) ) : 'sitemap';
		$manual_urls = isset( $source['manual_urls'] ) ? sanitize_textarea_field( wp_unslash( $source['manual_urls'] ) ) : '';

		if ( ! in_array( $scan_mode, array( 'sitemap', 'manual' ), true ) ) {
			$scan_mode = 'sitemap';
		}

		return array(
			'scan_mode'   => $scan_mode,
			'manual_urls' => $manual_urls,
		);
	}

	private static function sanitize_violation_workflow_input( array $source ) {
		$workflow_status = isset( $source['workflow_status'] ) ? sanitize_text_field( wp_unslash( $source['workflow_status'] ) ) : 'New';
		$notes           = isset( $source['notes'] ) ? sanitize_textarea_field( wp_unslash( $source['notes'] ) ) : '';

		return array(
			'workflow_status' => $workflow_status,
			'notes'           => $notes,
		);
	}

	private static function sanitize_violation_filter_input( array $source ) {
		$workflow_status = isset( $source['workflow_status'] ) ? sanitize_text_field( wp_unslash( $source['workflow_status'] ) ) : '';
		$impact          = isset( $source['impact'] ) ? sanitize_key( wp_unslash( $source['impact'] ) ) : '';
		$rule_id         = isset( $source['rule_id'] ) ? sanitize_text_field( wp_unslash( $source['rule_id'] ) ) : '';

		if ( ! in_array( $workflow_status, self::get_workflow_status_options(), true ) ) {
			$workflow_status = '';
		}

		if ( ! in_array( $impact, self::get_violation_impact_filter_options(), true ) ) {
			$impact = '';
		}

		return array(
			'workflow_status' => $workflow_status,
			'impact'          => $impact,
			'rule_id'         => $rule_id,
		);
	}

	private static function get_or_create_local_site() {
		$site = ACC_DB::get_local_site();

		if ( ! empty( $site ) ) {
			return $site;
		}

		$site_id = ACC_DB::create_site(
			array(
				'name'        => get_bloginfo( 'name' ),
				'base_url'    => home_url( '/' ),
				'sitemap_url' => '',
				'is_active'   => 1,
			)
		);

		if ( is_wp_error( $site_id ) ) {
			wp_die( esc_html__( 'The local accessibility site record could not be created.', 'accessibility-scan-manager' ) );
		}

		$site = ACC_DB::get_site( $site_id );

		if ( empty( $site ) ) {
			wp_die( esc_html__( 'The local accessibility site record could not be loaded.', 'accessibility-scan-manager' ) );
		}

		return $site;
	}

	private static function redirect_with_notice( $notice, $page = self::MENU_SLUG, array $args = array() ) {
		$url_args = wp_parse_args(
			$args,
			array(
				'page'       => sanitize_key( $page ),
				'acc_notice' => sanitize_key( $notice ),
			)
		);
		$url = add_query_arg( $url_args, admin_url( 'admin.php' ) );

		wp_safe_redirect( $url );
		exit;
	}

	public static function render_site_settings_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'accessibility-scan-manager' ) );
		}

		$site    = self::get_or_create_local_site();
		$summary = ACC_DB::get_site_summary( (int) $site['id'] );
		$scanner_base_url = ACC_Plugin::get_scanner_base_url();
		$has_scanner_api_key = ACC_Plugin::has_configured_scanner_api_key();
		$uses_constant_scanner_api_key = ACC_Plugin::uses_constant_scanner_api_key();
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Accessibility Scans', 'accessibility-scan-manager' ); ?></h1>
			<p><?php echo esc_html__( 'Site configuration for this WordPress install.', 'accessibility-scan-manager' ); ?></p>

			<?php self::render_notice(); ?>

			<h2><?php echo esc_html__( 'Site Settings', 'accessibility-scan-manager' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG ) ); ?>">
				<?php wp_nonce_field( 'acc_save_site_settings' ); ?>
				<input type="hidden" name="page" value="<?php echo esc_attr( self::MENU_SLUG ); ?>" />
				<input type="hidden" name="acc_action" value="save_site_settings" />

				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row">
								<label for="acc-site-name"><?php echo esc_html__( 'Site Name', 'accessibility-scan-manager' ); ?></label>
							</th>
							<td>
								<input name="name" id="acc-site-name" type="text" class="regular-text" required value="<?php echo esc_attr( $site['name'] ); ?>" />
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="acc-site-base-url"><?php echo esc_html__( 'Base URL', 'accessibility-scan-manager' ); ?></label>
							</th>
							<td>
								<input name="base_url" id="acc-site-base-url" type="url" class="regular-text" required value="<?php echo esc_attr( $site['base_url'] ); ?>" />
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="acc-site-sitemap-url"><?php echo esc_html__( 'Sitemap URL', 'accessibility-scan-manager' ); ?></label>
							</th>
							<td>
								<input name="sitemap_url" id="acc-site-sitemap-url" type="url" class="regular-text" value="<?php echo esc_attr( $site['sitemap_url'] ); ?>" />
								<p class="description"><?php echo esc_html__( 'Optional.', 'accessibility-scan-manager' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php echo esc_html__( 'Active', 'accessibility-scan-manager' ); ?></th>
							<td>
								<label for="acc-site-is-active">
									<input name="is_active" id="acc-site-is-active" type="checkbox" value="1" <?php checked( (int) $site['is_active'], 1 ); ?> />
									<?php echo esc_html__( 'Enable scans for this site record', 'accessibility-scan-manager' ); ?>
								</label>
							</td>
						</tr>
					</tbody>
				</table>

				<?php submit_button( __( 'Save Site Settings', 'accessibility-scan-manager' ) ); ?>
			</form>

			<hr />
			<h2><?php echo esc_html__( 'Scanner Connection', 'accessibility-scan-manager' ); ?></h2>
			<p><?php echo esc_html__( 'Connection settings for the external scanner service used by this WordPress install.', 'accessibility-scan-manager' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG ) ); ?>">
				<?php wp_nonce_field( 'acc_save_scanner_settings' ); ?>
				<input type="hidden" name="page" value="<?php echo esc_attr( self::MENU_SLUG ); ?>" />
				<input type="hidden" name="acc_action" value="save_scanner_settings" />

				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row">
								<label for="acc-scanner-base-url"><?php echo esc_html__( 'Scanner Service Base URL', 'accessibility-scan-manager' ); ?></label>
							</th>
							<td>
								<input name="scanner_base_url" id="acc-scanner-base-url" type="url" class="regular-text" value="<?php echo esc_attr( $scanner_base_url ); ?>" />
								<p class="description"><?php echo esc_html__( 'Example: https://scanner.example.internal', 'accessibility-scan-manager' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="acc-scanner-api-key"><?php echo esc_html__( 'Shared API Key', 'accessibility-scan-manager' ); ?></label>
							</th>
							<td>
								<input
									name="scanner_api_key"
									id="acc-scanner-api-key"
									type="password"
									class="regular-text"
									<?php disabled( $uses_constant_scanner_api_key ); ?>
								/>
								<?php if ( $uses_constant_scanner_api_key ) : ?>
									<p class="description"><?php echo esc_html__( 'This API key is currently provided by the ACC_SCANNER_API_KEY constant and cannot be edited here.', 'accessibility-scan-manager' ); ?></p>
								<?php elseif ( $has_scanner_api_key ) : ?>
									<p class="description"><?php echo esc_html__( 'An API key is already stored. Leave this blank to keep the current value.', 'accessibility-scan-manager' ); ?></p>
								<?php else : ?>
									<p class="description"><?php echo esc_html__( 'Stored locally for this plugin install and used for authenticated scanner requests.', 'accessibility-scan-manager' ); ?></p>
								<?php endif; ?>
							</td>
						</tr>
					</tbody>
				</table>

				<?php submit_button( __( 'Save Scanner Settings', 'accessibility-scan-manager' ) ); ?>
			</form>

			<?php if ( is_array( $summary ) ) : ?>
				<hr />
				<h2><?php echo esc_html__( 'Local Summary', 'accessibility-scan-manager' ); ?></h2>
				<table class="widefat striped" style="max-width: 720px;">
					<tbody>
						<tr>
							<th scope="row"><?php echo esc_html__( 'Scans', 'accessibility-scan-manager' ); ?></th>
							<td><?php echo esc_html( number_format_i18n( (int) $summary['scan_count'] ) ); ?></td>
						</tr>
						<tr>
							<th scope="row"><?php echo esc_html__( 'Scanned URLs', 'accessibility-scan-manager' ); ?></th>
							<td><?php echo esc_html( number_format_i18n( (int) $summary['url_count'] ) ); ?></td>
						</tr>
						<tr>
							<th scope="row"><?php echo esc_html__( 'Violations', 'accessibility-scan-manager' ); ?></th>
							<td><?php echo esc_html( number_format_i18n( (int) $summary['violation_count'] ) ); ?></td>
						</tr>
						<tr>
							<th scope="row"><?php echo esc_html__( 'Last Scan Started', 'accessibility-scan-manager' ); ?></th>
							<td>
								<?php
								echo ! empty( $summary['last_scan_at'] )
									? esc_html( $summary['last_scan_at'] )
									: esc_html__( 'No scans yet.', 'accessibility-scan-manager' );
								?>
							</td>
						</tr>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	public static function render_scan_jobs_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'accessibility-scan-manager' ) );
		}

		$site = self::get_or_create_local_site();

		if ( self::is_scan_detail_view() ) {
			self::render_scan_detail_view( $site );

			return;
		}

		$scan_jobs = ACC_DB::list_scan_jobs_for_site( (int) $site['id'] );
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Scan Jobs', 'accessibility-scan-manager' ); ?></h1>
			<p><?php echo esc_html__( 'Start a scan and review stored scan jobs for this WordPress install.', 'accessibility-scan-manager' ); ?></p>

			<?php self::render_notice(); ?>

			<h2><?php echo esc_html__( 'Start Scan', 'accessibility-scan-manager' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SCAN_JOBS_SLUG ) ); ?>">
				<?php wp_nonce_field( 'acc_submit_scan_job' ); ?>
				<input type="hidden" name="page" value="<?php echo esc_attr( self::SCAN_JOBS_SLUG ); ?>" />
				<input type="hidden" name="acc_action" value="submit_scan_job" />

				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row">
								<label for="acc-scan-mode"><?php echo esc_html__( 'Scan Mode', 'accessibility-scan-manager' ); ?></label>
							</th>
							<td>
								<select name="scan_mode" id="acc-scan-mode">
									<option value="sitemap"><?php echo esc_html__( 'Sitemap', 'accessibility-scan-manager' ); ?></option>
									<option value="manual"><?php echo esc_html__( 'Manual URL List', 'accessibility-scan-manager' ); ?></option>
								</select>
								<p class="description"><?php echo esc_html__( 'Sitemap mode uses the saved sitemap URL from Site Settings. Manual mode uses one absolute URL per line below.', 'accessibility-scan-manager' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="acc-manual-urls"><?php echo esc_html__( 'Manual URLs', 'accessibility-scan-manager' ); ?></label>
							</th>
							<td>
								<textarea name="manual_urls" id="acc-manual-urls" class="large-text code" rows="8"></textarea>
								<p class="description"><?php echo esc_html__( 'Used only for manual mode. Enter one absolute URL per line.', 'accessibility-scan-manager' ); ?></p>
							</td>
						</tr>
					</tbody>
				</table>

				<?php submit_button( __( 'Start Scan', 'accessibility-scan-manager' ) ); ?>
			</form>

			<hr />
			<h2><?php echo esc_html__( 'Stored Scan Jobs', 'accessibility-scan-manager' ); ?></h2>

			<?php if ( empty( $scan_jobs ) ) : ?>
				<div class="notice notice-info">
					<p><?php echo esc_html__( 'No scan jobs have been stored for this site yet.', 'accessibility-scan-manager' ); ?></p>
				</div>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th scope="col"><?php echo esc_html__( 'Status', 'accessibility-scan-manager' ); ?></th>
							<th scope="col"><?php echo esc_html__( 'Mode', 'accessibility-scan-manager' ); ?></th>
							<th scope="col"><?php echo esc_html__( 'Started', 'accessibility-scan-manager' ); ?></th>
							<th scope="col"><?php echo esc_html__( 'Finished', 'accessibility-scan-manager' ); ?></th>
							<th scope="col"><?php echo esc_html__( 'URL Count', 'accessibility-scan-manager' ); ?></th>
							<th scope="col"><?php echo esc_html__( 'Violation Count', 'accessibility-scan-manager' ); ?></th>
							<th scope="col"><?php echo esc_html__( 'Actions', 'accessibility-scan-manager' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $scan_jobs as $scan_job ) : ?>
							<tr>
								<td><?php echo esc_html( $scan_job['status'] ); ?></td>
								<td><?php echo esc_html( $scan_job['scan_mode'] ); ?></td>
								<td><?php echo esc_html( self::format_datetime_value( $scan_job['started_at'], __( 'Not started yet.', 'accessibility-scan-manager' ) ) ); ?></td>
								<td><?php echo esc_html( self::format_datetime_value( $scan_job['finished_at'], __( 'Not finished yet.', 'accessibility-scan-manager' ) ) ); ?></td>
								<td><?php echo esc_html( number_format_i18n( (int) $scan_job['url_count'] ) ); ?></td>
								<td><?php echo esc_html( number_format_i18n( (int) $scan_job['violation_count'] ) ); ?></td>
								<td><a href="<?php echo esc_url( self::get_scan_detail_url( $scan_job['id'] ) ); ?>"><?php echo esc_html__( 'View', 'accessibility-scan-manager' ); ?></a></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function render_scan_detail_view( array $site ) {
		$scan_id  = self::get_scan_id_param();
		$back_url = self::get_scan_jobs_page_url();
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Scan Detail', 'accessibility-scan-manager' ); ?></h1>
			<p><a href="<?php echo esc_url( $back_url ); ?>">&larr; <?php echo esc_html__( 'Back to Scan Jobs', 'accessibility-scan-manager' ); ?></a></p>
			<?php self::render_notice(); ?>
			<?php
			if ( $scan_id <= 0 ) {
				?>
				<div class="notice notice-warning">
					<p><?php echo esc_html__( 'A valid scan was not requested.', 'accessibility-scan-manager' ); ?></p>
				</div>
				<?php

				return;
			}

			$scan = ACC_DB::get_scan_detail_for_site( (int) $site['id'], $scan_id );

			if ( empty( $scan ) ) {
				?>
				<div class="notice notice-warning">
					<p><?php echo esc_html__( 'The requested scan could not be found for this site.', 'accessibility-scan-manager' ); ?></p>
				</div>
				<?php

				return;
			}

			$scan_urls = ACC_DB::list_scan_urls_for_site_scan( (int) $site['id'], (int) $scan['id'] );
			?>
			<p><?php echo esc_html__( 'Read-only detail view for one stored scan on this WordPress install.', 'accessibility-scan-manager' ); ?></p>

			<?php if ( ! empty( $scan['remote_job_id'] ) ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SCAN_JOBS_SLUG ) ); ?>" style="margin: 1em 0;">
					<?php wp_nonce_field( 'acc_refresh_scan_status' ); ?>
					<input type="hidden" name="page" value="<?php echo esc_attr( self::SCAN_JOBS_SLUG ); ?>" />
					<input type="hidden" name="acc_action" value="refresh_scan_status" />
					<input type="hidden" name="scan_id" value="<?php echo esc_attr( (string) $scan['id'] ); ?>" />
					<?php submit_button( __( 'Refresh Status', 'accessibility-scan-manager' ), 'secondary', 'submit', false ); ?>
				</form>
			<?php endif; ?>

			<?php if ( ! empty( $scan['remote_job_id'] ) && 'completed' === (string) $scan['status'] ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SCAN_JOBS_SLUG ) ); ?>" style="margin: 1em 0;">
					<?php wp_nonce_field( 'acc_fetch_scan_results' ); ?>
					<input type="hidden" name="page" value="<?php echo esc_attr( self::SCAN_JOBS_SLUG ); ?>" />
					<input type="hidden" name="acc_action" value="fetch_scan_results" />
					<input type="hidden" name="scan_id" value="<?php echo esc_attr( (string) $scan['id'] ); ?>" />
					<?php submit_button( __( 'Fetch Results', 'accessibility-scan-manager' ), 'secondary', 'submit', false ); ?>
				</form>
			<?php endif; ?>

			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Scan ID', 'accessibility-scan-manager' ); ?></th>
						<td><?php echo esc_html( number_format_i18n( (int) $scan['id'] ) ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Status', 'accessibility-scan-manager' ); ?></th>
						<td><?php echo esc_html( $scan['status'] ); ?></td>
					</tr>
					<?php if ( ! empty( $scan['remote_job_id'] ) ) : ?>
						<tr>
							<th scope="row"><?php echo esc_html__( 'Remote Job ID', 'accessibility-scan-manager' ); ?></th>
							<td><code><?php echo esc_html( $scan['remote_job_id'] ); ?></code></td>
						</tr>
					<?php endif; ?>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Mode', 'accessibility-scan-manager' ); ?></th>
						<td><?php echo esc_html( $scan['scan_mode'] ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Started', 'accessibility-scan-manager' ); ?></th>
						<td><?php echo esc_html( self::format_datetime_value( $scan['started_at'], __( 'Not started yet.', 'accessibility-scan-manager' ) ) ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Finished', 'accessibility-scan-manager' ); ?></th>
						<td><?php echo esc_html( self::format_datetime_value( $scan['finished_at'], __( 'Not finished yet.', 'accessibility-scan-manager' ) ) ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Total URLs', 'accessibility-scan-manager' ); ?></th>
						<td><?php echo esc_html( number_format_i18n( (int) $scan['url_count'] ) ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Total Violations', 'accessibility-scan-manager' ); ?></th>
						<td><?php echo esc_html( number_format_i18n( (int) $scan['violation_count'] ) ); ?></td>
					</tr>
					<?php if ( ! empty( $scan['error_message'] ) ) : ?>
						<tr>
							<th scope="row"><?php echo esc_html__( 'Error Message', 'accessibility-scan-manager' ); ?></th>
							<td><?php echo esc_html( $scan['error_message'] ); ?></td>
						</tr>
					<?php endif; ?>
				</tbody>
			</table>

			<h2><?php echo esc_html__( 'Scanned URLs', 'accessibility-scan-manager' ); ?></h2>
			<?php if ( empty( $scan_urls ) ) : ?>
				<div class="notice notice-info">
					<p><?php echo esc_html__( 'No scanned URLs are stored for this scan yet.', 'accessibility-scan-manager' ); ?></p>
				</div>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th scope="col"><?php echo esc_html__( 'URL', 'accessibility-scan-manager' ); ?></th>
							<th scope="col"><?php echo esc_html__( 'HTTP Status', 'accessibility-scan-manager' ); ?></th>
							<th scope="col"><?php echo esc_html__( 'Scanned At', 'accessibility-scan-manager' ); ?></th>
							<th scope="col"><?php echo esc_html__( 'Violation Count', 'accessibility-scan-manager' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $scan_urls as $scan_url ) : ?>
							<tr>
								<td><code><?php echo esc_html( self::get_page_display_url( $scan_url ) ); ?></code></td>
								<td><?php echo esc_html( self::format_http_status_value( $scan_url['http_status'] ) ); ?></td>
								<td><?php echo esc_html( self::format_datetime_value( $scan_url['scanned_at'], __( 'Not available.', 'accessibility-scan-manager' ) ) ); ?></td>
								<td><?php echo esc_html( number_format_i18n( (int) $scan_url['violation_count'] ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	public static function render_violations_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'accessibility-scan-manager' ) );
		}

		$site = self::get_or_create_local_site();

		if ( self::is_single_violation_view() ) {
			self::render_single_violation_view( $site );

			return;
		}

		if ( self::is_violation_detail_view() ) {
			self::render_violation_detail_view( $site );

			return;
		}

		$filters             = self::sanitize_violation_filter_input( $_GET );
		$violation_summaries = ACC_DB::list_violation_summaries_for_site( (int) $site['id'], $filters );
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Violations', 'accessibility-scan-manager' ); ?></h1>
			<p><?php echo esc_html__( 'Grouped violation summary for this WordPress install.', 'accessibility-scan-manager' ); ?></p>

			<?php self::render_notice(); ?>

			<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" style="margin: 1em 0 1.5em;">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::VIOLATIONS_SLUG ); ?>" />
				<table class="form-table" role="presentation" style="margin-top: 0;">
					<tbody>
						<tr>
							<th scope="row">
								<label for="acc-filter-workflow-status"><?php echo esc_html__( 'Workflow Status', 'accessibility-scan-manager' ); ?></label>
							</th>
							<td>
								<select name="workflow_status" id="acc-filter-workflow-status">
									<option value=""><?php echo esc_html__( 'Any Status', 'accessibility-scan-manager' ); ?></option>
									<?php foreach ( self::get_workflow_status_options() as $status_option ) : ?>
										<option value="<?php echo esc_attr( $status_option ); ?>" <?php selected( $filters['workflow_status'], $status_option ); ?>><?php echo esc_html( $status_option ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="acc-filter-impact"><?php echo esc_html__( 'Severity', 'accessibility-scan-manager' ); ?></label>
							</th>
							<td>
								<select name="impact" id="acc-filter-impact">
									<option value=""><?php echo esc_html__( 'Any Severity', 'accessibility-scan-manager' ); ?></option>
									<?php foreach ( self::get_violation_impact_filter_options() as $impact_option ) : ?>
										<option value="<?php echo esc_attr( $impact_option ); ?>" <?php selected( $filters['impact'], $impact_option ); ?>><?php echo esc_html( self::format_violation_impact( $impact_option ) ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="acc-filter-rule-id"><?php echo esc_html__( 'Rule ID', 'accessibility-scan-manager' ); ?></label>
							</th>
							<td>
								<input name="rule_id" id="acc-filter-rule-id" type="text" class="regular-text" value="<?php echo esc_attr( $filters['rule_id'] ); ?>" />
								<p class="description"><?php echo esc_html__( 'Matches stored rule IDs by partial text.', 'accessibility-scan-manager' ); ?></p>
							</td>
						</tr>
					</tbody>
				</table>

				<?php submit_button( __( 'Filter', 'accessibility-scan-manager' ), 'secondary', '', false ); ?>
				<a href="<?php echo esc_url( self::get_violations_page_url() ); ?>" class="button"><?php echo esc_html__( 'Clear', 'accessibility-scan-manager' ); ?></a>
			</form>

			<?php if ( empty( $violation_summaries ) ) : ?>
				<div class="notice notice-info">
					<p>
						<?php
						echo self::has_active_violation_filters( $filters )
							? esc_html__( 'No grouped violations matched the current filters for this site.', 'accessibility-scan-manager' )
							: esc_html__( 'No violations have been stored for this site yet.', 'accessibility-scan-manager' );
						?>
					</p>
				</div>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th scope="col"><?php echo esc_html__( 'Rule', 'accessibility-scan-manager' ); ?></th>
							<th scope="col"><?php echo esc_html__( 'Classification', 'accessibility-scan-manager' ); ?></th>
							<th scope="col"><?php echo esc_html__( 'Impact', 'accessibility-scan-manager' ); ?></th>
							<th scope="col"><?php echo esc_html__( 'Page Count', 'accessibility-scan-manager' ); ?></th>
							<th scope="col"><?php echo esc_html__( 'Occurrence Count', 'accessibility-scan-manager' ); ?></th>
							<th scope="col"><?php echo esc_html__( 'Last Seen', 'accessibility-scan-manager' ); ?></th>
							<th scope="col"><?php echo esc_html__( 'Actions', 'accessibility-scan-manager' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $violation_summaries as $violation_summary ) : ?>
							<tr>
								<td><code><?php echo esc_html( $violation_summary['rule_id'] ); ?></code></td>
								<td><?php echo esc_html( self::get_violation_classification_label( $violation_summary['tags_json'] ?? '' ) ); ?></td>
								<td><?php echo esc_html( self::format_violation_impact( $violation_summary['impact'] ) ); ?></td>
								<td><?php echo esc_html( number_format_i18n( (int) $violation_summary['page_count'] ) ); ?></td>
								<td><?php echo esc_html( number_format_i18n( (int) $violation_summary['occurrence_count'] ) ); ?></td>
								<td><?php echo esc_html( self::format_datetime_value( $violation_summary['last_seen_at'], __( 'Not available.', 'accessibility-scan-manager' ) ) ); ?></td>
								<td><a href="<?php echo esc_url( self::get_violation_detail_url( $violation_summary['rule_id'], $violation_summary['impact'] ) ); ?>"><?php echo esc_html__( 'View', 'accessibility-scan-manager' ); ?></a></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function render_violation_detail_view( array $site ) {
		$rule_id = self::get_violation_rule_id_param();
		$impact  = self::get_violation_impact_param();
		$back_url = self::get_violations_page_url();
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Violation Detail', 'accessibility-scan-manager' ); ?></h1>
			<p><a href="<?php echo esc_url( $back_url ); ?>">&larr; <?php echo esc_html__( 'Back to Violations', 'accessibility-scan-manager' ); ?></a></p>
			<?php self::render_notice(); ?>
			<?php
			if ( '' === $rule_id ) {
				?>
				<div class="notice notice-warning">
					<p><?php echo esc_html__( 'A valid violation summary was not requested.', 'accessibility-scan-manager' ); ?></p>
				</div>
				<?php

				return;
			}

			$summary = ACC_DB::get_violation_summary_for_site( (int) $site['id'], $rule_id, $impact );

			if ( empty( $summary ) ) {
				?>
				<div class="notice notice-warning">
					<p><?php echo esc_html__( 'The requested violation summary could not be found for this site.', 'accessibility-scan-manager' ); ?></p>
				</div>
				<?php

				return;
			}

			$pages = ACC_DB::list_violation_pages_for_site( (int) $site['id'], $summary['rule_id'], $summary['impact'] );
			$occurrences = ACC_DB::list_violation_occurrences_for_site( (int) $site['id'], $summary['rule_id'], $summary['impact'] );
			?>
			<p><?php echo esc_html__( 'Grouped detail view for one violation summary on this WordPress install, with links to individual stored occurrences.', 'accessibility-scan-manager' ); ?></p>

			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Rule ID', 'accessibility-scan-manager' ); ?></th>
						<td><code><?php echo esc_html( $summary['rule_id'] ); ?></code></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Impact', 'accessibility-scan-manager' ); ?></th>
						<td><?php echo esc_html( self::format_violation_impact( $summary['impact'] ) ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Classification', 'accessibility-scan-manager' ); ?></th>
						<td><?php echo esc_html( self::get_violation_classification_label( $summary['tags_json'] ?? '' ) ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Total Occurrences', 'accessibility-scan-manager' ); ?></th>
						<td><?php echo esc_html( number_format_i18n( (int) $summary['occurrence_count'] ) ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Affected Pages', 'accessibility-scan-manager' ); ?></th>
						<td><?php echo esc_html( number_format_i18n( (int) $summary['page_count'] ) ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Last Seen', 'accessibility-scan-manager' ); ?></th>
						<td><?php echo esc_html( self::format_datetime_value( $summary['last_seen_at'], __( 'Not available.', 'accessibility-scan-manager' ) ) ); ?></td>
					</tr>
					<?php if ( ! empty( $summary['description'] ) ) : ?>
						<tr>
							<th scope="row"><?php echo esc_html__( 'Description', 'accessibility-scan-manager' ); ?></th>
							<td><?php echo esc_html( $summary['description'] ); ?></td>
						</tr>
					<?php endif; ?>
					<?php if ( ! empty( $summary['help'] ) ) : ?>
						<tr>
							<th scope="row"><?php echo esc_html__( 'Help', 'accessibility-scan-manager' ); ?></th>
							<td><?php echo esc_html( $summary['help'] ); ?></td>
						</tr>
					<?php endif; ?>
					<?php if ( ! empty( $summary['help_url'] ) ) : ?>
						<tr>
							<th scope="row"><?php echo esc_html__( 'Help URL', 'accessibility-scan-manager' ); ?></th>
							<td><a href="<?php echo esc_url( $summary['help_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $summary['help_url'] ); ?></a></td>
						</tr>
					<?php endif; ?>
					<?php if ( ! empty( $summary['target_json'] ) ) : ?>
						<tr>
							<th scope="row"><?php echo esc_html__( 'Example Selectors', 'accessibility-scan-manager' ); ?></th>
							<td><code><?php echo esc_html( self::format_target_json_for_display( $summary['target_json'] ) ); ?></code></td>
						</tr>
					<?php endif; ?>
					<?php if ( ! empty( $summary['html_snippet'] ) ) : ?>
						<tr>
							<th scope="row"><?php echo esc_html__( 'Example HTML Snippet', 'accessibility-scan-manager' ); ?></th>
							<td><code><?php echo esc_html( $summary['html_snippet'] ); ?></code></td>
						</tr>
					<?php endif; ?>
				</tbody>
			</table>

			<h2><?php echo esc_html__( 'Occurrences', 'accessibility-scan-manager' ); ?></h2>
			<?php if ( empty( $occurrences ) ) : ?>
				<div class="notice notice-info">
					<p><?php echo esc_html__( 'No stored occurrences are available for this grouped violation yet.', 'accessibility-scan-manager' ); ?></p>
				</div>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th scope="col"><?php echo esc_html__( 'Violation ID', 'accessibility-scan-manager' ); ?></th>
							<th scope="col"><?php echo esc_html__( 'Page URL', 'accessibility-scan-manager' ); ?></th>
							<th scope="col"><?php echo esc_html__( 'First Seen', 'accessibility-scan-manager' ); ?></th>
							<th scope="col"><?php echo esc_html__( 'Last Seen', 'accessibility-scan-manager' ); ?></th>
							<th scope="col"><?php echo esc_html__( 'Scan State', 'accessibility-scan-manager' ); ?></th>
							<th scope="col"><?php echo esc_html__( 'Workflow Status', 'accessibility-scan-manager' ); ?></th>
							<th scope="col"><?php echo esc_html__( 'Notes', 'accessibility-scan-manager' ); ?></th>
							<th scope="col"><?php echo esc_html__( 'Actions', 'accessibility-scan-manager' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $occurrences as $occurrence ) : ?>
							<tr>
								<td><code><?php echo esc_html( (string) $occurrence['id'] ); ?></code></td>
								<td><code><?php echo esc_html( self::get_page_display_url( $occurrence ) ); ?></code></td>
								<td><?php echo esc_html( self::format_datetime_value( $occurrence['first_seen_at'], __( 'Not available.', 'accessibility-scan-manager' ) ) ); ?></td>
								<td><?php echo esc_html( self::format_datetime_value( $occurrence['last_seen_at'], __( 'Not available.', 'accessibility-scan-manager' ) ) ); ?></td>
								<td><?php echo esc_html( self::format_scan_state( $occurrence ) ); ?></td>
								<td><?php echo esc_html( self::format_workflow_status( $occurrence['workflow_status'] ) ); ?></td>
								<td><?php echo esc_html( self::get_violation_notes_excerpt( $occurrence['notes'] ?? '' ) ); ?></td>
								<td><a href="<?php echo esc_url( self::get_single_violation_url( (int) $occurrence['id'] ) ); ?>"><?php echo esc_html__( 'Edit', 'accessibility-scan-manager' ); ?></a></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<h2><?php echo esc_html__( 'Affected Pages', 'accessibility-scan-manager' ); ?></h2>
			<?php if ( empty( $pages ) ) : ?>
				<div class="notice notice-info">
					<p><?php echo esc_html__( 'No affected pages are available for this violation summary yet.', 'accessibility-scan-manager' ); ?></p>
				</div>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th scope="col"><?php echo esc_html__( 'Page URL', 'accessibility-scan-manager' ); ?></th>
							<th scope="col"><?php echo esc_html__( 'Occurrences', 'accessibility-scan-manager' ); ?></th>
							<th scope="col"><?php echo esc_html__( 'Last Seen', 'accessibility-scan-manager' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $pages as $page ) : ?>
							<tr>
								<td><code><?php echo esc_html( self::get_page_display_url( $page ) ); ?></code></td>
								<td><?php echo esc_html( number_format_i18n( (int) $page['occurrence_count'] ) ); ?></td>
								<td><?php echo esc_html( self::format_datetime_value( $page['last_seen_at'], __( 'Not available.', 'accessibility-scan-manager' ) ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function render_single_violation_view( array $site ) {
		$violation_id = self::get_violation_id_param();
		$back_url     = self::get_violations_page_url();
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Violation Occurrence', 'accessibility-scan-manager' ); ?></h1>
			<p><a href="<?php echo esc_url( $back_url ); ?>">&larr; <?php echo esc_html__( 'Back to Violations', 'accessibility-scan-manager' ); ?></a></p>
			<?php self::render_notice(); ?>
			<?php
			if ( $violation_id <= 0 ) {
				?>
				<div class="notice notice-warning">
					<p><?php echo esc_html__( 'A valid stored violation occurrence was not requested.', 'accessibility-scan-manager' ); ?></p>
				</div>
				<?php

				return;
			}

			$violation = ACC_DB::get_violation_detail_for_site( (int) $site['id'], $violation_id );

			if ( empty( $violation ) ) {
				?>
				<div class="notice notice-warning">
					<p><?php echo esc_html__( 'The requested stored violation occurrence could not be found for this site.', 'accessibility-scan-manager' ); ?></p>
				</div>
				<?php

				return;
			}

			$group_back_url = self::get_violation_detail_url( $violation['rule_id'], $violation['impact'] );
			?>
			<p><a href="<?php echo esc_url( $group_back_url ); ?>">&larr; <?php echo esc_html__( 'Back to Grouped Violation Detail', 'accessibility-scan-manager' ); ?></a></p>
			<p><?php echo esc_html__( 'Review and edit one stored violation occurrence for this WordPress install.', 'accessibility-scan-manager' ); ?></p>

			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Violation ID', 'accessibility-scan-manager' ); ?></th>
						<td><code><?php echo esc_html( (string) $violation['id'] ); ?></code></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Page URL', 'accessibility-scan-manager' ); ?></th>
						<td><code><?php echo esc_html( self::get_page_display_url( $violation ) ); ?></code></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Rule ID', 'accessibility-scan-manager' ); ?></th>
						<td><code><?php echo esc_html( $violation['rule_id'] ); ?></code></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Impact', 'accessibility-scan-manager' ); ?></th>
						<td><?php echo esc_html( self::format_violation_impact( $violation['impact'] ) ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Classification', 'accessibility-scan-manager' ); ?></th>
						<td><?php echo esc_html( self::get_violation_classification_label( $violation['tags_json'] ?? '' ) ); ?></td>
					</tr>
					<?php if ( ! empty( $violation['description'] ) ) : ?>
						<tr>
							<th scope="row"><?php echo esc_html__( 'Description', 'accessibility-scan-manager' ); ?></th>
							<td><?php echo esc_html( $violation['description'] ); ?></td>
						</tr>
					<?php endif; ?>
					<?php if ( ! empty( $violation['help'] ) ) : ?>
						<tr>
							<th scope="row"><?php echo esc_html__( 'Help', 'accessibility-scan-manager' ); ?></th>
							<td><?php echo esc_html( $violation['help'] ); ?></td>
						</tr>
					<?php endif; ?>
					<?php if ( ! empty( $violation['help_url'] ) ) : ?>
						<tr>
							<th scope="row"><?php echo esc_html__( 'Help URL', 'accessibility-scan-manager' ); ?></th>
							<td><a href="<?php echo esc_url( $violation['help_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $violation['help_url'] ); ?></a></td>
						</tr>
					<?php endif; ?>
					<?php if ( ! empty( $violation['target_json'] ) ) : ?>
						<tr>
							<th scope="row"><?php echo esc_html__( 'Selector Target', 'accessibility-scan-manager' ); ?></th>
							<td><code><?php echo esc_html( self::format_target_json_for_display( $violation['target_json'] ) ); ?></code></td>
						</tr>
					<?php endif; ?>
					<?php if ( ! empty( $violation['html_snippet'] ) ) : ?>
						<tr>
							<th scope="row"><?php echo esc_html__( 'HTML Snippet', 'accessibility-scan-manager' ); ?></th>
							<td><code><?php echo esc_html( $violation['html_snippet'] ); ?></code></td>
						</tr>
					<?php endif; ?>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Fingerprint', 'accessibility-scan-manager' ); ?></th>
						<td><code><?php echo esc_html( $violation['fingerprint'] ); ?></code></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'First Seen', 'accessibility-scan-manager' ); ?></th>
						<td><?php echo esc_html( self::format_datetime_value( $violation['first_seen_at'], __( 'Not available.', 'accessibility-scan-manager' ) ) ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Last Seen', 'accessibility-scan-manager' ); ?></th>
						<td><?php echo esc_html( self::format_datetime_value( $violation['last_seen_at'], __( 'Not available.', 'accessibility-scan-manager' ) ) ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Scan State', 'accessibility-scan-manager' ); ?></th>
						<td><?php echo esc_html( self::format_scan_state( $violation ) ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Current Workflow Status', 'accessibility-scan-manager' ); ?></th>
						<td><?php echo esc_html( self::format_workflow_status( $violation['workflow_status'] ) ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Current Notes', 'accessibility-scan-manager' ); ?></th>
						<td><?php echo wp_kses_post( nl2br( esc_html( self::format_violation_notes_value( $violation['notes'] ?? '' ) ) ) ); ?></td>
					</tr>
				</tbody>
			</table>

			<h2><?php echo esc_html__( 'Edit Workflow', 'accessibility-scan-manager' ); ?></h2>
			<form method="post" action="<?php echo esc_url( self::get_single_violation_url( (int) $violation['id'] ) ); ?>">
				<?php wp_nonce_field( 'acc_save_violation_workflow' ); ?>
				<input type="hidden" name="page" value="<?php echo esc_attr( self::VIOLATIONS_SLUG ); ?>" />
				<input type="hidden" name="acc_view" value="violation" />
				<input type="hidden" name="acc_action" value="save_violation_workflow" />
				<input type="hidden" name="violation_id" value="<?php echo esc_attr( (string) $violation['id'] ); ?>" />

				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row">
								<label for="acc-violation-workflow-status"><?php echo esc_html__( 'Workflow Status', 'accessibility-scan-manager' ); ?></label>
							</th>
							<td>
								<select name="workflow_status" id="acc-violation-workflow-status">
									<?php foreach ( self::get_workflow_status_options() as $status_option ) : ?>
										<option value="<?php echo esc_attr( $status_option ); ?>" <?php selected( $violation['workflow_status'], $status_option ); ?>><?php echo esc_html( $status_option ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="acc-violation-notes"><?php echo esc_html__( 'Internal Notes', 'accessibility-scan-manager' ); ?></label>
							</th>
							<td>
								<textarea name="notes" id="acc-violation-notes" class="large-text" rows="8"><?php echo esc_textarea( (string) ( $violation['notes'] ?? '' ) ); ?></textarea>
								<p class="description"><?php echo esc_html__( 'Use Ignored for false positives, duplicates, or intentionally excluded findings.', 'accessibility-scan-manager' ); ?></p>
							</td>
						</tr>
					</tbody>
				</table>

				<?php submit_button( __( 'Save Violation', 'accessibility-scan-manager' ) ); ?>
			</form>
		</div>
		<?php
	}

	private static function render_notice() {
		$notice = isset( $_GET['acc_notice'] ) ? sanitize_key( wp_unslash( $_GET['acc_notice'] ) ) : '';

		if ( empty( $notice ) ) {
			return;
		}

		$messages = array(
			'site_updated' => array(
				'class'   => 'notice notice-success is-dismissible',
				'message' => __( 'Site settings saved.', 'accessibility-scan-manager' ),
			),
			'site_error'   => array(
				'class'   => 'notice notice-error',
				'message' => __( 'The site settings could not be saved.', 'accessibility-scan-manager' ),
			),
			'scanner_settings_saved' => array(
				'class'   => 'notice notice-success is-dismissible',
				'message' => __( 'Scanner connection settings saved.', 'accessibility-scan-manager' ),
			),
			'scan_submitted' => array(
				'class'   => 'notice notice-success is-dismissible',
				'message' => __( 'Scan job submitted to the scanner service.', 'accessibility-scan-manager' ),
			),
			'scan_poll_schedule_failed' => array(
				'class'   => 'notice notice-warning',
				'message' => __( 'The scan job was submitted, but automatic background polling could not be scheduled.', 'accessibility-scan-manager' ),
			),
			'scan_submission_invalid' => array(
				'class'   => 'notice notice-error',
				'message' => __( 'The scan request could not be submitted. Check the selected mode and input values.', 'accessibility-scan-manager' ),
			),
			'scan_submission_failed' => array(
				'class'   => 'notice notice-error',
				'message' => __( 'The scan request could not be submitted to the scanner service.', 'accessibility-scan-manager' ),
			),
			'scan_status_refreshed' => array(
				'class'   => 'notice notice-success is-dismissible',
				'message' => __( 'Scan status refreshed from the scanner service.', 'accessibility-scan-manager' ),
			),
			'scan_status_refresh_failed' => array(
				'class'   => 'notice notice-error',
				'message' => __( 'The scan status could not be refreshed from the scanner service.', 'accessibility-scan-manager' ),
			),
			'scan_results_fetched' => array(
				'class'   => 'notice notice-success is-dismissible',
				'message' => __( 'Completed scan results fetched and stored locally.', 'accessibility-scan-manager' ),
			),
			'scan_results_fetch_failed' => array(
				'class'   => 'notice notice-error',
				'message' => __( 'The completed scan results could not be fetched or stored locally.', 'accessibility-scan-manager' ),
			),
			'violation_updated' => array(
				'class'   => 'notice notice-success is-dismissible',
				'message' => __( 'Violation workflow and notes updated.', 'accessibility-scan-manager' ),
			),
			'violation_update_failed' => array(
				'class'   => 'notice notice-error',
				'message' => __( 'The violation could not be updated.', 'accessibility-scan-manager' ),
			),
		);

		if ( ! isset( $messages[ $notice ] ) ) {
			return;
		}
		?>
		<div class="<?php echo esc_attr( $messages[ $notice ]['class'] ); ?>">
			<p><?php echo esc_html( $messages[ $notice ]['message'] ); ?></p>
		</div>
		<?php
	}

	private static function format_datetime_value( $value, $fallback ) {
		if ( empty( $value ) ) {
			return $fallback;
		}

		return (string) $value;
	}

	private static function format_violation_impact( $impact ) {
		if ( empty( $impact ) || 'unknown' === $impact ) {
			return __( 'Unknown', 'accessibility-scan-manager' );
		}

		return ucfirst( (string) $impact );
	}

	private static function format_workflow_status( $status ) {
		$status = sanitize_text_field( (string) $status );

		if ( '' === $status ) {
			return __( 'Unknown', 'accessibility-scan-manager' );
		}

		return $status;
	}

	private static function format_scan_state( array $violation ) {
		if ( 'Resolved' === (string) ( $violation['workflow_status'] ?? '' ) || ! empty( $violation['resolved_at'] ) ) {
			return __( 'Resolved', 'accessibility-scan-manager' );
		}

		if ( ! empty( $violation['first_seen_at'] ) && ! empty( $violation['last_seen_at'] ) && (string) $violation['first_seen_at'] !== (string) $violation['last_seen_at'] ) {
			return __( 'Persistent', 'accessibility-scan-manager' );
		}

		return __( 'New', 'accessibility-scan-manager' );
	}

	private static function is_violation_detail_view() {
		$view = isset( $_GET['acc_view'] ) ? sanitize_key( wp_unslash( $_GET['acc_view'] ) ) : '';

		return 'detail' === $view;
	}

	private static function is_single_violation_view() {
		$view = isset( $_GET['acc_view'] ) ? sanitize_key( wp_unslash( $_GET['acc_view'] ) ) : '';

		return 'violation' === $view;
	}

	private static function is_scan_detail_view() {
		$view = isset( $_GET['acc_view'] ) ? sanitize_key( wp_unslash( $_GET['acc_view'] ) ) : '';

		return 'detail' === $view;
	}

	private static function get_scan_id_param() {
		return isset( $_GET['scan_id'] ) ? absint( wp_unslash( $_GET['scan_id'] ) ) : 0;
	}

	private static function get_violation_rule_id_param() {
		return isset( $_GET['rule_id'] ) ? sanitize_text_field( wp_unslash( $_GET['rule_id'] ) ) : '';
	}

	private static function get_violation_impact_param() {
		$impact = isset( $_GET['impact'] ) ? sanitize_text_field( wp_unslash( $_GET['impact'] ) ) : '';

		return '' === $impact ? 'unknown' : $impact;
	}

	private static function get_violation_id_param() {
		return isset( $_GET['violation_id'] ) ? absint( wp_unslash( $_GET['violation_id'] ) ) : 0;
	}

	private static function get_violation_detail_url( $rule_id, $impact ) {
		return add_query_arg(
			array(
				'page'     => self::VIOLATIONS_SLUG,
				'acc_view' => 'detail',
				'rule_id'  => sanitize_text_field( (string) $rule_id ),
				'impact'   => sanitize_text_field( (string) $impact ),
			),
			admin_url( 'admin.php' )
		);
	}

	private static function get_violations_page_url() {
		return add_query_arg(
			array(
				'page' => self::VIOLATIONS_SLUG,
			),
			admin_url( 'admin.php' )
		);
	}

	private static function get_single_violation_url( $violation_id ) {
		return add_query_arg(
			array(
				'page'         => self::VIOLATIONS_SLUG,
				'acc_view'     => 'violation',
				'violation_id' => absint( $violation_id ),
			),
			admin_url( 'admin.php' )
		);
	}

	private static function get_scan_detail_url( $scan_id ) {
		return add_query_arg(
			array(
				'page'     => self::SCAN_JOBS_SLUG,
				'acc_view' => 'detail',
				'scan_id'  => absint( $scan_id ),
			),
			admin_url( 'admin.php' )
		);
	}

	private static function get_scan_jobs_page_url() {
		return add_query_arg(
			array(
				'page' => self::SCAN_JOBS_SLUG,
			),
			admin_url( 'admin.php' )
		);
	}

	private static function format_target_json_for_display( $target_json ) {
		$decoded = json_decode( (string) $target_json, true );

		if ( is_array( $decoded ) ) {
			return implode( ', ', array_map( 'strval', $decoded ) );
		}

		return (string) $target_json;
	}

	private static function get_page_display_url( array $page ) {
		if ( ! empty( $page['url'] ) ) {
			return (string) $page['url'];
		}

		return (string) ( $page['normalized_url'] ?? '' );
	}

	private static function get_violation_notes_excerpt( $notes ) {
		$notes = trim( sanitize_textarea_field( (string) $notes ) );

		if ( '' === $notes ) {
			return __( 'No notes', 'accessibility-scan-manager' );
		}

		if ( strlen( $notes ) <= 80 ) {
			return $notes;
		}

		return substr( $notes, 0, 77 ) . '...';
	}

	private static function format_violation_notes_value( $notes ) {
		$notes = trim( (string) $notes );

		if ( '' === $notes ) {
			return __( 'None.', 'accessibility-scan-manager' );
		}

		return $notes;
	}

	private static function get_violation_classification_label( $tags_json ) {
		return ACC_Violation_Classification::derive_display_classification( $tags_json );
	}

	private static function get_workflow_status_options() {
		return array(
			'New',
			'Accepted',
			'In Progress',
			'Needs Review',
			'Ignored',
			'Resolved',
		);
	}

	private static function get_violation_impact_filter_options() {
		return array(
			'critical',
			'serious',
			'moderate',
			'minor',
			'unknown',
		);
	}

	private static function has_active_violation_filters( array $filters ) {
		return '' !== (string) ( $filters['workflow_status'] ?? '' )
			|| '' !== (string) ( $filters['impact'] ?? '' )
			|| '' !== (string) ( $filters['rule_id'] ?? '' );
	}

	private static function format_http_status_value( $http_status ) {
		if ( null === $http_status || '' === $http_status ) {
			return __( 'Not available.', 'accessibility-scan-manager' );
		}

		return (string) absint( $http_status );
	}

	private static function map_remote_scan_status_to_local_status( $remote_status ) {
		$remote_status = sanitize_key( (string) $remote_status );
		$allowed       = array(
			'queued'    => 'queued',
			'running'   => 'running',
			'completed' => 'completed',
			'failed'    => 'failed',
		);

		if ( ! isset( $allowed[ $remote_status ] ) ) {
			return new WP_Error( 'acc_scan_unknown_remote_status', __( 'The scanner service returned an unknown scan status.', 'accessibility-scan-manager' ) );
		}

		return $allowed[ $remote_status ];
	}

	private static function build_scan_status_error_message( array $remote_scan ) {
		$messages = array();

		if ( ! empty( $remote_scan['error'] ) ) {
			$messages[] = sanitize_text_field( (string) $remote_scan['error'] );
		}

		if ( ! empty( $remote_scan['failures'] ) && is_array( $remote_scan['failures'] ) ) {
			foreach ( $remote_scan['failures'] as $failure ) {
				if ( empty( $failure['error'] ) ) {
					continue;
				}

				$failure_message = sanitize_text_field( (string) $failure['error'] );

				if ( ! empty( $failure['url'] ) ) {
					$failure_message = sprintf(
						/* translators: 1: failed URL, 2: failure message */
						__( '%1$s: %2$s', 'accessibility-scan-manager' ),
						esc_url_raw( (string) $failure['url'] ),
						$failure_message
					);
				}

				$messages[] = $failure_message;
			}
		}

		$messages = array_values( array_unique( array_filter( $messages ) ) );

		if ( empty( $messages ) ) {
			return null;
		}

		return implode( ' | ', $messages );
	}

	private static function build_remote_failures_message( array $failures ) {
		$messages = array();

		foreach ( $failures as $failure ) {
			if ( empty( $failure['error'] ) ) {
				continue;
			}

			$message = sanitize_text_field( (string) $failure['error'] );

			if ( ! empty( $failure['url'] ) ) {
				$message = sprintf(
					/* translators: 1: failed URL, 2: failure message */
					__( '%1$s: %2$s', 'accessibility-scan-manager' ),
					esc_url_raw( (string) $failure['url'] ),
					$message
				);
			}

			$messages[] = $message;
		}

		$messages = array_values( array_unique( array_filter( $messages ) ) );

		if ( empty( $messages ) ) {
			return null;
		}

		return implode( ' | ', $messages );
	}

	private static function normalize_remote_datetime_for_local_storage( $value ) {
		if ( empty( $value ) ) {
			return current_time( 'mysql' );
		}

		try {
			$datetime = new DateTimeImmutable( (string) $value );
		} catch ( Exception $exception ) {
			return current_time( 'mysql' );
		}

		return $datetime->setTimezone( wp_timezone() )->format( 'Y-m-d H:i:s' );
	}

	private static function derive_scan_result_timestamp( array $scan ) {
		if ( ! empty( $scan['finished_at'] ) ) {
			return (string) $scan['finished_at'];
		}

		if ( ! empty( $scan['started_at'] ) ) {
			return (string) $scan['started_at'];
		}

		return current_time( 'mysql' );
	}

	private static function prepare_scan_results_for_ingestion( array $results ) {
		$prepared_results = array();

		foreach ( $results as $result ) {
			if ( empty( $result['normalized_url'] ) || empty( $result['url'] ) || ! isset( $result['violations'] ) || ! is_array( $result['violations'] ) ) {
				return new WP_Error( 'acc_scan_results_invalid_result_for_ingestion', __( 'The scan results could not be prepared for local ingestion.', 'accessibility-scan-manager' ) );
			}

			$prepared_violations = array();

			foreach ( $result['violations'] as $violation ) {
				if ( empty( $violation['rule_id'] ) || ! isset( $violation['elements'] ) || ! is_array( $violation['elements'] ) ) {
					return new WP_Error( 'acc_scan_results_invalid_violation_for_ingestion', __( 'A scan violation could not be prepared for local ingestion.', 'accessibility-scan-manager' ) );
				}

				foreach ( $violation['elements'] as $element ) {
					$target_json = isset( $element['target'] ) && is_array( $element['target'] ) ? array_values( $element['target'] ) : array();

					$prepared_violations[] = array(
						'rule_id'      => $violation['rule_id'],
						'impact'       => $violation['impact'],
						'help'         => $violation['help'],
						'help_url'     => $violation['help_url'],
						'description'  => $violation['description'],
						'html_snippet' => $element['html_snippet'] ?? '',
						'target_json'  => $target_json,
						'tags_json'    => isset( $violation['tags'] ) && is_array( $violation['tags'] ) ? array_values( $violation['tags'] ) : array(),
						'fingerprint'  => self::build_violation_fingerprint( $result['normalized_url'], $violation['rule_id'], $target_json ),
					);
				}
			}

			$prepared_results[] = array(
				'url'            => $result['url'],
				'normalized_url' => $result['normalized_url'],
				'http_status'    => $result['http_status'],
				'violations'     => $prepared_violations,
			);
		}

		return $prepared_results;
	}

	private static function build_violation_fingerprint( $normalized_url, $rule_id, array $target_json ) {
		$normalized_selectors = array_map( 'sanitize_text_field', array_map( 'strval', $target_json ) );
		$fingerprint_source   = sanitize_text_field( (string) $normalized_url ) . '|' . sanitize_text_field( (string) $rule_id ) . '|' . implode( ',', $normalized_selectors );

		return sha1( $fingerprint_source );
	}

	private static function build_scan_request_payload( array $site, array $scan_input ) {
		$site_id   = (int) $site['id'];
		$base_url  = esc_url_raw( $site['base_url'] ?? '' );
		$scan_mode = $scan_input['scan_mode'];

		if ( '' === $base_url ) {
			return new WP_Error( 'acc_scan_base_url_missing', __( 'A base URL is required before starting a scan.', 'accessibility-scan-manager' ) );
		}

		if ( 'sitemap' === $scan_mode ) {
			$sitemap_url = esc_url_raw( $site['sitemap_url'] ?? '' );

			if ( '' === $sitemap_url ) {
				return new WP_Error( 'acc_scan_sitemap_missing', __( 'A sitemap URL is required for sitemap scans.', 'accessibility-scan-manager' ) );
			}

			return array(
				'siteId'     => $site_id,
				'baseUrl'    => $base_url,
				'mode'       => 'sitemap',
				'sitemapUrl' => $sitemap_url,
			);
		}

		$manual_urls = self::parse_manual_urls_input( $scan_input['manual_urls'] );

		if ( empty( $manual_urls ) ) {
			return new WP_Error( 'acc_scan_manual_urls_missing', __( 'At least one valid manual URL is required.', 'accessibility-scan-manager' ) );
		}

		return array(
			'siteId'  => $site_id,
			'baseUrl' => $base_url,
			'mode'    => 'manual',
			'urls'    => $manual_urls,
		);
	}

	private static function parse_manual_urls_input( $manual_urls ) {
		$lines      = preg_split( '/\r\n|\r|\n/', (string) $manual_urls );
		$valid_urls = array();

		foreach ( $lines as $line ) {
			$url = esc_url_raw( trim( (string) $line ) );

			if ( '' === $url ) {
				continue;
			}

			if ( ! wp_http_validate_url( $url ) ) {
				return array();
			}

			$valid_urls[] = $url;
		}

		return array_values( array_unique( $valid_urls ) );
	}
}
