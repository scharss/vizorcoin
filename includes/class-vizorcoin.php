<?php
/**
 * Orquestador principal de VizorCoin.
 *
 * @package VizorCoin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Clase principal (singleton).
 */
final class VizorCoin {

	/**
	 * Nombre de la opción que guarda la configuración.
	 *
	 * @var string
	 */
	const OPTION = 'vizorcoin_settings';

	/**
	 * Instancia única.
	 *
	 * @var VizorCoin|null
	 */
	private static $instance = null;

	/**
	 * Módulo de ajustes (admin).
	 *
	 * @var VizorCoin_Settings
	 */
	public $settings;

	/**
	 * Módulo de tasas de cambio.
	 *
	 * @var VizorCoin_Rates
	 */
	public $rates;

	/**
	 * Módulo de frontend (conversión de precios).
	 *
	 * @var VizorCoin_Frontend
	 */
	public $frontend;

	/**
	 * Módulo de checkout (modal).
	 *
	 * @var VizorCoin_Checkout
	 */
	public $checkout;

	/**
	 * Devuelve la instancia única.
	 *
	 * @return VizorCoin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor privado.
	 */
	private function __construct() {
		require_once VIZORCOIN_PATH . 'includes/class-vizorcoin-settings.php';
		require_once VIZORCOIN_PATH . 'includes/class-vizorcoin-rates.php';
		require_once VIZORCOIN_PATH . 'includes/class-vizorcoin-frontend.php';
		require_once VIZORCOIN_PATH . 'includes/class-vizorcoin-checkout.php';

		$this->rates    = new VizorCoin_Rates( $this );
		$this->settings = new VizorCoin_Settings( $this );
		$this->frontend = new VizorCoin_Frontend( $this );
		$this->checkout = new VizorCoin_Checkout( $this );

		$this->settings->init();
		$this->frontend->init();
		$this->checkout->init();

		add_filter(
			'plugin_action_links_' . plugin_basename( VIZORCOIN_FILE ),
			array( $this, 'action_links' )
		);
	}

	/**
	 * Añade el enlace "Ajustes" en la lista de plugins.
	 *
	 * @param array $links Enlaces existentes.
	 * @return array
	 */
	public function action_links( $links ) {
		$url = admin_url( 'admin.php?page=vizorcoin' );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Ajustes', 'vizorcoin' ) . '</a>' );
		return $links;
	}

	/**
	 * Valores por defecto de la configuración.
	 *
	 * @return array
	 */
	public function defaults() {
		return array(
			'enabled'          => 1,
			'display_currency' => 'USD',
			'rate_source'      => 'auto',
			'cache_ttl'        => 60,
			'manual_rate'      => '',
			'decimals'         => 0,
			'broaden'          => 0,
			'convert_checkout' => 1,
			'modal_enabled'    => 1,
			'modal_title'      => __( 'Confirmación de pago', 'vizorcoin' ),
			'modal_text'       => __( 'Tu pago se procesará en Pesos Colombianos COP, la moneda de la tienda. El monto final que verás en la pasarela se cobrará en COP Pesos Colombianos.', 'vizorcoin' ),
		);
	}

	/**
	 * Configuración completa (defaults + guardado).
	 *
	 * @return array
	 */
	public function get_settings() {
		$saved = get_option( self::OPTION, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		return wp_parse_args( $saved, $this->defaults() );
	}

	/**
	 * Devuelve un único ajuste.
	 *
	 * @param string $key     Clave.
	 * @param mixed  $default Valor por defecto.
	 * @return mixed
	 */
	public function get_setting( $key, $default = '' ) {
		$settings = $this->get_settings();
		return isset( $settings[ $key ] ) ? $settings[ $key ] : $default;
	}

	/**
	 * Moneda real de la tienda (WooCommerce).
	 *
	 * @return string
	 */
	public function base_currency() {
		return strtoupper( (string) get_option( 'woocommerce_currency', 'USD' ) );
	}

	/**
	 * Moneda de visualización elegida en VizorCoin.
	 *
	 * @return string
	 */
	public function display_currency() {
		return strtoupper( (string) $this->get_setting( 'display_currency', 'USD' ) );
	}

	/**
	 * Indica si el plugin debe aplicar conversión de visualización.
	 *
	 * @return bool
	 */
	public function is_active() {
		$settings = $this->get_settings();
		if ( empty( $settings['enabled'] ) ) {
			return false;
		}
		$display = $this->display_currency();
		return ( '' !== $display && $display !== $this->base_currency() );
	}

	/**
	 * Tasa actual (unidades de moneda destino por 1 unidad de la base).
	 *
	 * @return float|false
	 */
	public function get_rate() {
		if ( ! $this->is_active() ) {
			return false;
		}
		return $this->rates->get_rate( $this->base_currency(), $this->display_currency() );
	}

	/**
	 * Convierte un importe de la moneda base a la de visualización.
	 *
	 * @param float      $amount Importe en moneda base.
	 * @param float|null $rate   Tasa opcional.
	 * @return float|false
	 */
	public function convert( $amount, $rate = null ) {
		if ( null === $rate ) {
			$rate = $this->get_rate();
		}
		if ( ! $rate || $rate <= 0 ) {
			return false;
		}
		return (float) $amount * (float) $rate;
	}
}
