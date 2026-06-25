<?php
/**
 * Plugin Name:       Herroepingsknop voor WooCommerce
 * Plugin URI:        https://github.com/martijnbenjamin/herroepingsknop-woocommerce
 * Description:       Wettelijk verplichte digitale herroepingsfunctie (EU 2023/2673, NL per 19-06-2026): knop, formulier, aparte bevestiging en een ontvangstbevestiging per e-mail met vastgelegd tijdstip. Werkt met en zonder WooCommerce. Gratis, gemaakt door 072DESIGN.
 * Version:           1.2.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            072DESIGN
 * Author URI:        https://072design.nl
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       herroepingsknop
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'D072_HERR_VERSION', '1.2.0' );
define( 'D072_HERR_DIR', __DIR__ );

require_once __DIR__ . '/includes/class-herroeping.php';

D072_Herroeping::instance();

/**
 * Rewrite-regels netjes (her)opbouwen bij activatie en opruimen bij deactivatie.
 * De daadwerkelijke regel wordt op `init` geregistreerd; het wissen van de
 * vlag forceert een flush bij de eerstvolgende pageload.
 */
register_activation_hook( __FILE__, function () {
	delete_option( 'd072_herr_flushed' );
} );
register_deactivation_hook( __FILE__, 'flush_rewrite_rules' );
