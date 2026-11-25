/**
 * PDP TorobPay promo lightbox: copy the code on dismiss and hide the offer after expiry.
 */
jQuery(function($) {
	'use strict';

	var i18n = ( window.wp && window.wp.i18n ) ? window.wp.i18n : {};
	var __ = i18n.__ || function( s ) { return s; };
	var promoButtons = '.torobpay-widget-promo, .torobpay-topbar-campaign';
	var copyConfirmMs = 800;

	var $overlay = $('#torobpay-promo-overlay');
	if ( ! $overlay.length ) {
		return;
	}

	var $dialog = $('#torobpay-promo-dialog');
	var $expiry = $overlay.find('.torobpay-promo-expiry');
	var lastFocus = null;
	var expired = false;
	var copyCloseTimer = null;

	/** Drop the campaign track so the pre-rendered standard track shows. */
	function restoreTickerAfterCampaign() {
		var bar = document.getElementById( 'torobpay-topbar' );
		if ( ! bar || ! bar.classList.contains( 'torobpay-topbar--has-campaign' ) ) {
			return;
		}
		var seq = bar.querySelector( '.torobpay-topbar-campaign-seq' );
		// Clear --has-campaign first (standard is display:none while set).
		bar.classList.remove( 'torobpay-topbar--has-campaign' );
		bar.classList.remove( 'torobpay-topbar--spread-has-campaign' );
		if ( seq && seq.parentNode ) {
			seq.parentNode.removeChild( seq );
		}
		if ( window.torobpayTopbarRebindConfetti ) {
			window.torobpayTopbarRebindConfetti();
		}
	}

	function markExpired() {
		if ( expired ) {
			return;
		}
		expired = true;
		restoreTickerAfterCampaign();
		$( '#torobpay-topbar' ).removeClass( 'is-showing-campaign torobpay-topbar--spread-has-campaign' );
		$( '.torobpay-widget-promo' ).remove();
		$( '.torobpay-widget.has-torobpay-promo' ).removeClass( 'has-torobpay-promo torobpay-promo-marquee' );
		$( '.torobpay-topbar-ticker-item--campaign' ).remove();
		closePromo();
		$overlay.remove();
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
		$( promoButtons ).attr( 'aria-expanded', 'true' );
		$( 'body' ).addClass( 'torobpay-promo-open' );
		window.setTimeout( function() {
			var $dismiss = $overlay.find( '.torobpay-promo-dismiss' );
			if ( $dismiss.length ) {
				$dismiss.trigger( 'focus' );
			} else {
				$dialog.trigger( 'focus' );
			}
		}, 0 );
	}

	function closePromo() {
		if ( copyCloseTimer ) {
			window.clearTimeout( copyCloseTimer );
			copyCloseTimer = null;
		}
		var $dismiss = $overlay.find( '.torobpay-promo-dismiss' );
		if ( $dismiss.length ) {
			var original = $dismiss.data( 'torobpayOriginalLabel' );
			if ( original ) {
				$dismiss.text( original );
			}
			$dismiss.prop( 'disabled', false ).removeClass( 'is-copied' );
		}
		if ( $overlay.attr( 'hidden' ) ) {
			return;
		}
		$overlay.attr( 'hidden', 'hidden' );
		$( promoButtons ).attr( 'aria-expanded', 'false' );
		$( 'body' ).removeClass( 'torobpay-promo-open' );
		if ( lastFocus && typeof lastFocus.focus === 'function' ) {
			lastFocus.focus();
		}
		lastFocus = null;
	}

	function copyCode( code ) {
		if ( ! code ) {
			return;
		}
		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			navigator.clipboard.writeText( code ).catch( function() {
				fallbackCopy( code );
			} );
			return;
		}
		fallbackCopy( code );
	}

	function fallbackCopy( code ) {
		var $ta = $( '<textarea>' ).val( code ).css( {
			position: 'fixed',
			left: '-9999px',
			top: '0'
		} ).appendTo( 'body' );
		$ta.trigger( 'focus' );
		$ta[0].select();
		try {
			document.execCommand( 'copy' );
		} catch ( e ) { /* ignore */ }
		$ta.remove();
	}

	$( document ).on( 'click', promoButtons, function( e ) {
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
		if ( copyCloseTimer ) {
			return;
		}
		var $btn = $( this );
		copyCode( $btn.attr( 'data-promo-code' ) || '' );
		if ( ! $btn.data( 'torobpayOriginalLabel' ) ) {
			$btn.data( 'torobpayOriginalLabel', $btn.text() );
		}
		$btn.prop( 'disabled', true ).addClass( 'is-copied' ).text( __( 'Copied', 'torobpay-for-woocommerce' ) );
		copyCloseTimer = window.setTimeout( function() {
			copyCloseTimer = null;
			closePromo();
		}, copyConfirmMs );
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
