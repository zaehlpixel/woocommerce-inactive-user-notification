<?php
/**
 * Selbsttest der Löschsperre.
 *
 * Die Sperre hängt davon ab, dass WooCommerce die Löschabfrage so baut wie in der geprüften Version.
 * Der Test prüft das, was sich zur Laufzeit prüfen lässt, ohne etwas zu löschen. Er ersetzt nicht den
 * Integrationstest mit echtem WooCommerce-Code (tests/integration), der bei jeder neuen Version läuft.
 *
 * @package WCIUN
 */

defined( 'ABSPATH' ) || exit;

final class WCIUN_Selftest {

	const OPTION    = 'wciun_selftest';
	const CONFIRMED = 'wciun_confirmed_wc_versions';

	/**
	 * Geprüfte WooCommerce-Versionen: fest eingebaute plus bestätigte.
	 *
	 * @return string[]
	 */
	public static function tested_versions() {
		$list      = array_filter( array_map( 'trim', explode( ',', (string) WCIUN_TESTED_WC_VERSIONS ) ) );
		$confirmed = get_option( self::CONFIRMED, array() );
		$all       = array_values( array_unique( array_merge( $list, is_array( $confirmed ) ? $confirmed : array() ) ) );
		return (array) apply_filters( 'wciun_tested_wc_versions', $all );
	}

	/**
	 * Ein Administrator bestätigt die installierte WooCommerce-Version nach eigener Prüfung.
	 */
	public static function confirm_current_version() {
		if ( ! defined( 'WC_VERSION' ) ) {
			return;
		}
		$confirmed   = get_option( self::CONFIRMED, array() );
		$confirmed   = is_array( $confirmed ) ? $confirmed : array();
		$confirmed[] = WC_VERSION;
		update_option( self::CONFIRMED, array_values( array_unique( $confirmed ) ), false );
	}

