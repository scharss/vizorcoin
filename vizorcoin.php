<?php
/**
 * Plugin Name:       VizorCoin
 * Plugin URI:        https://byterock.xyz/
 * Description:       Muestra los precios del catálogo y de la ficha de producto en una moneda de visualización (USD, EUR, CNY, ...) usando tasas de Yahoo Finance con respaldo, sin alterar la moneda real de la tienda ni el procesamiento de pagos. Añade un aviso modal en el checkout antes de pasar a la pasarela.
 * Version:           1.0.1
 * Author:            Scharss Azael
 * Author URI:        https://byterock.xyz/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       vizorcoin
 * Domain Path:       /languages
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * WC requires at least: 6.0
 * WC tested up to:   9.8
 *
 * @package VizorCoin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'VIZORCOIN_VERSION', '1.0.1' );
define( 'VIZORCOIN_FILE', __FILE__ );
define( 'VIZORCOIN_PATH', plugin_dir_path( __FILE__ ) );
define( 'VIZORCOIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Declara compatibilidad con HPOS (High-Performance Order Storage) y con los
 * bloques de Carrito/Checkout de WooCommerce.
 */
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
		}
	}
);

/**
 * Arranca el plugin cuando WooCommerce (y sus funciones) están disponibles.
 */
add_action(
	'plugins_loaded',
	function () {
		load_plugin_textdomain( 'vizorcoin', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				function () {
					if ( ! current_user_can( 'activate_plugins' ) ) {
						return;
					}
					echo '<div class="notice notice-error"><p>' .
						esc_html__( 'VizorCoin requiere que WooCommerce esté instalado y activo.', 'vizorcoin' ) .
						'</p></div>';
				}
			);
			return;
		}

		require_once VIZORCOIN_PATH . 'includes/class-vizorcoin.php';
		VizorCoin::instance();
	}
);
