<?php
/**
 * E-Mail an das Team: Übersicht (Text). Enthält nur Anzahlen, keine Kundendaten.
 *
 * @package WCIUN\Templates\Emails\Plain
 * @version 0.1.0
 *
 * @var string   $email_heading
 * @var string   $additional_content
 * @var array    $payload
 * @var string   $admin_url
 * @var WC_Email $email
 */

defined( 'ABSPATH' ) || exit;

echo "=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n";
echo esc_html( wp_strip_all_tags( $email_heading ) );
echo "\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";

if ( ! empty( $payload['alerts'] ) ) {
	echo esc_html__( 'ACHTUNG:', 'wciun' ) . "\n";
	foreach ( (array) $payload['alerts'] as $alert ) {
		echo '- ' . esc_html( $alert ) . "\n";
	}
	echo "\n";
}

if ( ! empty( $payload['problems'] ) ) {
	echo esc_html__( 'Hinweise:', 'wciun' ) . "\n";
	foreach ( (array) $payload['problems'] as $problem ) {
		echo '- ' . esc_html( $problem ) . "\n";
	}
	echo "\n";
}

if ( ! empty( $payload['counts'] ) ) {
	foreach ( (array) $payload['counts'] as $label => $count ) {
		echo esc_html( $label ) . ': ' . (int) $count . "\n";
	}
	echo "\n";
}

echo esc_html__( 'Die einzelnen Konten sehen Sie nach der Anmeldung hier:', 'wciun' ) . ' ' . esc_url( $admin_url ) . "\n\n";
echo "\n----------------------------------------\n\n";
echo wp_kses_post( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) );
