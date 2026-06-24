<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ACC_Scan_Orchestrator {
	const POLL_ACTION_HOOK = 'acc_poll_scan';
	const ACTION_GROUP     = 'acc_scans';
	const POLL_INTERVAL    = MINUTE_IN_SECONDS;

	public static function bootstrap() {
		add_action( self::POLL_ACTION_HOOK, array( __CLASS__, 'handle_poll_action' ), 10, 1 );
	}

	public static function schedule_poll_for_scan( $scan_id, $timestamp = null ) {
		$scan_id = (int) $scan_id;
		$scan    = ACC_DB::get_scan( $scan_id );

		if ( empty( $scan ) ) {
			return new WP_Error( 'acc_scan_poll_missing_scan', __( 'The requested scan could not be loaded for polling.', 'accessibility-scan-manager' ) );
		}

		if ( ! self::is_pollable_scan( $scan ) ) {
			self::clear_poll_for_scan( $scan_id );

			return true;
		}

		if ( ! self::has_action_scheduler() ) {
			return new WP_Error( 'acc_scan_poll_scheduler_unavailable', __( 'Action Scheduler is not available for background polling.', 'accessibility-scan-manager' ) );
		}

		$args           = array( 'scan_id' => $scan_id );
		$next_scheduled = as_next_scheduled_action( self::POLL_ACTION_HOOK, $args, self::ACTION_GROUP );

		if ( false !== $next_scheduled ) {
			return true;
		}

		$scheduled = as_schedule_single_action(
			null !== $timestamp ? (int) $timestamp : time() + self::POLL_INTERVAL,
			self::POLL_ACTION_HOOK,
			$args,
			self::ACTION_GROUP
		);

		if ( empty( $scheduled ) ) {
			return new WP_Error( 'acc_scan_poll_schedule_failed', __( 'The next background poll could not be scheduled.', 'accessibility-scan-manager' ) );
		}

		return true;
	}

	public static function clear_poll_for_scan( $scan_id ) {
		if ( ! function_exists( 'as_unschedule_all_actions' ) ) {
			return true;
		}

		as_unschedule_all_actions(
			self::POLL_ACTION_HOOK,
			array( 'scan_id' => (int) $scan_id ),
			self::ACTION_GROUP
		);

		return true;
	}

	public static function handle_poll_action( $scan_id ) {
		self::run_poll_for_scan( (int) $scan_id );
	}

	public static function run_poll_for_scan( $scan_id ) {
		$scan_id = (int) $scan_id;
		$scan    = ACC_DB::get_scan( $scan_id );

		if ( empty( $scan ) ) {
			return new WP_Error( 'acc_scan_poll_missing_scan', __( 'The requested scan could not be loaded for polling.', 'accessibility-scan-manager' ) );
		}

		if ( ! self::is_pollable_scan( $scan ) ) {
			self::clear_poll_for_scan( $scan_id );

			return true;
		}

		$status_result = self::refresh_scan_status( $scan_id );

		if ( is_wp_error( $status_result ) ) {
			$latest_scan = ACC_DB::get_scan( $scan_id );

			if ( ! empty( $latest_scan ) && self::is_pollable_scan( $latest_scan ) ) {
				self::schedule_poll_for_scan( $scan_id );
			}

			return $status_result;
		}

		$updated_scan = ACC_DB::get_scan( $scan_id );

		if ( empty( $updated_scan ) ) {
			return new WP_Error( 'acc_scan_poll_missing_updated_scan', __( 'The updated scan could not be loaded after polling.', 'accessibility-scan-manager' ) );
		}

		if ( 'completed' === (string) $updated_scan['status'] ) {
			self::clear_poll_for_scan( $scan_id );

			return self::fetch_scan_results( $scan_id );
		}

		if ( 'failed' === (string) $updated_scan['status'] ) {
			self::clear_poll_for_scan( $scan_id );

			return true;
		}

		return self::schedule_poll_for_scan( $scan_id );
	}

	public static function refresh_scan_status( $scan_id ) {
		$scan_id = (int) $scan_id;
		$scan    = ACC_DB::get_scan( $scan_id );

		if ( empty( $scan ) || empty( $scan['remote_job_id'] ) ) {
			return new WP_Error( 'acc_scan_status_missing_remote_job', __( 'A remote scan job is required before status can be refreshed.', 'accessibility-scan-manager' ) );
		}

		$response = ACC_Scanner_Client::get_scan_job_status( $scan['remote_job_id'] );

		if ( is_wp_error( $response ) ) {
			self::update_scan_error_message( $scan_id, $response->get_error_message() );

			return $response;
		}

		$mapped_status = self::map_remote_scan_status_to_local_status( $response['status'] );

		if ( is_wp_error( $mapped_status ) ) {
			self::update_scan_error_message( $scan_id, $mapped_status->get_error_message() );

			return $mapped_status;
		}

		$update_data = array(
			'status'        => $mapped_status,
			'error_message' => self::build_scan_status_error_message( $response ),
		);

		if ( in_array( $mapped_status, array( 'completed', 'failed' ), true ) ) {
			$update_data['finished_at'] = self::normalize_remote_datetime_for_local_storage( $response['finished_at'] );
		} else {
			$update_data['finished_at'] = null;
		}

		$update_result = ACC_DB::update_scan( $scan_id, $update_data );

		if ( is_wp_error( $update_result ) ) {
			return $update_result;
		}

		return true;
	}

	public static function fetch_scan_results( $scan_id ) {
		$scan_id = (int) $scan_id;
		$scan    = ACC_DB::get_scan( $scan_id );

		if ( empty( $scan ) || empty( $scan['remote_job_id'] ) || 'completed' !== (string) $scan['status'] ) {
			return new WP_Error( 'acc_scan_results_missing_remote_job', __( 'Completed remote scan results are not available for this scan.', 'accessibility-scan-manager' ) );
		}

		$response = ACC_Scanner_Client::get_scan_job_results( $scan['remote_job_id'] );

		if ( is_wp_error( $response ) ) {
			self::update_scan_error_message( $scan_id, $response->get_error_message() );

			return $response;
		}

		$prepared_results = self::prepare_scan_results_for_ingestion( $response['results'] );

		if ( is_wp_error( $prepared_results ) ) {
			self::update_scan_error_message( $scan_id, $prepared_results->get_error_message() );

			return $prepared_results;
		}

		$ingestion_result = ACC_DB::replace_scan_results(
			$scan_id,
			$prepared_results,
			self::derive_scan_result_timestamp( $scan )
		);

		if ( is_wp_error( $ingestion_result ) ) {
			self::update_scan_error_message( $scan_id, $ingestion_result->get_error_message() );

			return $ingestion_result;
		}

		$update_result = ACC_DB::update_scan(
			$scan_id,
			array(
				'error_message' => self::build_remote_failures_message( $response['failures'] ),
			)
		);

		if ( is_wp_error( $update_result ) ) {
			return $update_result;
		}

		return $ingestion_result;
	}

	private static function has_action_scheduler() {
		return function_exists( 'as_schedule_single_action' ) && function_exists( 'as_next_scheduled_action' );
	}

	private static function is_pollable_scan( array $scan ) {
		if ( empty( $scan['remote_job_id'] ) ) {
			return false;
		}

		return ! self::is_terminal_status( $scan['status'] ?? '' );
	}

	private static function is_terminal_status( $status ) {
		return in_array( sanitize_key( (string) $status ), array( 'completed', 'failed' ), true );
	}

	private static function update_scan_error_message( $scan_id, $message ) {
		return ACC_DB::update_scan(
			(int) $scan_id,
			array(
				'error_message' => $message,
			)
		);
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
}
