<?php
/**
 * Integrationstest gegen echten WordPress- und WooCommerce-Code.
 *
 * Aufruf (in einer Wegwerf-Installation, NIEMALS im Live-Shop):
 *   wp eval-file wp-content/plugins/woocommerce-inactive-user-notification/tests/integration/run.php
 *
 * Der Test legt eigene Benutzer an und löscht echte Konten über WC_Privacy::delete_inactive_accounts().
 * Er bricht ab, wenn die Datenbank mehr als 5 Kundenkonten enthält.
 *
 * @package WCIUN
 */

if ( ! defined( 'ABSPATH' ) || ! class_exists( 'WC_Privacy' ) ) {
	fwrite( STDERR, "Bitte mit 'wp eval-file' in einer Installation mit WooCommerce ausführen.\n" );
	exit( 2 );
}
$existing = count( get_users( array( 'role__in' => array( 'customer', 'subscriber' ), 'fields' => 'ID' ) ) );
if ( $existing > 5 ) {
	fwrite( STDERR, "Abbruch: $existing Kundenkonten vorhanden. Dieser Test gehört nur in eine Wegwerf-Installation.\n" );
	exit( 2 );
}

$GLOBALS['t_fail'] = 0;
$GLOBALS['t_n']    = 0;
function t_ok( $cond, $label ) {
	++$GLOBALS['t_n'];
	if ( ! $cond ) {
		++$GLOBALS['t_fail'];
		echo "  FAIL: $label\n";
	}
}
function t_section( $name ) {
	echo "\n== $name\n";
}

// --- Hilfsfunktionen ---------------------------------------------------------------------------------------

$GLOBALS['t_mails']   = array();
$GLOBALS['t_mail_ok'] = true;
add_filter(
	'pre_wp_mail',
	static function ( $null, $atts ) {
		$GLOBALS['t_mails'][] = $atts;
		return $GLOBALS['t_mail_ok'];
	},
	10,
	2
);
function t_mails_to( $addr ) {
	$out = array();
	foreach ( $GLOBALS['t_mails'] as $m ) {
		$to = is_array( $m['to'] ) ? implode( ',', $m['to'] ) : $m['to'];
		if ( false !== stripos( $to, $addr ) ) {
			$out[] = $m;
		}
	}
	return $out;
}

/** Zeitpunkt vor $m Monaten. Dezimalstellen werden als je 30 Tage zusätzlich gerechnet (PHP kennt keine halben Monate). */
function t_months_ago( $m ) {
	$whole = (int) floor( $m );
	$frac  = $m - $whole;
	return strtotime( "-$whole months", time() ) - (int) round( $frac * 30 ) * DAY_IN_SECONDS;
}

function t_user( $login, $months_inactive, $role = 'customer', $email = null ) {
	$id = wp_insert_user(
		array(
			'user_login' => $login,
			'user_email' => $email ?: "$login@example.test",
			'user_pass'  => wp_generate_password(),
			'role'       => $role,
		)
	);
	if ( is_wp_error( $id ) ) {
		throw new RuntimeException( $id->get_error_message() );
	}
	update_user_meta( $id, 'wc_last_active', (string) t_months_ago( $months_inactive ) );
	return $id;
}

function t_settings( array $over = array() ) {
	update_option( WCIUN_Settings::OPTION, WCIUN_Settings::sanitize( array_merge( WCIUN_Settings::defaults(), $over ) ), false );
}

function t_retention( $number, $unit = 'months' ) {
	update_option( 'woocommerce_delete_inactive_accounts', $number ? array( 'number' => $number, 'unit' => $unit ) : array( 'number' => '', 'unit' => 'months' ) );
}

function t_reset_world() {
	global $wpdb;
	foreach ( get_users( array( 'fields' => 'ID', 'exclude' => array( 1 ) ) ) as $uid ) {
		wp_delete_user( $uid );
	}
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'wciun\\_claim\\_%'" );
	delete_option( WCIUN_Digest::OPTION );
	delete_option( WCIUN_Digest::COUNTER );
	delete_option( WCIUN_Scanner::RUN_OPTION );
	delete_option( WCIUN_Scanner::LAST_OPTION );
	$GLOBALS['t_mails']   = array();
	$GLOBALS['t_mail_ok'] = true;
	foreach ( array( 'wciun_alert_' . md5( 'unprotected_deletion' ), 'wciun_alert_' . md5( 'gate_not_applied' ) ) as $tr ) {
		delete_transient( $tr );
	}
	WCIUN_Gate::reset_request_state();
}

