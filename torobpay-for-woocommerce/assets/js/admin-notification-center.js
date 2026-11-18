/**
 * TorobPay Notification Center — Admin Bar + Panel JS
 *
 * Handles:
 *  - Opening the notification panel when the admin bar bell is clicked
 *  - Closing on backdrop click, Escape key, or close button
 *  - Dismissing individual notifications via AJAX
 *  - Updating the admin bar badge count and tier class after dismissal
 *
 * Inherits the modal pattern from admin-order-actions.js (same CSS classes,
 * same fadeIn/fadeOut approach) but is a completely separate file (SoC).
 *
 * Expects: window.torobpayNotifCenter = { ajaxurl, nonce }
 */
jQuery( function ( $ ) {
    'use strict';

    var __ = ( window.wp && wp.i18n && wp.i18n.__ ) ? wp.i18n.__ : function( s ) { return s; };

    var $overlay = $( '#torobpay-notification-panel-overlay' );
    var $barNode = $( '#wp-admin-bar-torobpay-notifications' );

    if ( ! $overlay.length ) {
        return;
    }

    // The category that the panel is currently filtered to. Persisted across
    // AJAX body refreshes (dismiss / undismiss) so the active filter sticks.
    var activeCategory = 'all';

    // following href="#". stopImmediatePropagation prevents WP's own mobile
    // admin bar toggle handler from competing on this click.
    $( document ).on( 'click', '#wp-admin-bar-torobpay-notifications > .ab-item', function ( e ) {
        e.preventDefault();
        e.stopImmediatePropagation();
        openPanel( 'all' );
    } );

    // encoded in the list item id (torobpay-notif-cat-<category>).
    $( document ).on( 'click', '#wp-admin-bar-torobpay-notifications .ab-submenu .ab-item', function ( e ) {
        var id    = $( this ).closest( 'li' ).attr( 'id' ) || '';
        var match = id.match( /torobpay-notif-cat-(.+)$/ );
        if ( ! match ) {
            return;
        }
        e.preventDefault();
        e.stopImmediatePropagation();
        openPanel( match[1] );
    } );

    function openPanel( category ) {
        $overlay.fadeIn( 200 );
        applyFilter( category || 'all' );
        $( document ).on( 'keydown.torobpay-notif', function ( e ) {
            if ( e.key === 'Escape' || e.keyCode === 27 ) {
                closePanel();
            }
        } );
    }

    $overlay.on( 'click', '#torobpay-notif-panel-close, .torobpay-modal-close', function () {
        closePanel();
    } );

    $overlay.on( 'click', function ( e ) {
        if ( $( e.target ).is( $overlay ) ) {
            closePanel();
        }
    } );

    function closePanel() {
        $overlay.fadeOut( 200 );
        $( document ).off( 'keydown.torobpay-notif' );
    }

    $overlay.on( 'click', '.torobpay-notif-filter-chip', function ( e ) {
        e.preventDefault();
        applyFilter( $( this ).data( 'category' ) );
    } );

    function applyFilter( category ) {
        category       = category || 'all';
        activeCategory = category;

        var $body = $overlay.find( '.torobpay-modal-body' );

        // (e.g. it was just emptied), fall back to "all".
        var $chips  = $body.find( '.torobpay-notif-filter-chip' );
        var $target = $chips.filter( '[data-category="' + category + '"]' );
        if ( $chips.length && ! $target.length ) {
            category       = 'all';
            activeCategory = 'all';
            $target        = $chips.filter( '[data-category="all"]' );
        }
        $chips.removeClass( 'is-active' );
        $target.addClass( 'is-active' );

        var $items = $body.find( '.torobpay-notification-item' );
        if ( 'all' === category ) {
            $items.show();
        } else {
            $items.hide().filter( '[data-category="' + category + '"]' ).show();
        }

        // Closed <details> children are not :visible, so do not use that to
        // decide whether the dismissed section itself should be shown.
        $body.find( '.torobpay-dismissed-section' ).each( function () {
            var $section      = $( this );
            var $sectionItems = $section.find( '.torobpay-notification-item' );
            var matchCount    = ( 'all' === category )
                ? $sectionItems.length
                : $sectionItems.filter( '[data-category="' + category + '"]' ).length;
            $section.toggle( matchCount > 0 );
        } );

        var $activeMatch    = $body.find( '.torobpay-notification-item' ).not( '.torobpay-notification-dismissed' );
        var $dismissedMatch = $body.find( '.torobpay-notification-dismissed' );
        if ( 'all' !== category ) {
            $activeMatch    = $activeMatch.filter( '[data-category="' + category + '"]' );
            $dismissedMatch = $dismissedMatch.filter( '[data-category="' + category + '"]' );
        }
        toggleFilterEmpty( $body, 0 === ( $activeMatch.length + $dismissedMatch.length ) && 'all' !== category );
    }

    function toggleFilterEmpty( $body, show ) {
        var $msg = $body.find( '.torobpay-filter-empty' );
        if ( show ) {
            if ( ! $msg.length ) {
                $msg = $(
                    '<div class="torobpay-filter-empty">' +
                    '<span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>' +
                    '<p>' + ( window.wp && wp.i18n ? wp.i18n.__( 'No notifications in this category.', 'torobpay-for-woocommerce' ) : 'No notifications in this category.' ) + '</p>' +
                    '</div>'
                );
                $body.append( $msg );
            }
            $msg.show();
        } else if ( $msg.length ) {
            $msg.hide();
        }
    }

    $overlay.on( 'click', '.torobpay-notification-dismiss', function ( e ) {
        e.preventDefault();
        e.stopPropagation();

        var $btn  = $( this );
        var $item = $btn.closest( '.torobpay-notification-item' );
        var id    = $item.attr( 'data-notification-id' );

        if ( ! id ) {
            return;
        }

        $btn.prop( 'disabled', true );

        $.ajax( {
            type: 'POST',
            url:  torobpayNotifCenter.ajaxurl,
            data: {
                action:          'torobpay_dismiss_notification',
                notification_id: id,
                security:        torobpayNotifCenter.nonce,
            },
            success: function ( response ) {
                if ( ! response.success ) {
                    $btn.prop( 'disabled', false );
                    return;
                }

                $overlay.find( '.torobpay-modal-body' ).html( response.data.panel_html );
                updateBadge( response.data.badge_count, response.data.tier );
                applyFilter( activeCategory );
                $overlay.find( '.torobpay-dismissed-section:visible' ).prop( 'open', true );
            },
            error: function () {
                $btn.prop( 'disabled', false );
            },
        } );
    } );

    $overlay.on( 'click', '.torobpay-notification-recheck', function ( e ) {
        e.preventDefault();
        e.stopPropagation();

        var $btn = $( this );
        if ( $btn.prop( 'disabled' ) ) {
            return;
        }

        var label = $btn.text();
        $btn.prop( 'disabled', true ).text( $btn.attr( 'data-label-busy' ) || label );

        $.ajax( {
            type: 'POST',
            url:  torobpayNotifCenter.ajaxurl,
            data: {
                action:   'torobpay_recheck_eligibility_contract',
                security: torobpayNotifCenter.nonce,
            },
            success: function ( response ) {
                if ( ! response.success ) {
                    $btn.prop( 'disabled', false ).text( label );
                    return;
                }

                $overlay.find( '.torobpay-modal-body' ).html( response.data.panel_html );
                updateBadge( response.data.badge_count, response.data.tier );
                applyFilter( activeCategory );

                if ( response.data.message ) {
                    var $body = $overlay.find( '[data-notification-id="eligibility_error_1100"] .torobpay-notification-body' );
                    if ( $body.length ) {
                        $body.append( $( '<p>', { 'class': 'torobpay-recheck-result', text: response.data.message } ) );
                    }
                }
            },
            error: function () {
                $btn.prop( 'disabled', false ).text( label );
            },
        } );
    } );

    $overlay.on( 'click', '.torobpay-notification-restore', function ( e ) {
        e.preventDefault();
        e.stopPropagation();

        var $btn  = $( this );
        var $item = $btn.closest( '.torobpay-notification-item' );
        var id    = $item.attr( 'data-notification-id' );

        if ( ! id ) {
            return;
        }

        $btn.prop( 'disabled', true );

        $.ajax( {
            type: 'POST',
            url:  torobpayNotifCenter.ajaxurl,
            data: {
                action:          'torobpay_undismiss_notification',
                notification_id: id,
                security:        torobpayNotifCenter.nonce,
            },
            success: function ( response ) {
                if ( ! response.success ) {
                    $btn.prop( 'disabled', false );
                    return;
                }

                $overlay.find( '.torobpay-modal-body' ).html( response.data.panel_html );
                updateBadge( response.data.badge_count, response.data.tier );
                applyFilter( activeCategory );
            },
            error: function () {
                $btn.prop( 'disabled', false );
            },
        } );
    } );

    function updateBadge( count, tier ) {

        var $badge = $barNode.find( '.torobpay-notif-badge' );

        if ( count > 0 ) {
            $badge.text( count ).css( 'display', 'inline-flex' );
        } else {
            $badge.text( '' ).hide();
        }

        $barNode.removeClass( 'has-attention-critical has-attention-notice' );
        if ( count > 0 && tier ) {
            $barNode.addClass( 'has-attention-' + tier );
        }
    }
} );