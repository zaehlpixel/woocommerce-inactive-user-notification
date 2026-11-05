<?php
/**
 * E-Mail an Kunden: Warnung vor Löschung eines inaktiven Kontos (HTML).
 *
 * Kann im Theme überschrieben werden: yourtheme/woocommerce/emails/customer-inactive-warning.php
 *
 * @package WCIUN\Templates\Emails
 * @version 0.1.0
 *
 * @var string   $email_heading      Überschrift.
 * @var string   $additional_content Zusatztext aus den E-Mail-Einstellungen.
 * @var array    $data               Werte: customer_name, username, last_active_date, earliest_date, login_url, contact_email.
 * @var string   $blogname           Name des Shops.
 * @var WC_Email $email              E-Mail-Objekt.
 */

use Automattic\WooCommerce\Utilities\FeaturesUtil;

defined( 'ABSPATH' ) || exit;

$email_improvements_enabled = FeaturesUtil::feature_is_enabled( 'email_improvements' );

do_action( 'woocommerce_email_header', $email_heading, $email );

echo $email_improvements_enabled ? '<div class="email-introduction">' : '';
?>
<p><?php printf( esc_html__( 'Hallo %s,', 'wciun' ), esc_html( $data['customer_name'] ) ); ?></p>
<p>
	<?php
	printf(
		/* translators: 1: Datum der letzten Aktivität, 2: Shopname, 3: Benutzername, 4: frühestes Löschdatum */
		esc_html__( 'Sie haben sich seit dem %1$s nicht mehr in Ihrem Kundenkonto bei %2$s angemeldet. Wir entfernen Kundenkonten, die längere Zeit nicht genutzt werden. Ihr Konto (Benutzername: %3$s) kann deshalb frühestens ab dem %4$s entfernt werden. Der genaue Zeitpunkt hängt von unseren automatischen Abläufen ab und kann einige Tage später liegen.', 'wciun' ),
		esc_html( $data['last_active_date'] ),
		esc_html( $blogname ),
		'<strong>' . esc_html( $data['username'] ) . '</strong>',
		'<strong>' . esc_html( $data['earliest_date'] ) . '</strong>'
	);
	?>
</p>
<p><?php esc_html_e( 'Sie möchten Ihr Konto behalten? Dann melden Sie sich bis dahin einfach einmal an. Damit gilt Ihr Konto wieder als aktiv, und die Frist beginnt neu. Auch ein Besuch im Shop, bei dem Sie bereits angemeldet sind, zählt.', 'wciun' ); ?></p>
<p>
	<a class="link" href="<?php echo esc_url( $data['login_url'] ); ?>"><?php esc_html_e( 'Zur Anmeldung', 'wciun' ); ?></a>
</p>
<p><?php esc_html_e( 'Wenn Sie nichts tun, wird Ihr Konto entfernt. Ihre bisherigen Bestellungen bleiben bei uns gespeichert, soweit wir sie aufbewahren müssen, sind dann aber nicht mehr mit einem Kundenkonto verbunden.', 'wciun' ); ?></p>
<?php if ( ! empty( $data['contact_email'] ) ) : ?>
	<p>
		<?php
		printf(
			/* translators: %s: E-Mail-Adresse des Shops */
			esc_html__( 'Bei Fragen schreiben Sie uns gern an %s.', 'wciun' ),
			'<a class="link" href="' . esc_url( 'mailto:' . $data['contact_email'] ) . '">' . esc_html( $data['contact_email'] ) . '</a>'
		);
		?>
	</p>
<?php endif; ?>
<?php
echo $email_improvements_enabled ? '</div>' : '';

if ( $additional_content ) {
	echo $email_improvements_enabled ? '<table border="0" cellpadding="0" cellspacing="0" width="100%" role="presentation"><tr><td class="email-additional-content email-additional-content-aligned">' : '';
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
	echo $email_improvements_enabled ? '</td></tr></table>' : '';
}

do_action( 'woocommerce_email_footer', $email );
