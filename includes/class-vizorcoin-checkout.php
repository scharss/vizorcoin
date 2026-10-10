<?php
/**
 * Aviso modal en el checkout antes de pasar a la pasarela.
 *
 * @package VizorCoin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Encola el CSS/JS del modal y expone los datos necesarios al script.
 */
class VizorCoin_Checkout {

	/**
	 * Referencia al plugin.
	 *
	 * @var VizorCoin
	 */
	private $plugin;

	/**
	 * Constructor.
	 *
	 * @param VizorCoin $plugin Plugin.
	 */
	public function __construct( $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Registra los hooks.
	 */
	public function init() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_thankyou' ) );
		add_action( 'woocommerce_review_order_after_order_total', array( $this, 'render_base_total' ) );
	}

	/**
	 * Indica si estamos en la página de confirmación del pedido.
	 *
	 * @return bool
	 */
	private function is_thankyou_context() {
		if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'order-received' ) ) {
			return true;
		}
		if ( function_exists( '_is_wcf_thankyou_type' ) && _is_wcf_thankyou_type() ) {
			return true;
		}
		return false;
	}

	/**
	 * Encola el script que ajusta la insignia de moneda en la confirmación.
	 */
	public function enqueue_thankyou() {
		if ( ! $this->is_thankyou_context() ) {
			return;
		}

		$settings = $this->plugin->get_settings();
		if ( empty( $settings['enabled'] ) || empty( $settings['convert_checkout'] ) ) {
			return;
		}
		if ( ! $this->plugin->is_active() ) {
			return;
		}

		wp_enqueue_script(
			'vizorcoin-thankyou',
			VIZORCOIN_URL . 'assets/js/vizorcoin-thankyou.js',
			array(),
			VIZORCOIN_VERSION,
			true
		);

		wp_localize_script(
			'vizorcoin-thankyou',
			'VizorCoinThankyou',
			array(
				'targetCurrency' => $this->plugin->display_currency(),
			)
		);
	}

	/**
	 * Imprime el total real (moneda base) en un elemento oculto dentro del
	 * resumen del pedido. Se refresca con los fragmentos AJAX del checkout,
	 * de modo que el modal lo lee al instante sin llamadas de red.
	 */
	public function render_base_total() {
		if ( ! $this->plugin->is_active() ) {
			return;
		}
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return;
		}

		printf(
			'<span id="vizorcoin-base-total" data-amount="%s" data-currency="%s" hidden></span>',
			esc_attr( (string) WC()->cart->get_total( 'edit' ) ),
			esc_attr( get_woocommerce_currency() )
		);
	}

	/**
	 * Encola los assets solo en la página de checkout.
	 */
	public function enqueue() {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
			return;
		}
		if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url() ) {
			return; // order-received, order-pay, etc.
		}

		$settings = $this->plugin->get_settings();
		if ( empty( $settings['enabled'] ) || empty( $settings['modal_enabled'] ) ) {
			return;
		}
		if ( ! $this->plugin->is_active() ) {
			return;
		}

		wp_enqueue_style(
			'vizorcoin',
			VIZORCOIN_URL . 'assets/css/vizorcoin.css',
			array(),
			VIZORCOIN_VERSION
		);

		wp_enqueue_script(
			'vizorcoin-checkout',
			VIZORCOIN_URL . 'assets/js/vizorcoin-checkout.js',
			array(),
			VIZORCOIN_VERSION,
			true
		);

		$base    = $this->plugin->base_currency();
		$target  = $this->plugin->display_currency();
		$rate    = $this->plugin->get_rate();
		$message = str_replace(
			array( '{base_currency}', '{display_currency}' ),
			array( $base, $target ),
			(string) $settings['modal_text']
		);

		wp_localize_script(
			'vizorcoin-checkout',
			'VizorCoinData',
			array(
				'baseCurrency'   => $base,
				'targetCurrency' => $target,
				'baseSymbol'     => html_entity_decode( get_woocommerce_currency_symbol( $base ), ENT_QUOTES, 'UTF-8' ),
				'targetSymbol'   => html_entity_decode( get_woocommerce_currency_symbol( $target ), ENT_QUOTES, 'UTF-8' ),
				'rate'           => $rate ? (float) $rate : 0,
				'decimals'       => 0,
				'thousandSep'    => wc_get_price_thousand_separator(),
				'decimalSep'     => wc_get_price_decimal_separator(),
				'cartUrl'        => esc_url_raw( rest_url( 'wc/store/v1/cart' ) ),
				'title'          => sanitize_text_field( $settings['modal_title'] ),
				'message'        => wp_kses_post( $message ),
				'accept'         => __( 'Aceptar y continuar', 'vizorcoin' ),
				'cancel'         => __( 'Cancelar', 'vizorcoin' ),
			)
		);
	}
}
