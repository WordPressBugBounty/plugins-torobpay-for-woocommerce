/**
 * PDP TorobPay promo lightbox: copy code and hide the offer after expiry.
 */
jQuery(function($) {
	'use strict';

	var i18n = ( window.wp && window.wp.i18n ) ? window.wp.i18n : {};
	var __ = i18n.__ || function( s ) { return s; };

	var $overlay = $('#torobpay-promo-overlay');
	if ( ! $overlay.length ) {
		return;
	}

	var $dialog = $('#torobpay-promo-dialog');
	var $expiry = $overlay.find('.torobpay-promo-expiry');
	var lastFocus = null;
	var copyResetTimer = null;
	var expired = false;

	function markExpired() {
		if ( expired ) {
			return;
		}
		expired = true;
		$( '.torobpay-widget-promo' ).remove();
		$( '.torobpay-widget.has-torobpay-promo' ).removeClass( 'has-torobpay-promo torobpay-promo-marquee' );
		closePromo();
	}

	function watchExpiry() {
		if ( expired || ! $expiry.length ) {
			return;
		}
		var expiresAt = parseInt( $expiry.attr( 'data-expires-at' ), 10 ) || 0;
		if ( ! expiresAt ) {
			return;
		}
		var remaining = ( expiresAt * 1000 ) - Date.now();
		if ( remaining <= 0 ) {
			markExpired();
			return;
		}
		window.setTimeout( watchExpiry, Math.min( remaining, 2147483647 ) );
	}

	function focusables() {
		return $dialog.find( 'button:visible' );
	}

	function openPromo() {
		if ( expired || ! $overlay.length ) {
			return;
		}
		lastFocus = document.activeElement;
		$overlay.removeAttr( 'hidden' );
		$( '.torobpay-widget-promo' ).attr( 'aria-expanded', 'true' );
		$( 'body' ).addClass( 'torobpay-promo-open' );
		window.setTimeout( function() {
			var $first = $overlay.find( '.torobpay-promo-copy' );
			if ( $first.length ) {
				$first.trigger( 'focus' );
			} else {
				$dialog.trigger( 'focus' );
			}
		}, 0 );
	}

	function closePromo() {
		if ( $overlay.attr( 'hidden' ) ) {
			return;
		}
		$overlay.attr( 'hidden', 'hidden' );
		$( '.torobpay-widget-promo' ).attr( 'aria-expanded', 'false' );
		$( 'body' ).removeClass( 'torobpay-promo-open' );
		if ( lastFocus && typeof lastFocus.focus === 'function' ) {
			lastFocus.focus();
		}
		lastFocus = null;
	}

	function copyCode( code, $btn ) {
		if ( ! code ) {
			return;
		}
		var done = function() {
			if ( ! $btn || ! $btn.length ) {
				return;
			}
			var original = $btn.data( 'torobpayOriginalLabel' );
			if ( ! original ) {
				original = $btn.text();
				$btn.data( 'torobpayOriginalLabel', original );
			}
			$btn.addClass( 'is-copied' ).text( __( 'Copied', 'torobpay-for-woocommerce' ) );
			if ( window.torobpayBurstConfetti ) {
				window.torobpayBurstConfetti( $overlay.find( '.torobpay-promo-confetti' ).get( 0 ) );
			}
			if ( copyResetTimer ) {
				window.clearTimeout( copyResetTimer );
			}
			copyResetTimer = window.setTimeout( function() {
				$btn.removeClass( 'is-copied' ).text( original );
			}, 1800 );
		};

		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			navigator.clipboard.writeText( code ).then( done ).catch( function() {
				fallbackCopy( code, done );
			} );
			return;
		}
		fallbackCopy( code, done );
	}

	function fallbackCopy( code, done ) {
		var $ta = $( '<textarea>' ).val( code ).css( {
			position: 'fixed',
			left: '-9999px',
			top: '0'
		} ).appendTo( 'body' );
		$ta.trigger( 'focus' );
		$ta[0].select();
		try {
			document.execCommand( 'copy' );
			done();
		} catch ( e ) { /* ignore */ }
		$ta.remove();
	}

	$( document ).on( 'click', '.torobpay-widget-promo', function( e ) {
		e.preventDefault();
		e.stopPropagation();
		if ( e.stopImmediatePropagation ) {
			e.stopImmediatePropagation();
		}
		openPromo();
	} );

	$overlay.on( 'click', function( e ) {
		if ( e.target === this ) {
			closePromo();
		}
	} );

	$overlay.on( 'click', '.torobpay-promo-dismiss', function( e ) {
		e.preventDefault();
		closePromo();
	} );

	$overlay.on( 'click', '.torobpay-promo-copy', function( e ) {
		e.preventDefault();
		copyCode( $( this ).attr( 'data-promo-code' ) || '', $( this ) );
	} );

	$( document ).on( 'keydown', function( e ) {
		if ( $overlay.attr( 'hidden' ) ) {
			return;
		}
		if ( e.key === 'Escape' ) {
			e.preventDefault();
			closePromo();
			return;
		}
		if ( e.key !== 'Tab' ) {
			return;
		}
		var $items = focusables();
		if ( ! $items.length ) {
			return;
		}
		var first = $items.get( 0 );
		var last = $items.get( $items.length - 1 );
		if ( e.shiftKey && document.activeElement === first ) {
			e.preventDefault();
			$( last ).trigger( 'focus' );
		} else if ( ! e.shiftKey && document.activeElement === last ) {
			e.preventDefault();
			$( first ).trigger( 'focus' );
		}
	} );

	watchExpiry();
});