/** Führt einen Lauf des Plugins synchron aus (die Blöcke laufen sonst über Action Scheduler). */
function t_scan() {
	WCIUN_Scanner::start();
	$guard = 0;
	while ( ( $run = get_option( WCIUN_Scanner::RUN_OPTION ) ) && $guard++ < 50 ) {
		WCIUN_Scanner::batch( $run['id'], 0 );
		// Der Plugin-Code plant den nächsten Block selbst; für den Test genügt der Versatz 0 mit Neuabfrage,
		// solange weniger als ein Block verarbeitet wird.
		break;
	}
	return get_option( WCIUN_Scanner::LAST_OPTION );
}

function t_exists( $id ) {
	clean_user_cache( $id );
	return (bool) get_userdata( $id );
}

/** Echte WooCommerce-Löschung. */
function t_wc_delete() {
	return WC_Privacy::delete_inactive_accounts( 20 );
}

/** Markierung setzen, als wäre die Warnung vor $days_ago Tagen verschickt worden. */
function t_marker( $uid, $days_ago, $lead = 28 ) {
	$now = time() - $days_ago * DAY_IN_SECONDS;
	WCIUN_Markers::set_released( $uid, (int) get_user_meta( $uid, 'wc_last_active', true ), $now, $lead );
}

WCIUN_Scanner::ensure_schedule();
t_reset_world();

// ==============================================================================================================
t_section( '1. Beweis, dass der Test aussagekräftig ist: ohne Sperre löscht WooCommerce ungewarnte Konten' );
t_retention( 36 );
t_settings();
remove_action( 'pre_get_users', array( 'WCIUN_Gate', 'on_pre_get_users' ), 10 );
$a = t_user( 'base_a', 40 );
$count = t_wc_delete();
t_ok( ! t_exists( $a ), 'ohne Sperre wird das ungewarnte Konto gelöscht (Test hat Zähne)' );
t_ok( 1 === $count, 'WooCommerce meldet eine Löschung' );
add_action( 'pre_get_users', array( 'WCIUN_Gate', 'on_pre_get_users' ), 10, 1 );
t_reset_world();

// ==============================================================================================================
t_section( '2. Löschsperre gegen den echten WooCommerce-Löschlauf' );
t_retention( 36 );
t_settings( array( 'mode' => 'live' ) );
$no_marker   = t_user( 'g_nomarker', 40 );
$released    = t_user( 'g_released', 40 );
$future      = t_user( 'g_future', 40 );
$mismatch    = t_user( 'g_mismatch', 40 );
$expired     = t_user( 'g_expired', 40 );
$recent      = t_user( 'g_recent', 2 );
$subscriber  = t_user( 'g_subscriber', 40, 'subscriber' );
$priv        = t_user( 'g_privileged', 40, 'shop_manager' );
$multi       = t_user( 'g_multirole', 40, 'customer' );
( new WP_User( $multi ) )->add_role( 'shop_manager' );

t_marker( $released, 29 );          // Warnung vor 29 Tagen: Freigabe erreicht.
t_marker( $subscriber, 40 );
t_marker( $future, 5 );             // Warnung vor 5 Tagen: Freigabe erst in 23 Tagen.
t_marker( $mismatch, 29 );
update_user_meta( $mismatch, WCIUN_Markers::ACTIVE_REF, '12345' ); // bezieht sich auf eine andere Aktivität
t_marker( $expired, 29 );
update_user_meta( $expired, WCIUN_Markers::VALID_UNTIL, (string) ( time() - 10 ) ); // Gültigkeit abgelaufen
t_marker( $priv, 29 );
t_marker( $multi, 29 );

$count = t_wc_delete();
t_ok( t_exists( $no_marker ), 'ohne Markierung bleibt das Konto' );
t_ok( ! t_exists( $released ), 'mit erreichter, gültiger Freigabe löscht WooCommerce das Konto' );
t_ok( ! t_exists( $subscriber ), 'auch die Rolle Abonnent wird bei gültiger Freigabe gelöscht (wie WooCommerce)' );
t_ok( t_exists( $future ), 'Freigabe liegt in der Zukunft: Konto bleibt' );
t_ok( t_exists( $mismatch ), 'Markierung bezieht sich auf andere Aktivität: Konto bleibt' );
t_ok( t_exists( $expired ), 'Markierung abgelaufen: Konto bleibt' );
t_ok( t_exists( $recent ), 'kürzlich aktives Konto bleibt' );
t_ok( t_exists( $priv ), 'Shop-Manager bleibt, auch mit Markierung' );
t_ok( t_exists( $multi ), 'Konto mit Kunde + Shop-Manager bleibt (WooCommerce prüft Rollen per ODER)' );
t_ok( 2 === $count, "genau zwei Löschungen (erhalten: $count)" );
t_ok( get_option( 'wciun_gate_last_applied' ) > time() - 60, 'Sperre hat sich gemeldet' );

