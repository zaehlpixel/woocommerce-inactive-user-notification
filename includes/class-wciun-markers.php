<?php
/**
 * Freigabe-Markierungen und Sperren je Konto.
 *
 * Eine Markierung entsteht erst, nachdem die Warn-E-Mail an den Mailserver übergeben wurde. Sie enthält:
 * - release_at:  Zeitpunkt, ab dem die Löschung durch WooCommerce zulässig ist (Versand + Vorlaufzeit)
 * - valid_until: Ende der Gültigkeit. Danach gilt die Warnung als veraltet und das Konto bleibt gesperrt.
 * - active_ref:  der Wert von wc_last_active zum Zeitpunkt der Warnung. Ändert sich die Aktivität, verfällt die Markierung.
 *
 * @package WCIUN
 */

defined( 'ABSPATH' ) || exit;

final class WCIUN_Markers {

	const RELEASE     = '_wciun_release_at';
	const VALID_UNTIL = '_wciun_valid_until';
	const ACTIVE_REF  = '_wciun_active_ref';
	const WARNED_AT   = '_wciun_warned_at';
	const STATUS      = '_wciun_status';
	const ATTEMPTS    = '_wciun_attempts';
	const NEXT_TRY    = '_wciun_next_try';
	const TEST_REF    = '_wciun_test_ref';
	const DUPLICATE   = '_wciun_dup';

	const STATUS_WARNED       = 'warned';
	const STATUS_MANUAL       = 'manual_release';
	const STATUS_FAILED       = 'failed';
	const STATUS_NO_EMAIL     = 'no_email';
	const STATUS_UNCLEAR      = 'unclear';

	/** So lange gilt eine Freigabe nach Ablauf der Vorlaufzeit (Tage). */
	const VALIDITY_DAYS = 90;

	const ALL_KEYS = array(
		self::RELEASE,
		self::VALID_UNTIL,
		self::ACTIVE_REF,
		self::WARNED_AT,
		self::STATUS,
		self::ATTEMPTS,
		self::NEXT_TRY,
		self::TEST_REF,
		self::DUPLICATE,
	);

	/**
	 * Hooks: Jede Änderung von wc_last_active setzt einen neuen Inaktivitätszeitraum in Gang.
	 */
	public static function init() {
		add_action( 'updated_user_meta', array( __CLASS__, 'on_meta_change' ), 10, 4 );
		add_action( 'added_user_meta', array( __CLASS__, 'on_meta_change' ), 10, 4 );
	}

	/**
	 * Verwirft Markierungen, wenn sich die letzte Aktivität geändert hat.
	 *
	 * @param int    $meta_id    Unbenutzt.
	 * @param int    $user_id    Benutzer.
	 * @param string $meta_key   Schlüssel.
	 * @param mixed  $meta_value Neuer Wert.
	 */
	public static function on_meta_change( $meta_id, $user_id, $meta_key, $meta_value ) {
		if ( 'wc_last_active' !== $meta_key ) {
			return;
		}
		$ref = get_user_meta( $user_id, self::ACTIVE_REF, true );
		$has = '' !== (string) $ref || '' !== (string) get_user_meta( $user_id, self::TEST_REF, true ) || '' !== (string) get_user_meta( $user_id, self::STATUS, true );
		if ( ! $has ) {
			return;
		}
		if ( '' !== (string) $ref && (string) $ref === (string) $meta_value ) {
			return; // Unverändert (zum Beispiel durch unsere eigene Markierung).
		}
		if ( '' !== (string) get_user_meta( $user_id, self::WARNED_AT, true ) ) {
			WCIUN_Digest::bump( 'reactivated' );
		}
		self::clear( $user_id );
	}

	/**
	 * Setzt die Markierung nach erfolgreicher Übergabe der Warn-E-Mail.
	 *
	 * @param int    $user_id     Benutzer.
	 * @param int    $last_active Wert von wc_last_active, auf den sich die Warnung bezieht.
	 * @param int    $now         Zeitpunkt des Versands.
	 * @param int    $lead_days   Vorlaufzeit in Tagen.
	 * @param string $status      Status.
	 * @return int Freigabezeitpunkt.
	 */
	public static function set_released( $user_id, $last_active, $now, $lead_days, $status = self::STATUS_WARNED ) {
		$release = (int) $now + (int) $lead_days * DAY_IN_SECONDS;
		if ( self::STATUS_MANUAL === $status ) {
			$release = (int) $now;
		}
		update_user_meta( $user_id, self::ACTIVE_REF, (string) (int) $last_active );
		update_user_meta( $user_id, self::WARNED_AT, (string) (int) $now );
		update_user_meta( $user_id, self::RELEASE, (string) $release );
		update_user_meta( $user_id, self::VALID_UNTIL, (string) ( $release + self::VALIDITY_DAYS * DAY_IN_SECONDS ) );
		update_user_meta( $user_id, self::STATUS, $status );
		delete_user_meta( $user_id, self::ATTEMPTS );
		delete_user_meta( $user_id, self::NEXT_TRY );
		return $release;
	}

