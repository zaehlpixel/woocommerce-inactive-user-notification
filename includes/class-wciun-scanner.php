<?php
/**
 * Täglicher Lauf: Konten finden, die bald von WooCommerce gelöscht würden, und warnen.
 *
 * Die Verarbeitung läuft in Blöcken zu 50 Konten über Action Scheduler (je Block eine eigene Aktion),
 * damit große Nutzerbestände den Server nicht belasten und ein Abbruch nur einen Block betrifft.
 *
 * @package WCIUN
 */

defined( 'ABSPATH' ) || exit;

final class WCIUN_Scanner {

	const HOOK_DAILY     = 'wciun_daily_scan';
	const HOOK_BATCH     = 'wciun_scan_batch';
	const HOOK_RECONCILE = 'wciun_reconcile';
	const GROUP          = 'wciun';
	const BATCH_SIZE     = 50;
	const MAX_ATTEMPTS   = 3;
	const RUN_OPTION     = 'wciun_run';
	const LAST_OPTION    = 'wciun_last_scan';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( self::HOOK_DAILY, array( __CLASS__, 'start' ) );
		add_action( self::HOOK_BATCH, array( __CLASS__, 'batch' ), 10, 2 );
		add_action( self::HOOK_RECONCILE, array( __CLASS__, 'reconcile' ) );
		add_action( 'action_scheduler_init', array( __CLASS__, 'ensure_schedule' ) );
		add_action( 'update_option_woocommerce_delete_inactive_accounts', array( __CLASS__, 'on_retention_change' ) );
		add_action( 'add_option_woocommerce_delete_inactive_accounts', array( __CLASS__, 'on_retention_change' ) );
	}

	/**
	 * Plant den täglichen Lauf (04:30 Uhr nach Zeitzone der Website), falls er fehlt.
	 */
	public static function ensure_schedule() {
		if ( ! function_exists( 'as_has_scheduled_action' ) || as_has_scheduled_action( self::HOOK_DAILY, array(), self::GROUP ) ) {
			return;
		}
		$next = new DateTimeImmutable( 'tomorrow 04:30', wp_timezone() );
		as_schedule_recurring_action( $next->getTimestamp(), DAY_IN_SECONDS, self::HOOK_DAILY, array(), self::GROUP, true );
	}

	/**
	 * Entfernt die geplanten Aktionen (beim Deaktivieren).
	 */
	public static function unschedule() {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			foreach ( array( self::HOOK_DAILY, self::HOOK_BATCH, self::HOOK_RECONCILE ) as $hook ) {
				as_unschedule_all_actions( $hook );
			}
		}
		WCIUN_Markers::release_claim( 'scan' );
		delete_option( self::RUN_OPTION );
	}

	/**
	 * Die WooCommerce-Frist wurde geändert: Markierungen neu bewerten.
	 */
	public static function on_retention_change() {
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::HOOK_RECONCILE, array(), self::GROUP );
		}
	}

	/**
	 * Markierungen neu bewerten.
	 */
	public static function reconcile() {
		$retention = WCIUN_Calc::parse_retention( get_option( 'woocommerce_delete_inactive_accounts' ) );
		WCIUN_Markers::reconcile( $retention, time(), (int) WCIUN_Settings::get( 'lead_days' ) );
	}

	/**
	 * Die WooCommerce-E-Mail-Klasse für die Kunden-Warnung.
	 *
	 * @return WCIUN_Email_Customer_Warning|null
	 */
	private static function customer_email() {
		if ( ! function_exists( 'WC' ) || ! WC()->mailer() ) {
			return null;
		}
		$emails = WC()->mailer()->get_emails();
		return isset( $emails['WCIUN_Email_Customer_Warning'] ) ? $emails['WCIUN_Email_Customer_Warning'] : null;
	}

	/**
	 * Start des täglichen Laufs.
	 */
	public static function start() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}
		if ( 'claimed' !== WCIUN_Markers::claim( 'scan', 30 * MINUTE_IN_SECONDS, true ) ) {
			return; // Ein anderer Lauf ist aktiv.
		}
		$settings = WCIUN_Settings::all();
		$now      = time();
		$summary  = array(
			'started'    => $now,
			'mode'       => $settings['mode'],
			'sent'       => 0,
			'would_send' => 0,
			'test_sent'  => 0,
			'failed'     => 0,
			'no_email'   => 0,
			'skipped'    => 0,
			'manual'     => 0,
			'unclear'    => 0,
			'dropped'    => 0,
		);

		try {
			WCIUN_Selftest::run();
			$retention = WCIUN_Calc::parse_retention( get_option( 'woocommerce_delete_inactive_accounts' ) );
			if ( ! $retention ) {
				$summary['retention_disabled'] = true;
				self::finish( $summary );
				return;
			}
			$summary['dropped'] = WCIUN_Markers::reconcile( $retention, $now, (int) $settings['lead_days'] );

			if ( WCIUN_Settings::MODE_DRY_RUN !== $settings['mode'] ) {
				$mail = self::customer_email();
				if ( ! $mail || ! $mail->is_enabled() ) {
					$summary['email_disabled'] = true;
					self::finish( $summary );
					return;
				}
			}

			$run_id = wp_generate_uuid4();
			update_option(
				self::RUN_OPTION,
				array(
					'id'      => $run_id,
					'summary' => $summary,
				),
				false
			);
			as_enqueue_async_action( self::HOOK_BATCH, array( $run_id, 0 ), self::GROUP );
		} catch ( Throwable $e ) {
			if ( function_exists( 'wc_get_logger' ) ) {
				wc_get_logger()->error( 'Lauf abgebrochen: ' . $e->getMessage(), array( 'source' => 'wciun' ) );
			}
			WCIUN_Markers::release_claim( 'scan' );
			delete_option( self::RUN_OPTION );
		}
	}

	/**
	 * Ein Block von Konten.
	 *
	 * @param string $run_id Kennung des Laufs.
	 * @param int    $offset Versatz in der Kandidatenliste.
	 */
	public static function batch( $run_id, $offset ) {
		$run = get_option( self::RUN_OPTION );
		if ( ! is_array( $run ) || ( $run['id'] ?? '' ) !== $run_id ) {
			return;
		}
		WCIUN_Markers::touch_claim( 'scan' );

		$summary   = (array) $run['summary'];
		$settings  = WCIUN_Settings::all();
		$retention = WCIUN_Calc::parse_retention( get_option( 'woocommerce_delete_inactive_accounts' ) );
		if ( ! $retention ) {
			$summary['retention_disabled'] = true;
			self::finish( $summary );
			return;
		}

		$now   = time();
		$ids   = self::candidate_ids( $retention, $now, $settings, (int) $offset );
		$moved = 0;
		$stop  = false;

		foreach ( $ids as $user_id ) {
			try {
				$outcome = self::process_user( (int) $user_id, $retention, $now, $settings );
			} catch ( Throwable $e ) {
				$outcome = 'failed';
				if ( function_exists( 'wc_get_logger' ) ) {
					wc_get_logger()->error( sprintf( 'Konto #%d: %s', $user_id, $e->getMessage() ), array( 'source' => 'wciun' ) );
				}
			}
			if ( 'limit' === $outcome ) {
				$summary['limit_hit'] = true;
				$stop                 = true;
				break;
			}
			if ( isset( $summary[ $outcome ] ) ) {
				++$summary[ $outcome ];
			}
			if ( 'sent' === $outcome ) {
				++$moved; // Das Konto hat jetzt eine Markierung und fällt aus der Kandidatenliste.
			}
		}

		if ( $stop || count( $ids ) < self::BATCH_SIZE ) {
			self::finish( $summary );
			return;
		}
		update_option(
			self::RUN_OPTION,
			array(
				'id'      => $run_id,
				'summary' => $summary,
			),
			false
		);
		as_enqueue_async_action( self::HOOK_BATCH, array( $run_id, (int) $offset + count( $ids ) - $moved ), self::GROUP );
	}

	/**
	 * Abschluss: Ergebnis merken, Sperre freigeben, Team informieren.
	 *
	 * @param array $summary Ergebnis.
	 */
	private static function finish( array $summary ) {
		$summary['finished'] = time();
		update_option( self::LAST_OPTION, $summary, false );
		delete_option( self::RUN_OPTION );
		WCIUN_Markers::release_claim( 'scan' );
		WCIUN_Digest::send_after_scan( $summary );
	}

	/**
	 * Konten, die WooCommerce innerhalb der Vorlaufzeit löschen würde und die noch keine Freigabe-Markierung haben.
	 *
	 * @param array $retention Frist.
	 * @param int   $now       Zeitpunkt.
	 * @param array $settings  Einstellungen.
	 * @param int   $offset    Versatz.
	 * @return int[]
	 */
	public static function candidate_ids( array $retention, $now, array $settings, $offset ) {
		$cutoff_lead = WCIUN_Calc::cutoff( $retention, $now + (int) $settings['lead_days'] * DAY_IN_SECONDS );
		$query       = new WP_User_Query(
			array(
				'fields'         => 'ID',
				'number'         => self::BATCH_SIZE,
				'offset'         => (int) $offset,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'role__in'       => WCIUN_Gate::deletion_roles(),
				'role__not_in'   => $settings['exclude_privileged'] ? WCIUN_Gate::privileged_roles() : array(),
				'wciun_internal' => true,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					'relation' => 'AND',
					array(
						'key'     => 'wc_last_active',
						'value'   => (string) $cutoff_lead,
						'compare' => '<',
						'type'    => 'NUMERIC',
					),
					array(
						'key'     => 'wc_last_active',
						'value'   => '0',
						'compare' => '>',
						'type'    => 'NUMERIC',
					),
					array(
						'key'     => WCIUN_Markers::RELEASE,
						'compare' => 'NOT EXISTS',
					),
					array(
						'relation' => 'OR',
						array(
							'key'     => WCIUN_Markers::STATUS,
							'compare' => 'NOT EXISTS',
						),
						array(
							'key'     => WCIUN_Markers::STATUS,
							'value'   => array( WCIUN_Markers::STATUS_FAILED, WCIUN_Markers::STATUS_UNCLEAR ),
							'compare' => 'NOT IN',
						),
					),
				),
			)
		);
		return array_map( 'intval', (array) $query->get_results() );
	}

	/**
	 * Aktueller Stand eines Kontos, frisch aus der Datenbank.
	 *
	 * @param int   $user_id   Benutzer.
	 * @param array $retention Frist.
	 * @param int   $now       Zeitpunkt.
	 * @param array $settings  Einstellungen.
	 * @return array{ok:bool,reason?:string,user?:WP_User,last_active?:int,eligible_at?:int}
	 */
	public static function evaluate( $user_id, array $retention, $now, array $settings ) {
		clean_user_cache( $user_id );
		wp_cache_delete( $user_id, 'user_meta' );
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return array(
				'ok'     => false,
				'reason' => 'gone',
			);
		}
		$last_active = (int) get_user_meta( $user_id, 'wc_last_active', true );
		if ( $last_active <= 0 ) {
			return array(
				'ok'     => false,
				'reason' => 'no_activity',
			);
		}
		$wc_roles = array_map( 'strtolower', WCIUN_Gate::deletion_roles() );
		if ( ! array_intersect( array_map( 'strtolower', (array) $user->roles ), $wc_roles ) ) {
			return array(
				'ok'     => false,
				'reason' => 'role',
			);
		}
		if ( $settings['exclude_privileged'] && WCIUN_Gate::is_privileged( $user ) ) {
			return array(
				'ok'     => false,
				'reason' => 'privileged',
			);
		}
		$eligible = WCIUN_Calc::eligible_at( $retention, $last_active );
		if ( $eligible > $now + (int) $settings['lead_days'] * DAY_IN_SECONDS ) {
			return array(
				'ok'     => false,
				'reason' => 'not_due',
			);
		}
		return array(
			'ok'          => true,
			'user'        => $user,
			'last_active' => $last_active,
			'eligible_at' => $eligible,
		);
	}

	/**
	 * Verarbeitet ein Konto.
	 *
	 * @param int   $user_id   Benutzer.
	 * @param array $retention Frist.
	 * @param int   $now       Zeitpunkt.
	 * @param array $settings  Einstellungen.
	 * @return string Ergebnis: sent, would_send, test_sent, failed, no_email, skipped, manual, unclear oder limit.
	 */
	private static function process_user( $user_id, array $retention, $now, array $settings ) {
		$ev = self::evaluate( $user_id, $retention, $now, $settings );
		if ( empty( $ev['ok'] ) ) {
			return 'skipped';
		}
		$user   = $ev['user'];
		$status = (string) get_user_meta( $user_id, WCIUN_Markers::STATUS, true );
		if ( in_array( $status, array( WCIUN_Markers::STATUS_FAILED, WCIUN_Markers::STATUS_UNCLEAR ), true ) ) {
			return 'manual';
		}

		if ( ! is_email( $user->user_email ) ) {
			update_user_meta( $user_id, WCIUN_Markers::STATUS, WCIUN_Markers::STATUS_NO_EMAIL );
			return 'no_email';
		}
		if ( WCIUN_Markers::STATUS_NO_EMAIL === $status ) {
			delete_user_meta( $user_id, WCIUN_Markers::STATUS ); // Adresse wurde inzwischen korrigiert.
		}

		$same = get_users(
			array(
				'search'         => $user->user_email,
				'search_columns' => array( 'user_email' ),
				'fields'         => 'ID',
				'number'         => 2,
			)
		);
		if ( count( $same ) > 1 ) {
			update_user_meta( $user_id, WCIUN_Markers::DUPLICATE, '1' );
		} else {
			delete_user_meta( $user_id, WCIUN_Markers::DUPLICATE );
		}

		if ( (int) get_user_meta( $user_id, WCIUN_Markers::NEXT_TRY, true ) > $now ) {
			return 'skipped';
		}
		if ( WCIUN_Settings::MODE_DRY_RUN === $settings['mode'] ) {
			return 'would_send';
		}
		if ( WCIUN_Digest::sent_today() >= (int) $settings['daily_limit'] ) {
			return 'limit';
		}

		$mail = self::customer_email();
		if ( ! $mail ) {
			return 'skipped';
		}
		$lead_seconds = (int) $settings['lead_days'] * DAY_IN_SECONDS;

		if ( WCIUN_Settings::MODE_TEST === $settings['mode'] ) {
			if ( ! is_email( $settings['test_recipient'] ) || (string) get_user_meta( $user_id, WCIUN_Markers::TEST_REF, true ) === (string) $ev['last_active'] ) {
				return 'skipped';
			}
			$ok = $mail->trigger( $user_id, $ev['last_active'], max( $ev['eligible_at'], $now + $lead_seconds ), $settings['test_recipient'] );
			if ( $ok ) {
				update_user_meta( $user_id, WCIUN_Markers::TEST_REF, (string) $ev['last_active'] );
				WCIUN_Digest::add_sent();
				return 'test_sent';
			}
			return 'failed';
		}

		// Live: je Konto atomar beanspruchen, damit parallele Läufe nicht doppelt versenden.
		$claim = WCIUN_Markers::claim( 'user_' . $user_id, DAY_IN_SECONDS, false );
		if ( 'busy' === $claim ) {
			return 'skipped';
		}
		if ( 'stale' === $claim ) {
			// Vor mehr als 24 Stunden begonnen, nie abgeschlossen: Ergebnis unklar, nicht automatisch erneut senden.
			update_user_meta( $user_id, WCIUN_Markers::STATUS, WCIUN_Markers::STATUS_UNCLEAR );
			return 'unclear';
		}

		try {
			// Kurz vor dem Versand noch einmal frisch prüfen (Kunde kann in der Zwischenzeit aktiv geworden sein).
			$now2 = time();
			$ev2  = self::evaluate( $user_id, $retention, $now2, $settings );
			if ( empty( $ev2['ok'] ) || WCIUN_Markers::has_marker( $user_id ) ) {
				return 'skipped';
			}
			$ok = $mail->trigger( $user_id, $ev2['last_active'], max( $ev2['eligible_at'], $now2 + $lead_seconds ), '' );
			if ( $ok ) {
				WCIUN_Markers::set_released( $user_id, $ev2['last_active'], time(), (int) $settings['lead_days'] );
				WCIUN_Digest::add_sent();
				return 'sent';
			}
			$attempts = (int) get_user_meta( $user_id, WCIUN_Markers::ATTEMPTS, true ) + 1;
			update_user_meta( $user_id, WCIUN_Markers::ATTEMPTS, $attempts );
			update_user_meta( $user_id, WCIUN_Markers::NEXT_TRY, (string) ( $now2 + DAY_IN_SECONDS ) );
			if ( $attempts >= self::MAX_ATTEMPTS ) {
				update_user_meta( $user_id, WCIUN_Markers::STATUS, WCIUN_Markers::STATUS_FAILED );
			}
			return 'failed';
		} finally {
			WCIUN_Markers::release_claim( 'user_' . $user_id );
		}
	}
}
