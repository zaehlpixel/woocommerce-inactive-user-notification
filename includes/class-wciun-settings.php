<?php
/**
 * Einstellungen des Plugins.
 *
 * @package WCIUN
 */

defined( 'ABSPATH' ) || exit;

final class WCIUN_Settings {

	const OPTION = 'wciun_settings';

	const MODE_DRY_RUN = 'dry_run';
	const MODE_TEST    = 'test';
	const MODE_LIVE    = 'live';

	/**
	 * Standardwerte. Startmodus ist der Trockenlauf: keine E-Mails, keine Markierungen, Sperre aktiv.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'mode'               => self::MODE_DRY_RUN,
			'lead_days'          => 28,
			'daily_limit'        => 100,
			'test_recipient'     => '',
			'team_recipients'    => '',
			'team_notify'        => 1,
			'exclude_privileged' => 1,
		);
	}

	/**
	 * Alle Einstellungen, ergänzt um Standardwerte.
	 *
	 * @return array
	 */
	public static function all() {
		$stored = get_option( self::OPTION, array() );
		return self::sanitize( wp_parse_args( is_array( $stored ) ? $stored : array(), self::defaults() ) );
	}

	/**
	 * Einzelwert.
	 *
	 * @param string $key Schlüssel.
	 * @return mixed
	 */
	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/**
	 * Bereinigt Eingaben. Wird beim Speichern und beim Lesen angewandt, damit ein manipulierter
	 * Optionswert nie zu unsinnigen Werten führt.
	 *
	 * @param array $in Rohwerte.
	 * @return array
	 */
	public static function sanitize( $in ) {
		$d   = self::defaults();
		$out = array();

		$mode        = isset( $in['mode'] ) ? (string) $in['mode'] : $d['mode'];
		$out['mode'] = in_array( $mode, array( self::MODE_DRY_RUN, self::MODE_TEST, self::MODE_LIVE ), true ) ? $mode : self::MODE_DRY_RUN;

		$lead             = isset( $in['lead_days'] ) ? (int) $in['lead_days'] : $d['lead_days'];
		$out['lead_days'] = max( 14, min( 90, $lead ) );

		$limit              = isset( $in['daily_limit'] ) ? (int) $in['daily_limit'] : $d['daily_limit'];
		$out['daily_limit'] = max( 1, min( 1000, $limit ) );

		$out['test_recipient']  = isset( $in['test_recipient'] ) && is_email( trim( (string) $in['test_recipient'] ) ) ? sanitize_email( trim( (string) $in['test_recipient'] ) ) : '';
		$out['team_recipients'] = implode( ', ', self::parse_addresses( isset( $in['team_recipients'] ) ? (string) $in['team_recipients'] : '' ) );

		$out['team_notify']        = ! empty( $in['team_notify'] ) ? 1 : 0;
		$out['exclude_privileged'] = ! empty( $in['exclude_privileged'] ) ? 1 : 0;
		return $out;
	}

	/**
	 * Zerlegt eine Liste von Adressen (Komma, Semikolon, Leerzeichen, Zeilenumbruch) und behält nur gültige.
	 *
	 * @param string $raw Eingabe.
	 * @return string[]
	 */
	public static function parse_addresses( $raw ) {
		$parts = preg_split( '/[\s,;]+/', (string) $raw, -1, PREG_SPLIT_NO_EMPTY );
		$out   = array();
		foreach ( (array) $parts as $p ) {
			if ( is_email( $p ) ) {
				$out[] = sanitize_email( $p );
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Empfänger der Team-Mail. Ohne eigene Eingabe die Administrator-Adresse aus Einstellungen → Allgemein.
	 *
	 * @return string Kommagetrennte Liste.
	 */
	public static function team_recipients() {
		$list = self::parse_addresses( (string) self::get( 'team_recipients' ) );
		if ( ! $list ) {
			$list = self::parse_addresses( (string) get_option( 'admin_email' ) );
		}
		return implode( ', ', $list );
	}
}