// Bestellungen werden zu Gastbestellungen, nicht gelöscht (belegtes WooCommerce-Verhalten, nur Kontrolle).
t_reset_world();

// ==============================================================================================================
t_section( '3. Sperre ohne Ausnahme für Teamkonten, wenn die Einstellung aus ist' );
t_retention( 36 );
t_settings( array( 'mode' => 'live', 'exclude_privileged' => 0 ) );
$multi = t_user( 'x_multirole', 40, 'customer' );
( new WP_User( $multi ) )->add_role( 'shop_manager' );
t_marker( $multi, 29 );
t_wc_delete();
t_ok( ! t_exists( $multi ), 'ohne Teamschutz entscheidet nur die Freigabe' );
t_reset_world();

// ==============================================================================================================
t_section( '4. Täglicher Lauf im Live-Modus' );
t_retention( 36 );
t_settings( array( 'mode' => 'live' ) );
$due_soon = t_user( 'u_due_soon', 35.5 ); // WooCommerce würde in etwa zwei Wochen löschen
$overdue  = t_user( 'u_overdue', 40 );    // bereits löschfällig
$far      = t_user( 'u_far', 20 );        // weit entfernt
$never    = t_user( 'u_never', 40 );
delete_user_meta( $never, 'wc_last_active' ); // nie angemeldet: WooCommerce löscht nie
$admin    = t_user( 'u_admin', 40, 'administrator' );
$multi    = t_user( 'u_multi', 40, 'customer' );
( new WP_User( $multi ) )->add_role( 'shop_manager' );
$noemail  = t_user( 'u_noemail', 40 );
global $wpdb;
$wpdb->update( $wpdb->users, array( 'user_email' => 'kein-email' ), array( 'ID' => $noemail ) );
clean_user_cache( $noemail );

$sum = t_scan();
t_ok( 2 === (int) $sum['sent'], "zwei Warnungen verschickt (erhalten: {$sum['sent']})" );
t_ok( 1 === count( t_mails_to( 'u_due_soon@example.test' ) ), 'Konto mit weniger als 28 Tagen bekommt eine Warnung' );
t_ok( 1 === count( t_mails_to( 'u_overdue@example.test' ) ), 'bereits löschfälliges Konto bekommt eine Warnung' );
t_ok( 0 === count( t_mails_to( 'u_far@example.test' ) ), 'weit entferntes Konto bekommt keine Warnung' );
t_ok( 0 === count( t_mails_to( 'u_never@example.test' ) ), 'Konto ohne Aktivitätszeitstempel bekommt keine Warnung' );
t_ok( 0 === count( t_mails_to( 'u_admin@example.test' ) ), 'Administrator wird nie angeschrieben' );
t_ok( 0 === count( t_mails_to( 'u_multi@example.test' ) ), 'Konto mit Team-Rolle wird nie angeschrieben' );
t_ok( WCIUN_Markers::STATUS_NO_EMAIL === get_user_meta( $noemail, WCIUN_Markers::STATUS, true ), 'ungültige Adresse: Status "ohne E-Mail"' );
t_ok( (int) get_user_meta( $overdue, WCIUN_Markers::RELEASE, true ) >= time() + 27 * DAY_IN_SECONDS, 'Freigabe liegt mindestens 28 Tage in der Zukunft' );
t_ok( WCIUN_Markers::STATUS_WARNED === get_user_meta( $overdue, WCIUN_Markers::STATUS, true ), 'Status "gewarnt"' );

$count = t_wc_delete();
t_ok( t_exists( $overdue ) && t_exists( $due_soon ), 'gewarnte Konten überleben den WooCommerce-Lauf am selben Tag' );
t_ok( t_exists( $noemail ), 'Konto ohne gültige Adresse bleibt gesperrt' );
t_ok( 0 === $count, "WooCommerce löscht heute nichts (erhalten: $count)" );

