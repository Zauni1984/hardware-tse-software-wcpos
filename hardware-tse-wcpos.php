<?php
/**
 * Plugin Name: Hardware-TSE (Epson) für WCPOS
 * Plugin URI:  https://github.com/Zauni1984/hardware-tse-software-wcpos
 * Description: KassenSichV-Signatur von WooCommerce-Kassenvorgängen mit einer Hardware-TSE im Epson-Bondrucker. Ein TSE-Agent im Browser am Kassenplatz spricht per ePOS-Device mit der TSE; das Plugin führt das Journal, setzt die TSE-Daten auf den WCPOS-Bon, hält den Cloud-Druck bis zur Signatur an, erstellt Kassenabschlüsse und den DSFinV-K-Export.
 * Version:     0.1.0
 * Author:      Hanfjack
 * License:     MIT
 * Requires at least: 6.4
 * Requires PHP: 8.1
 * Requires Plugins: woocommerce
 * Text Domain: hardware-tse-wcpos
 */

defined( 'ABSPATH' ) || exit;

define( 'HWTSE_VERSION', '0.1.0' );
define( 'HWTSE_FILE', __FILE__ );
define( 'HWTSE_DIR', plugin_dir_path( __FILE__ ) );
define( 'HWTSE_URL', plugin_dir_url( __FILE__ ) );
define( 'HWTSE_OPTION', 'hwtse_settings' );

require_once HWTSE_DIR . 'includes/class-hwtse-exception.php';
require_once HWTSE_DIR . 'includes/class-hwtse-settings.php';
require_once HWTSE_DIR . 'includes/class-hwtse-journal.php';
require_once HWTSE_DIR . 'includes/class-hwtse-process-data.php';
require_once HWTSE_DIR . 'includes/class-hwtse-sources.php';
require_once HWTSE_DIR . 'includes/class-hwtse-cashbook.php';
require_once HWTSE_DIR . 'includes/class-hwtse-receipt.php';
require_once HWTSE_DIR . 'includes/class-hwtse-print-gate.php';
require_once HWTSE_DIR . 'includes/class-hwtse-storage.php';
require_once HWTSE_DIR . 'includes/class-hwtse-closing.php';
require_once HWTSE_DIR . 'includes/class-hwtse-dsfinvk.php';
require_once HWTSE_DIR . 'includes/class-hwtse-rest.php';
require_once HWTSE_DIR . 'includes/class-hwtse-admin.php';

register_activation_hook( __FILE__, array( 'HWTSE_Journal', 'install' ) );
register_deactivation_hook(
	__FILE__,
	static function () {
		wp_clear_scheduled_hook( 'hwtse_daily_closing' );
		wp_clear_scheduled_hook( 'hwtse_housekeeping' );
	}
);

add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

add_action(
	'plugins_loaded',
	static function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}
		HWTSE_Journal::maybe_upgrade();
		HWTSE_Sources::init();
		HWTSE_Receipt::init();
		HWTSE_Print_Gate::init();
		HWTSE_Closing::init();
		HWTSE_Rest::init();
		if ( is_admin() ) {
			HWTSE_Admin::init();
		}
	}
);
