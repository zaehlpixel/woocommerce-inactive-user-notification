<?php
/**
 * Zähler, Alarme und Team-Übersicht.
 *
 * Die Team-Mail enthält nur Anzahlen und einen Link auf die geschützte Admin-Ansicht, keine Kundendaten.
 *
 * @package WCIUN
 */

defined( 'ABSPATH' ) || exit;

final class WCIUN_Digest {

	const OPTION  = 'wciun_digest';
	const COUNTER = 'wciun_sent_counter';

	/**
	 * @return array
	 */
	private static function data() {
		$d = get_option( self::OPTION, array() );
		$d = is_array( $d ) ? $d : array();
		return wp_parse_args(
			$d,
			array(
				'counters'  => array(),
				'alerts'    => array(),
				'last_hash' => '',
			)
		);
	}

	/**
	 * Nur für Tests: aktueller Stand der Zähler und Alarme.
	 *
	 * @return array
	 */
	public static function data_for_test() {
		return self::data();
	}

	/**
	 * Zählt ein Ereignis seit der letzten Team-Mail.
	 *
	 * @param string $key Name.
	 * @param int    $n   Anzahl.
	 */
	public static function bump( $key, $n = 1 ) {
		$d                        = self::data();
		$d['counters'][ $key ]    = (int) ( $d['counters'][ $key ] ?? 0 ) + (int) $n;
		update_option( self::OPTION, $d, false );
	}

	/**
	 * Merkt einen Alarm für die nächste Team-Mail vor und verschickt ihn sofort (höchstens einmal pro Stunde je Art).
	 *
	 * @param string $type Art des Alarms.
	 * @param string $text Text ohne personenbezogene Daten.
	 */
	public static function alert( $type, $text ) {
		$d             = self::data();
		$d['alerts'][] = array(
			'time' => time(),
			'type' => $type,
			'text' => $text,
		);
		$d['alerts']   = array_slice( $d['alerts'], -20 );
		update_option( self::OPTION, $d, false );

		$transient = 'wciun_alert_' . md5( $type );
		if ( get_transient( $transient ) ) {
			return;
		}
		set_transient( $transient, 1, HOUR_IN_SECONDS );
		self::send(
			array(
				'is_alert' => true,
				'alerts'   => array( $text ),
				'counts'   => array(),
				'problems' => array(),
				'mode'     => WCIUN_Settings::get( 'mode' ),
			)
		);
	}

	/**
	 * Heute bereits versendete Mails (Kunden-Warnungen und Testmails), Datum nach Zeitzone der Website.
	 *
	 * @return int
	 */
	public static function sent_today() {
		$c = get_option( self::COUNTER, array() );
		return is_array( $c ) && isset( $c['date'], $c['count'] ) && wp_date( 'Y-m-d' ) === $c['date'] ? (int) $c['count'] : 0;
	}

	/**
	 * Erhöht den Tageszähler.
	 */
	public static function add_sent() {
		update_option(
			self::COUNTER,
			array(
				'date'  => wp_date( 'Y-m-d' ),
				'count' => self::sent_today() + 1,
			),
			false
		);
	}