	/**
	 * Entfernt alle Markierungen und Status eines Kontos.
	 *
	 * @param int $user_id Benutzer.
	 */
	public static function clear( $user_id ) {
		foreach ( self::ALL_KEYS as $key ) {
			delete_user_meta( $user_id, $key );
		}
	}

	/**
	 * Ist die Löschung dieses Kontos durch eine gültige Freigabe gedeckt?
	 *
	 * @param int $user_id Benutzer.
	 * @param int $now     Zeitpunkt.
	 * @return bool
	 */
	public static function is_released( $user_id, $now ) {
		$release = (int) get_user_meta( $user_id, self::RELEASE, true );
		$until   = (int) get_user_meta( $user_id, self::VALID_UNTIL, true );
		$ref     = (string) get_user_meta( $user_id, self::ACTIVE_REF, true );
		$active  = (string) get_user_meta( $user_id, 'wc_last_active', true );
		return $release > 0 && $release <= $now && $until >= $now && '' !== $ref && $ref === $active;
	}

	/**
	 * Hat das Konto überhaupt eine Freigabe-Markierung (gleich ob schon erreicht)?
	 *
	 * @param int $user_id Benutzer.
	 * @return bool
	 */
	public static function has_marker( $user_id ) {
		return '' !== (string) get_user_meta( $user_id, self::RELEASE, true );
	}

	/**
	 * IDs aller Konten mit Freigabe-Markierung.
	 *
	 * @return int[]
	 */
	public static function marked_ids() {
		global $wpdb;
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s", self::RELEASE ) ) );
	}

	/**
	 * Räumt veraltete Markierungen auf:
	 * - die Aktivität hat sich geändert (Ausfall des Hooks oder direkter Datenbankzugriff),
	 * - die Gültigkeit ist abgelaufen,
	 * - die Frist wurde so geändert, dass das Konto nicht mehr in absehbarer Zeit fällig ist.
	 *
	 * Betroffene Konten werden bei einem späteren Lauf erneut gewarnt.
	 *
	 * @param array|null $retention Aktuelle Frist oder null, wenn deaktiviert.
	 * @param int        $now       Zeitpunkt.
	 * @param int        $lead_days Vorlaufzeit.
	 * @return int Anzahl verworfener Markierungen.
	 */
	public static function reconcile( $retention, $now, $lead_days ) {
		$dropped = 0;
		foreach ( self::marked_ids() as $user_id ) {
			if ( self::STATUS_MANUAL === get_user_meta( $user_id, self::STATUS, true ) ) {
				// Manuelle Freigabe: nur bei Aktivität oder Ablauf verwerfen.
				$retention_check = null;
			} else {
				$retention_check = $retention;
			}
			$ref    = (string) get_user_meta( $user_id, self::ACTIVE_REF, true );
			$active = (string) get_user_meta( $user_id, 'wc_last_active', true );
			$until  = (int) get_user_meta( $user_id, self::VALID_UNTIL, true );
			$drop   = false;
			if ( '' === $ref || $ref !== $active ) {
				$drop = true;
			} elseif ( $until < $now ) {
				$drop = true;
			} elseif ( $retention_check && WCIUN_Calc::eligible_at( $retention_check, (int) $active ) > $now + $lead_days * DAY_IN_SECONDS ) {
				$drop = true;
			}
			if ( $drop ) {
				self::clear( $user_id );
				++$dropped;
			}
		}
		return $dropped;
	}

	/**
	 * Atomare Sperren über eine Zeile in wp_options (der Name ist eindeutig, INSERT IGNORE ist atomar).
	 *
	 * @param string $name     Name der Sperre.
	 * @param int    $ttl      Lebensdauer in Sekunden.
	 * @param bool   $takeover Abgelaufene Sperre übernehmen?
	 * @return string 'claimed', 'busy' oder 'stale' (abgelaufen, nicht übernommen).
	 */
	public static function claim( $name, $ttl, $takeover ) {
		global $wpdb;
		$key = 'wciun_claim_' . $name;
		$now = time();
		$ins = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $key, (string) $now ) );
		if ( 1 === (int) $ins ) {
			return 'claimed';
		}
		$existing = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $key ) );
		if ( null === $existing ) {
			return 'busy';
		}
		if ( $now - (int) $existing <= $ttl ) {
			return 'busy';
		}
		if ( ! $takeover ) {
			return 'stale';
		}
		$upd = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", (string) $now, $key, $existing ) );
		return 1 === (int) $upd ? 'claimed' : 'busy';
	}

	/**
	 * Erneuert den Zeitstempel einer gehaltenen Sperre.
	 *
	 * @param string $name Name.
	 */
	public static function touch_claim( $name ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s", (string) time(), 'wciun_claim_' . $name ) );
	}

	/**
	 * Gibt eine Sperre frei.
	 *
	 * @param string $name Name.
	 */
	public static function release_claim( $name ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", 'wciun_claim_' . $name ) );
	}
}
