<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ACC_DB {
	public static function table_names() {
		global $wpdb;

		return array(
			'sites'      => $wpdb->prefix . 'acc_sites',
			'scans'      => $wpdb->prefix . 'acc_scans',
			'scan_urls'  => $wpdb->prefix . 'acc_scan_urls',
			'violations' => $wpdb->prefix . 'acc_violations',
		);
	}

	public static function get_local_site() {
		global $wpdb;

		$tables = self::table_names();

		return $wpdb->get_row(
			"SELECT * FROM {$tables['sites']} ORDER BY id ASC LIMIT 1",
			ARRAY_A
		);
	}

	public static function get_scan( $scan_id ) {
		global $wpdb;

		$tables = self::table_names();

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$tables['scans']} WHERE id = %d",
				(int) $scan_id
			),
			ARRAY_A
		);
	}

	public static function get_site( $site_id ) {
		global $wpdb;

		$tables = self::table_names();

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$tables['sites']} WHERE id = %d",
				(int) $site_id
			),
			ARRAY_A
		);
	}

	public static function get_violation( $violation_id ) {
		global $wpdb;

		$tables = self::table_names();

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$tables['violations']} WHERE id = %d",
				(int) $violation_id
			),
			ARRAY_A
		);
	}

	public static function create_site( array $data ) {
		global $wpdb;

		$tables = self::table_names();
		$now    = current_time( 'mysql' );
		$record = array(
			'name'        => sanitize_text_field( $data['name'] ?? '' ),
			'base_url'    => esc_url_raw( $data['base_url'] ?? '' ),
			'sitemap_url' => '' !== ( $data['sitemap_url'] ?? '' ) ? esc_url_raw( $data['sitemap_url'] ) : null,
			'is_active'   => isset( $data['is_active'] ) ? (int) (bool) $data['is_active'] : 1,
			'created_at'  => $now,
			'updated_at'  => $now,
		);

		$result = $wpdb->insert(
			$tables['sites'],
			$record,
			array( '%s', '%s', '%s', '%d', '%s', '%s' )
		);

		if ( false === $result ) {
			return new WP_Error( 'acc_site_create_failed', $wpdb->last_error );
		}

		return (int) $wpdb->insert_id;
	}

	public static function update_site( $site_id, array $data ) {
		global $wpdb;

		$tables = self::table_names();
		$record = array(
			'name'       => sanitize_text_field( $data['name'] ?? '' ),
			'base_url'   => esc_url_raw( $data['base_url'] ?? '' ),
			'is_active'  => isset( $data['is_active'] ) ? (int) (bool) $data['is_active'] : 1,
			'updated_at' => current_time( 'mysql' ),
		);
		$formats = array( '%s', '%s', '%d', '%s' );

		if ( array_key_exists( 'sitemap_url', $data ) ) {
			$record['sitemap_url'] = '' !== $data['sitemap_url'] ? esc_url_raw( $data['sitemap_url'] ) : null;
			$formats[]             = '%s';
		}

		$result = $wpdb->update(
			$tables['sites'],
			$record,
			array( 'id' => (int) $site_id ),
			$formats,
			array( '%d' )
		);

		if ( false === $result ) {
			return new WP_Error( 'acc_site_update_failed', $wpdb->last_error );
		}

		return true;
	}

	public static function list_sites( array $args = array() ) {
		global $wpdb;

		$tables     = self::table_names();
		$only_active = isset( $args['is_active'] );
		$sql        = "SELECT * FROM {$tables['sites']}";
		$params     = array();

		if ( $only_active ) {
			$sql      .= ' WHERE is_active = %d';
			$params[] = (int) (bool) $args['is_active'];
		}

		$sql .= ' ORDER BY name ASC';

		if ( ! empty( $params ) ) {
			return $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
		}

		return $wpdb->get_results( $sql, ARRAY_A );
	}

	public static function list_sites_with_summary() {
		global $wpdb;

		$tables = self::table_names();

		return $wpdb->get_results(
			"SELECT
				sites.*,
				COUNT(DISTINCT scans.id) AS scan_count,
				COUNT(DISTINCT scan_urls.id) AS url_count,
				COUNT(DISTINCT violations.id) AS violation_count,
				MAX(scans.started_at) AS last_scan_at
			FROM {$tables['sites']} sites
			LEFT JOIN {$tables['scans']} scans
				ON scans.site_id = sites.id
			LEFT JOIN {$tables['scan_urls']} scan_urls
				ON scan_urls.scan_id = scans.id
			LEFT JOIN {$tables['violations']} violations
				ON violations.scan_url_id = scan_urls.id
			GROUP BY sites.id, sites.name, sites.base_url, sites.sitemap_url, sites.is_active, sites.created_at, sites.updated_at
			ORDER BY sites.name ASC, sites.id ASC",
			ARRAY_A
		);
	}

	public static function delete_site( $site_id ) {
		global $wpdb;

		$tables  = self::table_names();
		$site_id = (int) $site_id;

		if ( $site_id <= 0 ) {
			return new WP_Error( 'acc_site_delete_invalid_site', __( 'A valid site is required.', 'accessibility-scan-manager' ) );
		}

		$transaction_started = false !== $wpdb->query( 'START TRANSACTION' );

		$delete_violations_result = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$tables['violations']}
				WHERE scan_url_id IN (
					SELECT scan_urls.id
					FROM {$tables['scan_urls']} scan_urls
					INNER JOIN {$tables['scans']} scans
						ON scans.id = scan_urls.scan_id
					WHERE scans.site_id = %d
				)",
				$site_id
			)
		);

		if ( false === $delete_violations_result ) {
			if ( $transaction_started ) {
				$wpdb->query( 'ROLLBACK' );
			}

			return new WP_Error( 'acc_site_delete_violations_failed', $wpdb->last_error );
		}

		$delete_scan_urls_result = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$tables['scan_urls']}
				WHERE scan_id IN (
					SELECT id FROM {$tables['scans']} WHERE site_id = %d
				)",
				$site_id
			)
		);

		if ( false === $delete_scan_urls_result ) {
			if ( $transaction_started ) {
				$wpdb->query( 'ROLLBACK' );
			}

			return new WP_Error( 'acc_site_delete_scan_urls_failed', $wpdb->last_error );
		}

		$delete_scans_result = $wpdb->delete(
			$tables['scans'],
			array( 'site_id' => $site_id ),
			array( '%d' )
		);

		if ( false === $delete_scans_result ) {
			if ( $transaction_started ) {
				$wpdb->query( 'ROLLBACK' );
			}

			return new WP_Error( 'acc_site_delete_scans_failed', $wpdb->last_error );
		}

		$delete_site_result = $wpdb->delete(
			$tables['sites'],
			array( 'id' => $site_id ),
			array( '%d' )
		);

		if ( false === $delete_site_result ) {
			if ( $transaction_started ) {
				$wpdb->query( 'ROLLBACK' );
			}

			return new WP_Error( 'acc_site_delete_failed', $wpdb->last_error );
		}

		if ( $transaction_started ) {
			$wpdb->query( 'COMMIT' );
		}

		return true;
	}

	public static function get_site_summary( $site_id ) {
		global $wpdb;

		$tables = self::table_names();

		$scan_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$tables['scans']} WHERE site_id = %d",
				(int) $site_id
			)
		);

		$url_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$tables['scan_urls']} WHERE scan_id IN (SELECT id FROM {$tables['scans']} WHERE site_id = %d)",
				(int) $site_id
			)
		);

		$violation_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$tables['violations']} WHERE scan_url_id IN (
					SELECT id FROM {$tables['scan_urls']} WHERE scan_id IN (
						SELECT id FROM {$tables['scans']} WHERE site_id = %d
					)
				)",
				(int) $site_id
			)
		);

		$last_scan_at = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT MAX(started_at) FROM {$tables['scans']} WHERE site_id = %d",
				(int) $site_id
			)
		);

		return array(
			'scan_count'      => $scan_count,
			'url_count'       => $url_count,
			'violation_count' => $violation_count,
			'last_scan_at'    => $last_scan_at,
		);
	}

	public static function list_scan_jobs_for_site( $site_id ) {
		global $wpdb;

		$tables = self::table_names();

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					scans.id,
					scans.status,
					scans.remote_job_id,
					scans.scan_mode,
					scans.started_at,
					scans.finished_at,
					COUNT(DISTINCT scan_urls.id) AS url_count,
					COUNT(DISTINCT violations.id) AS violation_count
				FROM {$tables['scans']} scans
				LEFT JOIN {$tables['scan_urls']} scan_urls
					ON scan_urls.scan_id = scans.id
				LEFT JOIN {$tables['violations']} violations
					ON violations.scan_url_id = scan_urls.id
				WHERE scans.site_id = %d
				GROUP BY scans.id, scans.status, scans.remote_job_id, scans.scan_mode, scans.started_at, scans.finished_at
				ORDER BY scans.started_at DESC, scans.id DESC",
				(int) $site_id
			),
			ARRAY_A
		);
	}

	public static function get_scan_detail_for_site( $site_id, $scan_id ) {
		global $wpdb;

		$tables = self::table_names();

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					scans.id,
					scans.status,
					scans.remote_job_id,
					scans.scan_mode,
					scans.started_at,
					scans.finished_at,
					scans.error_message,
					COUNT(DISTINCT scan_urls.id) AS url_count,
					COUNT(DISTINCT violations.id) AS violation_count
				FROM {$tables['scans']} scans
				LEFT JOIN {$tables['scan_urls']} scan_urls
					ON scan_urls.scan_id = scans.id
				LEFT JOIN {$tables['violations']} violations
					ON violations.scan_url_id = scan_urls.id
				WHERE scans.site_id = %d
					AND scans.id = %d
				GROUP BY scans.id, scans.status, scans.remote_job_id, scans.scan_mode, scans.started_at, scans.finished_at, scans.error_message",
				(int) $site_id,
				(int) $scan_id
			),
			ARRAY_A
		);
	}

	public static function list_scan_urls_for_site_scan( $site_id, $scan_id ) {
		global $wpdb;

		$tables = self::table_names();

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					scan_urls.id,
					scan_urls.url,
					scan_urls.normalized_url,
					scan_urls.http_status,
					scan_urls.scanned_at,
					COUNT(DISTINCT violations.id) AS violation_count
				FROM {$tables['scan_urls']} scan_urls
				INNER JOIN {$tables['scans']} scans
					ON scans.id = scan_urls.scan_id
				LEFT JOIN {$tables['violations']} violations
					ON violations.scan_url_id = scan_urls.id
				WHERE scans.site_id = %d
					AND scan_urls.scan_id = %d
				GROUP BY scan_urls.id, scan_urls.url, scan_urls.normalized_url, scan_urls.http_status, scan_urls.scanned_at
				ORDER BY scan_urls.scanned_at DESC, scan_urls.id DESC",
				(int) $site_id,
				(int) $scan_id
			),
			ARRAY_A
		);
	}

	public static function list_violation_summaries_for_site( $site_id, array $filters = array() ) {
		global $wpdb;

		$tables = self::table_names();
		$where  = array( 'scans.site_id = %d' );
		$params = array( (int) $site_id );

		if ( ! empty( $filters['workflow_status'] ) ) {
			$where[]  = 'violations.workflow_status = %s';
			$params[] = sanitize_text_field( (string) $filters['workflow_status'] );
		}

		if ( array_key_exists( 'impact', $filters ) && '' !== (string) $filters['impact'] ) {
			$where[]  = "COALESCE(NULLIF(violations.impact, ''), 'unknown') = %s";
			$params[] = self::normalize_violation_impact_value( $filters['impact'] );
		}

		if ( ! empty( $filters['rule_id'] ) ) {
			$where[]  = 'violations.rule_id LIKE %s';
			$params[] = '%' . $wpdb->esc_like( sanitize_text_field( (string) $filters['rule_id'] ) ) . '%';
		}

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					violations.rule_id,
					COALESCE(NULLIF(violations.impact, ''), 'unknown') AS impact,
					latest.tags_json,
					COUNT(DISTINCT scan_urls.normalized_url) AS page_count,
					COUNT(DISTINCT violations.id) AS occurrence_count,
					MAX(violations.last_seen_at) AS last_seen_at
				FROM {$tables['violations']} violations
				INNER JOIN {$tables['scan_urls']} scan_urls
					ON scan_urls.id = violations.scan_url_id
				INNER JOIN {$tables['scans']} scans
					ON scans.id = scan_urls.scan_id
				LEFT JOIN {$tables['violations']} latest
					ON latest.id = (
						SELECT recent.id
						FROM {$tables['violations']} recent
						INNER JOIN {$tables['scan_urls']} recent_scan_urls
							ON recent_scan_urls.id = recent.scan_url_id
						INNER JOIN {$tables['scans']} recent_scans
							ON recent_scans.id = recent_scan_urls.scan_id
						WHERE recent_scans.site_id = scans.site_id
							AND recent.rule_id = violations.rule_id
							AND COALESCE(NULLIF(recent.impact, ''), 'unknown') = COALESCE(NULLIF(violations.impact, ''), 'unknown')
						ORDER BY recent.last_seen_at DESC, recent.id DESC
						LIMIT 1
					)
				WHERE " . implode( ' AND ', $where ) . "
				GROUP BY violations.rule_id, COALESCE(NULLIF(violations.impact, ''), 'unknown'), latest.tags_json
				ORDER BY last_seen_at DESC, occurrence_count DESC, violations.rule_id ASC",
				$params
			),
			ARRAY_A
		);
	}

	public static function get_violation_summary_for_site( $site_id, $rule_id, $impact ) {
		global $wpdb;

		$tables = self::table_names();
		$impact = self::normalize_violation_impact_value( $impact );

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					summary.rule_id,
					summary.impact,
					summary.page_count,
					summary.occurrence_count,
					summary.last_seen_at,
					latest.description,
					latest.help,
					latest.help_url,
					latest.html_snippet,
					latest.target_json,
					latest.tags_json
				FROM (
					SELECT
						violations.rule_id,
						COALESCE(NULLIF(violations.impact, ''), 'unknown') AS impact,
						COUNT(DISTINCT scan_urls.normalized_url) AS page_count,
						COUNT(DISTINCT violations.id) AS occurrence_count,
						MAX(violations.last_seen_at) AS last_seen_at
					FROM {$tables['violations']} violations
					INNER JOIN {$tables['scan_urls']} scan_urls
						ON scan_urls.id = violations.scan_url_id
					INNER JOIN {$tables['scans']} scans
						ON scans.id = scan_urls.scan_id
					WHERE scans.site_id = %d
						AND violations.rule_id = %s
						AND COALESCE(NULLIF(violations.impact, ''), 'unknown') = %s
					GROUP BY violations.rule_id, COALESCE(NULLIF(violations.impact, ''), 'unknown')
				) summary
				LEFT JOIN (
					SELECT
						violations.rule_id,
						COALESCE(NULLIF(violations.impact, ''), 'unknown') AS impact,
						violations.description,
						violations.help,
						violations.help_url,
						violations.html_snippet,
						violations.target_json,
						violations.tags_json
					FROM {$tables['violations']} violations
					INNER JOIN {$tables['scan_urls']} scan_urls
						ON scan_urls.id = violations.scan_url_id
					INNER JOIN {$tables['scans']} scans
						ON scans.id = scan_urls.scan_id
					WHERE scans.site_id = %d
						AND violations.rule_id = %s
						AND COALESCE(NULLIF(violations.impact, ''), 'unknown') = %s
					ORDER BY violations.last_seen_at DESC, violations.id DESC
					LIMIT 1
				) latest
					ON latest.rule_id = summary.rule_id
					AND latest.impact = summary.impact",
				(int) $site_id,
				$rule_id,
				$impact,
				(int) $site_id,
				$rule_id,
				$impact
			),
			ARRAY_A
		);
	}

	public static function list_violation_pages_for_site( $site_id, $rule_id, $impact ) {
		global $wpdb;

		$tables = self::table_names();
		$impact = self::normalize_violation_impact_value( $impact );

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					scan_urls.url,
					scan_urls.normalized_url,
					COUNT(DISTINCT violations.id) AS occurrence_count,
					MAX(violations.last_seen_at) AS last_seen_at
				FROM {$tables['violations']} violations
				INNER JOIN {$tables['scan_urls']} scan_urls
					ON scan_urls.id = violations.scan_url_id
				INNER JOIN {$tables['scans']} scans
					ON scans.id = scan_urls.scan_id
				WHERE scans.site_id = %d
					AND violations.rule_id = %s
					AND COALESCE(NULLIF(violations.impact, ''), 'unknown') = %s
				GROUP BY scan_urls.url, scan_urls.normalized_url
				ORDER BY last_seen_at DESC, occurrence_count DESC, scan_urls.normalized_url ASC",
				(int) $site_id,
				$rule_id,
				$impact
			),
			ARRAY_A
		);
	}

	public static function list_violation_occurrences_for_site( $site_id, $rule_id, $impact ) {
		global $wpdb;

		$tables = self::table_names();
		$impact = self::normalize_violation_impact_value( $impact );

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					violations.id,
					violations.first_seen_at,
					violations.last_seen_at,
					violations.resolved_at,
					violations.workflow_status,
					violations.notes,
					scan_urls.url,
					scan_urls.normalized_url
				FROM {$tables['violations']} violations
				INNER JOIN {$tables['scan_urls']} scan_urls
					ON scan_urls.id = violations.scan_url_id
				INNER JOIN {$tables['scans']} scans
					ON scans.id = scan_urls.scan_id
				WHERE scans.site_id = %d
					AND violations.rule_id = %s
					AND COALESCE(NULLIF(violations.impact, ''), 'unknown') = %s
				ORDER BY violations.last_seen_at DESC, violations.id DESC",
				(int) $site_id,
				$rule_id,
				$impact
			),
			ARRAY_A
		);
	}

	public static function get_violation_detail_for_site( $site_id, $violation_id ) {
		global $wpdb;

		$tables = self::table_names();

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					violations.id,
					violations.rule_id,
					COALESCE(NULLIF(violations.impact, ''), 'unknown') AS impact,
					violations.help,
					violations.help_url,
					violations.description,
					violations.html_snippet,
					violations.target_json,
					violations.tags_json,
					violations.fingerprint,
					violations.workflow_status,
					violations.notes,
					violations.first_seen_at,
					violations.last_seen_at,
					violations.resolved_at,
					scan_urls.url,
					scan_urls.normalized_url
				FROM {$tables['violations']} violations
				INNER JOIN {$tables['scan_urls']} scan_urls
					ON scan_urls.id = violations.scan_url_id
				INNER JOIN {$tables['scans']} scans
					ON scans.id = scan_urls.scan_id
				WHERE scans.site_id = %d
					AND violations.id = %d
				LIMIT 1",
				(int) $site_id,
				(int) $violation_id
			),
			ARRAY_A
		);
	}

	public static function store_scan( array $data ) {
		global $wpdb;

		$tables = self::table_names();
		$record = array(
			'site_id'              => (int) $data['site_id'],
			'status'               => sanitize_text_field( $data['status'] ?? '' ),
			'remote_job_id'        => isset( $data['remote_job_id'] ) ? sanitize_text_field( $data['remote_job_id'] ) : null,
			'scan_mode'            => sanitize_text_field( $data['scan_mode'] ?? '' ),
			'initiated_by_user_id' => isset( $data['initiated_by_user_id'] ) ? (int) $data['initiated_by_user_id'] : null,
			'started_at'           => $data['started_at'] ?? null,
			'finished_at'          => $data['finished_at'] ?? null,
			'error_message'        => isset( $data['error_message'] ) ? wp_kses_post( $data['error_message'] ) : null,
		);

		$result = $wpdb->insert(
			$tables['scans'],
			$record,
			array( '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
		);

		if ( false === $result ) {
			return new WP_Error( 'acc_scan_store_failed', $wpdb->last_error );
		}

		return (int) $wpdb->insert_id;
	}

	public static function update_scan( $scan_id, array $data ) {
		global $wpdb;

		$tables  = self::table_names();
		$record  = array();
		$formats = array();

		if ( array_key_exists( 'status', $data ) ) {
			$record['status'] = sanitize_text_field( $data['status'] );
			$formats[]        = '%s';
		}

		if ( array_key_exists( 'remote_job_id', $data ) ) {
			$record['remote_job_id'] = '' !== (string) $data['remote_job_id'] ? sanitize_text_field( $data['remote_job_id'] ) : null;
			$formats[]               = '%s';
		}

		if ( array_key_exists( 'started_at', $data ) ) {
			$record['started_at'] = $data['started_at'];
			$formats[]            = '%s';
		}

		if ( array_key_exists( 'finished_at', $data ) ) {
			$record['finished_at'] = $data['finished_at'];
			$formats[]             = '%s';
		}

		if ( array_key_exists( 'error_message', $data ) ) {
			$record['error_message'] = null !== $data['error_message'] ? sanitize_textarea_field( $data['error_message'] ) : null;
			$formats[]               = '%s';
		}

		if ( empty( $record ) ) {
			return true;
		}

		$result = $wpdb->update(
			$tables['scans'],
			$record,
			array( 'id' => (int) $scan_id ),
			$formats,
			array( '%d' )
		);

		if ( false === $result ) {
			return new WP_Error( 'acc_scan_update_failed', $wpdb->last_error );
		}

		return true;
	}

	public static function store_scan_url( array $data ) {
		global $wpdb;

		$tables = self::table_names();
		$record = array(
			'scan_id'        => (int) $data['scan_id'],
			'url'            => esc_url_raw( $data['url'] ?? '' ),
			'normalized_url' => sanitize_text_field( $data['normalized_url'] ?? '' ),
			'http_status'    => isset( $data['http_status'] ) ? (int) $data['http_status'] : null,
			'scanned_at'     => $data['scanned_at'] ?? null,
		);

		$result = $wpdb->insert(
			$tables['scan_urls'],
			$record,
			array( '%d', '%s', '%s', '%d', '%s' )
		);

		if ( false === $result ) {
			return new WP_Error( 'acc_scan_url_store_failed', $wpdb->last_error );
		}

		return (int) $wpdb->insert_id;
	}

	public static function store_violation( array $data ) {
		global $wpdb;

		$tables = self::table_names();
		$now    = current_time( 'mysql' );
		$record = array(
			'scan_url_id'               => (int) $data['scan_url_id'],
			'rule_id'                   => sanitize_text_field( $data['rule_id'] ?? '' ),
			'impact'                    => isset( $data['impact'] ) ? sanitize_text_field( $data['impact'] ) : null,
			'help'                      => isset( $data['help'] ) ? sanitize_textarea_field( $data['help'] ) : null,
			'help_url'                  => isset( $data['help_url'] ) ? esc_url_raw( $data['help_url'] ) : null,
			'description'               => isset( $data['description'] ) ? sanitize_textarea_field( $data['description'] ) : null,
			'html_snippet'              => isset( $data['html_snippet'] ) ? wp_kses_post( $data['html_snippet'] ) : null,
			'target_json'               => isset( $data['target_json'] ) ? wp_json_encode( $data['target_json'] ) : null,
			'tags_json'                 => isset( $data['tags_json'] ) ? wp_json_encode( self::sanitize_violation_tags( $data['tags_json'] ) ) : null,
			'fingerprint'               => sanitize_text_field( $data['fingerprint'] ?? '' ),
			'workflow_status'           => sanitize_text_field( $data['workflow_status'] ?? 'New' ),
			'notes'                     => isset( $data['notes'] ) ? sanitize_textarea_field( $data['notes'] ) : null,
			'first_seen_at'             => $data['first_seen_at'] ?? $now,
			'last_seen_at'              => $data['last_seen_at'] ?? $now,
			'resolved_at'               => $data['resolved_at'] ?? null,
			'status_changed_at'         => $data['status_changed_at'] ?? null,
			'status_changed_by_user_id' => isset( $data['status_changed_by_user_id'] ) ? (int) $data['status_changed_by_user_id'] : null,
			'ignored_at'                => $data['ignored_at'] ?? null,
		);

		$result = $wpdb->insert(
			$tables['violations'],
			$record,
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s' )
		);

		if ( false === $result ) {
			return new WP_Error( 'acc_violation_store_failed', $wpdb->last_error );
		}

		return (int) $wpdb->insert_id;
	}

	public static function replace_scan_results( $scan_id, array $results, $scanned_at ) {
		global $wpdb;

		$tables     = self::table_names();
		$scan_id    = (int) $scan_id;
		$scanned_at = $scanned_at ? (string) $scanned_at : current_time( 'mysql' );
		$scan       = self::get_scan( $scan_id );

		if ( empty( $scan ) ) {
			return new WP_Error( 'acc_scan_results_missing_scan', __( 'The scan could not be loaded for result ingestion.', 'accessibility-scan-manager' ) );
		}

		$site_id                 = (int) $scan['site_id'];
		$current_fingerprints    = self::collect_scan_result_fingerprints( $results );
		$current_normalized_urls = self::collect_scan_result_normalized_urls( $results );
		$prior_by_fingerprint    = self::list_latest_prior_violations_by_fingerprint( $site_id, $scan_id, $current_fingerprints );

		$transaction_started = false !== $wpdb->query( 'START TRANSACTION' );

		$delete_violations_result = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$tables['violations']}
				WHERE scan_url_id IN (
					SELECT id FROM {$tables['scan_urls']} WHERE scan_id = %d
				)",
				$scan_id
			)
		);

		if ( false === $delete_violations_result ) {
			if ( $transaction_started ) {
				$wpdb->query( 'ROLLBACK' );
			}

			return new WP_Error( 'acc_scan_results_delete_violations_failed', $wpdb->last_error );
		}

		$delete_scan_urls_result = $wpdb->delete(
			$tables['scan_urls'],
			array( 'scan_id' => $scan_id ),
			array( '%d' )
		);

		if ( false === $delete_scan_urls_result ) {
			if ( $transaction_started ) {
				$wpdb->query( 'ROLLBACK' );
			}

			return new WP_Error( 'acc_scan_results_delete_scan_urls_failed', $wpdb->last_error );
		}

		$url_count       = 0;
		$violation_count = 0;

		foreach ( $results as $result ) {
			$scan_url_id = self::store_scan_url(
				array(
					'scan_id'        => $scan_id,
					'url'            => $result['url'],
					'normalized_url' => $result['normalized_url'],
					'http_status'    => $result['http_status'],
					'scanned_at'     => $scanned_at,
				)
			);

			if ( is_wp_error( $scan_url_id ) ) {
				if ( $transaction_started ) {
					$wpdb->query( 'ROLLBACK' );
				}

				return $scan_url_id;
			}

			++$url_count;

			foreach ( $result['violations'] as $violation ) {
				$prior_violation = $prior_by_fingerprint[ $violation['fingerprint'] ] ?? null;
				$continuity_data = self::build_recurring_violation_continuity_data( $prior_violation, $scanned_at );
				$violation_id = self::store_violation(
					array_merge(
						array(
							'scan_url_id'   => $scan_url_id,
							'rule_id'       => $violation['rule_id'],
							'impact'        => $violation['impact'],
							'help'          => $violation['help'],
							'help_url'      => $violation['help_url'],
							'description'   => $violation['description'],
							'html_snippet'  => $violation['html_snippet'],
							'target_json'   => $violation['target_json'],
							'tags_json'     => $violation['tags_json'],
							'fingerprint'   => $violation['fingerprint'],
							'first_seen_at' => $scanned_at,
							'last_seen_at'  => $scanned_at,
						),
						$continuity_data
					)
				);

				if ( is_wp_error( $violation_id ) ) {
					if ( $transaction_started ) {
						$wpdb->query( 'ROLLBACK' );
					}

					return $violation_id;
				}

				++$violation_count;
			}
		}

		$resolved_count = self::mark_missing_prior_violations_resolved(
			$site_id,
			$scan_id,
			$current_normalized_urls,
			$current_fingerprints,
			$scanned_at
		);

		if ( is_wp_error( $resolved_count ) ) {
			if ( $transaction_started ) {
				$wpdb->query( 'ROLLBACK' );
			}

			return $resolved_count;
		}

		if ( $transaction_started ) {
			$wpdb->query( 'COMMIT' );
		}

		return array(
			'url_count'       => $url_count,
			'violation_count' => $violation_count,
			'resolved_count'  => $resolved_count,
		);
	}

	public static function update_violation_workflow( $violation_id, $status, $notes = null, $user_id = null ) {
		global $wpdb;

		$tables = self::table_names();
		$now    = current_time( 'mysql' );
		$status = sanitize_text_field( $status );
		$data   = array(
			'workflow_status'           => $status,
			'status_changed_at'         => $now,
			'status_changed_by_user_id' => $user_id ? (int) $user_id : get_current_user_id(),
			'ignored_at'                => null,
			'resolved_at'               => null,
		);
		$formats = array( '%s', '%s', '%d', '%s', '%s' );

		if ( null !== $notes ) {
			$data['notes'] = sanitize_textarea_field( $notes );
			$formats[]     = '%s';
		}

		if ( 'Ignored' === $data['workflow_status'] ) {
			$data['ignored_at'] = $now;
		} elseif ( 'Resolved' === $data['workflow_status'] ) {
			$data['resolved_at'] = $now;
		}

		$result = $wpdb->update(
			$tables['violations'],
			$data,
			array( 'id' => (int) $violation_id ),
			$formats,
			array( '%d' )
		);

		if ( false === $result ) {
			return new WP_Error( 'acc_violation_workflow_update_failed', $wpdb->last_error );
		}

		return true;
	}

	private static function collect_scan_result_fingerprints( array $results ) {
		$fingerprints = array();

		foreach ( $results as $result ) {
			if ( empty( $result['violations'] ) || ! is_array( $result['violations'] ) ) {
				continue;
			}

			foreach ( $result['violations'] as $violation ) {
				if ( empty( $violation['fingerprint'] ) ) {
					continue;
				}

				$fingerprints[] = sanitize_text_field( (string) $violation['fingerprint'] );
			}
		}

		return array_values( array_unique( array_filter( $fingerprints ) ) );
	}

	private static function collect_scan_result_normalized_urls( array $results ) {
		$normalized_urls = array();

		foreach ( $results as $result ) {
			if ( empty( $result['normalized_url'] ) ) {
				continue;
			}

			$normalized_urls[] = sanitize_text_field( (string) $result['normalized_url'] );
		}

		return array_values( array_unique( array_filter( $normalized_urls ) ) );
	}

	private static function list_latest_prior_violations_by_fingerprint( $site_id, $scan_id, array $fingerprints ) {
		global $wpdb;

		if ( empty( $fingerprints ) ) {
			return array();
		}

		$tables               = self::table_names();
		$fingerprints         = array_values( array_unique( array_map( 'sanitize_text_field', $fingerprints ) ) );
		$fingerprint_formats  = implode( ', ', array_fill( 0, count( $fingerprints ), '%s' ) );
		$params               = array_merge( array( (int) $site_id, (int) $scan_id ), $fingerprints );
		$prior_by_fingerprint = array();

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					violations.fingerprint,
					violations.workflow_status,
					violations.notes,
					violations.first_seen_at,
					violations.status_changed_at,
					violations.status_changed_by_user_id,
					violations.ignored_at,
					violations.resolved_at
				FROM {$tables['violations']} violations
				INNER JOIN {$tables['scan_urls']} scan_urls
					ON scan_urls.id = violations.scan_url_id
				INNER JOIN {$tables['scans']} scans
					ON scans.id = scan_urls.scan_id
				WHERE scans.site_id = %d
					AND scans.id < %d
					AND violations.fingerprint IN ({$fingerprint_formats})
				ORDER BY violations.fingerprint ASC, violations.last_seen_at DESC, violations.id DESC",
				$params
			),
			ARRAY_A
		);

		foreach ( $rows as $row ) {
			$fingerprint = (string) $row['fingerprint'];

			if ( isset( $prior_by_fingerprint[ $fingerprint ] ) ) {
				continue;
			}

			$prior_by_fingerprint[ $fingerprint ] = $row;
		}

		return $prior_by_fingerprint;
	}

	private static function build_recurring_violation_continuity_data( $prior_violation, $scanned_at ) {
		if ( empty( $prior_violation ) || 'Resolved' === (string) $prior_violation['workflow_status'] ) {
			return array();
		}

		return array(
			'workflow_status'           => sanitize_text_field( (string) $prior_violation['workflow_status'] ),
			'notes'                     => isset( $prior_violation['notes'] ) ? sanitize_textarea_field( (string) $prior_violation['notes'] ) : null,
			'first_seen_at'             => ! empty( $prior_violation['first_seen_at'] ) ? (string) $prior_violation['first_seen_at'] : $scanned_at,
			'last_seen_at'              => $scanned_at,
			'status_changed_at'         => $prior_violation['status_changed_at'] ?? null,
			'status_changed_by_user_id' => isset( $prior_violation['status_changed_by_user_id'] ) ? (int) $prior_violation['status_changed_by_user_id'] : null,
			'ignored_at'                => $prior_violation['ignored_at'] ?? null,
			'resolved_at'               => null,
		);
	}

	private static function mark_missing_prior_violations_resolved( $site_id, $scan_id, array $current_normalized_urls, array $current_fingerprints, $resolved_at ) {
		global $wpdb;

		if ( empty( $current_normalized_urls ) ) {
			return 0;
		}

		$tables             = self::table_names();
		$open_statuses      = array( 'New', 'Accepted', 'In Progress', 'Needs Review' );
		$url_formats        = implode( ', ', array_fill( 0, count( $current_normalized_urls ), '%s' ) );
		$status_formats     = implode( ', ', array_fill( 0, count( $open_statuses ), '%s' ) );
		$params             = array_merge(
			array(
				'Resolved',
				(string) $resolved_at,
				(string) $resolved_at,
				(int) $site_id,
				(int) $scan_id,
			),
			array_map( 'sanitize_text_field', $current_normalized_urls ),
			$open_statuses
		);
		$fingerprint_filter = '';

		if ( ! empty( $current_fingerprints ) ) {
			$current_fingerprints = array_values( array_unique( array_map( 'sanitize_text_field', $current_fingerprints ) ) );
			$fingerprint_formats  = implode( ', ', array_fill( 0, count( $current_fingerprints ), '%s' ) );
			$fingerprint_filter   = " AND violations.fingerprint NOT IN ({$fingerprint_formats})";
			$params               = array_merge( $params, $current_fingerprints );
		}

		$result = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$tables['violations']} violations
				INNER JOIN {$tables['scan_urls']} scan_urls
					ON scan_urls.id = violations.scan_url_id
				INNER JOIN {$tables['scans']} scans
					ON scans.id = scan_urls.scan_id
				SET
					violations.workflow_status = %s,
					violations.resolved_at = %s,
					violations.status_changed_at = %s,
					violations.status_changed_by_user_id = NULL
				WHERE scans.site_id = %d
					AND scans.id < %d
					AND scan_urls.normalized_url IN ({$url_formats})
					AND violations.workflow_status IN ({$status_formats})
					{$fingerprint_filter}",
				$params
			)
		);

		if ( false === $result ) {
			return new WP_Error( 'acc_scan_results_resolve_prior_failed', $wpdb->last_error );
		}

		return (int) $result;
	}

	private static function normalize_violation_impact_value( $impact ) {
		$impact = sanitize_text_field( (string) $impact );

		if ( '' === $impact ) {
			return 'unknown';
		}

		return $impact;
	}

	private static function sanitize_violation_tags( $tags ) {
		if ( ! is_array( $tags ) ) {
			return array();
		}

		$sanitized = array();

		foreach ( $tags as $tag ) {
			if ( ! is_scalar( $tag ) ) {
				continue;
			}

			$sanitized_tag = sanitize_key( (string) $tag );

			if ( '' !== $sanitized_tag ) {
				$sanitized[] = $sanitized_tag;
			}
		}

		return array_values( array_unique( $sanitized ) );
	}
}