$mails_before = count( $GLOBALS['t_mails'] );
$sum2 = t_scan();
t_ok( 0 === (int) $sum2['sent'], 'zweiter Lauf verschickt nichts erneut' );
t_ok( 0 === count( array_filter( array_slice( $GLOBALS['t_mails'], $mails_before ), static function ( $m ) { return false !== stripos( is_array( $m['to'] ) ? implode( ',', $m['to'] ) : $m['to'], '@example.test' ); } ) ), 'keine zweite Kundenmail' );

// Zeit vergeht: Freigabe erreicht.
update_user_meta( $overdue, WCIUN_Markers::RELEASE, (string) ( time() - 1 ) );
update_user_meta( $overdue, WCIUN_Markers::VALID_UNTIL, (string) ( time() + 80 * DAY_IN_SECONDS ) );
t_ok( 1 === t_wc_delete(), 'nach Ablauf der Vorlaufzeit löscht WooCommerce das gewarnte Konto' );
t_ok( ! t_exists( $overdue ) && t_exists( $due_soon ), 'nur das Konto mit erreichter Freigabe ist weg' );

// Kunde meldet sich nach der Warnung an.
$user = get_userdata( $due_soon );
do_action( 'wp_login', $user->user_login, $user );
t_ok( '' === (string) get_user_meta( $due_soon, WCIUN_Markers::RELEASE, true ), 'Anmeldung entfernt die Markierung' );
t_ok( (int) get_user_meta( $due_soon, 'wc_last_active', true ) > time() - 60, 'WooCommerce hat die Aktivität aktualisiert' );
t_ok( 0 === t_wc_delete() && t_exists( $due_soon ), 'aktiv gewordenes Konto bleibt' );
$sum3 = t_scan();
t_ok( 0 === count( t_mails_to( 'u_due_soon@example.test' ) ) - 1, 'nach der Anmeldung keine weitere Warnung' );
t_reset_world();

// ==============================================================================================================
t_section( '5. Trockenlauf und Testmodus' );
t_retention( 36 );
t_settings( array( 'mode' => 'dry_run' ) );
$d1 = t_user( 'dry_1', 40 );
$d2 = t_user( 'dry_2', 35.5 );
$sum = t_scan();
t_ok( 2 === (int) $sum['would_send'], 'Trockenlauf zählt zwei Konten' );
t_ok( 0 === count( array_filter( $GLOBALS['t_mails'], static function ( $m ) { return false !== stripos( is_array( $m['to'] ) ? implode( ',', $m['to'] ) : $m['to'], 'dry_' ); } ) ), 'Trockenlauf verschickt keine Kundenmail' );
t_ok( '' === (string) get_user_meta( $d1, WCIUN_Markers::RELEASE, true ), 'Trockenlauf setzt keine Markierung' );
t_ok( 0 === t_wc_delete() && t_exists( $d1 ) && t_exists( $d2 ), 'Trockenlauf: Sperre aktiv, WooCommerce löscht nichts' );

t_settings( array( 'mode' => 'test', 'test_recipient' => 'tester@example.test' ) );
$GLOBALS['t_mails'] = array();
$sum = t_scan();
t_ok( 2 === (int) $sum['test_sent'], 'Testmodus verschickt zwei Testmails' );
t_ok( 2 === count( t_mails_to( 'tester@example.test' ) ) && 0 === count( t_mails_to( 'dry_1@example.test' ) ), 'Testmails gehen nur an die Testadresse' );
t_ok( false !== strpos( t_mails_to( 'tester@example.test' )[0]['subject'], '[TEST für dry_' ), 'Testbetreff nennt den echten Empfänger' );
t_ok( '' === (string) get_user_meta( $d1, WCIUN_Markers::RELEASE, true ), 'Testmodus setzt keine Markierung' );
$GLOBALS['t_mails'] = array();
$sum = t_scan();
t_ok( 0 === (int) $sum['test_sent'], 'Testmodus wiederholt sich nicht' );
t_reset_world();

