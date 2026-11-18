/**
 * TorobPay shared animated-background engine.
 *
 * Renders a decorative TorobPay brand-icon animation behind content on a
 * <canvas>. Originally built for the sitewide topbar, it is now reused by the
 * promo slider as well. Three canvas modes are supported (the CSS-only
 * "aurora" mode is handled in CSS and never reaches here):
 *
 *   - perlin : floating brand icons whose opacity is masked by a flowing
 *              Perlin-noise field (a "composite mask" backdrop).
 *   - rain   : brand-logo icons falling like rain; the fall angle follows the mouse.
 *   - float  : brand icons drifting gently upward with a soft bokeh feel.
 *
 * Each host element carries the configuration via data attributes
 * (data-bg-anim / data-bg-density / data-bg-speed / data-bg-icon-scale* /
 *  data-bg-custom-icon) and contains a layer element the <canvas> is appended
 * into. An optional merchant logo is mixed into the particle field alongside
 * the TorobPay brand mark — it never replaces it. The engine is dependency-free, respects
 * prefers-reduced-motion, pauses when the tab is hidden / the host scrolls out
 * of view / (for slider slides) the slide is not active, and never intercepts
 * pointer events (the layer is pointer-events:none in CSS).
 *
 * Brand palette: ink #003D01, light rgba(255,255,255,0.9).
 */
