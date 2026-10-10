<?php
/**
 * Conversión de precios en el frontend (solo presentación).
 *
 * @package VizorCoin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Convierte el HTML de precios mostrados en el catálogo y la ficha de producto.
 *
 * No modifica precios, totales ni la moneda real de WooCommerce: únicamente
 * reformatea la salida de wc_price() para la vista pública.
 */
class VizorCoin_Frontend {

	/**
	 * Referencia al plugin.
	 *
	 * @var VizorCoin
	 */
	private $plugin;

	/**
	 * Bandera anti-recursión.
	 *
	 * @var bool
	 */
	private $converting = false;

	/**
	 * Valores ya convertidos en esta petición (anti-doble-conversión).
	 *
	 * @var float[]
	 */
	private $converted_values = array();

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
		// Prioridad tardía para que otros plugins ya hayan formateado el precio.
		add_filter( 'wc_price', array( $this, 'filter_wc_price' ), 100, 4 );
	}

	/**
	 * Determina si corresponde convertir en el contexto actual.
	 *
	 * @return bool
	 */
	public function should_convert() {
		if ( ! $this->plugin->is_active() ) {
			return false;
		}

		/*
		 * Carrito lateral/flotante de Modern Cart. Se renderiza tanto en el
		 * frontend (wp_footer) como vía admin-ajax, donde is_admin() es true.
		 * Lo detectamos por su action de render para cubrir ambos casos.
		 */
		$settings = $this->plugin->get_settings();
		if ( ! empty( $settings['convert_checkout'] ) && doing_action( 'moderncart_slide_out_content' ) ) {
			/** This filter is documented below. */
			return (bool) apply_filters( 'vizorcoin_should_convert', true );
		}

		if ( is_admin() ) {
			return false;
		}
		if ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) {
			return false;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return false;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return false;
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return false;
		}

		// Emails de WooCommerce.
		if ( did_action( 'woocommerce_email_header' ) ) {
			return false;
		}

		$settings = $this->plugin->get_settings();

		// Carrito / checkout (o su AJAX de refresco): conversión de presentación.
		if ( ! empty( $settings['convert_checkout'] ) && $this->is_checkout_context() ) {
			/**
			 * Filtra si se debe convertir en el contexto actual.
			 *
			 * @param bool $should Convertir o no.
			 */
			return (bool) apply_filters( 'vizorcoin_should_convert', true );
		}

		// El resto de peticiones AJAX no se convierten.
		if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) {
			return false;
		}

		// Nunca convertir en carrito, checkout, cuenta ni endpoints de pedido.
		if ( function_exists( 'is_cart' ) && is_cart() ) {
			return false;
		}
		if ( function_exists( 'is_checkout' ) && is_checkout() ) {
			return false;
		}
		if ( function_exists( 'is_account_page' ) && is_account_page() ) {
			return false;
		}
		if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url() ) {
			return false;
		}

		// Solo catálogo (tienda, categorías, etiquetas) y ficha de producto,
		// salvo que el usuario active el ámbito ampliado (home y otras páginas).
		if ( empty( $settings['broaden'] ) && ! $this->is_catalog_context() ) {
			return false;
		}

		/**
		 * Filtra si se debe convertir en el contexto actual.
		 *
		 * @param bool $should Convertir o no.
		 */
		return (bool) apply_filters( 'vizorcoin_should_convert', true );
	}

	/**
	 * Indica si estamos en el carrito/checkout o en su AJAX de refresco.
	 *
	 * @return bool
	 */
	private function is_checkout_context() {
		// Endpoints de pago/confirmación: convertir (presentación).
		if ( function_exists( 'is_wc_endpoint_url' ) ) {
			if ( is_wc_endpoint_url( 'order-pay' ) || is_wc_endpoint_url( 'order-received' ) ) {
				return true;
			}
		}

		// Confirmación de CartFlows (Thank You).
		if ( function_exists( '_is_wcf_thankyou_type' ) && _is_wcf_thankyou_type() ) {
			return true;
		}

		// AJAX de refresco del resumen de checkout (CartFlows).
		if ( function_exists( '_is_wcf_doing_checkout_ajax' ) && _is_wcf_doing_checkout_ajax() ) {
			return true;
		}

		// AJAX de refresco del resumen de checkout (WooCommerce).
		if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() && isset( $_REQUEST['wc-ajax'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$action = sanitize_key( wp_unslash( $_REQUEST['wc-ajax'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( 'update_order_review' === $action ) {
				return true;
			}
		}

		// Cualquier otro endpoint: no convertir.
		if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url() ) {
			return false;
		}

		if ( function_exists( 'is_cart' ) && is_cart() ) {
			return true;
		}
		if ( function_exists( 'is_checkout' ) && is_checkout() ) {
			return true;
		}

		return false;
	}

	/**
	 * Indica si estamos en el catálogo o en una ficha de producto.
	 *
	 * @return bool
	 */
	private function is_catalog_context() {
		if ( ! function_exists( 'is_woocommerce' ) ) {
			return false;
		}
		return (
			is_woocommerce()
			|| is_shop()
			|| is_product()
			|| is_product_taxonomy()
			|| is_product_category()
			|| is_product_tag()
		);
	}

	/**
	 * Reformatea el precio usando la moneda de visualización.
	 *
	 * @param string $formatted       HTML ya formateado.
	 * @param float  $price           Precio original (moneda base).
	 * @param array  $args            Argumentos de wc_price().
	 * @param float  $unformatted     Precio sin formato.
	 * @return string
	 */
	public function filter_wc_price( $formatted, $price, $args, $unformatted = 0 ) {
		if ( $this->converting ) {
			return $formatted;
		}
		if ( ! $this->should_convert() ) {
			return $formatted;
		}

		$base   = $this->plugin->base_currency();
		$target = $this->plugin->display_currency();

		// Si el precio ya viene en otra moneda, no lo tocamos.
		if ( isset( $args['currency'] ) && $args['currency']
			&& strtoupper( (string) $args['currency'] ) !== $base ) {
			return $formatted;
		}

		/*
		 * El precio puede llegar como número (150.00) o ya formateado con los
		 * separadores de la tienda (p. ej. "150,000.00"). Lo normalizamos.
		 */
		$amount = $this->normalize_amount( $price );
		if ( null === $amount ) {
			return $formatted;
		}

		// Anti-doble-conversión: si el importe entrante ya es un valor que
		// produjimos, no reconvertir (el tema u otro render vuelve a pasar el
		// precio ya convertido por wc_price).
		if ( in_array( round( $amount, 4 ), $this->converted_values, true ) ) {
			return $formatted;
		}

		$rate = $this->plugin->get_rate();
		if ( ! $rate || $rate <= 0 ) {
			return $formatted;
		}

		$converted = $amount * (float) $rate;

		// Red de seguridad: nunca mostrar $0 por una conversión.
		if ( $amount > 0 && 0.0 === round( $converted, 0 ) ) {
			return $formatted;
		}

		if ( isset( $_GET['vcdbg'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				sprintf(
					'VC wc_price price=%s amount=%s rate=%s converted=%s currency=%s',
					wp_json_encode( $price ),
					$amount,
					$rate,
					$converted,
					isset( $args['currency'] ) ? $args['currency'] : ''
				)
			);
		}

		$this->converting = true;
		$new = wc_price( $converted, array( 'currency' => $target, 'decimals' => 0 ) );
		$this->converting = false;

		$this->converted_values[] = round( $converted, 4 );

		return $new;
	}

	/**
	 * Normaliza un importe que puede venir como número o ya formateado.
	 *
	 * @param mixed $price Precio.
	 * @return float|null
	 */
	private function normalize_amount( $price ) {
		if ( is_int( $price ) || is_float( $price ) ) {
			return (float) $price;
		}
		if ( ! is_string( $price ) ) {
			return null;
		}

		$price = trim( $price );
		if ( '' === $price ) {
			return null;
		}

		$thousand = function_exists( 'wc_get_price_thousand_separator' ) ? wc_get_price_thousand_separator() : ',';
		$decimal  = function_exists( 'wc_get_price_decimal_separator' ) ? wc_get_price_decimal_separator() : '.';

		/*
		 * Si el importe viene formateado con los separadores de la tienda
		 * (p. ej. "150.000" en tiendas con "." de miles), NO usar is_numeric()
		 * porque interpretaría el punto de miles como decimal (150 en vez de
		 * 150000). Detectamos el patrón y quitamos el separador de miles.
		 */
		if ( '' !== $thousand ) {
			$pattern = '/^\d{1,3}(' . preg_quote( $thousand, '/' ) . '\d{3})*(' . preg_quote( $decimal, '/' ) . '\d+)?$/';
			if ( preg_match( $pattern, $price ) ) {
				$value = str_replace( $thousand, '', $price );
				if ( '' !== $decimal && '.' !== $decimal ) {
					$value = str_replace( $decimal, '.', $value );
				}
				return is_numeric( $value ) ? (float) $value : null;
			}
		}

		// Número crudo (p. ej. "150000" o "150000.00").
		if ( is_numeric( $price ) ) {
			return (float) $price;
		}

		// Último intento: normalizar el separador decimal de la tienda.
		if ( '' !== $decimal && '.' !== $decimal ) {
			$price = str_replace( $decimal, '.', $price );
		}
		return is_numeric( $price ) ? (float) $price : null;
	}
}
