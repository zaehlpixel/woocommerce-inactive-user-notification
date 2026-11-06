<?php
/**
 * Datumsrechnung. Bewusst ohne WordPress-Abhängigkeit, damit sie einzeln testbar ist.
 *
 * WooCommerce löscht Konten, deren `wc_last_active` kleiner ist als
 * `strtotime( '-<Zahl> <Einheit>' )` zum Zeitpunkt des Laufs. Dieselbe Formel wird
 * hier verwendet, damit Auswahl und Datumsangaben nicht von WooCommerce abweichen.
 *
 * @package WCIUN
 */

defined( 'ABSPATH' ) || defined( 'WCIUN_TESTING' ) || exit;

final class WCIUN_Calc {

	const UNITS = array( 'days', 'weeks', 'months', 'years' );

	/**
	 * Liest die WooCommerce-Option "Inaktive Konten aufbewahren".
	 *
	 * Entspricht wc_parse_relative_date_option(): leere oder ungültige Zahl bedeutet "nie löschen".
	 *
	 * @param mixed $raw Rohwert der Option woocommerce_delete_inactive_accounts.
	 * @return array{number:int,unit:string}|null Null, wenn keine Frist eingestellt ist.
	 */
	public static function parse_retention( $raw ) {
		$raw    = is_array( $raw ) ? $raw : array();
		$number = isset( $raw['number'] ) ? abs( (int) $raw['number'] ) : 0;
		$unit   = isset( $raw['unit'] ) && in_array( $raw['unit'], self::UNITS, true ) ? $raw['unit'] : 'days';
		if ( $number <= 0 ) {
			return null;
		}
		return array(
			'number' => $number,
			'unit'   => $unit,
		);
	}

	/**
	 * Stichtag, den WooCommerce zum Zeitpunkt $now verwendet.
	 *
	 * @param array $retention Ergebnis von parse_retention().
	 * @param int   $now       Unix-Zeit.
	 * @return int
	 */
	public static function cutoff( array $retention, $now ) {
		return (int) strtotime( '-' . $retention['number'] . ' ' . $retention['unit'], (int) $now );
	}

	/**
	 * Frühester Zeitpunkt, ab dem WooCommerce ein Konto mit dieser letzten Aktivität löscht.
	 *
	 * Gesucht wird die kleinste Zeit T mit cutoff(T) > last_active. Da PHPs relative Datumsrechnung an
	 * Monatsenden nicht streng monoton ist (31. März minus 1 Monat ergibt den 3. März, 1. April ergibt
	 * den 1. März), wird das Ergebnis anschließend nach vorn abgesichert: Die Bedingung muss auch für die
	 * folgenden fünf Tage erfüllt sein.
	 *
	 * @param array $retention   Ergebnis von parse_retention().
	 * @param int   $last_active Wert von wc_last_active.
	 * @return int Unix-Zeit.
	 */
	public static function eligible_at( array $retention, $last_active ) {
		$last_active = (int) $last_active;
		$due         = static function ( $t ) use ( $retention, $last_active ) {
			return self::cutoff( $retention, $t ) > $last_active;
		};

		$lo = $last_active; // Hier ist die Bedingung falsch, denn der Stichtag liegt in der Vergangenheit.
		$hi = (int) strtotime( '+' . $retention['number'] . ' ' . $retention['unit'], $last_active ) + 40 * 86400;
		for ( $guard = 0; ! $due( $hi ) && $guard < 10; $guard++ ) {
			$hi += 40 * 86400;
		}

		$t = self::first_true( $due, $lo, $hi );

		// Nach vorn absichern: Wird die Bedingung an einem der folgenden Tage wieder falsch, weiterschieben.
		for ( $guard = 0, $d = 1; $d <= 5 && $guard < 20; $d++ ) {
			$probe = $t + $d * 86400;
			if ( ! $due( $probe ) ) {
				$t = self::first_true( $due, $probe, $probe + 40 * 86400 );
				$d = 0;
				++$guard;
			}
		}
		return $t;
	}

	/**
	 * Kleinste Zeit im Intervall (lo, hi], für die $due wahr ist (Annahme: wahr bei hi, falsch bei lo).
	 *
	 * @param callable $due Prädikat.
	 * @param int      $lo  Untergrenze (Bedingung falsch).
	 * @param int      $hi  Obergrenze (Bedingung wahr).
	 * @return int
	 */
	private static function first_true( $due, $lo, $hi ) {
		while ( $hi - $lo > 1 ) {
			$mid = intdiv( $lo + $hi, 2 );
			if ( $due( $mid ) ) {
				$hi = $mid;
			} else {
				$lo = $mid;
			}
		}
		return $hi;
	}
}
