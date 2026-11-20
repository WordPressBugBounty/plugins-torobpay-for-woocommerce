/**
 * Confetti for the topbar credit line (on appear + each cycle; respects reduced-motion).
 */
( function () {
	'use strict';

	var reduceMotion = window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;
	var colors = [ '#F46344', '#5CBCFF', '#FBBB23', '#1D6315' ];

	function burst( host ) {
		if ( reduceMotion || ! host ) {
			return;
		}

		var bar = host.parentElement;
		var width = host.clientWidth || 320;
		var stageHeight = host.clientHeight || 180;
		var barHeight = bar ? bar.clientHeight : 44;
		var room = Math.max( 12, ( stageHeight - barHeight ) / 2 );
		var narrow = width < 640;
		var span = narrow ? 0.94 : 0.8;
		var count = 24;
		var i;

		for ( i = 0; i < count; i++ ) {
			var piece = document.createElement( 'span' );
			var spread = ( i / ( count - 1 ) ) - 0.5;
			var x = spread * width * span + ( Math.random() - 0.5 ) * ( narrow ? width * 0.22 : 36 );
			var limit = width * 0.48;
			x = Math.max( -limit, Math.min( limit, x ) );
			var drop = 8 + Math.random() * Math.max( 4, room - 8 );
			var y0 = -( barHeight / 2 + drop );
			var yEnd = barHeight / 2 + drop;
			var x0 = x * 0.2;
			var delay = 0;
			// On a short stage, even spacing plus a shared fall draws an arc.
			// Scramble the start, the drift, and the phase so the pieces scatter.
			if ( narrow ) {
				y0 += ( Math.random() - 0.5 ) * barHeight;
				yEnd += ( Math.random() - 0.5 ) * barHeight * 0.7;
				x0 = ( x * Math.random() * 0.7 ) + ( ( Math.random() - 0.5 ) * width * 0.2 );
				delay = Math.round( Math.random() * 360 );
			}
			// Same speed at every width. 220px/s keeps a typical desktop
			// crossing near 1.1s; a phone path is shorter, so it finishes sooner.
			var dx = Math.abs( x - x0 );
			var dy = Math.abs( yEnd - y0 );
			var distance = Math.sqrt( ( dx * dx ) + ( dy * dy ) );
			var duration = Math.round( ( distance / 220 ) * 1000 );
			duration = Math.round( duration * ( narrow ? ( 0.7 + ( Math.random() * 0.5 ) ) : ( 0.92 + ( Math.random() * 0.16 ) ) ) );
			duration = Math.max( narrow ? 400 : 480, Math.min( narrow ? 1000 : 1500, duration ) );

			piece.className = 'torobpay-topbar-confetti-piece';
			if ( i % 3 === 0 ) {
				piece.className += ' torobpay-topbar-confetti-piece--round';
			}
			piece.style.setProperty( '--x0', Math.round( x0 ) + 'px' );
			piece.style.setProperty( '--y0', Math.round( y0 ) + 'px' );
			piece.style.setProperty( '--x', Math.round( x ) + 'px' );
			piece.style.setProperty( '--y', Math.round( yEnd ) + 'px' );
			piece.style.setProperty( '--r', Math.round( Math.random() * 540 - 270 ) + 'deg' );
			piece.style.setProperty( '--torobpay-tb-confetti-duration', duration + 'ms' );
			piece.style.setProperty( '--torobpay-tb-confetti-delay', delay + 'ms' );
			piece.style.setProperty( '--torobpay-tb-confetti-color', colors[ Math.floor( Math.random() * colors.length ) ] );
			host.appendChild( piece );

			window.setTimeout( function ( node ) {
				if ( node.parentNode ) {
					node.parentNode.removeChild( node );
				}
			}, duration + delay + 40, piece );
		}
	}

	var boundItem = null;

	function animationDelaySeconds( item ) {
		var raw = window.getComputedStyle( item ).animationDelay || '0s';
		var first = String( raw ).split( ',' )[ 0 ].trim();
		var value = parseFloat( first );
		if ( isNaN( value ) ) {
			return 0;
		}
		if ( first.slice( -2 ) === 'ms' ) {
			return value / 1000;
		}
		return value;
	}

	function bindCredit( item, host, immediate ) {
		if ( ! item || ! host || boundItem === item ) {
			return;
		}
		boundItem = item;
		if ( immediate ) {
			burst( host );
		} else {
			item.addEventListener( 'animationstart', function () {
				burst( host );
			} );
		}
		item.addEventListener( 'animationiteration', function () {
			burst( host );
		} );
	}

	function bindVisibleCredit() {
		var item = document.querySelector( '#torobpay-topbar .torobpay-topbar-confetti-target' );
		var host = document.querySelector( '#torobpay-topbar .torobpay-topbar-confetti' );
		if ( ! item || ! host ) {
			return;
		}
		// Delay ~0 usually already started when this footer script runs.
		bindCredit( item, host, animationDelaySeconds( item ) < 0.05 );
	}

	function boot() {
		if ( reduceMotion ) {
			return;
		}
		bindVisibleCredit();
	}

	window.torobpayTopbarRebindConfetti = function () {
		if ( reduceMotion ) {
			return;
		}
		boundItem = null;
		bindVisibleCredit();
	};

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
}() );
