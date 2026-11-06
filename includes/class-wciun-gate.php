<?php
/**
 * Löschsperre über die WooCommerce-Bereinigung.
 *
 * WooCommerce löscht inaktive Konten per WP_User_Query (WC_Privacy::delete_inactive_accounts_query).
 * Diese Klasse erkennt genau diese Abfrage und ergänzt UND-Bedingungen. Die Sperre kann dadurch nur
 * Konten aus der Abfrage herausnehmen, nie hinzufügen. Sie ändert weder wc_last_active noch Bestellungen
 * noch die WooCommerce-Einstellungen.
 *
 * Verwendet werden ausschließlich dokumentierte WordPress-Hooks (pre_get_users, pre_user_query,
 * delete_user) und der WooCommerce-Filter woocommerce_delete_inactive_account_roles.
 *
 * @package WCIUN
 */

defined( 'ABSPATH' ) || exit;

final class WCIUN_Gate {

	/** @var bool WooCommerce hat in dieser Anfrage den Rollen-Filter der Löschabfrage aufgerufen. */
	private static $armed = false;

	/** @var bool Die gesperrte Löschabfrage lief in dieser Anfrage. Danach folgen die Löschungen. */
	private static $deleting = false;

	/** @var bool Selbsttest läuft: keine Seiteneffekte. */
	private static $probe = false;

	/** @var array<int,string> Objekt-ID der Abfrage => 'gate' oder 'fail'. */
	private static $queries = array();

	/**
	 * Hooks registrieren.
	 */
	public static function init() {
		add_filter( 'woocommerce_delete_inactive_account_roles', array( __CLASS__, 'on_roles_filter' ), PHP_INT_MAX );
		add_action( 'pre_get_users', array( __CLASS__, 'on_pre_get_users' ), 10, 1 );
		add_action( 'pre_user_query', array( __CLASS__, 'on_pre_user_query' ), PHP_INT_MAX, 1 );
		add_action( 'delete_user', array( __CLASS__, 'on_delete_user' ), 1, 1 );
		add_action( 'shutdown', array( __CLASS__, 'on_shutdown' ) );
	}

	/**
	 * Hat WooCommerce den Rollen-Filter aufgerufen, ohne dass eine erkennbare Löschabfrage folgte, hat sich der
	 * Ablauf von WooCommerce verändert. Dann greift die Sperre nicht mehr, und das Team wird gewarnt.
	 */
	public static function on_shutdown() {
		if ( self::$armed && ! self::$deleting ) {
			WCIUN_Digest::alert( 'gate_not_applied', 'WooCommerce hat die Löschabfrage für inaktive Konten gestartet, aber das Plugin hat sie nicht erkannt. Die Löschsperre greift vermutlich nicht mehr. Bitte WooCommerce-Version und Plugin-Status prüfen.' );
		}
	}

	/**
	 * WooCommerce ruft diesen Filter unmittelbar vor der Löschabfrage auf. Die Rollen bleiben unverändert.
	 *
	 * @param mixed $roles Rollen.
	 * @return mixed
	 */
	public static function on_roles_filter( $roles ) {
		if ( ! self::$probe ) {
			self::$armed = true;
		}
		return $roles;
	}

