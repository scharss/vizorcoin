<?php
/**
 * Menú de administración y ajustes de VizorCoin.
 *
 * @package VizorCoin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gestiona el menú, la pantalla de ajustes y el saneado.
 */
class VizorCoin_Settings {

	/**
	 * Referencia al plugin.
	 *
	 * @var VizorCoin
	 */
	private $plugin;

	/**
	 * Slug del menú.
	 *
	 * @var string
	 */
	const SLUG = 'vizorcoin';

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
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_post_vizorcoin_refresh', array( $this, 'handle_refresh' ) );
	}

	/**
	 * Añade el menú de nivel superior.
	 */
	public function add_menu() {
		add_menu_page(
			__( 'VizorCoin', 'vizorcoin' ),
			__( 'VizorCoin', 'vizorcoin' ),
			'manage_woocommerce',
			self::SLUG,
			array( $this, 'render_page' ),
			'dashicons-money-alt',
			55.9
		);
	}

	/**
	 * Registra la opción y su saneado.
	 */
	public function register_settings() {
		register_setting(
			'vizorcoin_group',
			VizorCoin::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => $this->plugin->defaults(),
			)
		);
	}

	/**
	 * Sanea la configuración enviada.
	 *
	 * @param array $input Datos del formulario.
	 * @return array
	 */
	public function sanitize( $input ) {
		if ( ! is_array( $input ) ) {
			$input = array();
		}

		$defaults = $this->plugin->defaults();
		$output   = $defaults;

		$output['enabled']       = ! empty( $input['enabled'] ) ? 1 : 0;
		$output['modal_enabled'] = ! empty( $input['modal_enabled'] ) ? 1 : 0;
		$output['broaden']       = ! empty( $input['broaden'] ) ? 1 : 0;
		$output['convert_checkout'] = ! empty( $input['convert_checkout'] ) ? 1 : 0;

		$currencies = function_exists( 'get_woocommerce_currencies' ) ? array_keys( get_woocommerce_currencies() ) : array();
		$display    = isset( $input['display_currency'] ) ? strtoupper( sanitize_text_field( $input['display_currency'] ) ) : 'USD';
		if ( empty( $currencies ) || in_array( $display, $currencies, true ) ) {
			$output['display_currency'] = $display ? $display : 'USD';
		}

		$source                  = isset( $input['rate_source'] ) ? sanitize_key( $input['rate_source'] ) : 'auto';
		$output['rate_source']   = in_array( $source, array( 'auto', 'yahoo', 'er_api' ), true ) ? $source : 'auto';
		$output['cache_ttl']     = isset( $input['cache_ttl'] ) ? max( 1, absint( $input['cache_ttl'] ) ) : 60;
		$output['manual_rate']   = ( isset( $input['manual_rate'] ) && is_numeric( $input['manual_rate'] ) && (float) $input['manual_rate'] > 0 )
			? (float) $input['manual_rate']
			: '';
		$output['modal_title']   = isset( $input['modal_title'] ) ? sanitize_text_field( $input['modal_title'] ) : $defaults['modal_title'];
		$output['modal_text']    = isset( $input['modal_text'] ) ? sanitize_textarea_field( $input['modal_text'] ) : $defaults['modal_text'];

		// Al cambiar ajustes relevantes, refrescamos la caché de tasas.
		$this->plugin->rates->clear_cache();

		return $output;
	}

	/**
	 * Fuerza la actualización de la tasa desde el botón "Actualizar tasa".
	 */
	public function handle_refresh() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'No tienes permisos para realizar esta acción.', 'vizorcoin' ) );
		}
		check_admin_referer( 'vizorcoin_refresh' );

		$this->plugin->rates->clear_cache();
		$this->plugin->rates->get_rate( $this->plugin->base_currency(), $this->plugin->display_currency(), true );

		wp_safe_redirect( add_query_arg( 'refreshed', '1', admin_url( 'admin.php?page=' . self::SLUG ) ) );
		exit;
	}

	/**
	 * Renderiza la pantalla de ajustes.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$settings   = $this->plugin->get_settings();
		$currencies = function_exists( 'get_woocommerce_currencies' ) ? get_woocommerce_currencies() : array();
		$base       = $this->plugin->base_currency();
		$last       = get_option( 'vizorcoin_last_rate' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'VizorCoin', 'vizorcoin' ); ?></h1>

			<?php if ( isset( $_GET['refreshed'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Tasa de cambio actualizada.', 'vizorcoin' ); ?></p>
				</div>
			<?php endif; ?>

			<p>
				<?php
				printf(
					/* translators: %s: moneda base de la tienda. */
					esc_html__( 'Moneda real de la tienda (WooCommerce): %s. VizorCoin solo cambia cómo se muestran los precios en el catálogo y la ficha de producto; los pagos se procesan siempre en la moneda de la tienda.', 'vizorcoin' ),
					'<strong>' . esc_html( $base ) . '</strong>'
				);
				?>
			</p>

			<form method="post" action="options.php">
				<?php settings_fields( 'vizorcoin_group' ); ?>

				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><?php esc_html_e( 'Activar conversión visual', 'vizorcoin' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="vizorcoin_settings[enabled]" value="1" <?php checked( ! empty( $settings['enabled'] ) ); ?> />
									<?php esc_html_e( 'Mostrar los precios del catálogo en la moneda de visualización.', 'vizorcoin' ); ?>
								</label>
							</td>
						</tr>

						<tr>
							<th scope="row"><label for="vizorcoin_display_currency"><?php esc_html_e( 'Moneda de visualización', 'vizorcoin' ); ?></label></th>
							<td>
								<select id="vizorcoin_display_currency" name="vizorcoin_settings[display_currency]">
									<?php foreach ( $currencies as $code => $name ) : ?>
										<option value="<?php echo esc_attr( $code ); ?>" <?php selected( strtoupper( $settings['display_currency'] ), strtoupper( $code ) ); ?>>
											<?php echo esc_html( $code . ' — ' . $name ); ?>
										</option>
									<?php endforeach; ?>
								</select>
								<p class="description"><?php esc_html_e( 'Moneda en la que verán los precios los clientes. Debe ser distinta a la moneda de la tienda.', 'vizorcoin' ); ?></p>
							</td>
						</tr>

						<tr>
							<th scope="row"><label for="vizorcoin_rate_source"><?php esc_html_e( 'Fuente de la tasa', 'vizorcoin' ); ?></label></th>
							<td>
								<select id="vizorcoin_rate_source" name="vizorcoin_settings[rate_source]">
									<option value="auto" <?php selected( $settings['rate_source'], 'auto' ); ?>><?php esc_html_e( 'Automática (Yahoo + respaldo er-api)', 'vizorcoin' ); ?></option>
									<option value="yahoo" <?php selected( $settings['rate_source'], 'yahoo' ); ?>><?php esc_html_e( 'Solo Yahoo Finance', 'vizorcoin' ); ?></option>
									<option value="er_api" <?php selected( $settings['rate_source'], 'er_api' ); ?>><?php esc_html_e( 'Solo open.er-api.com', 'vizorcoin' ); ?></option>
								</select>
							</td>
						</tr>

						<tr>
							<th scope="row"><label for="vizorcoin_cache_ttl"><?php esc_html_e( 'Caché (minutos)', 'vizorcoin' ); ?></label></th>
							<td>
								<input type="number" min="1" step="1" id="vizorcoin_cache_ttl" name="vizorcoin_settings[cache_ttl]" value="<?php echo esc_attr( $settings['cache_ttl'] ); ?>" class="small-text" />
								<p class="description"><?php esc_html_e( 'Tiempo durante el cual se reutiliza la tasa obtenida antes de volver a consultarla.', 'vizorcoin' ); ?></p>
							</td>
						</tr>

						<tr>
							<th scope="row"><label for="vizorcoin_manual_rate"><?php esc_html_e( 'Tasa manual (opcional)', 'vizorcoin' ); ?></label></th>
							<td>
								<input type="text" inputmode="decimal" id="vizorcoin_manual_rate" name="vizorcoin_settings[manual_rate]" value="<?php echo esc_attr( $settings['manual_rate'] ); ?>" class="regular-text" />
								<p class="description"><?php esc_html_e( 'Si se indica, se usa esta tasa (unidades de la moneda de visualización por 1 unidad de la moneda de la tienda) y se ignoran las APIs.', 'vizorcoin' ); ?></p>
							</td>
						</tr>

						<tr>
							<th scope="row"><?php esc_html_e( 'Ámbito de conversión', 'vizorcoin' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="vizorcoin_settings[broaden]" value="1" <?php checked( ! empty( $settings['broaden'] ) ); ?> />
									<?php esc_html_e( 'Aplicar también fuera del catálogo (home y otras páginas donde se muestren productos).', 'vizorcoin' ); ?>
								</label>
								<p class="description"><?php esc_html_e( 'Si se desactiva, solo se convierten los precios en la tienda, categorías, etiquetas y la ficha de producto.', 'vizorcoin' ); ?></p>
							</td>
						</tr>

						<tr>
							<th scope="row"><?php esc_html_e( 'Carrito y checkout', 'vizorcoin' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="vizorcoin_settings[convert_checkout]" value="1" <?php checked( ! empty( $settings['convert_checkout'] ) ); ?> />
									<?php esc_html_e( 'Mostrar el carrito y el checkout en la moneda de visualización.', 'vizorcoin' ); ?>
								</label>
								<p class="description"><?php esc_html_e( 'Es solo presentación: el pedido y el pago se siguen procesando en la moneda real de la tienda.', 'vizorcoin' ); ?></p>
							</td>
						</tr>

						<tr>
							<th scope="row"><?php esc_html_e( 'Aviso en el checkout', 'vizorcoin' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="vizorcoin_settings[modal_enabled]" value="1" <?php checked( ! empty( $settings['modal_enabled'] ) ); ?> />
									<?php esc_html_e( 'Mostrar el modal de confirmación antes de pasar a la pasarela de pago.', 'vizorcoin' ); ?>
								</label>
							</td>
						</tr>

						<tr>
							<th scope="row"><label for="vizorcoin_modal_title"><?php esc_html_e( 'Título del modal', 'vizorcoin' ); ?></label></th>
							<td>
								<input type="text" id="vizorcoin_modal_title" name="vizorcoin_settings[modal_title]" value="<?php echo esc_attr( $settings['modal_title'] ); ?>" class="regular-text" />
							</td>
						</tr>

						<tr>
							<th scope="row"><label for="vizorcoin_modal_text"><?php esc_html_e( 'Texto del modal', 'vizorcoin' ); ?></label></th>
							<td>
								<textarea id="vizorcoin_modal_text" name="vizorcoin_settings[modal_text]" rows="4" class="large-text"><?php echo esc_textarea( $settings['modal_text'] ); ?></textarea>
								<p class="description">
									<?php esc_html_e( 'Puedes usar los marcadores {base_currency} y {display_currency}.', 'vizorcoin' ); ?>
								</p>
							</td>
						</tr>
					</tbody>
				</table>

				<?php submit_button(); ?>
			</form>

			<hr />

			<h2><?php esc_html_e( 'Estado de la tasa', 'vizorcoin' ); ?></h2>
			<?php if ( is_array( $last ) && isset( $last['rate'] ) ) : ?>
				<p>
					<?php
					printf(
						/* translators: 1: rate, 2: base, 3: target, 4: date, 5: source */
						esc_html__( '1 %2$s = %1$s %3$s · actualizada el %4$s · fuente: %5$s', 'vizorcoin' ),
						esc_html( number_format_i18n( (float) $last['rate'], 6 ) ),
						esc_html( $last['base'] ),
						esc_html( $last['target'] ),
						esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $last['time'] ) ),
						esc_html( $last['source'] )
					);
					?>
				</p>
			<?php else : ?>
				<p><?php esc_html_e( 'Aún no hay una tasa registrada. Activa el plugin y guarda, o pulsa "Actualizar tasa ahora".', 'vizorcoin' ); ?></p>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="vizorcoin_refresh" />
				<?php wp_nonce_field( 'vizorcoin_refresh' ); ?>
				<?php submit_button( __( 'Actualizar tasa ahora', 'vizorcoin' ), 'secondary' ); ?>
			</form>
		</div>
		<?php
	}
}
