<?php
/**
 * Admin-Seite: WooCommerce → Inaktive Konten.
 *
 * Alle Aktionen verlangen das Recht manage_woocommerce und eine gültige Nonce.
 *
 * @package WCIUN
 */

defined( 'ABSPATH' ) || exit;

final class WCIUN_Admin {

	const PAGE = 'wciun-accounts';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_wciun_save_settings', array( __CLASS__, 'save_settings' ) );
		add_action( 'admin_post_wciun_scan_now', array( __CLASS__, 'scan_now' ) );
		add_action( 'admin_post_wciun_confirm_wc', array( __CLASS__, 'confirm_wc' ) );
		add_action( 'admin_post_wciun_user_action', array( __CLASS__, 'user_action' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( WCIUN_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/**
	 * @param array $links Links.
	 * @return array
	 */
	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ) . '">' . esc_html__( 'Einstellungen', 'wciun' ) . '</a>' );
		return $links;
	}

	/**
	 * Menüpunkt.
	 */
	public static function menu() {
		add_submenu_page( 'woocommerce', __( 'Inaktive Konten', 'wciun' ), __( 'Inaktive Konten', 'wciun' ), 'manage_woocommerce', self::PAGE, array( __CLASS__, 'render' ) );
	}

	/**
	 * Rechte und Nonce prüfen.
	 *
	 * @param string $action Nonce-Aktion.
	 */
	private static function guard( $action ) {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'wciun' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( $action );
	}

