/**
 * TorobPay Promo Slider — front-end carousel engine (dependency-free).
 *
 * Handles slide switching (active class swap, CSS does the transition),
 * autoplay with pause-on-hover, prev/next arrows, dot indicators, basic touch
 * swipe, and keyboard arrows. Multiple sliders per page are supported.
 *
 * Slide backgrounds (perlin/rain/float/aurora) are rendered by the shared
 * TorobPay background engine (assets/js/topbar-background.js), which reads the
 * is-active class on each slide to only animate the visible one.
 */
( function () {
	'use strict';

	function initSlider( root ) {
		if ( root.dataset.torobpaySliderReady === '1' ) {
			return;
		}
		root.dataset.torobpaySliderReady = '1';

		var track = root.querySelector( '.torobpay-slider-track' );
		if ( ! track ) {
			return;
		}

		var slides = Array.prototype.slice.call( root.querySelectorAll( '.torobpay-slider-slide' ) );
		if ( slides.length === 0 ) {
			return;
		}

		var dots = Array.prototype.slice.call( root.querySelectorAll( '.torobpay-slider-dot' ) );
		var prev = root.querySelector( '.torobpay-slider-prev' );
		var next = root.querySelector( '.torobpay-slider-next' );

		// A slide is "hidden" when a per-device CSS rule sets display:none.
		function isHidden( slide ) {
			return window.getComputedStyle( slide ).display === 'none';
		}

		// Find the next non-hidden slide index starting from `from` in `dir`.
		function nextVisible( from, dir ) {
			var n = slides.length;
			for ( var step = 1; step <= n; step++ ) {
				var idx = ( ( ( from + dir * step ) % n ) + n ) % n;
				if ( ! isHidden( slides[ idx ] ) ) {
					return idx;
				}
			}
			return from;
		}

		var current = 0;
		slides.forEach( function ( s, i ) {
			if ( s.classList.contains( 'is-active' ) ) {
				current = i;
			}
		} );

		// If the initial slide is hidden on this device, jump to the first
		// visible one so the carousel never starts on a blank slide.
		if ( isHidden( slides[ current ] ) ) {
			var firstVisible = nextVisible( current, 1 );
			if ( firstVisible !== current ) {
				slides[ current ].classList.remove( 'is-active' );
				if ( dots[ current ] ) {
					dots[ current ].classList.remove( 'is-active' );
				}
				current = firstVisible;
				slides[ current ].classList.add( 'is-active' );
				if ( dots[ current ] ) {
					dots[ current ].classList.add( 'is-active' );
				}
			}
		}

		var autoplay = root.getAttribute( 'data-autoplay' ) === '1' && slides.length > 1;
		var pauseHover = root.getAttribute( 'data-pause-hover' ) === '1';

		var timer = null;
		var hovered = false;
		var manuallyPaused = root.getAttribute( 'data-initial-paused' ) === '1';
		var flipTransitionMs = 550;

		function shouldRunAutoplay() {
			return autoplay && ! manuallyPaused && ! ( pauseHover && hovered );
		}

		function goTo( target ) {
			if ( target === current || isHidden( slides[ target ] ) ) {
				return;
			}

			var outgoing = slides[ current ];

			if ( outgoing.classList.contains( 'torobpay-slider-slide--anim-flip' ) ) {
				outgoing.classList.add( 'is-leaving' );
				outgoing.classList.remove( 'is-active' );
				window.setTimeout( function () {
					outgoing.classList.remove( 'is-leaving' );
				}, flipTransitionMs );
			} else {
				outgoing.classList.remove( 'is-active' );
			}

			if ( dots[ current ] ) {
				dots[ current ].classList.remove( 'is-active' );
			}

			current = target;

			slides[ current ].classList.add( 'is-active' );
			if ( dots[ current ] ) {
				dots[ current ].classList.add( 'is-active' );
			}
		}

		function nextSlide() {
			goTo( nextVisible( current, 1 ) );
		}

		function prevSlide() {
			goTo( nextVisible( current, -1 ) );
		}

		// Per-slide display duration: each slide may stay on screen for a
		// different time (data-duration, in ms). We reschedule after each move.
		function currentDuration() {
			var dur = parseInt( slides[ current ].getAttribute( 'data-duration' ), 10 );
			if ( isNaN( dur ) || dur < 1500 ) {
				dur = 5000;
			}
			return dur;
		}

		function scheduleAutoplayTick( delay ) {
			if ( ! shouldRunAutoplay() ) {
				timer = null;
				return;
			}
			timer = window.setTimeout( function onAutoplayTick() {
				if ( ! shouldRunAutoplay() ) {
					timer = null;
					return;
				}
				nextSlide();
				scheduleAutoplayTick( currentDuration() );
			}, delay );
		}

		function startAutoplay() {
			if ( ! shouldRunAutoplay() || timer ) {
				return;
			}
			scheduleAutoplayTick( currentDuration() );
		}

		function stopAutoplay() {
			if ( timer ) {
				window.clearTimeout( timer );
				timer = null;
			}
		}

		function restartAutoplay() {
			stopAutoplay();
			startAutoplay();
		}

		if ( next ) {
			next.addEventListener( 'click', function () {
				nextSlide();
				restartAutoplay();
			} );
		}
		if ( prev ) {
			prev.addEventListener( 'click', function () {
				prevSlide();
				restartAutoplay();
			} );
		}

		dots.forEach( function ( dot ) {
			dot.addEventListener( 'click', function () {
				var to = parseInt( dot.getAttribute( 'data-slide-to' ), 10 );
				if ( ! isNaN( to ) ) {
					goTo( to );
					restartAutoplay();
				}
			} );
		} );

		if ( pauseHover ) {
			root.addEventListener( 'pointerenter', function () {
				hovered = true;
				stopAutoplay();
			} );
			root.addEventListener( 'pointerleave', function () {
				hovered = false;
				startAutoplay();
			} );
		}

		// Pause when the tab is hidden.
		document.addEventListener( 'visibilitychange', function () {
			if ( document.hidden ) {
				stopAutoplay();
			} else {
				startAutoplay();
			}
		} );

		// Keyboard support when the slider is focused/hovered.
		root.setAttribute( 'tabindex', '0' );
		root.addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'ArrowLeft' ) {
				// RTL: left arrow advances to the next slide.
				nextSlide();
				restartAutoplay();
			} else if ( e.key === 'ArrowRight' ) {
				prevSlide();
				restartAutoplay();
			}
		} );

		// Basic touch swipe.
		var touchStartX = 0;
		var touchActive = false;
		root.addEventListener(
			'touchstart',
			function ( e ) {
				if ( e.touches && e.touches.length === 1 ) {
					touchStartX = e.touches[ 0 ].clientX;
					touchActive = true;
					stopAutoplay();
				}
			},
			{ passive: true }
		);
		root.addEventListener(
			'touchend',
			function ( e ) {
				if ( ! touchActive ) {
					return;
				}
				touchActive = false;
				var endX = e.changedTouches && e.changedTouches.length ? e.changedTouches[ 0 ].clientX : touchStartX;
				var dx = endX - touchStartX;
				if ( Math.abs( dx ) > 40 ) {
					// RTL: swipe left -> next, swipe right -> prev.
					if ( dx < 0 ) {
						nextSlide();
					} else {
						prevSlide();
					}
				}
				startAutoplay();
			},
			{ passive: true }
		);

		root.torobpaySliderSetPaused = function ( paused ) {
			manuallyPaused = !! paused;
			if ( manuallyPaused ) {
				stopAutoplay();
			} else {
				startAutoplay();
			}
		};

		root.torobpaySliderGoTo = function ( target ) {
			var index = parseInt( target, 10 );
			if ( isNaN( index ) || index < 0 || index >= slides.length ) {
				return;
			}
			goTo( index );
		};

		if ( ! manuallyPaused ) {
			startAutoplay();
		}
	}

	function setSlidersPaused( scope, paused ) {
		var el = scope && scope.nodeType === 1 ? scope : document;
		var roots = el.querySelectorAll ? el.querySelectorAll( '.torobpay-slider' ) : [];
		Array.prototype.forEach.call( roots, function ( root ) {
			if ( typeof root.torobpaySliderSetPaused === 'function' ) {
				root.torobpaySliderSetPaused( paused );
			}
		} );
	}

	function initSliders( root ) {
		var scope = root && root.querySelectorAll ? root : document;
		var roots = scope.querySelectorAll( '.torobpay-slider' );
		Array.prototype.forEach.call( roots, initSlider );
	}

	// For the admin live preview, which replaces the preview host's markup
	// with a freshly rendered slider after each edit and needs to (re)init it.
	window.torobpayInitSliders = initSliders;
	window.torobpaySetSlidersPaused = setSlidersPaused;

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', initSliders );
	} else {
		initSliders();
	}
}() );
