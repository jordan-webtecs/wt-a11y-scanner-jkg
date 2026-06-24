<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ACC_Violation_Classification {
	public static function derive_display_classification( $stored_tags_json ) {
		// MVP rule: prefer WCAG A tags, then WCAG AA tags, then treat tagged
		// non-WCAG findings as Best Practice. "Other" is reserved for missing
		// or unusable stored tags so the rule can be revised later without
		// changing stored source data.
		$tags = self::decode_tags_json( $stored_tags_json );

		if ( empty( $tags ) ) {
			return 'Other';
		}

		if ( self::has_any_tag( $tags, array( 'wcag2a', 'wcag21a' ) ) ) {
			return 'WCAG A';
		}

		if ( self::has_any_tag( $tags, array( 'wcag2aa', 'wcag21aa' ) ) ) {
			return 'WCAG AA';
		}

		return 'Best Practice';
	}

	private static function decode_tags_json( $stored_tags_json ) {
		if ( ! is_string( $stored_tags_json ) || '' === trim( $stored_tags_json ) ) {
			return array();
		}

		$decoded = json_decode( $stored_tags_json, true );

		if ( ! is_array( $decoded ) ) {
			return array();
		}

		$tags = array();

		foreach ( $decoded as $tag ) {
			if ( ! is_scalar( $tag ) ) {
				continue;
			}

			$sanitized_tag = sanitize_key( (string) $tag );

			if ( '' !== $sanitized_tag ) {
				$tags[] = $sanitized_tag;
			}
		}

		return array_values( array_unique( $tags ) );
	}

	private static function has_any_tag( array $tags, array $expected_tags ) {
		foreach ( $expected_tags as $expected_tag ) {
			if ( in_array( $expected_tag, $tags, true ) ) {
				return true;
			}
		}

		return false;
	}
}
