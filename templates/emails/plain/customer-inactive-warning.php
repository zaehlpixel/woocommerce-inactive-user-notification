<?php
/**
 * E-Mail an Kunden: Warnung vor Löschung eines inaktiven Kontos (Text).
 *
 * Kann im Theme überschrieben werden: yourtheme/woocommerce/emails/plain/customer-inactive-warning.php
 *
 * @package WCIUN\Templates\Emails\Plain
 * @version 0.1.0
 *
 * @var string   $email_heading
 * @var string   $additional_content
 * @var array    $data
 * @var string   $blogname
 * @var WC_Email $email
 */

defined( 'ABSPATH' ) || exit;

echo "=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n";
echo esc_html( wp_strip_all_tags( $email_heading ) );
echo "\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";

echo sprintf( esc_html__( 'Hallo %s,', 'wciun' ), esc_html( $data['customer_name'] ) ) . "\n\n";

echo sprintf(
	/* translators: 1: Datum der letzten Aktivität, 2: Shopname, 3: Benutzername, 4: frühestes Löschdatum */
	esc_html__( 'Sie haben sich seit dem %1$s nicht mehr in Ihrem Kundenkonto bei %2$s angemeldet. Wir entfernen Kundenkonten, die längere Zeit nicht genutzt werden. Ihr Konto (Benutzername: %3$s) kann deshalb frühestens ab dem %4$s entfernt werden. Der genaue Zeitpunkt hängt von unseren automatischen Abläufen ab und kann einige Tage später liegen.', 'wciun' ),
	esc_html( $data['last_active_date'] ),
	esc_html( $blogname ),
	esc_html( $data['username'] ),
	esc_html( $data['earliest_date'] )
) . "\n\n";

echo esc_html__( 'Sie möchten Ihr Konto behalten? Dann melden Sie sich bis dahin einfach einmal an. Damit gilt Ihr Konto wieder als aktiv, und die Frist beginnt neu. Auch ein Besuch im Shop, bei dem Sie bereits angemeldet sind, zählt.', 'wciun' ) . "\n\n";
echo esc_html__( 'Zur Anmeldung:', 'wciun' ) . ' ' . esc_url( $data['login_url'] ) . "\n\n";
echo esc_html__( 'Wenn Sie nichts tun, wird Ihr Konto entfernt. Ihre bisherigen Bestellungen bleiben bei uns gespeichert, soweit wir sie aufbewahren müssen, sind dann aber nicht mehr mit einem Kundenkonto verbunden.', 'wciun' ) . "\n\n";

if ( ! empty( $data['contact_email'] ) ) {
	echo sprintf(
		/* translators: %s: E-Mail-Adresse des Shops */
		esc_html__( 'Bei Fragen schreiben Sie uns gern an %s.', 'wciun' ),
		esc_html( $data['contact_email'] )
	) . "\n\n";
}

echo "\n----------------------------------------\n\n";

if ( $additional_content ) {
	echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) );
	echo "\n\n----------------------------------------\n\n";
}

echo wp_kses_post( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) );