	/**
	 * Führt den Test aus und speichert das Ergebnis.
	 *
	 * @return array
	 */
	public static function run() {
		$checks = array();

		$wc_ok    = class_exists( 'WooCommerce' ) && class_exists( 'WC_Privacy' ) && defined( 'WC_VERSION' );
		$checks[] = array(
			'id'       => 'wc_loaded',
			'label'    => 'WooCommerce geladen',
			'ok'       => $wc_ok,
			'required' => true,
			'detail'   => $wc_ok ? 'Version ' . WC_VERSION : 'WooCommerce oder WC_Privacy fehlt.',
		);

		$tested   = self::tested_versions();
		$ver_ok   = $wc_ok && in_array( WC_VERSION, $tested, true );
		$checks[] = array(
			'id'       => 'wc_version',
			'label'    => 'WooCommerce-Version geprüft',
			'ok'       => $ver_ok,
			'required' => true,
			'detail'   => $ver_ok ? 'Geprüft: ' . implode( ', ', $tested ) : ( $wc_ok ? 'Version ' . WC_VERSION . ' wurde nicht gegen die Löschsperre getestet (geprüft: ' . implode( ', ', $tested ) . '). Bitte den Integrationstest ausführen und die Version dann auf der Plugin-Seite bestätigen.' : '–' ),
		);

		$src_ok     = false;
		$src_detail = 'Methode WC_Privacy::delete_inactive_accounts_query nicht gefunden.';
		if ( $wc_ok && method_exists( 'WC_Privacy', 'delete_inactive_accounts_query' ) ) {
			try {
				$ref    = new ReflectionMethod( 'WC_Privacy', 'delete_inactive_accounts_query' );
				$lines  = file( $ref->getFileName() );
				$code   = is_array( $lines ) ? implode( '', array_slice( $lines, $ref->getStartLine() - 1, $ref->getEndLine() - $ref->getStartLine() + 1 ) ) : '';
				$tokens = array( 'WP_User_Query', 'woocommerce_delete_inactive_account_roles', 'wc_last_active', 'wp_delete_user' );
				$miss   = array();
				foreach ( $tokens as $t ) {
					if ( false === strpos( $code, $t ) ) {
						$miss[] = $t;
					}
				}
				$src_ok     = empty( $miss ) && '' !== $code;
				$src_detail = $src_ok ? 'Aufbau wie in der geprüften Version.' : 'Die WooCommerce-Löschfunktion sieht anders aus. Es fehlt: ' . implode( ', ', $miss );
			} catch ( Throwable $e ) {
				$src_detail = 'Quelltext nicht lesbar: ' . $e->getMessage();
			}
		}
		$checks[] = array(
			'id'       => 'wc_source',
			'label'    => 'Löschfunktion von WooCommerce unverändert im Aufbau',
			'ok'       => $src_ok,
			'required' => true,
			'detail'   => $src_detail,
		);

		$sql_ok = false;
		try {
			$sql    = WCIUN_Gate::probe_sql();
			$sql_ok = false !== strpos( $sql, WCIUN_Markers::RELEASE ) && false !== strpos( $sql, WCIUN_Markers::VALID_UNTIL ) && false !== strpos( $sql, WCIUN_Markers::ACTIVE_REF );
		} catch ( Throwable $e ) {
			$sql_ok = false;
		}
		$checks[] = array(
			'id'       => 'gate_sql',
			'label'    => 'Sperre hängt an einer Abfrage wie der von WooCommerce',
			'ok'       => $sql_ok,
			'required' => true,
			'detail'   => $sql_ok ? 'Die Bedingungen der Sperre sind in der Abfrage enthalten.' : 'Die Bedingungen der Sperre fehlen in der Abfrage.',
		);

		// as_next_scheduled_action() liefert true, solange die Aktion gerade läuft, sonst den Zeitstempel oder false.
		$next     = function_exists( 'as_next_scheduled_action' ) ? as_next_scheduled_action( WCIUN_Scanner::HOOK_DAILY ) : false;
		$sched_ok = false !== $next;
		$checks[] = array(
			'id'       => 'scheduler',
			'label'    => 'Täglicher Lauf des Plugins geplant',
			'ok'       => $sched_ok,
			'required' => true,
			'detail'   => self::next_run_text( $next, 'Keine geplante Aktion gefunden.' ),
		);

		$woo_next = function_exists( 'as_next_scheduled_action' ) ? as_next_scheduled_action( 'woocommerce_cleanup_personal_data' ) : false;
		$checks[] = array(
			'id'       => 'wc_cleanup',
			'label'    => 'WooCommerce-Bereinigung geplant (Information)',
			'ok'       => false !== $woo_next,
			'required' => false,
			'detail'   => self::next_run_text( $woo_next, 'Keine geplante Bereinigung gefunden.' ),
		);

		$ok = true;
		foreach ( $checks as $c ) {
			if ( $c['required'] && ! $c['ok'] ) {
				$ok = false;
			}
		}
		$result = array(
			'time'   => time(),
			'ok'     => $ok,
			'checks' => $checks,
		);
		update_option( self::OPTION, $result, false );
		return $result;
	}

	/**
	 * Text zum Ergebnis von as_next_scheduled_action().
	 *
	 * @param int|bool $next    Zeitstempel, true (läuft gerade) oder false (nicht geplant).
	 * @param string   $missing Text, wenn nichts geplant ist.
	 * @return string
	 */
	private static function next_run_text( $next, $missing ) {
		if ( true === $next ) {
			return 'Läuft gerade.';
		}
		return false === $next ? $missing : 'Nächster Lauf: ' . wp_date( 'd.m.Y H:i', (int) $next );
	}

	/**
	 * Letztes Ergebnis.
	 *
	 * @return array
	 */
	public static function last() {
		$r = get_option( self::OPTION, array() );
		return is_array( $r ) ? $r : array();
	}
}