( function () {
	'use strict';

	if ( window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches ) {
		return;
	}

	/* --------------------------------------------------------------------- *
	 * Perlin noise (Ken Perlin's improved 2D noise) with a per-load shuffle.
	 * --------------------------------------------------------------------- */
	var perm = ( function () {
		var p = new Uint8Array( 512 );
		var source = new Uint8Array( 256 );
		var i;
		for ( i = 0; i < 256; i++ ) {
			source[ i ] = i;
		}
		for ( i = 255; i > 0; i-- ) {
			var j = Math.floor( Math.random() * ( i + 1 ) );
			var tmp = source[ i ];
			source[ i ] = source[ j ];
			source[ j ] = tmp;
		}
		for ( i = 0; i < 512; i++ ) {
			p[ i ] = source[ i & 255 ];
		}
		return p;
	}() );

	function fade( t ) {
		return t * t * t * ( t * ( t * 6 - 15 ) + 10 );
	}

	function lerp( a, b, t ) {
		return a + t * ( b - a );
	}

	function grad( hash, x, y ) {
		var h = hash & 7;
		var u = h < 4 ? x : y;
		var v = h < 4 ? y : x;
		return ( ( h & 1 ) ? -u : u ) + ( ( h & 2 ) ? -2 * v : 2 * v );
	}

	function noise2d( x, y ) {
		var X = Math.floor( x ) & 255;
		var Y = Math.floor( y ) & 255;
		x -= Math.floor( x );
		y -= Math.floor( y );
		var u = fade( x );
		var v = fade( y );
		var A = perm[ X ] + Y;
		var B = perm[ X + 1 ] + Y;
		return lerp(
			lerp( grad( perm[ A ], x, y ), grad( perm[ B ], x - 1, y ), u ),
			lerp( grad( perm[ A + 1 ], x, y - 1 ), grad( perm[ B + 1 ], x - 1, y - 1 ), u ),
			v
		);
	}

	/* --------------------------------------------------------------------- *
	 * TorobPay brand logo path, drawn in a unit box centred on (0,0).
	 * --------------------------------------------------------------------- */
	function buildBrandIcon() {
		// 4-petal clover mark, pre-scaled to fit ±0.32. Derived from
		// logo_favicon.svg (viewBox 0 0 512 512): (v − 256) / 512.
		return new Path2D(
			'M0.3201,0.1326' +
			'c-0.0366,0.0366,-0.0846,0.0549,-0.1326,0.0549' +
			',0,0.0480,-0.0183,0.0960,-0.0549,0.1326' +
			',-0.0732,0.0732,-0.1919,0.0732,-0.2652,0' +
			',-0.0366,-0.0366,-0.0549,-0.0846,-0.0549,-0.1326' +
			',-0.0480,0,-0.0960,-0.0183,-0.1326,-0.0549' +
			',-0.0732,-0.0732,-0.0732,-0.1919,0,-0.2652' +
			',0.0366,-0.0366,0.0846,-0.0549,0.1326,-0.0549' +
			',0,-0.0480,0.0183,-0.0960,0.0549,-0.1326' +
			',0.0732,-0.0732,0.1919,-0.0732,0.2652,0' +
			',0.0366,0.0366,0.0549,0.0846,0.0549,0.1326' +
			',0.0480,0,0.0960,0.0183,0.1326,0.0549' +
			',0.0732,0.0732,0.0732,0.1919,0,0.2652Z'
		);
	}

	var BRAND_ICON = buildBrandIcon();
	var INK = '#003D01';
	var LIGHT = 'rgba(255,255,255,0.9)';
	var MINT = 'rgba(156,231,106,0.85)';

	/* --------------------------------------------------------------------- *
	 * Merchant-tunable config keys (read from each host's data attributes).
	 *   density : low | medium | high | dense  -> particle-count multiplier
	 *   speed   : 1..10 (5 = normal)           -> motion-speed multiplier
	 * --------------------------------------------------------------------- */
	var DENSITY_FACTORS = { low: 1.7, medium: 1.0, high: 0.62, dense: 0.42 };

	/* --------------------------------------------------------------------- *
	 * One engine instance bound to a host element + its background layer.
	 *
	 * @param {HTMLElement}   host        Element carrying the data-bg-* config.
	 * @param {HTMLElement}   layer       Element the <canvas> is appended into.
	 * @param {Function|null} activeCheck Optional extra run gate (e.g. the slide
	 *                                    is active). Returns true when allowed.
	 * --------------------------------------------------------------------- */
	function createBg( host, layer, activeCheck ) {
		var mode = host.getAttribute( 'data-bg-anim' ) || 'none';
		if ( mode !== 'perlin' && mode !== 'rain' && mode !== 'float' ) {
			return;
		}

		var isDark = host.getAttribute( 'data-bg-dark' ) === '1';

		var densityKey = host.getAttribute( 'data-bg-density' ) || 'medium';
		var densityFactor = DENSITY_FACTORS[ densityKey ] || 1.0;

		var speedSetting = parseInt( host.getAttribute( 'data-bg-speed' ), 10 );
		if ( isNaN( speedSetting ) ) {
			speedSetting = 5;
		}
		var speedMult = Math.max( 1, Math.min( 10, speedSetting ) ) / 5;

		// Per-device background-icon size multiplier (slider slides only). The
		// matching CSS custom property scales the CSS-only clover field; here we
		// scale the canvas particle sizes. Slider defaults to 2× (200%) via PHP.
		var bpTablet = parseInt( host.getAttribute( 'data-bp-tablet' ), 10 ) || 1024;
		var bpMobile = parseInt( host.getAttribute( 'data-bp-mobile' ), 10 ) || 768;

		function readScale( attr ) {
			var v = parseFloat( host.getAttribute( attr ) );
			return ( isNaN( v ) || v <= 0 ) ? null : v;
		}

		var iconScaleBase = readScale( 'data-bg-icon-scale' );
		var iconScaleTablet = readScale( 'data-bg-icon-scale-tablet' );
		var iconScaleMobile = readScale( 'data-bg-icon-scale-mobile' );

		function currentIconScale() {
			var w = window.innerWidth || document.documentElement.clientWidth || 0;
			if ( w <= bpMobile && iconScaleMobile !== null ) {
				return iconScaleMobile;
			}
			if ( w <= bpTablet && iconScaleTablet !== null ) {
				return iconScaleTablet;
			}
			return iconScaleBase !== null ? iconScaleBase : 1;
		}

		var customIconUrl = host.getAttribute( 'data-bg-custom-icon' ) || '';
		var customIconImg = null;
		var customIconReady = false;

		if ( customIconUrl ) {
			customIconImg = new Image();
			try {
				var iconOrigin = new URL( customIconUrl, window.location.href ).origin;
				if ( iconOrigin !== window.location.origin ) {
					customIconImg.crossOrigin = 'anonymous';
				}
			} catch ( ignore ) {}
			customIconImg.onload = function () {
				if ( customIconImg.naturalWidth > 0 ) {
					customIconReady = true;
					queueSeed();
				}
			};
			customIconImg.onerror = function () {
				customIconImg = null;
				customIconReady = false;
			};
			customIconImg.src = customIconUrl;
			if ( customIconImg.complete && customIconImg.naturalWidth > 0 ) {
				customIconReady = true;
			}
		}

	/* --------------------------------------------------------------------- *
	 * Canvas setup with DPR awareness.
	 * --------------------------------------------------------------------- */
	var canvas = document.createElement( 'canvas' );
	var ctx = canvas.getContext( '2d' );
	layer.appendChild( canvas );

	var W = 0;
	var H = 0;
	var dpr = 1;
	var particles = [];
	var pendingSeed = false;

	// Layout box (offset*) matches how CSS sizes the canvas at 100%; getBoundingClientRect
	// includes ancestor transforms (e.g. Revolution Slider scale) and causes stretch.
	function layoutSize( el ) {
		var w = el.offsetWidth;
		var h = el.offsetHeight;
		if ( w > 0 && h > 0 ) {
			return { w: w, h: h };
		}
		var rect = el.getBoundingClientRect();
		return {
			w: Math.max( 1, Math.round( rect.width ) ),
			h: Math.max( 1, Math.round( rect.height ) )
		};
	}

	function resize() {
		var size = layoutSize( host );
		var nextW = size.w;
		var nextH = size.h;
		if ( nextW === W && nextH === H ) {
			return;
		}
		W = nextW;
		H = nextH;
		dpr = Math.min( window.devicePixelRatio || 1, 2 );
		canvas.width = Math.round( W * dpr );
		canvas.height = Math.round( H * dpr );
		ctx.setTransform( dpr, 0, 0, dpr, 0, 0 );
		queueSeed();
	}

	function queueSeed() {
		if ( running ) {
			pendingSeed = true;
			return;
		}
		seed();
	}

	function applyPendingSeed() {
		if ( pendingSeed ) {
			pendingSeed = false;
			seed();
		}
	}

	function rand( min, max ) {
		return min + Math.random() * ( max - min );
	}

	function seed() {
		if ( W <= 0 || H <= 0 ) {
			particles = [];
			return;
		}
		var next = [];
		// Density scales with the bar's area; the merchant factor and a higher cap
		// let the field get noticeably denser. Lower divisor = more particles.
		var baseDivisor = mode === 'perlin' ? 5200 : 7200;
		var divisor = baseDivisor * densityFactor;
		var count = Math.max( 8, Math.min( 140, Math.round( ( W * H ) / divisor ) ) );
		var iconScale = currentIconScale();
		var mixCustom = customIconReady && customIconImg && customIconImg.naturalWidth > 0;
		var i;

		function maybeCustom() {
			return mixCustom && Math.random() < 0.5;
		}

		if ( mode === 'rain' ) {
			for ( i = 0; i < count; i++ ) {
				next.push( {
					x: rand( 0, W ),
					y: rand( -H, H ),
					size: rand( 9, 18 ) * iconScale,
					speed: rand( 60, 130 ),
					rot: rand( 0, Math.PI * 2 ),
					spin: rand( -0.6, 0.6 ),
					alpha: rand( 0.12, 0.32 ),
					light: isDark ? Math.random() < 0.75 : Math.random() < 0.4,
					custom: maybeCustom()
				} );
			}
		} else if ( mode === 'float' ) {
			for ( i = 0; i < count; i++ ) {
				next.push( {
					x: rand( 0, W ),
					y: rand( 0, H ),
					size: rand( 10, 20 ) * iconScale,
					speed: rand( 8, 22 ),
					rot: rand( 0, Math.PI * 2 ),
					spin: rand( -0.4, 0.4 ),
					sway: rand( 6, 20 ),
					swayFreq: rand( 0.4, 1.1 ),
					phase: rand( 0, Math.PI * 2 ),
					alpha: rand( 0.1, 0.28 ),
					light: isDark ? Math.random() < 0.75 : Math.random() < 0.4,
					custom: maybeCustom()
				} );
			}
		} else {
			// perlin: a loose, jittered grid sampled against the noise field.
			for ( i = 0; i < count; i++ ) {
				next.push( {
					x: rand( 0, W ),
					y: rand( 0, H ),
					size: rand( 12, 22 ) * iconScale,
					rot: rand( 0, Math.PI * 2 ),
					spin: rand( -0.25, 0.25 ),
					drift: rand( 4, 12 ),
					phase: rand( 0, Math.PI * 2 ),
					light: isDark ? Math.random() < 0.75 : Math.random() < 0.4,
					custom: maybeCustom()
				} );
			}
		}

		particles = next;
	}

	/* --------------------------------------------------------------------- *
	 * Mouse-reactive rain angle (smoothly eased toward the pointer side).
	 * --------------------------------------------------------------------- */
	var rainAngle = 0;
	var rainTarget = 0;
	var MAX_ANGLE = 0.55; // ~31deg

	function onPointerMove( e ) {
		var vw = window.innerWidth || 1;
		var nx = ( e.clientX / vw ) * 2 - 1; // -1 (left) .. 1 (right)
		rainTarget = nx * MAX_ANGLE;
	}

	if ( mode === 'rain' ) {
		window.addEventListener( 'mousemove', onPointerMove, { passive: true } );
		window.addEventListener(
			'touchmove',
			function ( e ) {
				if ( e.touches && e.touches.length ) {
					onPointerMove( e.touches[ 0 ] );
				}
			},
			{ passive: true }
		);
	}

	function drawIcon( p, alpha ) {
		if ( alpha <= 0.01 ) {
			return;
		}
		ctx.save();
		ctx.translate( p.x, p.y );
		ctx.rotate( p.rot );
		ctx.globalAlpha = alpha;
		if ( p.custom && customIconImg && customIconReady && customIconImg.naturalWidth > 0 ) {
			var s = p.size * 2;
			ctx.drawImage( customIconImg, -s / 2, -s / 2, s, s );
		} else {
			ctx.scale( p.size, p.size );
			ctx.strokeStyle = isDark ? ( p.light ? LIGHT : MINT ) : ( p.light ? LIGHT : INK );
			ctx.lineWidth = 0.09;
			ctx.lineJoin = 'round';
			ctx.lineCap = 'round';
			ctx.stroke( BRAND_ICON );
		}
		ctx.restore();
	}

	/* --------------------------------------------------------------------- *
	 * Animation loop.
	 * --------------------------------------------------------------------- */
	var lastTs = 0;
	var running = false;
	var rafId = 0;

	function frame( ts ) {
		if ( ! running ) {
			return;
		}
		applyPendingSeed();
		var dt = lastTs ? Math.min( 0.05, ( ts - lastTs ) / 1000 ) : 0.016;
		lastTs = ts;
		var t = ts / 1000;
		var sdt = dt * speedMult;
		var st = t * speedMult;

		ctx.clearRect( 0, 0, W, H );
		var i;
		var p;
		var frameParticles = particles;

		if ( mode === 'rain' ) {
			rainAngle += ( rainTarget - rainAngle ) * Math.min( 1, dt * 4 );
			var vx = Math.sin( rainAngle );
			var vy = Math.cos( rainAngle );
			for ( i = 0; i < frameParticles.length; i++ ) {
				p = frameParticles[ i ];
				p.x += vx * p.speed * sdt;
				p.y += vy * p.speed * sdt;
				p.rot += p.spin * sdt;
				// Recycle once fully off the bottom or sides.
				if ( p.y - p.size > H || p.x < -p.size * 2 || p.x > W + p.size * 2 ) {
					p.y = -p.size;
					p.x = rand( -W * 0.2, W * 1.2 ) - vx * H;
				}
				drawIcon( p, p.alpha );
			}
		} else if ( mode === 'float' ) {
			for ( i = 0; i < frameParticles.length; i++ ) {
				p = frameParticles[ i ];
				p.y -= p.speed * sdt;
				p.rot += p.spin * sdt;
				var sx = Math.sin( st * p.swayFreq + p.phase ) * p.sway;
				if ( p.y + p.size < 0 ) {
					p.y = H + p.size;
					p.x = rand( 0, W );
				}
				ctx.save();
				ctx.translate( sx, 0 );
				drawIcon( p, p.alpha );
				ctx.restore();
			}
		} else {
			// perlin: opacity masked by the flowing noise field.
			var freq = 0.004;
			var flow = st * 0.08;
			for ( i = 0; i < frameParticles.length; i++ ) {
				p = frameParticles[ i ];
				var n = noise2d( p.x * freq + flow, p.y * freq - flow );
				var mask = ( n + 1 ) * 0.5; // 0..1
				var alpha = Math.max( 0, ( mask - 0.35 ) ) * 0.55;
				p.rot += p.spin * sdt;
				var dx = Math.sin( st * 0.3 + p.phase ) * p.drift;
				var dy = Math.cos( st * 0.25 + p.phase ) * p.drift;
				ctx.save();
				ctx.translate( dx, dy );
				drawIcon( p, alpha );
				ctx.restore();
			}
		}

		rafId = window.requestAnimationFrame( frame );
	}

	function start() {
		if ( running ) {
			return;
		}
		running = true;
		lastTs = 0;
		rafId = window.requestAnimationFrame( frame );
	}

	function stop() {
		running = false;
		if ( rafId ) {
			window.cancelAnimationFrame( rafId );
			rafId = 0;
		}
	}

	/* --------------------------------------------------------------------- *
	 * Lifecycle: pause when hidden or scrolled out of view; handle resize.
	 * --------------------------------------------------------------------- */
	var inView = true;

	function updateRunState() {
		var allowed = inView && ! document.hidden && ( ! activeCheck || activeCheck() );
		if ( allowed ) {
			start();
		} else {
			stop();
		}
	}

	document.addEventListener( 'visibilitychange', updateRunState );

	if ( 'IntersectionObserver' in window ) {
		var io = new IntersectionObserver(
			function ( entries ) {
				inView = entries[ 0 ].isIntersecting;
				updateRunState();
			},
			{ threshold: 0 }
		);
		io.observe( host );
	}

	// For slider slides: re-evaluate when the is-active class toggles, and
	// reseed on (re)show so the canvas matches the current size.
	if ( activeCheck && 'MutationObserver' in window ) {
		var mo = new MutationObserver( function () {
			if ( activeCheck() ) {
				resize();
			}
			updateRunState();
		} );
		mo.observe( host, { attributes: true, attributeFilter: [ 'class' ] } );
	}

	var resizeRaf = 0;
	function scheduleResize() {
		if ( resizeRaf ) {
			return;
		}
		resizeRaf = window.requestAnimationFrame( function () {
			resizeRaf = 0;
			resize();
		} );
	}

	window.addEventListener(
		'resize',
		scheduleResize,
		{ passive: true }
	);

	if ( 'ResizeObserver' in window ) {
		var ro = new ResizeObserver( scheduleResize );
		ro.observe( host );
	}

		resize();
		if ( customIconReady ) {
			queueSeed();
		}
		updateRunState();
	}

	/* --------------------------------------------------------------------- *
	 * Bootstrap: wire the topbar + every slider slide that wants a canvas bg.
	 * --------------------------------------------------------------------- */
	function initSlideBackgrounds( root ) {
		var slides;
		if ( root && root.nodeType === 1 && root.matches && root.matches( '.torobpay-slider-slide[data-bg-anim]' ) ) {
			slides = [ root ];
		} else {
			var scope = root && root.querySelectorAll ? root : document;
			slides = scope.querySelectorAll( '.torobpay-slider-slide[data-bg-anim]:not([data-torobpay-bg-init])' );
		}

		Array.prototype.forEach.call( slides, function ( slide ) {
			if ( slide.getAttribute( 'data-torobpay-bg-init' ) === '1' ) {
				return;
			}
			var layer = slide.querySelector( '.torobpay-slider-bg' );
			if ( ! layer ) {
				return;
			}
			slide.setAttribute( 'data-torobpay-bg-init', '1' );
			var isEmbed = !! slide.closest( '.torobpay-slide-embed' );
			createBg(
				slide,
				layer,
				isEmbed
					? null
					: function () {
						return slide.classList.contains( 'is-active' );
					}
			);
		} );
	}

	function boot() {
		// Sitewide topbar (single host).
		var topbar = document.getElementById( 'torobpay-topbar' );
		if ( topbar ) {
			var topbarLayer = topbar.querySelector( '.torobpay-topbar-bg' );
			if ( topbarLayer ) {
				createBg( topbar, topbarLayer, null );
			}
		}

		initSlideBackgrounds( document );
	}

	// For third-party sliders (e.g. Revolution Slider) that inject HTML layers
	// after DOMContentLoaded or on slide enter.
	window.torobpayInitSlideBackgrounds = initSlideBackgrounds;

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
}() );
