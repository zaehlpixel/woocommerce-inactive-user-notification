<?php
/**
 * Start, Aktivierung und globale Hinweise.
 *
 * @package WCIUN
 */

defined( 'ABSPATH' ) || exit;

final class WCIUN_Plugin {

	/**
	 * Wird nach dem Laden aller Plugins aufgerufen.
	 */
	public static function init() {
		load_plugin_textdomain( 'wciun', false, dirname( plugin_basename( WCIUN_FILE ) ) . '/languages' );

		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( __CLASS__, 'notice_no_woocommerce' ) );
			return;
		}

		add_filter( 'woocommerce_email_classes', array( __CLASS__, 'register_emails' ) );
		WCIUN_Scanner::init();
		add_action( 'admin_notices', array( __CLASS__, 'notice_selftest' ) );
		add_action( 'admin_print_footer_scripts-plugins.php', array( __CLASS__, 'deactivation_warning' ) );
		if ( is_admin() ) {
			WCIUN_Admin::init();
		}
	}

	/**
	 * Aktivierung: Startmodus ist der Trockenlauf. Es wird nichts versendet.
	 */
	public static function activate() {
		if ( false === get_option( WCIUN_Settings::OPTION ) ) {
			add_option( WCIUN_Settings::OPTION, WCIUN_Settings::defaults(), '', false );
		}
		update_option( 'wciun_activated', time(), false );
	}

	/**
	 * Deaktivierung: geplante Aktionen entfernen, Daten behalten.
	 */
	public static function deactivate() {
		WCIUN_Scanner::unschedule();
	}

	/**
	 * Registriert die WooCommerce-E-Mails.
	 *
	 * @param array $emails Bestehende E-Mails.
	 * @return array
	 */
	public static function register_emails( $emails ) {
		$emails['WCIUN_Email_Customer_Warning'] = include WCIUN_DIR . 'includes/emails/class-wciun-email-customer-warning.php';
		$emails['WCIUN_Email_Team']             = include WCIUN_DIR . 'includes/emails/class-wciun-email-team.php';
		return $emails;
	}

	/**
	 * Hinweis, wenn WooCommerce fehlt.
	 */
	public static function notice_no_woocommerce() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		echo '<div class="notice notice-warning"><p>' . esc_html__( 'Inaktive Kundenkonten: WooCommerce ist nicht aktiv. Das Plugin ist passiv und sendet nichts.', 'wciun' ) . '</p></div>';
	}

	/**
	 * Hinweis, wenn der Selbsttest der Löschsperre fehlschlägt.
	 */
	public static function notice_selftest() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$last = WCIUN_Selftest::last();
		if ( empty( $last ) || ! empty( $last['ok'] ) ) {
			return;
		}
		$problems = array();
		foreach ( (array) $last['checks'] as $check ) {
			if ( ! empty( $check['required'] ) && empty( $check['ok'] ) ) {
				$problems[] = $check['label'] . ': ' . $check['detail'];
			}
		}
		printf(
			'<div class="notice notice-error"><p><strong>%s</strong> %s</p><ul style="list-style:disc;margin-left:2em;"><li>%s</li></ul><p><a href="%s">%s</a></p></div>',
			esc_html__( 'Inaktive Kundenkonten: Die Löschsperre ist ungeprüft.', 'wciun' ),
			esc_html__( 'WooCommerce könnte Konten ohne Vorwarnung löschen.', 'wciun' ),
			implode( '</li><li>', array_map( 'esc_html', $problems ) ),
			esc_url( admin_url( 'admin.php?page=wciun-accounts' ) ),
			esc_html__( 'Zur Plugin-Seite', 'wciun' )
		);
	}

	/**
	 * Auf der Plugin-Liste: Rückfrage vor dem Deaktivieren, solange WooCommerce Konten automatisch löscht.
	 */
	public static function deactivation_warning() {
		if ( ! WCIUN_Calc::parse_retention( get_option( 'woocommerce_delete_inactive_accounts' ) ) ) {
			return;
		}
		$msg = 'Wenn Sie dieses Plugin deaktivieren, löscht WooCommerce inaktive Konten wieder ohne Vorwarnung, auch Konten, die noch nicht gewarnt wurden. Bitte vorher in WooCommerce → Einstellungen → Konten & Datenschutz die Frist für inaktive Konten leeren oder diese Folge bewusst in Kauf nehmen. Trotzdem deaktivieren?';
		?>
		<script>
		document.querySelectorAll('tr[data-plugin="<?php echo esc_js( plugin_basename( WCIUN_FILE ) ); ?>"] .deactivate a').forEach(function (a) {
			a.addEventListener('click', function (e) {
				if (!window.confirm(<?php echo wp_json_encode( $msg ); ?>)) { e.preventDefault(); }
			});
		});
		</script>
		<?php
	}
}