	/**
	 * Zurück zur Seite mit einer Meldung.
	 *
	 * @param string $type success oder error.
	 * @param string $msg  Text.
	 * @param array  $args Weitere Query-Argumente.
	 */
	private static function back( $type, $msg, array $args = array() ) {
		$url = add_query_arg(
			array_merge(
				array(
					'page'        => self::PAGE,
					'wciun_type'  => $type,
					'wciun_msg'   => rawurlencode( $msg ),
				),
				$args
			),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Einstellungen speichern.
	 */
	public static function save_settings() {
		self::guard( 'wciun_save_settings' );
		$old = WCIUN_Settings::all();
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce wurde in guard() geprüft.
		$raw = isset( $_POST['wciun'] ) ? wp_unslash( (array) $_POST['wciun'] ) : array();
		$new = WCIUN_Settings::sanitize( $raw );

		// Der Wechsel in den Live-Modus ist eine bewusste Freigabe durch einen Menschen.
		if ( WCIUN_Settings::MODE_LIVE === $new['mode'] && WCIUN_Settings::MODE_LIVE !== $old['mode'] ) {
			if ( empty( $raw['live_confirm'] ) ) {
				$new['mode'] = $old['mode'];
				update_option( WCIUN_Settings::OPTION, $new, false );
				self::back( 'error', 'Der Live-Modus wurde nicht aktiviert: Bitte bestätigen Sie, dass Sie Trockenlauf-Bericht und E-Mail-Text geprüft haben. Die übrigen Einstellungen wurden gespeichert.' );
			}
			if ( ! WCIUN_Selftest::run()['ok'] ) {
				$new['mode'] = $old['mode'];
				update_option( WCIUN_Settings::OPTION, $new, false );
				self::back( 'error', 'Der Live-Modus wurde nicht aktiviert: Der Selbsttest der Löschsperre ist fehlgeschlagen (Details unten). Die übrigen Einstellungen wurden gespeichert.' );
			}
		}
		if ( WCIUN_Settings::MODE_TEST === $new['mode'] && '' === $new['test_recipient'] ) {
			$new['mode'] = $old['mode'];
			update_option( WCIUN_Settings::OPTION, $new, false );
			self::back( 'error', 'Der Testmodus braucht eine gültige Testadresse. Die übrigen Einstellungen wurden gespeichert.' );
		}

		update_option( WCIUN_Settings::OPTION, $new, false );
		self::back( 'success', 'Einstellungen gespeichert.' );
	}

	/**
	 * Lauf sofort anstoßen (asynchron über Action Scheduler).
	 */
	public static function scan_now() {
		self::guard( 'wciun_scan_now' );
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( WCIUN_Scanner::HOOK_DAILY, array(), WCIUN_Scanner::GROUP );
			self::back( 'success', 'Der Lauf wurde angestoßen und startet in wenigen Minuten (über Action Scheduler).' );
		}
		self::back( 'error', 'Action Scheduler ist nicht verfügbar.' );
	}

	/**
	 * Installierte WooCommerce-Version nach eigener Prüfung bestätigen.
	 */
	public static function confirm_wc() {
		self::guard( 'wciun_confirm_wc' );
		WCIUN_Selftest::confirm_current_version();
		WCIUN_Selftest::run();
		self::back( 'success', 'WooCommerce ' . ( defined( 'WC_VERSION' ) ? WC_VERSION : '' ) . ' wurde als geprüft vermerkt.' );
	}

	/**
	 * Aktion für ein einzelnes Konto: Löschung freigeben oder erneut senden.
	 */
	public static function user_action() {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended -- Nonce folgt unmittelbar in guard().
		$user_id = isset( $_REQUEST['user_id'] ) ? absint( $_REQUEST['user_id'] ) : 0;
        // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended
		$do      = isset( $_REQUEST['do'] ) ? sanitize_key( wp_unslash( $_REQUEST['do'] ) ) : '';
		self::guard( 'wciun_user_' . $user_id . '_' . $do );

		$user = $user_id ? get_userdata( $user_id ) : false;
		if ( ! $user ) {
			self::back( 'error', 'Konto nicht gefunden.' );
		}
		if ( 'release' === $do ) {
			$last = (int) get_user_meta( $user_id, 'wc_last_active', true );
			if ( $last <= 0 ) {
				self::back( 'error', 'Das Konto hat keine erfasste letzte Aktivität. WooCommerce löscht es ohnehin nie.' );
			}
			WCIUN_Markers::set_released( $user_id, $last, time(), 0, WCIUN_Markers::STATUS_MANUAL );
			self::back( 'success', sprintf( 'Löschung für Konto #%d freigegeben. WooCommerce darf es beim nächsten Lauf löschen, falls die Frist erreicht ist.', $user_id ) );
		}
		if ( 'resend' === $do ) {
			foreach ( array( WCIUN_Markers::STATUS, WCIUN_Markers::ATTEMPTS, WCIUN_Markers::NEXT_TRY ) as $key ) {
				delete_user_meta( $user_id, $key );
			}
			WCIUN_Markers::release_claim( 'user_' . $user_id );
			self::back( 'success', sprintf( 'Konto #%d wird beim nächsten Lauf erneut geprüft und gegebenenfalls gewarnt.', $user_id ) );
		}
		self::back( 'error', 'Unbekannte Aktion.' );
	}

	/**
	 * Link für eine Konto-Aktion.
	 *
	 * @param int    $user_id Benutzer.
	 * @param string $do      release oder resend.
	 * @param string $label   Beschriftung.
	 * @return string
	 */
	private static function user_link( $user_id, $do, $label ) {
		$url = wp_nonce_url(
			add_query_arg(
				array(
					'action'  => 'wciun_user_action',
					'user_id' => $user_id,
					'do'      => $do,
				),
				admin_url( 'admin-post.php' )
			),
			'wciun_user_' . $user_id . '_' . $do
		);
		return '<a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
	}

	/**
	 * Seite zeichnen.
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$s         = WCIUN_Settings::all();
		$retention = WCIUN_Calc::parse_retention( get_option( 'woocommerce_delete_inactive_accounts' ) );
		$selftest  = WCIUN_Selftest::last();
		$last_scan = get_option( WCIUN_Scanner::LAST_OPTION, array() );
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- nur Anzeige einer Meldung.
		$msg_type = isset( $_GET['wciun_type'] ) && 'error' === $_GET['wciun_type'] ? 'error' : 'success';
		$msg      = isset( $_GET['wciun_msg'] ) ? sanitize_text_field( rawurldecode( wp_unslash( $_GET['wciun_msg'] ) ) ) : '';
		$view     = isset( $_GET['view'] ) && 'warned' === $_GET['view'] ? 'warned' : 'attention';
		// phpcs:enable

		echo '<div class="wrap"><h1>' . esc_html__( 'Inaktive Kundenkonten', 'wciun' ) . '</h1>';
		if ( $msg ) {
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $msg_type ), esc_html( $msg ) );
		}

		// --- Stand der Dinge.
		echo '<h2>' . esc_html__( 'Stand', 'wciun' ) . '</h2><table class="widefat striped" style="max-width:900px"><tbody>';
		$retention_text = $retention ? sprintf( 'WooCommerce löscht Konten nach %d %s ohne Anmeldung oder Bestellung.', $retention['number'], self::unit_label( $retention['unit'], $retention['number'] ) ) : 'In WooCommerce ist keine Frist eingestellt. WooCommerce löscht derzeit keine Konten, das Plugin sendet nichts.';
		self::row( 'WooCommerce-Frist', $retention_text );
		$modes = array(
			WCIUN_Settings::MODE_DRY_RUN => 'Trockenlauf: keine E-Mails, keine Freigaben. Die Löschsperre ist aktiv, WooCommerce löscht deshalb derzeit kein Konto.',
			WCIUN_Settings::MODE_TEST    => 'Testmodus: Kunden-Mails gehen nur an die Testadresse. Es werden keine Freigaben erteilt, WooCommerce löscht deshalb derzeit kein Konto.',
			WCIUN_Settings::MODE_LIVE    => 'Live: Kunden werden gewarnt. WooCommerce löscht ein Konto erst, wenn seine Warnung mindestens ' . (int) $s['lead_days'] . ' Tage alt ist.',
		);
		self::row( 'Modus', $modes[ $s['mode'] ] );
		if ( $last_scan ) {
			self::row(
				'Letzter Lauf',
				sprintf(
					'%s. Verschickt: %d, im Trockenlauf gefunden: %d, Testmails: %d, fehlgeschlagen: %d, ohne gültige Adresse: %d.%s',
					wp_date( 'd.m.Y H:i', (int) ( $last_scan['finished'] ?? $last_scan['started'] ?? 0 ) ),
					(int) ( $last_scan['sent'] ?? 0 ),
					(int) ( $last_scan['would_send'] ?? 0 ),
					(int) ( $last_scan['test_sent'] ?? 0 ),
					(int) ( $last_scan['failed'] ?? 0 ),
					(int) ( $last_scan['no_email'] ?? 0 ),
					! empty( $last_scan['limit_hit'] ) ? ' Das Tageslimit wurde erreicht.' : ''
				)
			);
		} else {
			self::row( 'Letzter Lauf', 'Noch kein Lauf.' );
		}
		self::row( 'E-Mails heute verschickt', (string) WCIUN_Digest::sent_today() . ' von höchstens ' . (int) $s['daily_limit'] );
		echo '</tbody></table>';

		// --- Selbsttest.
		echo '<h2>' . esc_html__( 'Selbsttest der Löschsperre', 'wciun' ) . '</h2>';
		if ( empty( $selftest ) ) {
			echo '<p>Noch nicht ausgeführt. Er läuft bei jedem täglichen Lauf.</p>';
		} else {
			echo '<table class="widefat striped" style="max-width:900px"><tbody>';
			foreach ( (array) $selftest['checks'] as $c ) {
				$icon = ! empty( $c['ok'] ) ? '<span style="color:#1a7f37">&#10003;</span>' : ( ! empty( $c['required'] ) ? '<span style="color:#b32d2e">&#10007;</span>' : '<span style="color:#996800">!</span>' );
				echo '<tr><td style="width:2em">' . wp_kses_post( $icon ) . '</td><td><strong>' . esc_html( $c['label'] ) . '</strong><br>' . esc_html( $c['detail'] ) . '</td></tr>';
			}
			echo '</tbody></table><p>Stand: ' . esc_html( wp_date( 'd.m.Y H:i', (int) $selftest['time'] ) ) . '</p>';
		}
		$wc_tested = defined( 'WC_VERSION' ) && in_array( WC_VERSION, WCIUN_Selftest::tested_versions(), true );
		if ( defined( 'WC_VERSION' ) && ! $wc_tested ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-bottom:1em"><input type="hidden" name="action" value="wciun_confirm_wc">';
			wp_nonce_field( 'wciun_confirm_wc' );
			submit_button( sprintf( 'WooCommerce %s nach eigener Prüfung als getestet vermerken', WC_VERSION ), 'secondary', 'submit', false );
			echo '<p class="description">Nur nach erfolgreichem Integrationstest (tests/integration) mit genau dieser Version.</p></form>';
		}

		// --- Einstellungen.
		echo '<h2>' . esc_html__( 'Einstellungen', 'wciun' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="wciun_save_settings">';
		wp_nonce_field( 'wciun_save_settings' );
		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row">Modus</th><td>';
		foreach ( array(
			WCIUN_Settings::MODE_DRY_RUN => 'Trockenlauf (empfohlen zum Start): keine E-Mails, nur Bericht',
			WCIUN_Settings::MODE_TEST    => 'Testmodus: Kunden-Mails gehen nur an die Testadresse',
			WCIUN_Settings::MODE_LIVE    => 'Live: Kunden werden angeschrieben',
		) as $value => $label ) {
			printf( '<label style="display:block;margin-bottom:4px"><input type="radio" name="wciun[mode]" value="%s" %s> %s</label>', esc_attr( $value ), checked( $s['mode'], $value, false ), esc_html( $label ) );
		}
		echo '<label style="display:block;margin-top:8px"><input type="checkbox" name="wciun[live_confirm]" value="1"> Beim Wechsel zu „Live“: Ich habe den Trockenlauf-Bericht und den E-Mail-Text geprüft und gebe den Versand frei.</label>';
		echo '</td></tr>';

		self::number_field( 'lead_days', 'Vorlaufzeit (Tage)', $s['lead_days'], 14, 90, 'Wie viele Tage vor der möglichen Löschung gewarnt wird. 14 bis 90, Standard 28.' );
		self::number_field( 'daily_limit', 'Höchstens E-Mails pro Tag', $s['daily_limit'], 1, 1000, 'Begrenzt vor allem die erste Welle. Übrige Konten bleiben gesperrt und folgen an den nächsten Tagen.' );
		self::text_field( 'test_recipient', 'Testadresse', $s['test_recipient'], 'Im Testmodus gehen alle Kunden-Mails an diese Adresse.' );
		self::text_field( 'team_recipients', 'Empfänger der Team-Mail', $s['team_recipients'], 'Eine oder mehrere Adressen, durch Komma getrennt. Leer: Administrator-Adresse der Website (' . get_option( 'admin_email' ) . '). Aktuell: ' . WCIUN_Settings::team_recipients() );
		echo '<tr><th scope="row">Team-Übersicht</th><td><label><input type="checkbox" name="wciun[team_notify]" value="1" ' . checked( $s['team_notify'], 1, false ) . '> Tägliche Sammelmail mit Anzahlen (nur an Tagen mit Neuem oder bei Problemen). Alarme werden immer gesendet, solange eine Adresse gesetzt ist.</label></td></tr>';
		echo '<tr><th scope="row">Team-Konten</th><td><label><input type="checkbox" name="wciun[exclude_privileged]" value="1" ' . checked( $s['exclude_privileged'], 1, false ) . '> Konten mit Team-Rechten (zum Beispiel Administrator, Shop-Manager, Redakteur) nie warnen und nie löschen lassen</label></td></tr>';
		echo '</tbody></table>';
		submit_button( 'Einstellungen speichern' );
		echo '</form>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="wciun_scan_now">';
		wp_nonce_field( 'wciun_scan_now' );
		submit_button( 'Lauf jetzt anstoßen', 'secondary', 'submit', false );
		echo '<p class="description">Führt denselben Lauf aus wie der tägliche Lauf, im oben gewählten Modus. Im Live-Modus werden dabei Kunden angeschrieben.</p></form>';

		// --- Kontenliste.
		self::render_list( $view, $retention );
		echo '</div>';
	}

	/**
	 * @param string $label Beschriftung.
	 * @param string $value Wert.
	 */
	private static function row( $label, $value ) {
		echo '<tr><th style="width:220px">' . esc_html( $label ) . '</th><td>' . esc_html( $value ) . '</td></tr>';
	}

	/**
	 * @param string $key   Schlüssel.
	 * @param string $label Beschriftung.
	 * @param int    $value Wert.
	 * @param int    $min   Minimum.
	 * @param int    $max   Maximum.
	 * @param string $desc  Hinweis.
	 */
	private static function number_field( $key, $label, $value, $min, $max, $desc ) {
		printf(
			'<tr><th scope="row"><label for="wciun_%1$s">%2$s</label></th><td><input type="number" id="wciun_%1$s" name="wciun[%1$s]" value="%3$d" min="%4$d" max="%5$d" class="small-text"><p class="description">%6$s</p></td></tr>',
			esc_attr( $key ),
			esc_html( $label ),
			(int) $value,
			(int) $min,
			(int) $max,
			esc_html( $desc )
		);
	}

	/**
	 * @param string $key   Schlüssel.
	 * @param string $label Beschriftung.
	 * @param string $value Wert.
	 * @param string $desc  Hinweis.
	 */
	private static function text_field( $key, $label, $value, $desc ) {
		printf(
			'<tr><th scope="row"><label for="wciun_%1$s">%2$s</label></th><td><input type="text" id="wciun_%1$s" name="wciun[%1$s]" value="%3$s" class="regular-text"><p class="description">%4$s</p></td></tr>',
			esc_attr( $key ),
			esc_html( $label ),
			esc_attr( $value ),
			esc_html( $desc )
		);
	}

	/**
	 * @param string $unit   Einheit.
	 * @param int    $number Anzahl.
	 * @return string
	 */
	private static function unit_label( $unit, $number ) {
		$one = array(
			'days'   => 'Tag',
			'weeks'  => 'Woche',
			'months' => 'Monat',
			'years'  => 'Jahr',
		);
		$many = array(
			'days'   => 'Tagen',
			'weeks'  => 'Wochen',
			'months' => 'Monaten',
			'years'  => 'Jahren',
		);
		return 1 === (int) $number ? $one[ $unit ] : $many[ $unit ];
	}

	/**
	 * Liste der Konten mit Hinweisen oder Warnstatus (höchstens 200).
	 *
	 * @param string     $view      attention oder warned.
	 * @param array|null $retention Frist.
	 */
	private static function render_list( $view, $retention ) {
		$labels = array(
			WCIUN_Markers::STATUS_WARNED   => 'Gewarnt',
			WCIUN_Markers::STATUS_MANUAL   => 'Manuell freigegeben',
			WCIUN_Markers::STATUS_FAILED   => 'Versand fehlgeschlagen',
			WCIUN_Markers::STATUS_NO_EMAIL => 'Keine gültige E-Mail-Adresse',
			WCIUN_Markers::STATUS_UNCLEAR  => 'Versandergebnis unklar',
		);
		if ( 'warned' === $view ) {
			$meta = array(
				array(
					'key'     => WCIUN_Markers::STATUS,
					'value'   => array( WCIUN_Markers::STATUS_WARNED, WCIUN_Markers::STATUS_MANUAL ),
					'compare' => 'IN',
				),
			);
		} else {
			$meta = array(
				'relation' => 'OR',
				array(
					'key'     => WCIUN_Markers::STATUS,
					'value'   => array( WCIUN_Markers::STATUS_NO_EMAIL, WCIUN_Markers::STATUS_FAILED, WCIUN_Markers::STATUS_UNCLEAR ),
					'compare' => 'IN',
				),
				array(
					'key'     => WCIUN_Markers::DUPLICATE,
					'value'   => '1',
					'compare' => '=',
				),
			);
		}
		$query = new WP_User_Query(
			array(
				'number'         => 200,
				'orderby'        => 'ID',
				'wciun_internal' => true,
				'meta_query'     => $meta, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			)
		);
		$users = $query->get_results();

		$base = admin_url( 'admin.php?page=' . self::PAGE );
		echo '<h2>' . esc_html__( 'Konten', 'wciun' ) . '</h2><p>';
		echo '<a href="' . esc_url( $base ) . '"' . ( 'attention' === $view ? ' style="font-weight:600"' : '' ) . '>Braucht Aufmerksamkeit</a> | ';
		echo '<a href="' . esc_url( add_query_arg( 'view', 'warned', $base ) ) . '"' . ( 'warned' === $view ? ' style="font-weight:600"' : '' ) . '>Gewarnt</a></p>';

		if ( ! $users ) {
			echo '<p>Keine Konten in dieser Ansicht.</p>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr><th>Konto</th><th>E-Mail</th><th>Letzte Aktivität</th><th>Frühester Stichtag laut WooCommerce</th><th>Status</th><th>Freigabe ab</th><th>Hinweis</th><th>Aktionen</th></tr></thead><tbody>';
		foreach ( $users as $user ) {
			$last     = (int) get_user_meta( $user->ID, 'wc_last_active', true );
			$status   = (string) get_user_meta( $user->ID, WCIUN_Markers::STATUS, true );
			$release  = (int) get_user_meta( $user->ID, WCIUN_Markers::RELEASE, true );
			$eligible = $retention && $last > 0 ? WCIUN_Calc::eligible_at( $retention, $last ) : 0;
			$notes    = array();
			if ( get_user_meta( $user->ID, WCIUN_Markers::DUPLICATE, true ) ) {
				$notes[] = 'Adresse wird von mehreren Konten genutzt';
			}
			if ( (int) get_user_meta( $user->ID, WCIUN_Markers::ATTEMPTS, true ) ) {
				$notes[] = (int) get_user_meta( $user->ID, WCIUN_Markers::ATTEMPTS, true ) . ' Fehlversuch(e)';
			}
			$actions = array();
			if ( in_array( $status, array( WCIUN_Markers::STATUS_NO_EMAIL, WCIUN_Markers::STATUS_FAILED, WCIUN_Markers::STATUS_UNCLEAR ), true ) ) {
				$actions[] = self::user_link( $user->ID, 'resend', 'Erneut prüfen und senden' );
				$actions[] = self::user_link( $user->ID, 'release', 'Löschung freigeben' );
			}
			printf(
				'<tr><td><a href="%1$s">#%2$d %3$s</a></td><td>%4$s</td><td>%5$s</td><td>%6$s</td><td>%7$s</td><td>%8$s</td><td>%9$s</td><td>%10$s</td></tr>',
				esc_url( get_edit_user_link( $user->ID ) ),
				(int) $user->ID,
				esc_html( $user->user_login ),
				esc_html( $user->user_email ),
				esc_html( $last ? wp_date( 'd.m.Y', $last ) : '–' ),
				esc_html( $eligible ? wp_date( 'd.m.Y', $eligible ) : '–' ),
				esc_html( $labels[ $status ] ?? '–' ),
				esc_html( $release ? wp_date( 'd.m.Y', $release ) : '–' ),
				esc_html( implode( '; ', $notes ) ),
				wp_kses_post( implode( ' | ', $actions ) )
			);
		}
		echo '</tbody></table>';
		if ( count( $users ) >= 200 ) {
			echo '<p class="description">Es werden höchstens 200 Konten angezeigt.</p>';
		}
	}
}
