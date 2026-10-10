( function () {
	'use strict';

	if ( typeof window.VizorCoinData === 'undefined' ) {
		return;
	}

	var D = window.VizorCoinData;

	var PLACE_ORDER_SELECTORS = [
		'#place_order',
		'.wc-block-components-checkout-place-order-button',
		'.wc-block-checkout__place-order-button',
		'.wc-block-components-button.wc-block-checkout__place-order-button'
	].join( ',' );

	var confirmed = false;
	var resetTimer = null;
	var lastButton = null;
	var modal = null;
	var lastFocused = null;

	function escapeHtml( value ) {
		return String( value ).replace( /[&<>"']/g, function ( ch ) {
			return {
				'&': '&amp;',
				'<': '&lt;',
				'>': '&gt;',
				'"': '&quot;',
				"'": '&#39;'
			}[ ch ];
		} );
	}

	function formatNumber( amount, decimals ) {
		decimals = parseInt( decimals, 10 );
		if ( isNaN( decimals ) || decimals < 0 ) {
			decimals = 0;
		}
		amount = parseFloat( amount );
		if ( isNaN( amount ) ) {
			amount = 0;
		}
		var negative = amount < 0;
		amount = Math.abs( amount );
		var fixed = amount.toFixed( decimals );
		var parts = fixed.split( '.' );
		var intPart = parts[ 0 ].replace( /\B(?=(\d{3})+(?!\d))/g, D.thousandSep || ',' );
		var out = intPart;
		if ( decimals > 0 ) {
			out += ( D.decimalSep || '.' ) + parts[ 1 ];
		}
		return ( negative ? '-' : '' ) + out;
	}

	function formatMoney( amount, symbol, decimals ) {
		return ( symbol || '' ) + formatNumber( amount, decimals );
	}

	function getCartData() {
		try {
			if ( window.wp && wp.data && typeof wp.data.select === 'function' ) {
				var cart = wp.data.select( 'wc/store/cart' );
				if ( cart && typeof cart.getCartData === 'function' ) {
					return cart.getCartData();
				}
			}
		} catch ( e ) {
			// Silencioso: usamos el fallback de DOM.
		}
		return null;
	}

	function parseStoreApiData( data ) {
		if ( data && data.totals && typeof data.totals.total_price !== 'undefined' ) {
			var minorUnit = ( data.currency && typeof data.currency.currency_minor_unit !== 'undefined' )
				? parseInt( data.currency.currency_minor_unit, 10 )
				: 2;
			var rawTotal = parseInt( data.totals.total_price, 10 );
			if ( ! isNaN( rawTotal ) ) {
				return rawTotal / Math.pow( 10, minorUnit );
			}
		}
		return null;
	}

	function getDomTotal() {
		var el = document.querySelector( '.wc-block-components-totals-footer-item .wc-block-components-totals-item__value' )
			|| document.querySelector( '.order-total .amount' )
			|| document.querySelector( '.wc-block-components-totals-item__value' );

		if ( el ) {
			var text = el.textContent || '';
			text = text.replace( /[^\d.,-]/g, '' );
			if ( D.thousandSep ) {
				text = text.split( D.thousandSep ).join( '' );
			}
			if ( D.decimalSep && D.decimalSep !== '.' ) {
				text = text.replace( D.decimalSep, '.' );
			}
			var parsed = parseFloat( text );
			if ( ! isNaN( parsed ) ) {
				return parsed;
			}
		}

		return null;
	}

	/*
	 * Obtiene el total REAL en la moneda base de la tienda (COP) de forma
	 * síncrona: primero el elemento oculto del resumen (clásico/CartFlows),
	 * luego el store de bloques y, por último, el DOM. Sin llamadas de red.
	 */
	function resolveBaseTotal( callback ) {
		// Total del pedido en la página de pago (order-pay), provisto por el servidor.
		if ( D.baseTotal !== null && typeof D.baseTotal !== 'undefined' ) {
			var forced = parseFloat( D.baseTotal );
			if ( ! isNaN( forced ) ) {
				callback( forced );
				return;
			}
		}

		var hidden = document.getElementById( 'vizorcoin-base-total' );
		if ( hidden ) {
			var raw = parseFloat( hidden.getAttribute( 'data-amount' ) );
			if ( ! isNaN( raw ) ) {
				callback( raw );
				return;
			}
		}

		var store = parseStoreApiData( getCartData() );
		if ( store !== null ) {
			callback( store );
			return;
		}

		callback( getDomTotal() );
	}

	function fillAmounts( container, total ) {
		container.innerHTML = '';

		var hasTotal = ( total !== null && typeof total !== 'undefined' && ! isNaN( total ) );

		var rowBase = document.createElement( 'div' );
		rowBase.className = 'vizorcoin-modal__row';
		var baseText = hasTotal ? formatMoney( total, D.baseSymbol, D.decimals ) : '—';
		rowBase.innerHTML =
			'<span class="vizorcoin-modal__label">' + escapeHtml( D.baseCurrency ) + '</span>' +
			'<strong class="vizorcoin-modal__value">' + escapeHtml( baseText ) + '</strong>';
		container.appendChild( rowBase );

		if ( hasTotal && D.rate > 0 && D.targetCurrency ) {
			var converted = total * D.rate;
			var rowTarget = document.createElement( 'div' );
			rowTarget.className = 'vizorcoin-modal__row vizorcoin-modal__row--converted';
			rowTarget.innerHTML =
				'<span class="vizorcoin-modal__label">' + escapeHtml( D.targetCurrency ) + '</span>' +
				'<strong class="vizorcoin-modal__value">' +
				escapeHtml( formatMoney( converted, D.targetSymbol, D.decimals ) ) +
				'</strong>';
			container.appendChild( rowTarget );
		}
	}

	function buildModal() {
		var overlay = document.createElement( 'div' );
		overlay.className = 'vizorcoin-overlay';
		overlay.setAttribute( 'role', 'dialog' );
		overlay.setAttribute( 'aria-modal', 'true' );
		overlay.style.display = 'none';

		var box = document.createElement( 'div' );
		box.className = 'vizorcoin-modal';

		var title = document.createElement( 'h2' );
		title.className = 'vizorcoin-modal__title';
		title.textContent = D.title || '';

		var message = document.createElement( 'div' );
		message.className = 'vizorcoin-modal__message';
		message.innerHTML = D.message || '';

		var amounts = document.createElement( 'div' );
		amounts.className = 'vizorcoin-modal__amounts';

		var actions = document.createElement( 'div' );
		actions.className = 'vizorcoin-modal__actions';

		var cancel = document.createElement( 'button' );
		cancel.type = 'button';
		cancel.className = 'vizorcoin-btn vizorcoin-btn--ghost';
		cancel.textContent = D.cancel || 'Cancelar';

		var accept = document.createElement( 'button' );
		accept.type = 'button';
		accept.className = 'vizorcoin-btn vizorcoin-btn--primary';
		accept.textContent = D.accept || 'Aceptar';

		actions.appendChild( cancel );
		actions.appendChild( accept );

		box.appendChild( title );
		box.appendChild( message );
		box.appendChild( amounts );
		box.appendChild( actions );
		overlay.appendChild( box );
		document.body.appendChild( overlay );

		accept.addEventListener( 'click', function ( event ) {
			event.stopPropagation();
			acceptOrder();
		} );
		cancel.addEventListener( 'click', function ( event ) {
			event.stopPropagation();
			closeModal();
		} );
		overlay.addEventListener( 'click', function ( event ) {
			if ( event.target === overlay ) {
				closeModal();
			}
		} );

		return {
			overlay: overlay,
			accept: accept,
			cancel: cancel,
			amounts: amounts
		};
	}

	function openModal( button ) {
		if ( ! modal ) {
			modal = buildModal();
		}
		lastButton = button;
		resolveBaseTotal( function ( total ) {
			fillAmounts( modal.amounts, total );
		} );
		modal.overlay.style.display = 'flex';
		lastFocused = document.activeElement;
		document.body.classList.add( 'vizorcoin-modal-open' );
		modal.accept.focus();
	}

	function closeModal() {
		if ( ! modal ) {
			return;
		}
		modal.overlay.style.display = 'none';
		document.body.classList.remove( 'vizorcoin-modal-open' );
		if ( lastFocused && typeof lastFocused.focus === 'function' ) {
			lastFocused.focus();
		}
	}

	function acceptOrder() {
		var target = lastButton;
		closeModal();
		if ( ! target ) {
			return;
		}

		confirmed = true;
		if ( resetTimer ) {
			window.clearTimeout( resetTimer );
		}

		try {
			if ( target.tagName === 'FORM' ) {
				target.submit();
			} else {
				target.click();
			}
		} catch ( e ) {
			confirmed = false;
		}

		// Si el pago no llegó a redirigir (p. ej. error de validación),
		// permitimos que el modal vuelva a aparecer tras unos segundos.
		resetTimer = window.setTimeout( function () {
			confirmed = false;
		}, 6000 );
	}

	function onDocumentClick( event ) {
		if ( confirmed ) {
			return;
		}
		var target = event.target;
		if ( ! target || typeof target.closest !== 'function' ) {
			return;
		}
		if ( target.closest( '.vizorcoin-overlay' ) ) {
			return;
		}
		var button = target.closest( PLACE_ORDER_SELECTORS );
		if ( ! button ) {
			return;
		}
		if ( modal && modal.overlay.style.display === 'flex' ) {
			return;
		}

		event.preventDefault();
		event.stopPropagation();
		if ( typeof event.stopImmediatePropagation === 'function' ) {
			event.stopImmediatePropagation();
		}

		openModal( button );
	}

	document.addEventListener( 'click', onDocumentClick, true );

	/*
	 * Respaldo para la página de pago (order-pay): su formulario tiene id
	 * "order_review" y el botón lo controla la pasarela (p. ej. Wompi). Si el
	 * clic no coincide con los selectores, interceptamos el envío del formulario.
	 */
	function onFormSubmit( event ) {
		var form = event.target;
		if ( ! form || form.id !== 'order_review' ) {
			return;
		}
		if ( confirmed ) {
			return;
		}
		if ( modal && modal.overlay.style.display === 'flex' ) {
			event.preventDefault();
			return;
		}

		event.preventDefault();
		event.stopPropagation();
		if ( typeof event.stopImmediatePropagation === 'function' ) {
			event.stopImmediatePropagation();
		}

		openModal( event.submitter || form );
	}

	document.addEventListener( 'submit', onFormSubmit, true );

	document.addEventListener( 'keydown', function ( event ) {
		if ( ( event.key === 'Escape' || event.key === 'Esc' ) && modal && modal.overlay.style.display === 'flex' ) {
			closeModal();
		}
	} );
} )();