// ==============================================================================================================
t_section( '6. Versandfehler, Tageslimit, parallele Läufe, unklares Ergebnis' );
t_retention( 36 );
t_settings( array( 'mode' => 'live' ) );
$f = t_user( 'fail_1', 40 );
$GLOBALS['t_mail_ok'] = false;
$sum = t_scan();
t_ok( 1 === (int) $sum['failed'] && '' === (string) get_user_meta( $f, WCIUN_Markers::RELEASE, true ), 'Versand schlägt fehl: keine Markierung, Konto bleibt gesperrt' );
t_ok( 1 === (int) get_user_meta( $f, WCIUN_Markers::ATTEMPTS, true ), 'Fehlversuch wird gezählt' );
t_ok( 0 === t_wc_delete() && t_exists( $f ), 'WooCommerce löscht das Konto trotz Fehlversuch nicht' );
$sum = t_scan();
t_ok( 0 === (int) $sum['failed'], 'Wiederholung erst am nächsten Tag (Wartezeit)' );
for ( $i = 0; $i < 2; $i++ ) {
	update_user_meta( $f, WCIUN_Markers::NEXT_TRY, '0' );
	t_scan();
}
t_ok( WCIUN_Markers::STATUS_FAILED === get_user_meta( $f, WCIUN_Markers::STATUS, true ), 'nach drei Fehlversuchen: Status "fehlgeschlagen" für die Admin-Liste' );
$GLOBALS['t_mail_ok'] = true;
$GLOBALS['t_mails']   = array();
t_scan();
t_ok( 0 === count( t_mails_to( 'fail_1@example.test' ) ), 'Konto mit Status "fehlgeschlagen" wird nicht automatisch erneut versucht' );
t_reset_world();

t_settings( array( 'mode' => 'live', 'daily_limit' => 1 ) );
t_user( 'lim_1', 40 );
t_user( 'lim_2', 40 );
t_user( 'lim_3', 40 );
$sum = t_scan();
t_ok( 1 === (int) $sum['sent'] && ! empty( $sum['limit_hit'] ), 'Tageslimit: eine Mail, Rest folgt später' );
t_ok( 0 === t_wc_delete(), 'nicht gewarnte Konten bleiben durch die Sperre erhalten' );
t_reset_world();

t_settings( array( 'mode' => 'live' ) );
t_ok( 'claimed' === WCIUN_Markers::claim( 'race', 60, true ), 'erste Beanspruchung gelingt' );
t_ok( 'busy' === WCIUN_Markers::claim( 'race', 60, true ), 'gleichzeitige zweite Beanspruchung wird abgewiesen' );
$wpdb->update( $wpdb->options, array( 'option_value' => (string) ( time() - 90000 ) ), array( 'option_name' => 'wciun_claim_race' ) );
t_ok( 'stale' === WCIUN_Markers::claim( 'race', 86400, false ), 'abgelaufene Beanspruchung wird gemeldet, nicht übernommen' );
t_ok( 'claimed' === WCIUN_Markers::claim( 'race', 86400, true ), 'abgelaufene Beanspruchung kann übernommen werden' );
WCIUN_Markers::release_claim( 'race' );

$u = t_user( 'unclear_1', 40 );
$wpdb->insert( $wpdb->options, array( 'option_name' => 'wciun_claim_user_' . $u, 'option_value' => (string) ( time() - 90000 ), 'autoload' => 'no' ) );
$GLOBALS['t_mails'] = array();
t_scan();
t_ok( WCIUN_Markers::STATUS_UNCLEAR === get_user_meta( $u, WCIUN_Markers::STATUS, true ), 'Abbruch mitten im Versand: Status "unklar"' );
t_ok( 0 === count( t_mails_to( 'unclear_1@example.test' ) ), 'unklarer Versand wird nicht automatisch wiederholt' );
t_reset_world();

// ==============================================================================================================
t_section( '7. Frist ändern oder abschalten' );
t_settings( array( 'mode' => 'live' ) );
t_retention( 12 );
$e = t_user( 'ret_1', 11.2 );
t_scan();
t_ok( WCIUN_Markers::STATUS_WARNED === get_user_meta( $e, WCIUN_Markers::STATUS, true ), 'Bei 12 Monaten wird das Konto gewarnt' );
t_retention( 36 );
WCIUN_Scanner::reconcile();
t_ok( '' === (string) get_user_meta( $e, WCIUN_Markers::RELEASE, true ), 'Umstellung auf 3 Jahre verwirft die Markierung (Konto ist nicht mehr fällig)' );
$GLOBALS['t_mails'] = array();
t_scan();
t_ok( 0 === count( t_mails_to( 'ret_1@example.test' ) ), 'nach der Umstellung keine Warnung, solange das Konto nicht fällig ist' );

t_retention( 0 );
$GLOBALS['t_mails'] = array();
$sum = t_scan();
t_ok( ! empty( $sum['retention_disabled'] ), 'deaktivierte Frist wird erkannt' );
t_ok( 0 === count( t_mails_to( 'ret_1@example.test' ) ), 'bei deaktivierter Frist keine Kundenmail (kein erfundenes Löschdatum)' );
t_reset_world();

