<?php
/**
 * Einfache Tests ohne WordPress: php tests/run-unit.php
 *
 * @package WCIUN
 */

define( 'WCIUN_TESTING', true );
date_default_timezone_set( 'UTC' ); // WordPress setzt die PHP-Zeitzone ebenfalls auf UTC.
require_once dirname( __DIR__ ) . '/includes/class-wciun-calc.php';

$failures = 0;
$checks   = 0;
function check( $cond, $label ) {
	global $failures, $checks;
	++$checks;
	if ( ! $cond ) {
		++$failures;
		echo "FAIL: $label\n";
	}
}

// parse_retention entspricht wc_parse_relative_date_option().
check( null === WCIUN_Calc::parse_retention( '' ), 'leere Option = keine Frist' );
check( null === WCIUN_Calc::parse_retention( array( 'number' => '', 'unit' => 'months' ) ), 'leere Zahl = keine Frist' );
check( null === WCIUN_Calc::parse_retention( array( 'number' => '0', 'unit' => 'months' ) ), 'Null = keine Frist' );
check( array( 'number' => 36, 'unit' => 'months' ) === WCIUN_Calc::parse_retention( array( 'number' => '36', 'unit' => 'months' ) ), '36 Monate' );
check( 'days' === WCIUN_Calc::parse_retention( array( 'number' => 5, 'unit' => 'fortnights' ) )['unit'], 'ungültige Einheit wird zu Tagen' );
check( 3 === WCIUN_Calc::parse_retention( array( 'number' => '-3', 'unit' => 'years' ) )['number'], 'negative Zahl wird positiv (absint)' );

// eligible_at: Eigenschaft prüfen. Bedingung gilt am Ergebnis und an den fünf Folgetagen, nicht kurz davor.
$retentions = array(
	array( 'number' => 3, 'unit' => 'years' ),
	array( 'number' => 12, 'unit' => 'months' ),
	array( 'number' => 36, 'unit' => 'months' ),
	array( 'number' => 1, 'unit' => 'months' ),
	array( 'number' => 90, 'unit' => 'days' ),
	array( 'number' => 4, 'unit' => 'weeks' ),
);
$bases = array();
foreach ( array( '2021-02-28', '2021-03-31', '2023-01-31', '2024-02-29', '2024-02-28', '2024-03-01', '2020-02-29', '2023-10-29', '2023-03-26', '2026-11-05', '2000-02-29', '2024-12-31' ) as $d ) {
	foreach ( array( '00:00:00', '01:59:59', '12:30:00', '23:59:59' ) as $time ) {
		$bases[] = strtotime( "$d $time UTC" );
	}
}
foreach ( $retentions as $ret ) {
	foreach ( $bases as $la ) {
		$t   = WCIUN_Calc::eligible_at( $ret, $la );
		$lbl = $ret['number'] . ' ' . $ret['unit'] . ' ab ' . gmdate( 'Y-m-d H:i:s', $la );
		check( WCIUN_Calc::cutoff( $ret, $t ) > $la, "an T ist WooCommerce-Bedingung erfüllt: $lbl" );
		check( WCIUN_Calc::cutoff( $ret, $t - 1 ) <= $la, "eine Sekunde vorher noch nicht: $lbl" );
		for ( $d = 1; $d <= 5; $d++ ) {
			check( WCIUN_Calc::cutoff( $ret, $t + $d * 86400 ) > $la, "bleibt in den Folgetagen erfüllt (+{$d}d): $lbl" );
		}
	}
}

// Konkrete Beispiele, von Hand nachgerechnet.
$ret = array( 'number' => 3, 'unit' => 'years' );
check( gmdate( 'Y-m-d', WCIUN_Calc::eligible_at( $ret, strtotime( '2023-11-05 10:00:00 UTC' ) ) ) === '2026-11-05', '3 Jahre ab 5.11.2023 = 5.11.2026 (Sekunde nach der Aktivität)' );
// Schaltjahr: 29.2.2024 + 3 Jahre -> 1.3.2027 (PHP rechnet 29.2.2027 zu 1.3.2027).
check( gmdate( 'Y-m-d', WCIUN_Calc::eligible_at( $ret, strtotime( '2024-02-29 12:00:00 UTC' ) ) ) === '2027-03-01', 'Schaltjahr: 29.2.2024 + 3 Jahre = 1.3.2027' );
$ret1 = array( 'number' => 12, 'unit' => 'months' );
check( gmdate( 'Y-m-d', WCIUN_Calc::eligible_at( $ret1, strtotime( '2025-03-31 08:00:00 UTC' ) ) ) === '2026-03-31', '12 Monate ab 31.3.2025 = 31.3.2026' );

echo $failures ? "\n$failures von $checks Prüfungen fehlgeschlagen\n" : "Alle $checks Prüfungen bestanden\n";
exit( $failures ? 1 : 0 );
