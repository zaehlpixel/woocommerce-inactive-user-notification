<?php
/**
 * Deinstallation: entfernt alle Daten des Plugins.
 *
 * Hinweis: Ohne das Plugin gibt es keine Löschsperre mehr. Solange in WooCommerce eine Frist für inaktive
 * Konten eingestellt ist, löscht WooCommerce danach wieder ohne Vorwarnung.
 *
 * @package WCIUN
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

if ( function_exists( 'as_unschedule_all_actions' ) ) {
	foreach ( array( 'wciun_daily_scan', 'wciun_scan_batch', 'wciun_reconcile' ) as $hook ) {
		as_unschedule_all_actions( $hook );
	}
}

$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s", $wpdb->esc_like( '_wciun_' ) . '%' ) );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'wciun_' ) . '%' ) );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like( '_transient_wciun_' ) . '%', $wpdb->esc_like( '_transient_timeout_wciun_' ) . '%' ) );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'woocommerce_wciun_' ) . '%' ) );