// ==============================================================================================================
t_section( '8. Wächter: WooCommerce löscht ohne Freigabe (Sperre greift nicht)' );
t_retention( 36 );
t_settings( array( 'mode' => 'live', 'team_recipients' => 'team@example.test' ) );
$victim = t_user( 'w_victim', 40 );
remove_action( 'pre_get_users', array( 'WCIUN_Gate', 'on_pre_get_users' ), 10 );
t_wc_delete();
add_action( 'pre_get_users', array( 'WCIUN_Gate', 'on_pre_get_users' ), 10, 1 );
t_ok( ! t_exists( $victim ), 'Vorbedingung: ohne Sperre wird gelöscht (der Wächter kann es nicht verhindern)' );
$alerts = WCIUN_Digest::data_for_test();
t_ok( ! empty( $alerts['alerts'] ) && 'unprotected_deletion' === $alerts['alerts'][0]['type'], 'Wächter hat den Alarm vorgemerkt' );
t_ok( 1 === count( t_mails_to( 'team@example.test' ) ) && false !== strpos( t_mails_to( 'team@example.test' )[0]['subject'], 'ACHTUNG' ), 'Wächter hat sofort eine Alarm-Mail an das Team geschickt' );
t_reset_world();

// ==============================================================================================================
t_section( '9. Selbsttest' );
$r   = WCIUN_Selftest::run();
$bad = array_filter(
	$r['checks'],
	static function ( $c ) {
		return $c['required'] && ! $c['ok'] && 'wc_version' !== $c['id']; // Die Versionsbestätigung erfolgt erst nach diesem Test.
	}
);
t_ok( ! $bad, 'Alle Pflichtprüfungen des Selbsttests bestehen mit WooCommerce ' . WC_VERSION . ( $bad ? ' (fehlt: ' . implode( ', ', array_column( $bad, 'id' ) ) . ')' : '' ) );
t_ok( in_array( WC_VERSION, WCIUN_Selftest::tested_versions(), true ) === (bool) array_values( array_filter( $r['checks'], static function ( $c ) { return 'wc_version' === $c['id']; } ) )[0]['ok'], 'Versionsprüfung entspricht der Liste geprüfter Versionen' );
add_filter( 'wciun_tested_wc_versions', static function () { return array( '1.0.0' ); } );
$r = WCIUN_Selftest::run();
t_ok( ! $r['ok'], 'unbekannte WooCommerce-Version: Selbsttest schlägt an' );
remove_all_filters( 'wciun_tested_wc_versions' );

// ==============================================================================================================
t_section( '10. E-Mail: Inhalt, Escaping, Varianten' );
t_retention( 36 );
t_settings( array( 'mode' => 'live', 'team_recipients' => 'team@example.test' ) );
$mail_user = t_user( 'mail_1', 40, 'customer', 'mail_1@example.test' );
update_user_meta( $mail_user, 'first_name', '<b>Ann</b> "Q" & Co <script>alert(1)</script>' );
$mailer = WC()->mailer()->get_emails()['WCIUN_Email_Customer_Warning'];
foreach ( array( 'yes', 'no' ) as $improvements ) {
	update_option( 'woocommerce_feature_email_improvements_enabled', $improvements );
	foreach ( array( 'html', 'plain' ) as $type ) {
		$mailer->email_type = $type;
		$GLOBALS['t_mails'] = array();
		$earliest = strtotime( '+28 days' );
		$ok = $mailer->trigger( $mail_user, (int) get_user_meta( $mail_user, 'wc_last_active', true ), $earliest );
		$m  = $GLOBALS['t_mails'][0] ?? null;
		$tag = "[$type, improvements=$improvements]";
		t_ok( $ok && $m, "$tag Versand" );
		if ( ! $m ) {
			continue;
		}
		$body = $m['message'];
		t_ok( false !== strpos( $body, wp_date( 'd.m.Y', $earliest ) ), "$tag frühestes Datum steht im Text" );
		t_ok( false !== strpos( $body, wc_get_page_permalink( 'myaccount' ) ), "$tag Link auf Mein Konto" );
		t_ok( false !== strpos( $body, 'mail_1' ), "$tag Benutzername" );
		if ( 'html' === $type ) {
			t_ok( false === stripos( $body, '<script' ), "$tag kein Skript im Text" );
		}
		t_ok( 0 === preg_match( '/(key=|password|passwort|token)/i', $body ), "$tag kein Passwort, kein Schlüssel, kein Token" );
		if ( 'html' === $type ) {
			t_ok( false !== strpos( $body, '&lt;b&gt;Ann&lt;/b&gt;' ) || false !== strpos( $body, '&lt;b&gt;Ann' ), "$tag Name wird maskiert" );
			t_ok( false === strpos( $body, '<b>Ann</b>' ), "$tag Name nicht als HTML ausgegeben" );
			t_ok( false !== stripos( $body, '<html' ), "$tag HTML mit WooCommerce-Rahmen" );
		} else {
			// WooCommerce macht in Text-Mails aus Entitäten wieder Zeichen. Das ist in text/plain unkritisch.
			$headers = is_array( $m['headers'] ) ? implode( "\n", $m['headers'] ) : (string) $m['headers'];
			t_ok( false === stripos( $body, '<html' ), "$tag Textvariante ohne HTML" );
			t_ok( false !== stripos( $headers, 'text/plain' ), "$tag Content-Type text/plain" );
		}
		t_ok( false === strpos( $m['subject'], '{' ), "$tag keine unersetzten Platzhalter im Betreff" );
	}
}
delete_option( 'woocommerce_feature_email_improvements_enabled' );
$mailer->email_type = 'html';
t_reset_world();

