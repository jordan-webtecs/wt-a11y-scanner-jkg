<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ACC_Installer {
	public static function activate() {
		self::install();
	}

	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$tables           = ACC_DB::table_names();
		$sql              = array();

		$sql[] = "CREATE TABLE {$tables['sites']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(255) NOT NULL,
			base_url text NOT NULL,
			sitemap_url text NULL,
			is_active tinyint(1) NOT NULL DEFAULT 1,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$tables['scans']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			site_id bigint(20) unsigned NOT NULL,
			status varchar(50) NOT NULL,
			remote_job_id varchar(191) NULL,
			scan_mode varchar(50) NOT NULL,
			initiated_by_user_id bigint(20) unsigned NULL,
			started_at datetime NULL,
			finished_at datetime NULL,
			error_message text NULL,
			PRIMARY KEY  (id),
			KEY site_id (site_id),
			KEY status (status)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$tables['scan_urls']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			scan_id bigint(20) unsigned NOT NULL,
			url text NOT NULL,
			normalized_url varchar(191) NOT NULL,
			http_status smallint unsigned NULL,
			scanned_at datetime NULL,
			PRIMARY KEY  (id),
			KEY scan_id (scan_id),
			KEY normalized_url (normalized_url)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$tables['violations']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			scan_url_id bigint(20) unsigned NOT NULL,
			rule_id varchar(191) NOT NULL,
			impact varchar(50) NULL,
			help text NULL,
			help_url text NULL,
			description text NULL,
			html_snippet longtext NULL,
			target_json longtext NULL,
			tags_json longtext NULL,
			fingerprint varchar(191) NOT NULL,
			workflow_status varchar(50) NOT NULL DEFAULT 'New',
			notes longtext NULL,
			first_seen_at datetime NOT NULL,
			last_seen_at datetime NOT NULL,
			resolved_at datetime NULL,
			status_changed_at datetime NULL,
			status_changed_by_user_id bigint(20) unsigned NULL,
			ignored_at datetime NULL,
			PRIMARY KEY  (id),
			KEY scan_url_id (scan_url_id),
			KEY rule_id (rule_id),
			KEY workflow_status (workflow_status),
			KEY fingerprint (fingerprint),
			KEY rule_id_workflow_status (rule_id, workflow_status),
			KEY scan_url_id_fingerprint (scan_url_id, fingerprint)
		) {$charset_collate};";

		foreach ( $sql as $statement ) {
			dbDelta( $statement );
		}

		update_option( ACC_Plugin::DB_VERSION_OPTION, ACC_Plugin::DB_VERSION );
	}
}
