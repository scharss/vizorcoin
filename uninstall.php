<?php
/**
 * Limpieza al desinstalar VizorCoin.
 *
 * @package VizorCoin
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'vizorcoin_settings' );
delete_option( 'vizorcoin_last_rate' );

$vizorcoin_keys = get_option( 'vizorcoin_rate_keys', array() );
if ( is_array( $vizorcoin_keys ) ) {
	foreach ( $vizorcoin_keys as $vizorcoin_key ) {
		delete_transient( $vizorcoin_key );
	}
}
delete_option( 'vizorcoin_rate_keys' );