// ==============================================================================================================
t_section( '11. Team-Mail: Anzahlen, keine Kundendaten' );
t_retention( 36 );
t_settings( array( 'mode' => 'live', 'team_recipients' => '' ) );
update_option( 'admin_email', 'admin@example.test' );
t_user( 'team_1', 40 );
t_user( 'team_2', 35.5 );
$GLOBALS['t_mails'] = array();
t_scan();
$tm = t_mails_to( 'admin@example.test' );
t_ok( 1 === count( $tm ), 'Team-Mail geht ohne eigene Adresse an die Administrator-Adresse' );
if ( $tm ) {
	$tb = $tm[0]['message'];
	t_ok( false !== strpos( $tb, 'Warnungen verschickt' ), 'Team-Mail nennt Anzahlen' );
	t_ok( false === strpos( $tb, 'team_1' ) && false === strpos( $tb, 'team_2' ) && false === strpos( $tb, '@example.test' ), 'Team-Mail enthält keine Kundendaten' );
	t_ok( false !== strpos( $tb, 'wciun-accounts' ), 'Team-Mail verweist auf die Admin-Ansicht' );
}
$GLOBALS['t_mails'] = array();
t_scan();
t_ok( 0 === count( t_mails_to( 'admin@example.test' ) ), 'ohne Neues keine zweite Team-Mail' );
t_settings( array( 'mode' => 'live', 'team_recipients' => 'a@example.test; b@example.test, kaputt' ) );
t_ok( 'a@example.test, b@example.test' === WCIUN_Settings::team_recipients(), 'mehrere Empfänger, ungültige werden verworfen' );
t_reset_world();

// ==============================================================================================================
t_section( '13. Mehrere Blöcke: 130 Konten (Blockgröße 50) im Live-Modus und im Trockenlauf' );
t_retention( 36 );

/** Führt die Kette der Folgeaktionen aus, so wie Action Scheduler es täte. */
function t_scan_chain() {
	as_unschedule_all_actions( WCIUN_Scanner::HOOK_BATCH ); // Altlasten früherer Abschnitte (Aktionen mit alter Lauf-Kennung).
	WCIUN_Scanner::start();
	$blocks = 0;
	while ( $blocks < 20 && get_option( WCIUN_Scanner::RUN_OPTION ) ) {
		$pending = as_get_scheduled_actions(
			array(
				'hook'     => WCIUN_Scanner::HOOK_BATCH,
				'status'   => ActionScheduler_Store::STATUS_PENDING,
				'per_page' => 1,
				'orderby'  => 'date',
				'order'    => 'ASC',
			),
			'ids'
		);
		if ( ! $pending ) {
			break;
		}
		$store  = ActionScheduler::store();
		$action = $store->fetch_action( $pending[0] );
		$store->mark_complete( $pending[0] );
		do_action_ref_array( WCIUN_Scanner::HOOK_BATCH, $action->get_args() );
		++$blocks;
	}
	return array( get_option( WCIUN_Scanner::LAST_OPTION ), $blocks );
}

