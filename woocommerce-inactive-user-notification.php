<?php
/**
 * Plugin Name:       Inaktive Kundenkonten: Vorwarnung vor Löschung
 * Description:       Warnt Kunden und Shop-Team vor der automatischen Löschung inaktiver Konten durch WooCommerce und sperrt die Löschung, bis die Vorwarnfrist abgelaufen ist.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * Author:            Albert Brückmann
 * License:           GPL-2.0-or-later
 * Text Domain:       wciun
 * Domain Path:       /languages
 * WC requires at least: 9.0
 * WC tested up to:   11.2.2
 *
 * @package WCIUN
 */

defined( 'ABSPATH' ) || exit;

define( 'WCIUN_VERSION', '0.1.0' );
define( 'WCIUN_FILE', __FILE__ );
define( 'WCIUN_DIR', plugin_dir_path( __FILE__ ) );

/**
 * WooCommerce-Versionen, gegen die die Löschsperre getestet wurde.
 * Weitere Versionen kann ein Administrator nach eigener Prüfung bestätigen.
 */
define( 'WCIUN_TESTED_WC_VERSIONS', '11.2.2' );

require_once WCIUN_DIR . 'includes/class-wciun-calc.php';
require_once WCIUN_DIR . 'includes/class-wciun-settings.php';
require_once WCIUN_DIR . 'includes/class-wciun-markers.php';
require_once WCIUN_DIR . 'includes/class-wciun-gate.php';
require_once WCIUN_DIR . 'includes/class-wciun-digest.php';
require_once WCIUN_DIR . 'includes/class-wciun-selftest.php';
require_once WCIUN_DIR . 'includes/class-wciun-scanner.php';
require_once WCIUN_DIR . 'includes/class-wciun-admin.php';
require_once WCIUN_DIR . 'includes/class-wciun-plugin.php';

register_activation_hook( __FILE__, array( 'WCIUN_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'WCIUN_Plugin', 'deactivate' ) );

// Die Sperre wird bewusst sofort beim Laden registriert, nicht erst nach WooCommerce.
WCIUN_Gate::init();
WCIUN_Markers::init();

add_action( 'plugins_loaded', array( 'WCIUN_Plugin', 'init' ), 20 );