	/**
	 * Anzahl Konten mit einem Status.
	 *
	 * @param string $status Status.
	 * @return int
	 */
	public static function count_status( $status ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT user_id) FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value = %s", WCIUN_Markers::STATUS, $status ) );
	}

	/**
	 * Anzahl Konten, deren Freigabe in den nächsten Tagen erreicht wird.
	 *
	 * @param int $days Tage.
	 * @return int
	 */
	public static function count_releasing( $days ) {
		global $wpdb;
		$now = time();
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT user_id) FROM {$wpdb->usermeta} WHERE meta_key = %s AND CAST(meta_value AS UNSIGNED) BETWEEN %d AND %d",
				WCIUN_Markers::RELEASE,
				$now,
				$now + (int) $days * DAY_IN_SECONDS
			)
		);
	}

	/**
	 * Nach jedem Scan: Team-Übersicht, aber nur wenn es Neues oder Probleme gibt.
	 *
	 * @param array $summary Ergebnis des Scans.
	 * @return bool Wurde eine Mail übergeben?
	 */
	public static function send_after_scan( array $summary ) {
		if ( ! WCIUN_Settings::get( 'team_notify' ) ) {
			return false;
		}
		$d        = self::data();
		$selftest = WCIUN_Selftest::last();
		$problems = array();
		foreach ( (array) ( $selftest['checks'] ?? array() ) as $check ) {
			if ( empty( $check['ok'] ) && ! empty( $check['required'] ) ) {
				$problems[] = $check['label'] . ': ' . $check['detail'];
			}
		}
		if ( ! empty( $summary['email_disabled'] ) ) {
			$problems[] = 'Die E-Mail „Inaktives Konto: Warnung an Kunden“ ist in WooCommerce deaktiviert. Es wurde nichts versendet.';
		}
		if ( ! empty( $summary['retention_disabled'] ) ) {
			$problems[] = 'In WooCommerce ist keine Frist für inaktive Konten eingestellt. WooCommerce löscht derzeit keine Konten; das Plugin sendet nichts.';
		}

		$counts = array(
			'Warnungen verschickt'                       => (int) ( $summary['sent'] ?? 0 ),
			'Wären gewarnt worden (Trockenlauf)'         => (int) ( $summary['would_send'] ?? 0 ),
			'Testmails verschickt'                       => (int) ( $summary['test_sent'] ?? 0 ),
			'Versand fehlgeschlagen (dieser Lauf)'       => (int) ( $summary['failed'] ?? 0 ),
			'Seit der letzten Übersicht wieder aktiv'    => (int) ( $d['counters']['reactivated'] ?? 0 ),
			'Freigabe in den nächsten 7 Tagen'           => self::count_releasing( 7 ),
			'Konten ohne gültige E-Mail-Adresse'         => self::count_status( WCIUN_Markers::STATUS_NO_EMAIL ),
			'Konten mit wiederholtem Versandfehler'      => self::count_status( WCIUN_Markers::STATUS_FAILED ),
			'Konten mit unklarem Versandergebnis'        => self::count_status( WCIUN_Markers::STATUS_UNCLEAR ),
		);
		if ( ! empty( $summary['limit_hit'] ) ) {
			$problems[] = 'Das Tageslimit für Mails wurde erreicht. Die übrigen Konten folgen an den nächsten Tagen.';
		}

		$alerts  = array_map(
			static function ( $a ) {
				return $a['text'];
			},
			(array) $d['alerts']
		);
		$payload = array(
			'is_alert' => false,
			'alerts'   => $alerts,
			'counts'   => $counts,
			'problems' => $problems,
			'mode'     => (string) ( $summary['mode'] ?? WCIUN_Settings::get( 'mode' ) ),
		);

		$news = ( $summary['sent'] ?? 0 ) + ( $summary['would_send'] ?? 0 ) + ( $summary['test_sent'] ?? 0 ) + ( $summary['failed'] ?? 0 ) + ( $d['counters']['reactivated'] ?? 0 );
		$hash = md5( wp_json_encode( array( $counts, $problems, $alerts, $payload['mode'] ) ) );
		if ( ! $news && ! $problems && ! $alerts ) {
			return false;
		}
		// Gleicher Inhalt wie beim letzten Mal und nichts Neues: nicht erneut versenden.
		if ( ! $alerts && ! $news && $hash === $d['last_hash'] ) {
			return false;
		}
		if ( ! self::send( $payload ) ) {
			return false;
		}
		$d['last_hash'] = $hash;
		$d['counters']  = array();
		$d['alerts']    = array();
		update_option( self::OPTION, $d, false );
		return true;
	}

	/**
	 * Versendet die Team-Mail über die WooCommerce-E-Mail-Klasse.
	 *
	 * @param array $payload Inhalt.
	 * @return bool
	 */
	public static function send( array $payload ) {
		try {
			if ( ! function_exists( 'WC' ) || ! WC()->mailer() ) {
				return false;
			}
			$emails = WC()->mailer()->get_emails();
			if ( empty( $emails['WCIUN_Email_Team'] ) ) {
				return false;
			}
			return (bool) $emails['WCIUN_Email_Team']->trigger( $payload );
		} catch ( Throwable $e ) {
			return false;
		}
	}
}
