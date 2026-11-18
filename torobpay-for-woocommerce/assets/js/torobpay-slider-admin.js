/**
 * TorobPay Promo Slider — admin repeaters for slides and placements.
 */
( function ( $ ) {
	'use strict';

	var __ = ( window.wp && wp.i18n && wp.i18n.__ ) ? wp.i18n.__ : function( s ) { return s; };

	var l10n = window.torobpaySliderAdmin || {};

	function showToast(message, type) {
		if (window.torobpayShowToast) {
			window.torobpayShowToast(message, type || 'warning');
			return;
		}
		window.alert(message);
	}

	function reindexByPrefix( $repeater, prefix ) {
		$repeater.children( '.torobpay-slider-repeater-item' ).each( function ( index ) {
			$( this ).attr( 'data-index', index );
			$( this )
				.find( 'input, textarea, select' )
				.each( function () {
					var name = $( this ).attr( 'name' );
					if ( ! name ) {
						return;
					}
					var pattern = new RegExp( prefix.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' ) + '\\[[^\\]]*\\]' );
					$( this ).attr( 'name', name.replace( pattern, prefix + '[' + index + ']' ) );
				} );
		} );
	}

	function reindexSlides( $repeater ) {
		reindexByPrefix( $repeater, 'torobpay_slider_slides' );
	}

	function reindexPlacements( $repeater ) {
		$repeater.children( '.torobpay-slider-placement-item' ).each( function ( index ) {
			$( this ).attr( 'data-index', index );
			$( this )
				.find( 'input, textarea, select' )
				.each( function () {
					var name = $( this ).attr( 'name' );
					if ( ! name ) {
						return;
					}
					$( this ).attr(
						'name',
						name.replace( /torobpay_slider_placements\[[^\]]*\]/, 'torobpay_slider_placements[' + index + ']' )
					);
				} );
		} );
	}

	function openMediaFrame( $item ) {
		var frame = wp.media( {
			title: l10n.mediaTitle || __( 'Select image', 'torobpay-for-woocommerce' ),
			button: { text: l10n.mediaButton || __( 'Use this image', 'torobpay-for-woocommerce' ) },
			library: { type: 'image' },
			multiple: false,
		} );

		frame.on( 'select', function () {
			var attachment = frame.state().get( 'selection' ).first().toJSON();
			var url = attachment.url;
			if ( attachment.sizes && attachment.sizes.large ) {
				url = attachment.sizes.large.url;
			}
			$item.find( '.torobpay-slider-image-url' ).val( url );
			$item.find( '.torobpay-slider-image-id' ).val( attachment.id );
			updateSlideImageCard( $item, url );
			if ( typeof window.torobpaySliderSchedulePreview === 'function' ) {
				window.torobpaySliderSchedulePreview();
			}
		});

		frame.open();
	}

	var allowedBgIconMimes = { 'image/png': true, 'image/svg+xml': true };

	function openBgIconMediaFrame( $scope ) {
		var frame = wp.media( {
			title: __( 'Select background logo', 'torobpay-for-woocommerce' ),
			button: { text: __( 'Use this logo', 'torobpay-for-woocommerce' ) },
			library: { type: 'image' },
			multiple: false,
		} );

		frame.on( 'select', function () {
			var attachment = frame.state().get( 'selection' ).first().toJSON();
			if ( ! allowedBgIconMimes[ attachment.mime ] ) {
				window.alert( __( 'Only PNG or SVG files are allowed.', 'torobpay-for-woocommerce' ) );
				return;
			}
			var url = attachment.url;
			$scope.find( '.torobpay-slider-bg-icon-url' ).val( url );
			$scope.find( '.torobpay-slider-bg-icon-id' ).val( attachment.id );
			updateBgIconCard( $scope, url );
			if ( typeof window.torobpaySliderSchedulePreview === 'function' ) {
				window.torobpaySliderSchedulePreview();
			}
		} );

		frame.open();
	}

	function updateBgIconCard( $scope, url ) {
		var $card = $scope.find( '.torobpay-bg-icon-card' );
		var $preview = $scope.find( '.torobpay-slider-bg-icon-preview' );
		if ( url ) {
			$card.removeClass( 'is-empty' );
			$preview.attr( 'src', url );
		} else {
			$card.addClass( 'is-empty' );
			$preview.removeAttr( 'src' );
		}
	}

	function updateSlideImageCard( $item, url ) {
		var $card = $item.find( '.torobpay-slider-image-card' );
		var $preview = $item.find( '.torobpay-slider-image-preview' );
		if ( url ) {
			$card.removeClass( 'is-empty' );
			$preview.attr( 'src', url );
		} else {
			$card.addClass( 'is-empty' );
			$preview.removeAttr( 'src' );
		}
		syncSlideImagePositionControl( $item );
	}

	function imageObjectPositionCss( key ) {
		return String( key || 'center' ).replace( /-/g, ' ' );
	}

	function syncSlideImagePositionControl( $item ) {
		var hasImage = !!$.trim( $item.find( '.torobpay-slider-image-url' ).val() || '' );
		var $select = $item.find( '.torobpay-slider-image-object-position' );
		var $preview = $item.find( '.torobpay-slider-image-preview' );

		if ( hasImage ) {
			$preview.css( 'object-position', imageObjectPositionCss( $select.val() ) );
		}
	}

	function mergePreset( hook ) {
		var base = $.extend( {}, l10n.defaultPlacement || {} );
		var presets = l10n.placementPresets || {};
		var choices = l10n.hookChoices || {};

		if ( presets[ hook ] ) {
			return $.extend( {}, base, presets[ hook ] );
		}

		if ( choices[ hook ] ) {
			return $.extend( {}, base, { hook: hook } );
		}

		return $.extend( {}, base, { hook: hook } );
	}

	function getResolvedHook( $row ) {
		var selected = $row.find( '.torobpay-slider-placement-hook-select' ).val();
		if ( selected === 'custom' ) {
			return $.trim( $row.find( '.torobpay-slider-placement-hook-custom' ).val() );
		}
		return selected;
	}

	function getHookLabel( hook ) {
		var choices = l10n.hookChoices || {};
		if ( choices[ hook ] ) {
			return choices[ hook ];
		}
		return hook || ( l10n.newPlacement || __( 'New display location', 'torobpay-for-woocommerce' ) );
	}

	function collectPlacementHooks( $repeater ) {
		var hooks = [];
		if ( ! $repeater || ! $repeater.length ) {
			return hooks;
		}
		$repeater.find( '.torobpay-slider-placement-item' ).each( function () {
			var hook = getResolvedHook( $( this ) );
			if ( hook && hooks.indexOf( hook ) === -1 ) {
				hooks.push( hook );
			}
		} );
		return hooks;
	}

	function addUniqueHook( hooks, hook ) {
		if ( hook && hooks.indexOf( hook ) === -1 ) {
			hooks.push( hook );
		}
	}

	function appendOrphanedPlacementState( $container, nameBase, orphaned ) {
		if ( ! orphaned.length ) {
			return;
		}

		var $notice = $( '<div class="torobpay-slide-placements-orphaned"></div>' );
		var $body = $( '<div class="torobpay-slide-placements-orphaned-body"></div>' );
		var $list = $( '<ul class="torobpay-slide-placements-orphan-items"></ul>' );

		$body.append(
			$( '<p class="torobpay-slide-placements-orphaned-intro"></p>' ).text(
				__( 'This slide is still linked to removed display location(s):', 'torobpay-for-woocommerce' )
			)
		);

		orphaned.forEach( function ( hook ) {
			var $item = $( '<li class="torobpay-slide-placements-orphan-item"></li>' ).attr( 'data-hook', hook );
			$item.append(
				$( '<input type="hidden" class="torobpay-placement-orphan-input">' )
					.attr( 'name', nameBase + '[placements][]' )
					.val( hook )
			);
			$item.append(
				$( '<span class="torobpay-slide-placements-orphan-label"></span>' ).text( getHookLabel( hook ) )
			);
			$item.append(
				$( '<button type="button" class="button-link torobpay-placement-orphan-remove"></button>' )
					.attr( 'title', __( 'Remove link from this slide', 'torobpay-for-woocommerce' ) )
					.text( __( 'Remove link', 'torobpay-for-woocommerce' ) )
			);
			$list.append( $item );
		} );

		$body.append( $list );
		$body.append(
			$( '<p class="torobpay-slide-placements-orphaned-note"></p>' ).text(
				__( 'Recreating the same display location restores the previous link.', 'torobpay-for-woocommerce' )
			)
		);
		$notice
			.append( $( '<span class="dashicons dashicons-warning" aria-hidden="true"></span>' ) )
			.append( $body );
		$container.append( $notice );
	}

	function syncSlidePlacementOptions( $placementRepeater ) {
		var hooks = collectPlacementHooks( $placementRepeater );
		$( '.torobpay-slide-placements-chips' ).each( function () {
			var $container = $( this );
			var nameBase = $container.data( 'name-base' );
			if ( ! nameBase ) {
				return;
			}
			var selected = [];
			$container.find( 'input[type="checkbox"]:checked, .torobpay-placement-orphan-input' ).each( function () {
				addUniqueHook( selected, $( this ).val() );
			} );
			$container.empty();
			var orphaned = selected.filter( function ( hook ) {
				return hooks.indexOf( hook ) === -1;
			} );
			if ( ! hooks.length ) {
				appendOrphanedPlacementState( $container, nameBase, orphaned );
				$container.append(
					$( '<p class="torobpay-slide-placements-empty"></p>' ).text(
						__( 'No display location defined yet. Add one under "Manage display locations" first.', 'torobpay-for-woocommerce' )
					)
				);
				return;
			}
			hooks.forEach( function ( hook ) {
				var isChecked = selected.indexOf( hook ) !== -1;
				var $chip = $( '<label class="torobpay-placement-chip"></label>' );
				if ( isChecked ) {
					$chip.addClass( 'is-selected' );
				}
				var $input = $( '<input type="checkbox">' )
					.addClass( 'torobpay-placement-input' )
					.attr( 'name', nameBase + '[placements][]' )
					.val( hook )
					.prop( 'checked', isChecked );
				var $label = $( '<span class="torobpay-placement-chip-label"></span>' ).text( getHookLabel( hook ) );
				$chip.append( $input ).append( $label );
				$container.append( $chip );
			} );
			appendOrphanedPlacementState( $container, nameBase, orphaned );
		} );
	}

	function getUsedHooks( $repeater, excludeRow ) {
		var hooks = [];
		$repeater.find( '.torobpay-slider-placement-item' ).each( function () {
			var $row = $( this );
			if ( excludeRow && $row[ 0 ] === excludeRow[ 0 ] ) {
				return;
			}
			var hook = getResolvedHook( $row );
			if ( hook ) {
				hooks.push( hook );
			}
		} );
		return hooks;
	}

	function isDuplicateHook( hook, $repeater, excludeRow ) {
		if ( ! hook ) {
			return false;
		}
		return getUsedHooks( $repeater, excludeRow ).indexOf( hook ) !== -1;
	}

	function findUnusedPredefinedHook( $repeater ) {
		var used = getUsedHooks( $repeater );
		var choices = l10n.hookChoices || {};
		var hook;
		for ( hook in choices ) {
			if (
				Object.prototype.hasOwnProperty.call( choices, hook ) &&
				used.indexOf( hook ) === -1
			) {
				return hook;
			}
		}
		return null;
	}

	function revertHookSelection( $row, prevHook ) {
		var choices = l10n.hookChoices || {};
		if ( prevHook && choices[ prevHook ] ) {
			$row.find( '.torobpay-slider-placement-hook-select' ).val( prevHook );
			$row.find( '.torobpay-slider-placement-custom-hook' ).hide();
			$row.find( '.torobpay-slider-placement-hook-custom' ).val( '' );
			return;
		}
		if ( prevHook ) {
			$row.find( '.torobpay-slider-placement-hook-select' ).val( 'custom' );
			$row.find( '.torobpay-slider-placement-custom-hook' ).show();
			$row.find( '.torobpay-slider-placement-hook-custom' ).val( prevHook );
		}
	}

	function updateHookSelectOptions( $repeater ) {
		var used = getUsedHooks( $repeater );
		$repeater.find( '.torobpay-slider-placement-item' ).each( function () {
			var $row = $( this );
			var current = getResolvedHook( $row );
			$row.find( '.torobpay-slider-placement-hook-select option' ).each( function () {
				var val = $( this ).val();
				if ( val === 'custom' ) {
					return;
				}
				var taken = used.indexOf( val ) !== -1 && val !== current;
				$( this ).prop( 'disabled', taken );
			} );
		} );
	}

	function storePrevHook( $row ) {
		$row.data( 'prev-hook', getResolvedHook( $row ) );
	}

	function validateHookChange( $row, $repeater ) {
		var prevHook = $row.data( 'prev-hook' ) || '';
		var hook = getResolvedHook( $row );
		if ( hook && isDuplicateHook( hook, $repeater, $row ) ) {
			if ( l10n.duplicateHook ) {
				showToast( l10n.duplicateHook, 'warning' );
			}
			revertHookSelection( $row, prevHook );
			return false;
		}
		$row.data( 'prev-hook', hook );
		return true;
	}

	function setCheckbox( $row, name, value ) {
		$row.find( 'input[type="checkbox"][name$="[' + name + ']"]' ).prop( 'checked', value === 'yes' );
	}

	function setNumberField( $row, name, value ) {
		$row.find( 'input[name$="[' + name + ']"]' ).val( value === '' || value === null || typeof value === 'undefined' ? '' : value );
	}

	function setUnitSelect( $row, name, value ) {
		$row.find( 'select[name$="[' + name + ']"]' ).val( value || 'px' );
	}

	function getPlacementDimensionBounds( dimension, unit ) {
		var boundsMap = ( l10n.placementDimensionBounds && l10n.placementDimensionBounds[ dimension ] ) || {};
		return boundsMap[ unit ] || boundsMap.px || { min: 0, max: 9999 };
	}

	function syncPlacementDimensionBounds( $field ) {
		var dimension = $field.data( 'dimension' );
		var unit = $field.find( '.torobpay-slider-placement-unit' ).val() || 'px';
		var bounds = getPlacementDimensionBounds( dimension, unit );
		$field.find( '.torobpay-rf-input' ).attr( 'min', bounds.min ).attr( 'max', bounds.max );
	}

	function syncPlacementRowDimensionBounds( $row ) {
		$row.find( '.torobpay-slider-placement-dimension-field' ).each( function () {
			syncPlacementDimensionBounds( $( this ) );
		} );
	}

	function applyPresetToRow( $row, preset ) {
		preset = preset || {};

		var defaultEnabled = ( l10n.defaultPlacement && l10n.defaultPlacement.enabled ) || 'no';
		setCheckbox( $row, 'enabled', typeof preset.enabled !== 'undefined' ? preset.enabled : defaultEnabled );
		setCheckbox( $row, 'show_desktop', preset.show_desktop || 'yes' );
		setCheckbox( $row, 'show_tablet', preset.show_tablet || 'yes' );
		setCheckbox( $row, 'show_mobile', preset.show_mobile || 'yes' );
		setCheckbox( $row, 'autoplay', preset.autoplay || 'yes' );
		setCheckbox( $row, 'arrows', preset.arrows || 'no' );
		setCheckbox( $row, 'dots', preset.dots || 'yes' );
		setCheckbox( $row, 'pause_hover', preset.pause_hover || 'yes' );

		setNumberField( $row, 'priority', preset.priority || 10 );
		setNumberField( $row, 'height', preset.height );
		setNumberField( $row, 'height_tablet', preset.height_tablet || '' );
		setNumberField( $row, 'height_mobile', preset.height_mobile || '' );
		setUnitSelect( $row, 'height_unit', preset.height_unit || 'px' );
		setNumberField( $row, 'max_width', preset.max_width );
		setNumberField( $row, 'max_width_tablet', preset.max_width_tablet || '' );
		setNumberField( $row, 'max_width_mobile', preset.max_width_mobile || '' );
		setUnitSelect( $row, 'max_width_unit', preset.max_width_unit || 'px' );
		setNumberField( $row, 'radius', preset.radius );
		syncPlacementRowDimensionBounds( $row );
	}

	function applyPresetForRow( $row ) {
		var selected = $row.find( '.torobpay-slider-placement-hook-select' ).val();
		var hook =
			selected === 'custom'
				? 'woocommerce_before_main_content'
				: selected || 'woocommerce_before_main_content';
		applyPresetToRow( $row, mergePreset( hook ) );
	}

	function syncPlacementHookUi( $row ) {
		var selected = $row.find( '.torobpay-slider-placement-hook-select' ).val();
		var isCustom = selected === 'custom';
		$row.find( '.torobpay-slider-placement-custom-hook' ).toggle( isCustom );
		var hook = getResolvedHook( $row );
		var label = getHookLabel( hook );
		$row.find( '.torobpay-slider-repeater-title, .torobpay-slider-placement-panel-title' ).text( label );
	}

	function getActivePlacementIndex( $nav, $panels ) {
		var $active = $nav.find( '.torobpay-slider-placement-tab.is-active' );
		if ( $active.length ) {
			return parseInt( $active.attr( 'data-index' ), 10 );
		}
		return $panels.filter( '.is-active' ).index();
	}

	function activatePlacementPanel( $nav, $panels, index ) {
		if ( ! $panels.length ) {
			return;
		}
		index = Math.max( 0, Math.min( index, $panels.length - 1 ) );

		$panels.removeClass( 'is-active' ).attr( 'hidden', true );
		$panels.eq( index ).addClass( 'is-active' ).removeAttr( 'hidden' );

		$nav.find( '.torobpay-slider-placement-tab' )
			.removeClass( 'nav-tab-active is-active' )
			.attr( 'aria-selected', 'false' );
		$nav.find( '.torobpay-slider-placement-tab[data-index="' + index + '"]' )
			.addClass( 'nav-tab-active is-active' )
			.attr( 'aria-selected', 'true' );
	}

	function syncPlacementTabs( $nav, $panels, preferredIndex ) {
		var activeIndex =
			typeof preferredIndex === 'number'
				? preferredIndex
				: getActivePlacementIndex( $nav, $panels );
		if ( activeIndex < 0 || activeIndex >= $panels.length ) {
			activeIndex = Math.max( 0, $panels.length - 1 );
		}

		$nav.empty();
		$panels.each( function ( i ) {
			var $panel = $( this );
			var label =
				$.trim( $panel.find( '.torobpay-slider-placement-panel-title' ).first().text() ) ||
				__( 'Location ', 'torobpay-for-woocommerce' ) + ( i + 1 );
			var enabled = $panel.find( 'input[type="checkbox"][name$="[enabled]"]' ).is( ':checked' );
			var $tab = $( '<button type="button" class="torobpay-slider-placement-tab nav-tab" role="tab"></button>' );
			$tab.attr( 'data-index', i );
			$tab.attr( 'data-tab', String( i ) );
			$tab.attr( 'id', 'torobpay-slider-placement-tab-' + i );
			$tab.attr( 'aria-controls', 'torobpay-slider-placement-panel-' + i );
			$tab.append( $( '<span class="torobpay-slider-placement-tab-text"></span>' ).text( label ) );
			if ( ! enabled ) {
				$tab.addClass( 'is-disabled-placement' );
			}
			if ( i === activeIndex ) {
				$tab.addClass( 'nav-tab-active is-active' ).attr( 'aria-selected', 'true' );
			} else {
				$tab.attr( 'aria-selected', 'false' );
			}
			$nav.append( $tab );
			$panel.attr( 'id', 'torobpay-slider-placement-panel-' + i );
			$panel.attr( 'aria-labelledby', 'torobpay-slider-placement-tab-' + i );
		} );

		activatePlacementPanel( $nav, $panels, activeIndex );
	}

	function initPlacementRow( $row ) {
		syncPlacementHookUi( $row );
		syncPlacementRowDimensionBounds( $row );
		if ( typeof window.torobpayInitDeviceSwitchers === 'function' ) {
			window.torobpayInitDeviceSwitchers( $row[ 0 ] );
		}
	}

	$( function () {
		/* ---------------------------------------------------------------
		 * Live preview
		 * ------------------------------------------------------------- */
		var previewCfg = l10n.preview || {};
		var $previewSection = $( '#torobpay-slider-preview-section' );
		var $previewHost = $( '#torobpay-slider-preview-host' );
		var $previewFrame = $( '.torobpay-slider-preview-frame' );
		var $previewEmpty = $( '#torobpay-slider-preview-empty' );
		var $previewPlacementSelect = $( '#torobpay-slider-preview-placement' );
		var $previewPlaybackBtn = $( '#torobpay-slider-preview-playback' );
		var previewPlaybackPaused = true;
		var previewDevice = 'desktop';
		var previewTimer = null;
		var previewXhr = null;
		var PREVIEW_WIDTH_KEY = 'torobpay_slider_preview_width';
		var PREVIEW_HEIGHT_KEY = 'torobpay_slider_preview_height';
		var PREVIEW_MIN_WIDTH = 280;
		var PREVIEW_MIN_HEIGHT = 200;
		var PREVIEW_DEFAULT_WIDTH = 560;
		var PREVIEW_DEFAULT_HEIGHT = 420;

		function isPreviewFloating() {
			return window.innerWidth >= 1150;
		}

		function getPreviewMaxWidth() {
			if ( isPreviewFloating() ) {
				return Math.max( PREVIEW_MIN_WIDTH, window.innerWidth - 40 );
			}
			var $tab = $( '#slider-settings' );
			var tabWidth = $tab.length ? $tab.width() : window.innerWidth;
			return Math.max( PREVIEW_MIN_WIDTH, tabWidth || window.innerWidth );
		}

		function getPreviewMaxHeight() {
			return Math.max( PREVIEW_MIN_HEIGHT, window.innerHeight - 90 );
		}

		function getPreviewContentWidth() {
			if ( ! $previewFrame.length ) {
				return PREVIEW_DEFAULT_WIDTH;
			}
			var width = $previewFrame.width();
			return width > 0 ? width : $previewSection.outerWidth();
		}

		function getPreviewDeviceFromWidth( width ) {
			var bp = previewCfg.breakpoints || { tablet: 1024, mobile: 768 };
			var mobile = parseInt( bp.mobile, 10 ) || 768;
			var tablet = parseInt( bp.tablet, 10 ) || 1024;
			if ( width <= mobile ) {
				return 'mobile';
			}
			if ( width <= tablet ) {
				return 'tablet';
			}
			return 'desktop';
		}

		function applyPreviewSize( width, height, persist ) {
			if ( ! $previewSection.length ) {
				return { width: width, height: height };
			}
			if ( $previewSection.hasClass( 'torobpay-preview-collapsed' ) ) {
				return { width: width, height: height };
			}

			var maxW = getPreviewMaxWidth();
			var nextW = Math.max( PREVIEW_MIN_WIDTH, Math.min( maxW, width ) );
			$previewSection.css( 'width', nextW + 'px' );

			var nextH = height;
			if ( null !== height && ! isNaN( height ) ) {
				var maxH = getPreviewMaxHeight();
				nextH = Math.max( PREVIEW_MIN_HEIGHT, Math.min( maxH, height ) );
				$previewSection.css( 'height', nextH + 'px' );
			}

			if ( persist ) {
				try {
					localStorage.setItem( PREVIEW_WIDTH_KEY, String( nextW ) );
					if ( null !== nextH && ! isNaN( nextH ) ) {
						localStorage.setItem( PREVIEW_HEIGHT_KEY, String( nextH ) );
					}
				} catch ( err ) {
					// Ignore storage failures (private mode, quota, etc.).
				}
			}

			return { width: nextW, height: nextH };
		}

		function syncPreviewDeviceFromWidth( triggerRefresh ) {
			var nextDevice = getPreviewDeviceFromWidth( getPreviewContentWidth() );
			var changed = nextDevice !== previewDevice;
			previewDevice = nextDevice;
			if ( changed && triggerRefresh ) {
				schedulePreview();
			}
			return changed;
		}

		function initPreviewResize() {
			var $handle = $previewSection.find( '.torobpay-slider-preview-resize-handle' );
			if ( ! $handle.length ) {
				return;
			}

			var savedWidth = null;
			var savedHeight = null;
			try {
				var storedWidth = parseInt( localStorage.getItem( PREVIEW_WIDTH_KEY ), 10 );
				if ( ! isNaN( storedWidth ) && storedWidth >= PREVIEW_MIN_WIDTH ) {
					savedWidth = storedWidth;
				}
				var storedHeight = parseInt( localStorage.getItem( PREVIEW_HEIGHT_KEY ), 10 );
				if ( ! isNaN( storedHeight ) && storedHeight >= PREVIEW_MIN_HEIGHT ) {
					savedHeight = storedHeight;
				}
			} catch ( err ) {
				// Ignore storage failures.
			}

			if ( isPreviewFloating() ) {
				applyPreviewSize(
					null !== savedWidth ? savedWidth : PREVIEW_DEFAULT_WIDTH,
					null !== savedHeight ? savedHeight : PREVIEW_DEFAULT_HEIGHT,
					false
				);
			} else if ( null !== savedWidth || null !== savedHeight ) {
				applyPreviewSize(
					null !== savedWidth ? savedWidth : $previewSection.outerWidth(),
					savedHeight,
					false
				);
			}
			syncPreviewDeviceFromWidth( false );

			$handle.on( 'mousedown', function ( e ) {
				if ( e.button !== 0 ) {
					return;
				}
				e.preventDefault();
				var startX = e.pageX;
				var startY = e.pageY;
				var startW = $previewSection.outerWidth();
				var startH = $previewSection.outerHeight();

				$handle.addClass( 'is-dragging' );
				$previewSection.addClass( 'is-resizing' );
				$( 'body' ).css( 'cursor', 'nesw-resize' );

				function onMove( ev ) {
					applyPreviewSize(
						startW + ( ev.pageX - startX ),
						startH + ( startY - ev.pageY ),
						false
					);
					syncPreviewDeviceFromWidth( false );
				}

				function onUp() {
					$( document ).off( 'mousemove.torobpaySliderPreview', onMove );
					$( document ).off( 'mouseup.torobpaySliderPreview', onUp );
					$handle.removeClass( 'is-dragging' );
					$previewSection.removeClass( 'is-resizing' );
					$( 'body' ).css( 'cursor', '' );
					applyPreviewSize( $previewSection.outerWidth(), $previewSection.outerHeight(), true );
					syncPreviewDeviceFromWidth( true );
				}

				$( document ).on( 'mousemove.torobpaySliderPreview', onMove );
				$( document ).on( 'mouseup.torobpaySliderPreview', onUp );
			} );

			$( window ).on( 'resize.torobpaySliderPreview', function () {
				if ( $previewSection.hasClass( 'torobpay-preview-collapsed' ) ) {
					return;
				}
				var width = $previewSection.outerWidth() || PREVIEW_DEFAULT_WIDTH;
				var height = $previewSection.outerHeight();
				var hasStoredHeight = false;
				try {
					hasStoredHeight = null !== localStorage.getItem( PREVIEW_HEIGHT_KEY );
				} catch ( err ) {
					hasStoredHeight = false;
				}
				applyPreviewSize( width, hasStoredHeight || isPreviewFloating() ? height : null, isPreviewFloating() );
			} );

			$previewSection.on( 'click', '.torobpay-preview-toggle', function () {
				if ( $previewSection.hasClass( 'torobpay-preview-collapsed' ) ) {
					return;
				}
				var savedWidth = null;
				var savedHeight = null;
				try {
					var storedWidth = parseInt( localStorage.getItem( PREVIEW_WIDTH_KEY ), 10 );
					if ( ! isNaN( storedWidth ) && storedWidth >= PREVIEW_MIN_WIDTH ) {
						savedWidth = storedWidth;
					}
					var storedHeight = parseInt( localStorage.getItem( PREVIEW_HEIGHT_KEY ), 10 );
					if ( ! isNaN( storedHeight ) && storedHeight >= PREVIEW_MIN_HEIGHT ) {
						savedHeight = storedHeight;
					}
				} catch ( err ) {
					// Ignore storage failures.
				}
				applyPreviewSize(
					null !== savedWidth ? savedWidth : PREVIEW_DEFAULT_WIDTH,
					isPreviewFloating() ? ( null !== savedHeight ? savedHeight : PREVIEW_DEFAULT_HEIGHT ) : savedHeight,
					false
				);
				syncPreviewDeviceFromWidth( false );
			} );
		}

		function getPreviewPlacementIndex() {
			var val = parseInt( $previewPlacementSelect.val(), 10 );
			return isNaN( val ) ? 0 : val;
		}

		function syncPreviewPlacementOptions( $repeater ) {
			if ( ! $previewPlacementSelect.length || ! $repeater || ! $repeater.length ) {
				return;
			}
			var current = getPreviewPlacementIndex();
			$previewPlacementSelect.empty();
			$repeater.find( '.torobpay-slider-placement-item' ).each( function ( index ) {
				var hook = getResolvedHook( $( this ) );
				$previewPlacementSelect.append(
					$( '<option></option>' ).val( index ).text( getHookLabel( hook ) || ( __( 'Location ', 'torobpay-for-woocommerce' ) + ( index + 1 ) ) )
				);
			} );
			var max = $previewPlacementSelect.find( 'option' ).length - 1;
			$previewPlacementSelect.val( Math.max( 0, Math.min( current, max ) ) );
		}

		function getPreviewSlideIndex() {
			var slider = $previewHost.find( '.torobpay-slider' )[ 0 ];
			if ( ! slider ) {
				return 0;
			}
			var slides = slider.querySelectorAll( '.torobpay-slider-slide' );
			for ( var i = 0; i < slides.length; i++ ) {
				if ( slides[ i ].classList.contains( 'is-active' ) ) {
					return i;
				}
			}
			return 0;
		}

		function applyPreviewPausedState( savedSlideIndex ) {
			var sliderEl = $previewHost.find( '.torobpay-slider' )[ 0 ];
			if ( ! sliderEl ) {
				return;
			}
			if ( previewPlaybackPaused ) {
				if (
					null !== savedSlideIndex &&
					typeof sliderEl.torobpaySliderGoTo === 'function'
				) {
					sliderEl.torobpaySliderGoTo( savedSlideIndex );
				}
				if ( typeof sliderEl.torobpaySliderSetPaused === 'function' ) {
					sliderEl.torobpaySliderSetPaused( true );
				}
			} else if ( typeof sliderEl.torobpaySliderSetPaused === 'function' ) {
				sliderEl.torobpaySliderSetPaused( false );
			}
		}

		function syncPreviewPlayback( applyToSlider ) {
			var paused = previewPlaybackPaused;
			if ( $previewPlaybackBtn.length ) {
				$previewPlaybackBtn
					.attr( 'aria-pressed', paused ? 'true' : 'false' )
					.attr( 'aria-label', paused ? __( 'Continue preview', 'torobpay-for-woocommerce' ) : __( 'Stop preview', 'torobpay-for-woocommerce' ) )
					.attr( 'title', paused ? __( 'Continue preview', 'torobpay-for-woocommerce' ) : __( 'Stop preview', 'torobpay-for-woocommerce' ) );
				$previewPlaybackBtn.find( '.dashicons' )
					.removeClass( 'dashicons-controls-pause dashicons-controls-play' )
					.addClass( paused ? 'dashicons-controls-play' : 'dashicons-controls-pause' );
			}
			if ( applyToSlider ) {
				applyPreviewPausedState( null );
			}
		}

		function renderPreview( html, emptyMessage ) {
			if ( ! $previewHost.length ) {
				return;
			}
			if ( html ) {
				var savedSlideIndex = previewPlaybackPaused ? getPreviewSlideIndex() : null;

				$previewHost.html( html );
				$previewEmpty.prop( 'hidden', true );

				var sliderEl = $previewHost.find( '.torobpay-slider' )[ 0 ];
				if ( sliderEl ) {
					sliderEl.setAttribute( 'data-initial-paused', previewPlaybackPaused ? '1' : '0' );
				}
				if ( typeof window.torobpayInitSliders === 'function' ) {
					window.torobpayInitSliders( $previewHost[ 0 ] );
				}
				if ( typeof window.torobpayInitSlideBackgrounds === 'function' ) {
					window.torobpayInitSlideBackgrounds( $previewHost[ 0 ] );
				}
				applyPreviewPausedState( savedSlideIndex );
				syncPreviewPlayback( false );
			} else {
				$previewHost.empty();
				$previewEmpty
					.text( emptyMessage || previewCfg.emptyText || __( 'No slide is selected for this display location.', 'torobpay-for-woocommerce' ) )
					.prop( 'hidden', false );
			}
		}

		function requestPreview() {
			var $tab = $( '#slider-settings' );
			if ( ! previewCfg.ajaxurl || ! $previewSection.length || ! $tab.hasClass( 'active' ) ) {
				return;
			}

			var data = $tab.find( ':input' ).serializeArray();
			data.push( { name: 'action', value: previewCfg.action || 'torobpay_slider_preview' } );
			data.push( { name: 'nonce', value: previewCfg.nonce || '' } );
			data.push( { name: 'placement_index', value: String( getPreviewPlacementIndex() ) } );
			data.push( { name: 'preview_device', value: previewDevice } );

			if ( previewXhr ) {
				previewXhr.abort();
			}

			$previewFrame.addClass( 'is-loading' );

			previewXhr = $.post( previewCfg.ajaxurl, data )
				.done( function ( response ) {
					if ( response && response.success ) {
						renderPreview( response.data.html, response.data.message );
					}
				} )
				.always( function () {
					previewXhr = null;
					$previewFrame.removeClass( 'is-loading' );
				} );
		}

		function schedulePreview() {
			if ( ! $previewSection.length ) {
				return;
			}
			if ( previewTimer ) {
				clearTimeout( previewTimer );
			}
			previewTimer = setTimeout( requestPreview, 300 );
		}

		// Exposed so the media-library "select" callback (openMediaFrame(),
		// defined outside this ready-callback) can trigger a refresh once an
		// image URL is actually written back into the slide's hidden field.
		window.torobpaySliderSchedulePreview = schedulePreview;

		if ( $previewSection.length ) {
			$( document ).on( 'input change', '#slider-settings :input', schedulePreview );

			$previewPlaybackBtn.on( 'click', function () {
				previewPlaybackPaused = ! previewPlaybackPaused;
				syncPreviewPlayback( true );
			} );

			syncPreviewPlayback( true );

			initPreviewResize();
			if ( previewDevice !== 'desktop' ) {
				schedulePreview();
			}
		}

		function syncBgControls( $card, anim ) {
			var isCanvas = anim === 'perlin' || anim === 'rain' || anim === 'float';
			var isIcon = anim === 'perlin' || anim === 'rain' || anim === 'float' || anim === 'clover';
			var isAurora = anim === 'aurora';

			$card.find( '.torobpay-slider-bg-density-control' ).css( 'display', isCanvas ? '' : 'none' );
			$card.find( '.torobpay-slider-bg-speed-control' ).css( 'display', isCanvas ? '' : 'none' );
			$card.find( '.torobpay-slider-bg-custom-icon-control' ).css( 'display', isCanvas ? '' : 'none' );
			$card.find( '.torobpay-slider-bg-icon-scale-control' ).css( 'display', isIcon ? '' : 'none' );
			$card.find( '.torobpay-slider-bg-aurora-scale-control' ).css( 'display', isAurora ? '' : 'none' );

			if ( isIcon ) {
				var iconDefault = anim === 'clover' ? '100' : '200';
				$card.find( '.torobpay-slider-bg-icon-scale-control .torobpay-rf-input' ).each( function () {
					var $input = $( this );
					var device = $input.data( 'device' );
					if ( 'desktop' === device ) {
						$input.attr( 'placeholder', iconDefault );
					} else {
						var desktopPh = $card
							.find( '.torobpay-slider-bg-icon-scale-control .torobpay-rf-input[data-device="desktop"]' )
							.val();
						$input.attr( 'placeholder', desktopPh || iconDefault );
					}
				} );
			}
		}

		function getSlideBgScope( $select ) {
			var $item = $select.closest( '.torobpay-slider-repeater-item' );
			if ( $item.length ) {
				return $item;
			}
			return $select.closest( '.torobpay-slide-settings' );
		}

		$( document ).on( 'change', '.torobpay-slider-bg-anim', function () {
			syncBgControls( getSlideBgScope( $( this ) ), this.value );
		} );

		$( '.torobpay-slider-bg-anim' ).each( function () {
			syncBgControls( getSlideBgScope( $( this ) ), this.value );
		} );

		var SHOP_NAME_HIDDEN = l10n.shopNameHidden || '__none__';

		function getMandatoryShopNameHiddenValue( $block ) {
			return String( $block.data( 'shopNameHidden' ) || SHOP_NAME_HIDDEN );
		}

		function getMandatoryShopNameRaw( $block ) {
			return $.trim( $block.find( 'input[name="torobpay_slider_mandatory[shop_name]"]' ).val() || '' );
		}

		function isMandatoryShopNameHidden( $block ) {
			return getMandatoryShopNameRaw( $block ) === getMandatoryShopNameHiddenValue( $block );
		}

		function isMandatoryShopNameEdited( $block ) {
			var raw = getMandatoryShopNameRaw( $block );
			var siteDefault = String( $block.data( 'siteName' ) || '' );
			var hiddenValue = getMandatoryShopNameHiddenValue( $block );

			if ( raw === hiddenValue ) {
				return true;
			}
			if ( raw === '' || raw === siteDefault ) {
				return false;
			}
			return true;
		}

		function getMandatoryShopNameDisplay( $block ) {
			var raw = getMandatoryShopNameRaw( $block );
			var siteDefault = String( $block.data( 'siteName' ) || '' );
			var hiddenValue = getMandatoryShopNameHiddenValue( $block );

			if ( raw === hiddenValue ) {
				return '';
			}
			if ( raw === '' ) {
				return siteDefault;
			}
			return raw;
		}

		function getMandatoryShopNameHeading( $block ) {
			if ( isMandatoryShopNameHidden( $block ) ) {
				return __( 'Buy in installments with TorobPay', 'torobpay-for-woocommerce' );
			}

			return __( 'Buy in installments from ', 'torobpay-for-woocommerce' ) + getMandatoryShopNameDisplay( $block ) + __( ' with TorobPay', 'torobpay-for-woocommerce' );
		}

		function buildMandatoryShopNameTrigger( displayName ) {
			return $( '<button type="button" class="torobpay-slider-shop-name-trigger" aria-label="' + __( 'Edit store name', 'torobpay-for-woocommerce' ) + '">' )
				.append( $( '<span class="torobpay-slider-shop-name-label"></span>' ).text( displayName ) )
				.append( '<span class="dashicons dashicons-edit" aria-hidden="true"></span>' );
		}

		function buildMandatoryShopNameEditor( currentValue ) {
			var $editor = $( '<span class="torobpay-slider-shop-name-editor"></span>' );
			var $input = $( '<input type="text" class="torobpay-slider-shop-name-input" maxlength="100">' );
			var $clear = $( '<button type="button" class="torobpay-slider-shop-name-clear" aria-label="' + __( 'Clear name', 'torobpay-for-woocommerce' ) + '"></button>' )
				.append( '<span class="dashicons dashicons-no-alt" aria-hidden="true"></span>' );

			$input.val( currentValue );
			return $editor.append( $input ).append( $clear );
		}

		function syncMandatoryShopNameUI( $block ) {
			var hidden = isMandatoryShopNameHidden( $block );
			var edited = isMandatoryShopNameEdited( $block );
			var displayName = getMandatoryShopNameDisplay( $block );

			$block.toggleClass( 'is-shop-name-hidden', hidden );
			$block.toggleClass( 'is-shop-name-edited', edited );

			if ( ! $block.hasClass( 'is-editing-shop-name' ) ) {
				$block.find( '.torobpay-slider-shop-name-label' ).text( displayName );
			}

			$block.find( '.torobpay-slider-shop-name-reset' ).prop( 'hidden', ! edited );
			$block.closest( '.torobpay-slider-repeater-item' ).find( '.torobpay-slider-repeater-title' ).text( getMandatoryShopNameHeading( $block ) );
		}

		function closeMandatoryShopNameEditor( $block, save ) {
			if ( ! $block.hasClass( 'is-editing-shop-name' ) ) {
				return;
			}

			var siteDefault = String( $block.data( 'siteName' ) || '' );
			var hiddenValue = getMandatoryShopNameHiddenValue( $block );
			var $hidden = $block.find( 'input[name="torobpay_slider_mandatory[shop_name]"]' );
			var $editor = $block.find( '.torobpay-slider-shop-name-editor' );
			var $input = $editor.find( '.torobpay-slider-shop-name-input' );

			if ( save ) {
				var next = $.trim( $input.val() || '' );
				if ( next === '' ) {
					next = hiddenValue;
				} else if ( next === siteDefault ) {
					next = '';
				}
				$hidden.val( next );
				syncMandatoryShopNameUI( $block );
				if ( typeof window.torobpaySliderSchedulePreview === 'function' ) {
					window.torobpaySliderSchedulePreview();
				}
			}

			$block.removeClass( 'is-editing-shop-name' );
			$editor.replaceWith( buildMandatoryShopNameTrigger( getMandatoryShopNameDisplay( $block ) ) );
			syncMandatoryShopNameUI( $block );
		}

		function openMandatoryShopNameEditor( $block, startEmpty ) {
			if ( $block.hasClass( 'is-editing-shop-name' ) ) {
				return;
			}

			var siteDefault = String( $block.data( 'siteName' ) || '' );
			var hiddenValue = getMandatoryShopNameHiddenValue( $block );
			var raw = getMandatoryShopNameRaw( $block );
			var current = '';

			if ( startEmpty ) {
				current = '';
			} else if ( raw === hiddenValue ) {
				current = '';
			} else if ( raw === '' ) {
				current = siteDefault;
			} else {
				current = raw;
			}

			var $trigger = $block.find( '.torobpay-slider-shop-name-trigger' );
			var $editor = buildMandatoryShopNameEditor( current );

			$block.addClass( 'is-editing-shop-name' );
			$trigger.replaceWith( $editor );

			var $input = $editor.find( '.torobpay-slider-shop-name-input' );
			$input.trigger( 'focus' );
			if ( ! startEmpty && current !== '' ) {
				$input[ 0 ].select();
			}

			$input.on( 'keydown', function ( e ) {
				if ( e.key === 'Enter' ) {
					e.preventDefault();
					closeMandatoryShopNameEditor( $block, true );
				} else if ( e.key === 'Escape' ) {
					e.preventDefault();
					closeMandatoryShopNameEditor( $block, false );
				}
			} );

			$input.on( 'blur', function () {
				window.setTimeout( function () {
					closeMandatoryShopNameEditor( $block, true );
				}, 0 );
			} );
		}

		var $mandatoryList = $( '.torobpay-slider-mandatory-list' );
		if ( $mandatoryList.length ) {
			$mandatoryList.find( '.torobpay-slider-locked-text' ).each( function () {
				syncMandatoryShopNameUI( $( this ) );
			} );

			$mandatoryList.on( 'click', '.torobpay-slider-repeater-head', function ( e ) {
				if ( $( e.target ).closest( '.torobpay-slide-shortcode-toggle, .torobpay-slide-shortcode-panel' ).length ) {
					return;
				}
				$( this ).closest( '.torobpay-slider-repeater-item' ).toggleClass( 'is-collapsed' );
			} );

			$mandatoryList.on( 'click', '.torobpay-slider-shop-name-trigger', function ( e ) {
				e.preventDefault();
				openMandatoryShopNameEditor( $( this ).closest( '.torobpay-slider-locked-text' ), false );
			} );

			$mandatoryList.on( 'click', '.torobpay-slider-shop-name-reset', function ( e ) {
				e.preventDefault();
				var $block = $( this ).closest( '.torobpay-slider-locked-text' );
				if ( $block.hasClass( 'is-editing-shop-name' ) ) {
					closeMandatoryShopNameEditor( $block, false );
				}
				$block.find( 'input[name="torobpay_slider_mandatory[shop_name]"]' ).val( '' );
				syncMandatoryShopNameUI( $block );
				if ( typeof window.torobpaySliderSchedulePreview === 'function' ) {
					window.torobpaySliderSchedulePreview();
				}
			} );

			$mandatoryList.on( 'click', '.torobpay-slider-shop-name-clear', function ( e ) {
				e.preventDefault();
				e.stopPropagation();
				$( this ).closest( '.torobpay-slider-shop-name-editor' ).find( '.torobpay-slider-shop-name-input' ).val( '' ).trigger( 'focus' );
			} );

			$mandatoryList.on( 'mousedown', '.torobpay-slider-shop-name-clear, .torobpay-slider-shop-name-reset', function ( e ) {
				e.preventDefault();
			} );

			$( '#slider-settings' ).closest( 'form' ).on( 'submit', function () {
				$( '.torobpay-slider-locked-text.is-editing-shop-name' ).each( function () {
					closeMandatoryShopNameEditor( $( this ), true );
				} );
			} );
		}

		var $slideRepeater = $( '#torobpay-slider-repeater' );
		var $placementRepeater = $( '#torobpay-slider-placements-repeater' );

		if ( $slideRepeater.length ) {
			syncSlidePlacementOptions( $placementRepeater );

			$( document ).on( 'click', '.torobpay-slider-add-slide', function () {
				var tmpl = $( '#torobpay-slider-slide-template' ).html();
				if ( ! tmpl ) {
					return;
				}
				var position = $( this ).data( 'position' ) || 'end';
				var index = $slideRepeater.children( '.torobpay-slider-repeater-item' ).length;
				var html = tmpl.replace( /__INDEX__/g, index );
				var $new = $( html );
				if ( position === 'start' ) {
					$slideRepeater.prepend( $new );
				} else {
					$slideRepeater.append( $new );
				}
				reindexSlides( $slideRepeater );

				if ( typeof window.torobpayInitDeviceSwitchers === 'function' ) {
					window.torobpayInitDeviceSwitchers( $new[ 0 ] );
				}
				syncSlidePlacementOptions( $placementRepeater );
				schedulePreview();
			} );

			$slideRepeater.on( 'click', '.torobpay-slider-remove:not(.torobpay-slider-placement-remove)', function () {
				if ( l10n.confirmDel && ! window.confirm( l10n.confirmDel ) ) {
					return;
				}
				$( this ).closest( '.torobpay-slider-repeater-item' ).remove();
				reindexSlides( $slideRepeater );
				schedulePreview();
			} );

			$slideRepeater.on( 'input', '.torobpay-slider-field-heading', function () {
				var val = $( this ).val();
				$( this )
					.closest( '.torobpay-slider-repeater-item' )
					.find( '.torobpay-slider-repeater-title' )
					.text( val ? val : __( 'New slide', 'torobpay-for-woocommerce' ) );
			} );

			$slideRepeater.on( 'click', '.torobpay-slider-repeater-head', function ( e ) {
				if ( $( e.target ).closest( '.torobpay-slider-remove, .torobpay-slider-drag, .torobpay-slider-placement-enabled, .torobpay-slide-shortcode-toggle, .torobpay-slide-shortcode-panel' ).length ) {
					return;
				}
				$( this ).closest( '.torobpay-slider-repeater-item' ).toggleClass( 'is-collapsed' );
			} );

			$slideRepeater.on( 'click', '.torobpay-slider-image-select', function ( e ) {
				e.preventDefault();
				openMediaFrame( $( this ).closest( '.torobpay-slider-repeater-item' ) );
			} );

			$slideRepeater.on( 'click', '.torobpay-slider-image-remove', function ( e ) {
				e.preventDefault();
				var $item = $( this ).closest( '.torobpay-slider-repeater-item' );
				$item.find( '.torobpay-slider-image-url' ).val( '' );
				$item.find( '.torobpay-slider-image-id' ).val( '' );
				updateSlideImageCard( $item, '' );
				schedulePreview();
			} );

			$slideRepeater.on( 'change', '.torobpay-slider-image-object-position', function () {
				var $item = $( this ).closest( '.torobpay-slider-repeater-item' );
				syncSlideImagePositionControl( $item );
				schedulePreview();
			} );

			$slideRepeater.find( '.torobpay-slider-repeater-item' ).each( function () {
				syncSlideImagePositionControl( $( this ) );
			} );

			$( document ).on( 'click', '.torobpay-slider-bg-icon-select', function ( e ) {
				e.preventDefault();
				openBgIconMediaFrame( $( this ).closest( '.torobpay-slider-bg-custom-icon-control' ) );
			} );

			$( document ).on( 'click', '.torobpay-slider-bg-icon-remove', function ( e ) {
				e.preventDefault();
				var $scope = $( this ).closest( '.torobpay-slider-bg-custom-icon-control' );
				$scope.find( '.torobpay-slider-bg-icon-url' ).val( '' );
				$scope.find( '.torobpay-slider-bg-icon-id' ).val( '' );
				updateBgIconCard( $scope, '' );
				schedulePreview();
			} );

			$( document ).on( 'change', '.torobpay-placement-chip input', function () {
				$( this ).closest( '.torobpay-placement-chip' ).toggleClass( 'is-selected', this.checked );
			} );

			$( document ).on( 'click', '.torobpay-placement-orphan-remove', function ( e ) {
				e.preventDefault();
				var $item = $( this ).closest( '.torobpay-slide-placements-orphan-item' );
				var $container = $item.closest( '.torobpay-slide-placements-chips' );
				$item.remove();
				if ( ! $container.find( '.torobpay-slide-placements-orphan-item' ).length ) {
					$container.find( '.torobpay-slide-placements-orphaned' ).remove();
				}
			} );

			if ( $.fn.sortable ) {
				$slideRepeater.sortable( {
					handle: '.torobpay-slider-drag',
					items: '> .torobpay-slider-repeater-item',
					update: function () {
						reindexSlides( $slideRepeater );
						schedulePreview();
					},
				} );
			}
		}

		if ( $placementRepeater.length ) {
			var $placementNav = $( '#torobpay-slider-placement-nav' );
			var placementTabBar = document.querySelector( '.torobpay-slider-placement-tab-bar' );
			var layoutPlacementTabOverflow = function () {};

			function getPlacementPanels() {
				return $placementRepeater.children( '.torobpay-slider-placement-item' );
			}

			function activatePlacementByIndex( indexStr ) {
				var index = parseInt( indexStr, 10 );
				activatePlacementPanel( $placementNav, getPlacementPanels(), index );
				layoutPlacementTabOverflow();
			}

			if ( typeof initSettingsTabOverflow === 'function' && placementTabBar ) {
				layoutPlacementTabOverflow = initSettingsTabOverflow( placementTabBar, activatePlacementByIndex );
			}

			function refreshPlacementTabs( preferredIndex ) {
				syncPlacementTabs( $placementNav, getPlacementPanels(), preferredIndex );
				layoutPlacementTabOverflow();
				syncPreviewPlacementOptions( $placementRepeater );
				schedulePreview();
			}

			$placementRepeater.find( '.torobpay-slider-placement-item' ).each( function () {
				var $row = $( this );
				initPlacementRow( $row );
				storePrevHook( $row );
			} );
			updateHookSelectOptions( $placementRepeater );
			refreshPlacementTabs( 0 );
			syncSlidePlacementOptions( $placementRepeater );

			$( '#torobpay-slider-add-placement' ).on( 'click', function () {
				var tmpl = $( '#torobpay-slider-placement-template' ).html();
				if ( ! tmpl ) {
					return;
				}
				var index = $placementRepeater.children( '.torobpay-slider-placement-item' ).length;
				var html = tmpl.replace( /__INDEX__/g, index );
				var $new = $( html );
				var unused = findUnusedPredefinedHook( $placementRepeater );
				$placementRepeater.append( $new );
				reindexPlacements( $placementRepeater );
				if ( unused ) {
					$new.find( '.torobpay-slider-placement-hook-select' ).val( unused );
					$new.find( '.torobpay-slider-placement-custom-hook' ).hide();
				} else {
					if ( l10n.noUnusedHook ) {
						showToast( l10n.noUnusedHook, 'info' );
					}
					$new.find( '.torobpay-slider-placement-hook-select' ).val( 'custom' );
					$new.find( '.torobpay-slider-placement-custom-hook' ).show();
				}
				initPlacementRow( $new );
				applyPresetForRow( $new );
				storePrevHook( $new );
				updateHookSelectOptions( $placementRepeater );
				refreshPlacementTabs( $new.index() );
				syncSlidePlacementOptions( $placementRepeater );
			} );

			$placementNav.on( 'click', '.torobpay-slider-placement-tab', function () {
				activatePlacementByIndex( $( this ).attr( 'data-tab' ) );
			} );

			$placementRepeater.on( 'click', '.torobpay-slider-placement-remove', function ( e ) {
				e.preventDefault();
				if ( $placementRepeater.children( '.torobpay-slider-placement-item' ).length <= 1 ) {
					showToast( __( 'At least one display location must remain.', 'torobpay-for-woocommerce' ), 'warning' );
					return;
				}
				if ( l10n.confirmDelPlacement && ! window.confirm( l10n.confirmDelPlacement ) ) {
					return;
				}
				var $item = $( this ).closest( '.torobpay-slider-placement-item' );
				var removeIndex = $item.index();
				var activeIndex = getActivePlacementIndex(
					$placementNav,
					$placementRepeater.children( '.torobpay-slider-placement-item' )
				);
				$item.remove();
				reindexPlacements( $placementRepeater );
				if ( activeIndex >= removeIndex && activeIndex > 0 ) {
					activeIndex -= 1;
				}
				updateHookSelectOptions( $placementRepeater );
				refreshPlacementTabs( activeIndex );
				syncSlidePlacementOptions( $placementRepeater );
			} );

			$placementRepeater.on(
				'focusin',
				'.torobpay-slider-placement-hook-select, .torobpay-slider-placement-hook-custom',
				function () {
					storePrevHook( $( this ).closest( '.torobpay-slider-placement-item' ) );
				}
			);

			$placementRepeater.on( 'change', '.torobpay-slider-placement-hook-select', function () {
				var $row = $( this ).closest( '.torobpay-slider-placement-item' );
				if ( ! validateHookChange( $row, $placementRepeater ) ) {
					syncPlacementHookUi( $row );
					updateHookSelectOptions( $placementRepeater );
					refreshPlacementTabs( $row.index() );
					return;
				}
				syncPlacementHookUi( $row );
				applyPresetForRow( $row );
				updateHookSelectOptions( $placementRepeater );
				refreshPlacementTabs( $row.index() );
				syncSlidePlacementOptions( $placementRepeater );
			} );

			$placementRepeater.on( 'input', '.torobpay-slider-placement-hook-custom', function () {
				var $row = $( this ).closest( '.torobpay-slider-placement-item' );
				syncPlacementHookUi( $row );
				refreshPlacementTabs( $row.index() );
				syncSlidePlacementOptions( $placementRepeater );
			} );

			$placementRepeater.on( 'change', '.torobpay-slider-placement-hook-custom', function () {
				var $row = $( this ).closest( '.torobpay-slider-placement-item' );
				if ( ! validateHookChange( $row, $placementRepeater ) ) {
					syncPlacementHookUi( $row );
					updateHookSelectOptions( $placementRepeater );
					refreshPlacementTabs( $row.index() );
					return;
				}
				syncPlacementHookUi( $row );
				updateHookSelectOptions( $placementRepeater );
				refreshPlacementTabs( $row.index() );
				syncSlidePlacementOptions( $placementRepeater );
			} );

			$placementRepeater.on( 'change', 'input[type="checkbox"][name$="[enabled]"]', function () {
				refreshPlacementTabs(
					$( this ).closest( '.torobpay-slider-placement-item' ).index()
				);
			} );

			$placementRepeater.on( 'change', '.torobpay-slider-placement-unit', function () {
				syncPlacementDimensionBounds( $( this ).closest( '.torobpay-slider-placement-dimension-field' ) );
			} );
		}

		$( document ).on( 'click', '.torobpay-slide-shortcode-toggle', function ( e ) {
			e.preventDefault();
			e.stopPropagation();

			var $btn = $( this );
			var $item = $btn.closest( '.torobpay-slider-repeater-item' );
			var $panel = $item.children( '.torobpay-slide-shortcode-panel' );
			if ( ! $panel.length ) {
				return;
			}

			var isOpen = $btn.hasClass( 'is-open' );
			$item.find( '.torobpay-slide-shortcode-toggle' ).removeClass( 'is-open' ).attr( 'aria-expanded', 'false' );
			$item.find( '.torobpay-slide-shortcode-panel' ).prop( 'hidden', true );

			if ( ! isOpen ) {
				$btn.addClass( 'is-open' ).attr( 'aria-expanded', 'true' );
				$panel.prop( 'hidden', false );
			}
		} );

		$( document ).on( 'click', '.torobpay-slide-shortcode-copy', function ( e ) {
			e.stopPropagation();
			var $input = $( this ).closest( '.torobpay-slide-shortcode-row' ).find( '.torobpay-slide-shortcode-input' );
			var text = $input.val();
			if ( ! text ) {
				return;
			}

			if ( navigator.clipboard && navigator.clipboard.writeText ) {
				navigator.clipboard.writeText( text ).then( function () {
					showToast( l10n.copiedShortcode || __( 'Shortcode copied', 'torobpay-for-woocommerce' ), 'success' );
				} );
				return;
			}

			$input.trigger( 'focus' ).select();
			try {
				document.execCommand( 'copy' );
				showToast( l10n.copiedShortcode || __( 'Shortcode copied', 'torobpay-for-woocommerce' ), 'success' );
			} catch ( e ) {
				showToast( __( 'Automatic copy failed — select the text manually.', 'torobpay-for-woocommerce' ), 'warning' );
			}
		} );
	} );
}( jQuery ) );
