<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ACC_Plugin {
	const DB_VERSION = '3';
	const DB_VERSION_OPTION = 'acc_db_version';
	const SCANNER_BASE_URL_OPTION = 'acc_scanner_base_url';
	const SCANNER_API_KEY_OPTION  = 'acc_scanner_api_key';

	public static function bootstrap() {
		add_action( 'plugins_loaded', array( __CLASS__, 'maybe_upgrade_database' ) );
		ACC_Admin::bootstrap();
		ACC_Scan_Orchestrator::bootstrap();
	}

	public static function maybe_upgrade_database() {
		$installed_version = get_option( self::DB_VERSION_OPTION );

		if ( self::DB_VERSION !== (string) $installed_version ) {
			ACC_Installer::install();
		}
	}

	public static function get_scanner_base_url() {
		$value = get_option( self::SCANNER_BASE_URL_OPTION, '' );

		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return '';
		}

		return untrailingslashit( esc_url_raw( $value ) );
	}

	public static function get_scanner_api_key() {
		if ( defined( 'ACC_SCANNER_API_KEY' ) ) {
			return sanitize_text_field( (string) ACC_SCANNER_API_KEY );
		}

		$value = get_option( self::SCANNER_API_KEY_OPTION, '' );

		if ( ! is_string( $value ) ) {
			return '';
		}

		return sanitize_text_field( $value );
	}

	public static function has_configured_scanner_api_key() {
		return '' !== self::get_scanner_api_key();
	}

	public static function uses_constant_scanner_api_key() {
		return defined( 'ACC_SCANNER_API_KEY' );
	}
}