	/**
	 * Erkennt die WooCommerce-Löschabfrage: eine Benutzerabfrage mit der Bedingung wc_last_active "kleiner als".
	 *
	 * @param WP_User_Query $query Abfrage.
	 * @return bool
	 */
	public static function is_deletion_query( $query ) {
		if ( ! $query instanceof WP_User_Query || $query->get( 'wciun_internal' ) ) {
			return false;
		}
		$meta = $query->get( 'meta_query' );
		if ( ! is_array( $meta ) ) {
			return false;
		}
		foreach ( $meta as $clause ) {
			if ( is_array( $clause ) && isset( $clause['key'], $clause['compare'] ) && 'wc_last_active' === $clause['key'] && '<' === $clause['compare'] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Ergänzt die Löschabfrage: nur Konten mit gültiger, erreichter Freigabe.
	 *
	 * @param WP_User_Query $query Abfrage (vor dem Parsen).
	 */
	public static function on_pre_get_users( $query ) {
		if ( ! self::is_deletion_query( $query ) ) {
			return;
		}
		$id = spl_object_id( $query );
		try {
			$now = time();
			$mq  = array(
				'relation' => 'AND',
				$query->get( 'meta_query' ),
				array(
					'key'     => WCIUN_Markers::RELEASE,
					'value'   => (string) $now,
					'compare' => '<=',
					'type'    => 'NUMERIC',
				),
				array(
					'key'     => WCIUN_Markers::VALID_UNTIL,
					'value'   => (string) $now,
					'compare' => '>=',
					'type'    => 'NUMERIC',
				),
			);
			$query->set( 'meta_query', $mq );

			if ( WCIUN_Settings::get( 'exclude_privileged' ) ) {
				$exclude = array_unique( array_merge( (array) $query->get( 'role__not_in' ), self::privileged_roles() ) );
				$query->set( 'role__not_in', $exclude );
			}
			self::$queries[ $id ] = 'gate';
		} catch ( Throwable $e ) {
			// Im Zweifel nichts löschen.
			self::$queries[ $id ] = 'fail';
			$query->set( 'include', array( 0 ) );
		}

		if ( ! self::$probe ) {
			self::$deleting = true;
			update_option( 'wciun_gate_last_applied', time(), false );
		}
	}

	/**
	 * Zusätzliche SQL-Bedingung: Die Markierung muss sich auf die aktuelle letzte Aktivität beziehen.
	 * Das ist mit einer Meta-Abfrage nicht ausdrückbar (Vergleich zweier Meta-Werte), daher hier.
	 *
	 * @param WP_User_Query $query Abfrage (nach dem Parsen).
	 */
	public static function on_pre_user_query( $query ) {
		$id = spl_object_id( $query );
		if ( ! isset( self::$queries[ $id ] ) ) {
			return;
		}
		global $wpdb;
		if ( 'gate' === self::$queries[ $id ] ) {
			$query->query_where .= $wpdb->prepare(
				" AND EXISTS (SELECT 1 FROM {$wpdb->usermeta} wciun_la INNER JOIN {$wpdb->usermeta} wciun_ref ON wciun_ref.user_id = wciun_la.user_id WHERE wciun_la.user_id = {$wpdb->users}.ID AND wciun_la.meta_key = 'wc_last_active' AND wciun_ref.meta_key = %s AND wciun_la.meta_value = wciun_ref.meta_value)",
				WCIUN_Markers::ACTIVE_REF
			);
		} else {
			$query->query_where .= ' AND 1 = 0';
		}
		unset( self::$queries[ $id ] );
	}

	/**
	 * Wächter: Wird während des WooCommerce-Laufs ein Konto ohne gültige Freigabe gelöscht, bedeutet das,
	 * dass die Sperre nicht gegriffen hat (zum Beispiel nach einem WooCommerce-Update). Verhindern lässt sich
	 * die Löschung hier nicht mehr, aber das Team erfährt es sofort.
	 *
	 * @param int $user_id Benutzer.
	 */
	public static function on_delete_user( $user_id ) {
		if ( ! self::$deleting && ! self::$armed ) {
			return;
		}
		if ( WCIUN_Markers::is_released( $user_id, time() ) ) {
			WCIUN_Digest::bump( 'deleted_after_warning' );
			return;
		}
		$text = sprintf(
			/* translators: %d: Benutzer-ID */
			'WooCommerce hat das Konto #%d ohne gültige Freigabe gelöscht. Die Löschsperre hat bei diesem Konto nicht gegriffen.',
			(int) $user_id
		);
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->critical( $text, array( 'source' => 'wciun' ) );
		}
		WCIUN_Digest::alert( 'unprotected_deletion', $text );
	}

	/**
	 * Rollen, deren Konten nie gewarnt und (bei aktivierter Einstellung) nie gelöscht werden:
	 * alle Rollen mit Rechten wie dem Bearbeiten von Beiträgen oder dem Verwalten von WooCommerce.
	 *
	 * @return string[]
	 */
	public static function privileged_roles() {
		$caps = (array) apply_filters(
			'wciun_privileged_caps',
			array( 'manage_options', 'manage_woocommerce', 'edit_posts', 'edit_shop_orders', 'list_users', 'edit_users', 'promote_users' )
		);
		$out  = array();
		foreach ( wp_roles()->roles as $slug => $role ) {
			foreach ( $caps as $cap ) {
				if ( ! empty( $role['capabilities'][ $cap ] ) ) {
					$out[] = $slug;
					break;
				}
			}
		}
		return $out;
	}

	/**
	 * Hat dieses Konto Rechte, die es von Warnung und Löschung ausnehmen? Zusätzlich zur Rollenliste
	 * werden hier auch einzeln vergebene Rechte beachtet.
	 *
	 * @param WP_User $user Benutzer.
	 * @return bool
	 */
	public static function is_privileged( $user ) {
		if ( array_intersect( (array) $user->roles, self::privileged_roles() ) ) {
			return true;
		}
		$caps = (array) apply_filters( 'wciun_privileged_caps', array( 'manage_options', 'manage_woocommerce', 'edit_posts', 'edit_shop_orders', 'list_users', 'edit_users', 'promote_users' ) );
		foreach ( $caps as $cap ) {
			if ( user_can( $user, $cap ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Rollen, die WooCommerce für die Löschung berücksichtigt (inklusive aller Änderungen durch Filter).
	 * Ruft den Filter ohne Seiteneffekte auf, damit der eigene Aufruf nicht als WooCommerce-Lauf gilt.
	 *
	 * @return string[]
	 */
	public static function deletion_roles() {
		$before      = self::$probe;
		self::$probe = true;
		try {
			$roles = apply_filters( 'woocommerce_delete_inactive_account_roles', array( 'Customer', 'Subscriber' ) );
		} finally {
			self::$probe = $before;
		}
		return is_array( $roles ) ? array_map( 'strval', $roles ) : array();
	}

	/**
	 * Selbsttest: baut eine Abfrage wie WooCommerce und liefert das SQL, ohne Seiteneffekte.
	 *
	 * @return string SQL der gesperrten Abfrage.
	 */
	public static function probe_sql() {
		self::$probe = true;
		try {
			$roles = self::deletion_roles();
			$q     = new WP_User_Query(
				array(
					'fields'     => 'ID',
					'number'     => 1,
					'role__in'   => $roles,
					'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
						'relation' => 'AND',
						array(
							'key'     => 'wc_last_active',
							'value'   => '1',
							'compare' => '<',
							'type'    => 'NUMERIC',
						),
						array(
							'key'     => 'wc_last_active',
							'value'   => '0',
							'compare' => '>',
							'type'    => 'NUMERIC',
						),
					),
				)
			);
			$sql   = (string) $q->request;
		} finally {
			self::$probe = false;
		}
		return $sql;
	}

	/**
	 * Nur für Tests: Zustand dieser Anfrage zurücksetzen.
	 */
	public static function reset_request_state() {
		self::$armed    = false;
		self::$deleting = false;
		self::$queries  = array();
	}

	/**
	 * Wurde in dieser Anfrage eine Löschabfrage gesperrt?
	 *
	 * @return bool
	 */
	public static function deleting_window() {
		return self::$deleting;
	}
}