$ids = array();
for ( $i = 1; $i <= 130; $i++ ) {
	$ids[] = t_user( sprintf( 'mass_%03d', $i ), 40 );
}
t_settings( array( 'mode' => 'dry_run' ) );
list( $sum, $blocks ) = t_scan_chain();
t_ok( 130 === (int) $sum['would_send'], "Trockenlauf über Blöcke: 130 gefunden (erhalten: {$sum['would_send']}, Blöcke: $blocks)" );
t_ok( 3 === $blocks, "drei Blöcke bei 130 Konten (erhalten: $blocks)" );
t_ok( 0 === count( array_filter( $GLOBALS['t_mails'], static function ( $m ) { return false !== strpos( is_array( $m['to'] ) ? implode( ',', $m['to'] ) : $m['to'], 'mass_' ); } ) ), 'Trockenlauf verschickt keine Kundenmails' );

t_settings( array( 'mode' => 'live', 'daily_limit' => 1000 ) );
$GLOBALS['t_mails'] = array();
list( $sum, $blocks ) = t_scan_chain();
t_ok( 130 === (int) $sum['sent'], "live über Blöcke: 130 verschickt (erhalten: {$sum['sent']}, Blöcke: $blocks)" );
$per_user = array();
foreach ( $GLOBALS['t_mails'] as $m ) {
	$to = is_array( $m['to'] ) ? implode( ',', $m['to'] ) : $m['to'];
	if ( false !== strpos( $to, 'mass_' ) ) {
		$per_user[ $to ] = ( $per_user[ $to ] ?? 0 ) + 1;
	}
}
t_ok( 130 === count( $per_user ) && 1 === max( $per_user ), 'jedes der 130 Konten bekam genau eine Mail, keines doppelt oder keins vergessen' );
$marked = 0;
foreach ( $ids as $uid ) {
	if ( WCIUN_Markers::has_marker( $uid ) ) {
		++$marked;
	}
}
t_ok( 130 === $marked, "alle 130 Konten haben eine Markierung (erhalten: $marked)" );
t_ok( 0 === t_wc_delete(), 'WooCommerce löscht heute keines der 130 Konten' );
$GLOBALS['t_mails'] = array();
list( $sum ) = t_scan_chain();
t_ok( 0 === (int) $sum['sent'], 'zweiter Lauf über Blöcke verschickt nichts erneut' );

t_settings( array( 'mode' => 'live', 'daily_limit' => 60 ) );
foreach ( $ids as $uid ) {
	WCIUN_Markers::clear( $uid );
}
delete_option( WCIUN_Digest::COUNTER );
$GLOBALS['t_mails'] = array();
list( $sum, $blocks ) = t_scan_chain();
t_ok( 60 === (int) $sum['sent'] && ! empty( $sum['limit_hit'] ), "Tageslimit 60 über Blöcke: genau 60 verschickt (erhalten: {$sum['sent']})" );
delete_option( WCIUN_Digest::COUNTER );
list( $sum ) = t_scan_chain();
t_ok( 60 === (int) $sum['sent'], "am nächsten Tag weitere 60 (erhalten: {$sum['sent']})" );
delete_option( WCIUN_Digest::COUNTER );
list( $sum ) = t_scan_chain();
t_ok( 10 === (int) $sum['sent'] && empty( $sum['limit_hit'] ), "am dritten Tag die restlichen 10 (erhalten: {$sum['sent']})" );
t_reset_world();

// ==============================================================================================================
t_section( '12. Einstellungen werden bereinigt' );
$s = WCIUN_Settings::sanitize( array( 'mode' => 'drop table', 'lead_days' => 5, 'daily_limit' => 99999, 'test_recipient' => 'nope', 'team_recipients' => "x@y.de\nz" ) );
t_ok( 'dry_run' === $s['mode'] && 14 === $s['lead_days'] && 1000 === $s['daily_limit'] && '' === $s['test_recipient'] && 'x@y.de' === $s['team_recipients'], 'ungültige Werte werden auf sichere Werte gesetzt' );
t_ok( 90 === WCIUN_Settings::sanitize( array( 'lead_days' => 500 ) )['lead_days'], 'Vorlaufzeit höchstens 90 Tage' );

// ==============================================================================================================
echo "\n" . ( $GLOBALS['t_fail'] ? "{$GLOBALS['t_fail']} von {$GLOBALS['t_n']} Prüfungen fehlgeschlagen" : "Alle {$GLOBALS['t_n']} Prüfungen bestanden" ) . "\n";
exit( $GLOBALS['t_fail'] ? 1 : 0 );
