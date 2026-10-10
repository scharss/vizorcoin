<?php
/**
 * Obtención y caché de tasas de cambio.
 *
 * @package VizorCoin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gestiona las tasas de cambio (Yahoo Finance + open.er-api.com + manual).
 */
class VizorCoin_Rates {

	/**
	 * Referencia al plugin.
	 *
	 * @var VizorCoin
	 */
	private $plugin;

	/**
	 * Prefijo de los transients de tasa.
	 *
	 * @var string
	 */
	const PREFIX = 'vizorcoin_rate_';

	/**
	 * Clave de la opción con la lista de transients creados.
	 *
	 * @var string
	 */
	const KEYS_OPTION = 'vizorcoin_rate_keys';

	/**
	 * Última fuente usada.
	 *
	 * @var string
	 */
	public $last_source = '';

	/**
	 * Constructor.
	 *
	 * @param VizorCoin $plugin Plugin.
	 */
	public function __construct( $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Devuelve la tasa (destino por 1 unidad de la base).
	 *
	 * @param string $base   Moneda base.
	 * @param string $target Moneda destino.
	 * @param bool   $force  Ignorar caché.
	 * @return float|false
	 */
	public function get_rate( $base, $target, $force = false ) {
		$base   = strtoupper( (string) $base );
		$target = strtoupper( (string) $target );

		if ( '' === $base || '' === $target ) {
			return false;
		}
		if ( $base === $target ) {
			return 1.0;
		}

		$settings = $this->plugin->get_settings();

		// Override manual (tiene prioridad absoluta).
		if ( '' !== $settings['manual_rate'] && is_numeric( $settings['manual_rate'] ) && (float) $settings['manual_rate'] > 0 ) {
			$this->last_source = 'manual';
			return (float) $settings['manual_rate'];
		}

		$key = self::PREFIX . $base . '_' . $target;

		if ( ! $force ) {
			$cached = get_transient( $key );
			if ( false !== $cached && is_numeric( $cached ) && (float) $cached > 0 ) {
				return (float) $cached;
			}
		}

		$rate = $this->fetch_rate( $base, $target, $settings['rate_source'] );

		if ( $rate && $rate > 0 ) {
			$ttl = max( 60, absint( $settings['cache_ttl'] ) * MINUTE_IN_SECONDS );
			set_transient( $key, $rate, $ttl );
			$this->register_key( $key );
			update_option(
				'vizorcoin_last_rate',
				array(
					'base'   => $base,
					'target' => $target,
					'rate'   => (float) $rate,
					'time'   => time(),
					'source' => $this->last_source,
				),
				false
			);
			return (float) $rate;
		}

		// Sin conexión: intentamos reutilizar la última tasa conocida.
		$last = get_option( 'vizorcoin_last_rate' );
		if ( is_array( $last ) && isset( $last['base'], $last['target'], $last['rate'] )
			&& strtoupper( (string) $last['base'] ) === $base
			&& strtoupper( (string) $last['target'] ) === $target
			&& (float) $last['rate'] > 0 ) {
			$this->last_source = 'stale';
			return (float) $last['rate'];
		}

		return false;
	}

	/**
	 * Consulta las fuentes configuradas.
	 *
	 * @param string $base   Base.
	 * @param string $target Destino.
	 * @param string $source auto|yahoo|er_api.
	 * @return float|false
	 */
	private function fetch_rate( $base, $target, $source ) {
		if ( 'auto' === $source ) {
			$sources = array( 'yahoo', 'er_api' );
		} else {
			$sources = array( $source );
		}

		foreach ( $sources as $candidate ) {
			if ( 'yahoo' === $candidate ) {
				$rate = $this->fetch_yahoo( $base, $target );
				if ( $rate ) {
					$this->last_source = 'yahoo';
					return $rate;
				}
			}
			if ( 'er_api' === $candidate ) {
				$rate = $this->fetch_er_api( $base, $target );
				if ( $rate ) {
					$this->last_source = 'er_api';
					return $rate;
				}
			}
		}
		return false;
	}

	/**
	 * Yahoo Finance (endpoint no oficial de gráficos).
	 *
	 * @param string $base   Base.
	 * @param string $target Destino.
	 * @return float|false
	 */
	private function fetch_yahoo( $base, $target ) {
		// Par directo: BASE+TARGET => "cuántos TARGET por 1 BASE".
		$direct = $this->yahoo_price( $base . $target );

		// Yahoo redondea mucho los pares con valores diminutos (p. ej. COP->USD
		// devuelve 0.0003 y se pierde precisión). Si el valor directo es muy
		// pequeño, usamos el par inverso (más grande) y lo invertimos.
		if ( $direct && $direct >= 0.01 ) {
			return $direct;
		}

		$inverse = $this->yahoo_price( $target . $base );
		if ( $inverse && $inverse > 0 ) {
			$rate = 1 / $inverse;
			if ( $rate > 0 ) {
				return $rate;
			}
		}

		return $direct ? $direct : false;
	}

	/**
	 * Consulta un único símbolo en Yahoo.
	 *
	 * @param string $symbol Símbolo FX (p.ej. USDCOP).
	 * @return float|false
	 */
	private function yahoo_price( $symbol ) {
		$url  = 'https://query1.finance.yahoo.com/v8/finance/chart/' . rawurlencode( strtoupper( $symbol ) . '=X' ) . '?interval=1d&range=1d';
		$json = $this->http_get_json( $url, array( 'User-Agent' => 'Mozilla/5.0 (compatible; VizorCoin/1.0; +https://byterock.xyz/)' ) );

		if ( is_array( $json )
			&& isset( $json['chart']['result'][0]['meta']['regularMarketPrice'] ) ) {
			$price = (float) $json['chart']['result'][0]['meta']['regularMarketPrice'];
			if ( $price > 0 ) {
				return $price;
			}
		}
		return false;
	}

	/**
	 * open.er-api.com (libre, sin API key).
	 *
	 * @param string $base   Base.
	 * @param string $target Destino.
	 * @return float|false
	 */
	private function fetch_er_api( $base, $target ) {
		$url  = 'https://open.er-api.com/v6/latest/' . rawurlencode( strtoupper( $base ) );
		$json = $this->http_get_json( $url );

		if ( is_array( $json ) && isset( $json['rates'][ strtoupper( $target ) ] ) ) {
			$rate = (float) $json['rates'][ strtoupper( $target ) ];
			if ( $rate > 0 ) {
				return $rate;
			}
		}
		return false;
	}

	/**
	 * Realiza una petición GET y devuelve el JSON decodificado.
	 *
	 * @param string $url     URL.
	 * @param array  $headers Cabeceras extra.
	 * @return array|null
	 */
	private function http_get_json( $url, $headers = array() ) {
		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 12,
				'headers' => array_merge(
					array( 'Accept' => 'application/json' ),
					$headers
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return null;
		}
		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$body = wp_remote_retrieve_body( $response );
		$json = json_decode( $body, true );

		return is_array( $json ) ? $json : null;
	}

	/**
	 * Guarda la clave del transient creado para poderlimpiarla luego.
	 *
	 * @param string $key Clave.
	 */
	private function register_key( $key ) {
		$keys = get_option( self::KEYS_OPTION, array() );
		if ( ! is_array( $keys ) ) {
			$keys = array();
		}
		if ( ! in_array( $key, $keys, true ) ) {
			$keys[] = $key;
			update_option( self::KEYS_OPTION, $keys, false );
		}
	}

	/**
	 * Elimina todas las tasas cacheadas.
	 */
	public function clear_cache() {
		$keys = get_option( self::KEYS_OPTION, array() );
		if ( is_array( $keys ) ) {
			foreach ( $keys as $key ) {
				delete_transient( $key );
			}
		}
		update_option( self::KEYS_OPTION, array(), false );
	}
}
