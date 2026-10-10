( function () {
	'use strict';

	var data = window.VizorCoinThankyou;

	if ( ! data || ! data.targetCurrency ) {
		return;
	}

	function swapBadges() {
		var badges = document.querySelectorAll( '.wcf-ic-cart-totals__currency-badge' );
		for ( var i = 0; i < badges.length; i++ ) {
			if ( badges[ i ].textContent !== data.targetCurrency ) {
				badges[ i ].textContent = data.targetCurrency;
			}
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', swapBadges );
	} else {
		swapBadges();
	}

	// Reintento por si el contenido se pinta de forma diferida.
	window.setTimeout( swapBadges, 800 );
} )();
