<?php
/**
 * E-Mail an das Team: Übersicht (HTML). Enthält nur Anzahlen, keine Kundendaten.
 *
 * @package WCIUN\Templates\Emails
 * @version 0.1.0
 *
 * @var string   $email_heading
 * @var string   $additional_content
 * @var array    $payload
 * @var string   $admin_url
 * @var WC_Email $email
 */

use Automattic\WooCommerce\Utilities\FeaturesUtil;

defined( 'ABSPATH' ) || exit;

$email_improvements_enabled = FeaturesUtil::feature_is_enabled( 'email_improvements' );
$modes                      = array(
	'dry_run' => 'Trockenlauf (es werden keine Kunden angeschrieben)',
	'test'    => 'Testmodus (Kunden-Mails gehen nur an die Testadresse)',
	'live'    => 'Live',
);
$mode                       = isset( $payload['mode'], $modes[ $payload['mode'] ] ) ? $modes[ $payload['mode'] ] : '';

do_action( 'woocommerce_email_header', $email_heading, $email );

echo $email_improvements_enabled ? '<div class="email-introduction">' : '';

if ( ! empty( $payload['alerts'] ) ) :
	?>
	<p><strong><?php esc_html_e( 'Achtung:', 'wciun' ); ?></strong></p>
	<ul>
		<?php foreach ( (array) $payload['alerts'] as $alert ) : ?>
			<li><?php echo esc_html( $alert ); ?></li>
		<?php endforeach; ?>
	</ul>
	<?php
endif;

if ( ! empty( $payload['problems'] ) ) :
	?>
	<p><strong><?php esc_html_e( 'Hinweise:', 'wciun' ); ?></strong></p>
	<ul>
		<?php foreach ( (array) $payload['problems'] as $problem ) : ?>
			<li><?php echo esc_html( $problem ); ?></li>
		<?php endforeach; ?>
	</ul>
	<?php
endif;

if ( ! empty( $payload['counts'] ) ) :
	?>
	<?php if ( $mode ) : ?>
		<p><?php echo esc_html( sprintf( /* translators: %s: Modus */ __( 'Modus: %s', 'wciun' ), $mode ) ); ?></p>
	<?php endif; ?>
	<table cellspacing="0" cellpadding="6" border="1" style="width:100%; border-collapse:collapse; border-color:#e5e5e5;" role="presentation">
		<?php foreach ( (array) $payload['counts'] as $label => $count ) : ?>
			<tr>
				<td style="text-align:left;"><?php echo esc_html( $label ); ?></td>
				<td style="text-align:right;"><strong><?php echo esc_html( (string) (int) $count ); ?></strong></td>
			</tr>
		<?php endforeach; ?>
	</table>
	<?php
endif;
?>
<p>
	<?php esc_html_e( 'Die einzelnen Konten sehen Sie nach der Anmeldung hier:', 'wciun' ); ?>
	<a class="link" href="<?php echo esc_url( $admin_url ); ?>"><?php esc_html_e( 'WooCommerce → Inaktive Konten', 'wciun' ); ?></a>
</p>
<?php
echo $email_improvements_enabled ? '</div>' : '';

if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

do_action( 'woocommerce_email_footer', $email );
