<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ACC_Scanner_Client {
	const API_KEY_HEADER = 'Authorization';

	public static function request( $path, array $args = array() ) {
		$base_url = ACC_Plugin::get_scanner_base_url();
		$api_key  = ACC_Plugin::get_scanner_api_key();

		if ( '' === $base_url ) {
			return new WP_Error( 'acc_scanner_base_url_missing', __( 'Scanner service base URL is not configured.', 'accessibility-scan-manager' ) );
		}

		if ( '' === $api_key ) {
			return new WP_Error( 'acc_scanner_api_key_missing', __( 'Scanner API key is not configured.', 'accessibility-scan-manager' ) );
		}

		$request_url = trailingslashit( $base_url ) . ltrim( (string) $path, '/' );
		$defaults    = array(
			'method'  => 'GET',
			'timeout' => 20,
			'headers' => array(),
		);
		$args        = wp_parse_args( $args, $defaults );

		if ( ! is_array( $args['headers'] ) ) {
			$args['headers'] = array();
		}

		$args['headers'][ self::API_KEY_HEADER ] = 'Bearer ' . $api_key;

		return wp_remote_request( $request_url, $args );
	}

	public static function create_scan_job( array $payload ) {
		$response = self::request(
			'/api/scans',
			array(
				'method'  => 'POST',
				'headers' => array(
					'Content-Type' => 'application/json',
				),
				'body'    => wp_json_encode( $payload ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );
		$body        = wp_remote_retrieve_body( $response );
		$decoded     = json_decode( $body, true );

		if ( 202 !== $status_code ) {
			$message = is_array( $decoded ) && ! empty( $decoded['error'] )
				? sanitize_text_field( (string) $decoded['error'] )
				: __( 'The scanner service rejected the scan request.', 'accessibility-scan-manager' );

			return new WP_Error( 'acc_scanner_scan_create_failed', $message );
		}

		if ( ! is_array( $decoded ) || empty( $decoded['jobId'] ) ) {
			return new WP_Error( 'acc_scanner_scan_create_invalid_response', __( 'The scanner service returned an invalid scan job response.', 'accessibility-scan-manager' ) );
		}

		return array(
			'job_id' => sanitize_text_field( (string) $decoded['jobId'] ),
			'status' => sanitize_text_field( (string) ( $decoded['status'] ?? 'queued' ) ),
		);
	}

	public static function get_scan_job_status( $job_id ) {
		$job_id = sanitize_text_field( (string) $job_id );

		if ( '' === $job_id ) {
			return new WP_Error( 'acc_scanner_scan_status_missing_job_id', __( 'A remote scan job ID is required.', 'accessibility-scan-manager' ) );
		}

		$response = self::request( '/api/scans/' . rawurlencode( $job_id ) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );
		$body        = wp_remote_retrieve_body( $response );
		$decoded     = json_decode( $body, true );

		if ( 200 !== $status_code ) {
			$message = is_array( $decoded ) && ! empty( $decoded['error'] )
				? sanitize_text_field( (string) $decoded['error'] )
				: __( 'The scanner service rejected the scan status request.', 'accessibility-scan-manager' );

			return new WP_Error( 'acc_scanner_scan_status_failed', $message );
		}

		if ( ! is_array( $decoded ) || empty( $decoded['jobId'] ) || empty( $decoded['status'] ) ) {
			return new WP_Error( 'acc_scanner_scan_status_invalid_response', __( 'The scanner service returned an invalid scan status response.', 'accessibility-scan-manager' ) );
		}

		$failures = array();

		if ( isset( $decoded['failures'] ) ) {
			if ( ! is_array( $decoded['failures'] ) ) {
				return new WP_Error( 'acc_scanner_scan_status_invalid_failures', __( 'The scanner service returned an invalid failures list.', 'accessibility-scan-manager' ) );
			}

			foreach ( $decoded['failures'] as $failure ) {
				if ( ! is_array( $failure ) ) {
					return new WP_Error( 'acc_scanner_scan_status_invalid_failure_item', __( 'The scanner service returned an invalid failure item.', 'accessibility-scan-manager' ) );
				}

				$failures[] = array(
					'url'   => isset( $failure['url'] ) ? esc_url_raw( (string) $failure['url'] ) : '',
					'error' => isset( $failure['error'] ) ? sanitize_text_field( (string) $failure['error'] ) : '',
				);
			}
		}

		return array(
			'job_id'      => sanitize_text_field( (string) $decoded['jobId'] ),
			'status'      => sanitize_text_field( (string) $decoded['status'] ),
			'created_at'  => self::sanitize_remote_datetime_value( $decoded['createdAt'] ?? null ),
			'started_at'  => self::sanitize_remote_datetime_value( $decoded['startedAt'] ?? null ),
			'finished_at' => self::sanitize_remote_datetime_value( $decoded['finishedAt'] ?? null ),
			'error'       => isset( $decoded['error'] ) && null !== $decoded['error'] ? sanitize_text_field( (string) $decoded['error'] ) : '',
			'failures'    => $failures,
		);
	}

	public static function get_scan_job_results( $job_id ) {
		$job_id = sanitize_text_field( (string) $job_id );

		if ( '' === $job_id ) {
			return new WP_Error( 'acc_scanner_scan_results_missing_job_id', __( 'A remote scan job ID is required.', 'accessibility-scan-manager' ) );
		}

		$response = self::request( '/api/scans/' . rawurlencode( $job_id ) . '/results' );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );
		$body        = wp_remote_retrieve_body( $response );
		$decoded     = json_decode( $body, true );

		if ( 200 !== $status_code ) {
			$message = is_array( $decoded ) && ! empty( $decoded['error'] )
				? sanitize_text_field( (string) $decoded['error'] )
				: __( 'The scanner service rejected the scan results request.', 'accessibility-scan-manager' );

			return new WP_Error( 'acc_scanner_scan_results_failed', $message );
		}

		if ( ! is_array( $decoded ) || empty( $decoded['jobId'] ) || 'completed' !== (string) ( $decoded['status'] ?? '' ) || ! isset( $decoded['results'] ) || ! is_array( $decoded['results'] ) || empty( $decoded['results'] ) ) {
			return new WP_Error( 'acc_scanner_scan_results_invalid_response', __( 'The scanner service returned an invalid scan results response.', 'accessibility-scan-manager' ) );
		}

		$results = array();

		foreach ( $decoded['results'] as $result ) {
			if ( ! is_array( $result ) || empty( $result['url'] ) || empty( $result['normalizedUrl'] ) || ! array_key_exists( 'violations', $result ) || ! is_array( $result['violations'] ) ) {
				return new WP_Error( 'acc_scanner_scan_results_invalid_item', __( 'The scanner service returned an invalid scan result item.', 'accessibility-scan-manager' ) );
			}

			$violations = array();

			foreach ( $result['violations'] as $violation ) {
				if ( ! is_array( $violation ) || empty( $violation['ruleId'] ) || ! isset( $violation['description'] ) || ! isset( $violation['help'] ) || ! isset( $violation['helpUrl'] ) || ! array_key_exists( 'elements', $violation ) || ! is_array( $violation['elements'] ) ) {
					return new WP_Error( 'acc_scanner_scan_results_invalid_violation', __( 'The scanner service returned an invalid violation item.', 'accessibility-scan-manager' ) );
				}

				$tags = array();

				if ( array_key_exists( 'tags', $violation ) ) {
					if ( ! is_array( $violation['tags'] ) ) {
						return new WP_Error( 'acc_scanner_scan_results_invalid_tags', __( 'The scanner service returned invalid violation tags.', 'accessibility-scan-manager' ) );
					}

					foreach ( $violation['tags'] as $tag ) {
						if ( ! is_scalar( $tag ) ) {
							return new WP_Error( 'acc_scanner_scan_results_invalid_tag_item', __( 'The scanner service returned an invalid violation tag.', 'accessibility-scan-manager' ) );
						}

						$sanitized_tag = sanitize_key( (string) $tag );

						if ( '' !== $sanitized_tag ) {
							$tags[] = $sanitized_tag;
						}
					}
				}

				$elements = array();

				foreach ( $violation['elements'] as $element ) {
					if ( ! is_array( $element ) || ! isset( $element['target'] ) || ! is_array( $element['target'] ) || ! isset( $element['htmlSnippet'] ) ) {
						return new WP_Error( 'acc_scanner_scan_results_invalid_element', __( 'The scanner service returned an invalid violation element.', 'accessibility-scan-manager' ) );
					}

					$target = array();

					foreach ( $element['target'] as $target_item ) {
						if ( ! is_scalar( $target_item ) ) {
							return new WP_Error( 'acc_scanner_scan_results_invalid_target', __( 'The scanner service returned an invalid violation target.', 'accessibility-scan-manager' ) );
						}

						$target[] = sanitize_text_field( (string) $target_item );
					}

					$elements[] = array(
						'target'       => $target,
						'html_snippet' => wp_kses_post( (string) $element['htmlSnippet'] ),
					);
				}

				$violations[] = array(
					'rule_id'     => sanitize_text_field( (string) $violation['ruleId'] ),
					'impact'      => isset( $violation['impact'] ) && null !== $violation['impact'] ? sanitize_text_field( (string) $violation['impact'] ) : null,
					'tags'        => array_values( array_unique( $tags ) ),
					'description' => sanitize_textarea_field( (string) $violation['description'] ),
					'help'        => sanitize_textarea_field( (string) $violation['help'] ),
					'help_url'    => esc_url_raw( (string) $violation['helpUrl'] ),
					'elements'    => $elements,
				);
			}

			$results[] = array(
				'url'            => esc_url_raw( (string) $result['url'] ),
				'normalized_url' => sanitize_text_field( (string) $result['normalizedUrl'] ),
				'http_status'    => isset( $result['httpStatus'] ) && null !== $result['httpStatus'] ? (int) $result['httpStatus'] : null,
				'violations'     => $violations,
			);
		}

		$failures = self::sanitize_failures_list( $decoded['failures'] ?? array() );

		if ( is_wp_error( $failures ) ) {
			return $failures;
		}

		return array(
			'job_id'   => sanitize_text_field( (string) $decoded['jobId'] ),
			'status'   => 'completed',
			'results'  => $results,
			'failures' => $failures,
		);
	}

	private static function sanitize_remote_datetime_value( $value ) {
		if ( null === $value ) {
			return null;
		}

		$value = sanitize_text_field( (string) $value );

		return '' === $value ? null : $value;
	}

	private static function sanitize_failures_list( $failures ) {
		if ( ! is_array( $failures ) ) {
			return new WP_Error( 'acc_scanner_invalid_failures_list', __( 'The scanner service returned an invalid failures list.', 'accessibility-scan-manager' ) );
		}

		$sanitized = array();

		foreach ( $failures as $failure ) {
			if ( ! is_array( $failure ) ) {
				return new WP_Error( 'acc_scanner_invalid_failure_item', __( 'The scanner service returned an invalid failure item.', 'accessibility-scan-manager' ) );
			}

			$sanitized[] = array(
				'url'   => isset( $failure['url'] ) ? esc_url_raw( (string) $failure['url'] ) : '',
				'error' => isset( $failure['error'] ) ? sanitize_text_field( (string) $failure['error'] ) : '',
			);
		}

		return $sanitized;
	}
}
