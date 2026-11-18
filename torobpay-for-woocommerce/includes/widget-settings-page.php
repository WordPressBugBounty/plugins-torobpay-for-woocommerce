<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * TorobPay Widget Settings Page
 *
 * This file contains the admin page for configuring widget look and feel
 */

function torobpay_widget_settings_page() {
	if ( isset( $_POST['submit'] ) && isset( $_POST['torobpay_widget_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['torobpay_widget_nonce'] ) ), 'torobpay_widget_settings' ) ) {
		update_option( 'torobpay_widget_enabled', isset( $_POST['torobpay_widget_enabled'] ) ? 'yes' : 'no' );
		update_option( 'torobpay_badge_enabled', isset( $_POST['torobpay_badge_enabled'] ) ? 'yes' : 'no' );
		update_option( 'torobpay_marquee_enabled', isset( $_POST['torobpay_marquee_enabled'] ) ? 'yes' : 'no' );
		update_option( 'torobpay_topbar_enabled', isset( $_POST['torobpay_topbar_enabled'] ) ? 'yes' : 'no' );
		update_option( 'torobpay_slider_enabled', isset( $_POST['torobpay_slider_enabled'] ) ? 'yes' : 'no' );

		update_option( 'torobpay_widget_layout', torobpay_sanitize_widget_layout( isset( $_POST['widget_layout'] ) ? sanitize_text_field( wp_unslash( $_POST['widget_layout'] ) ) : 'classic' ) );
		update_option( 'torobpay_widget_background_color', isset( $_POST['widget_background_color'] ) ? sanitize_hex_color( wp_unslash( $_POST['widget_background_color'] ) ) : '' );
		update_option( 'torobpay_widget_border_color', isset( $_POST['widget_border_color'] ) ? sanitize_hex_color( wp_unslash( $_POST['widget_border_color'] ) ) : '' );
		update_option( 'torobpay_widget_text_color', isset( $_POST['widget_text_color'] ) ? sanitize_hex_color( wp_unslash( $_POST['widget_text_color'] ) ) : '' );
		torobpay_save_responsive_number( 'widget_border_size', 'torobpay_widget_border_size', 0, 20, 1 );
		torobpay_save_responsive_number( 'widget_border_radius', 'torobpay_widget_border_radius', 0, 999, 16 );
		torobpay_save_responsive_number( 'widget_title_font_size', 'torobpay_widget_title_font_size', 10, 30, 14 );
		update_option( 'torobpay_widget_title_font_weight', isset( $_POST['widget_title_font_weight'] ) ? sanitize_text_field( wp_unslash( $_POST['widget_title_font_weight'] ) ) : '' );
		torobpay_save_responsive_number( 'widget_desc_font_size', 'torobpay_widget_desc_font_size', 8, 24, 12 );
		update_option( 'torobpay_widget_desc_font_weight', isset( $_POST['widget_desc_font_weight'] ) ? sanitize_text_field( wp_unslash( $_POST['widget_desc_font_weight'] ) ) : '' );
		update_option( 'torobpay_widget_logo_animation', isset( $_POST['widget_logo_animation'] ) ? sanitize_text_field( wp_unslash( $_POST['widget_logo_animation'] ) ) : '' );

		// Widget display hook settings
		$selected_hook = isset( $_POST['widget_display_hook'] ) ? sanitize_text_field( wp_unslash( $_POST['widget_display_hook'] ) ) : '';
		if ( $selected_hook === 'custom' && ! empty( $_POST['widget_display_hook_custom'] ) ) {
			$selected_hook = sanitize_text_field( wp_unslash( $_POST['widget_display_hook_custom'] ) );
		}
		update_option( 'torobpay_widget_display_hook', $selected_hook );
		update_option( 'torobpay_widget_display_priority', isset( $_POST['widget_display_priority'] ) ? absint( wp_unslash( $_POST['widget_display_priority'] ) ) : 10 );
		if ( function_exists( 'torobpay_save_pdp_promo_from_post' ) ) {
			torobpay_save_pdp_promo_from_post();
		}

		update_option( 'torobpay_badge_position_horizontal', isset( $_POST['badge_position_horizontal'] ) ? sanitize_text_field( wp_unslash( $_POST['badge_position_horizontal'] ) ) : '' );
		update_option( 'torobpay_badge_position_vertical', isset( $_POST['badge_position_vertical'] ) ? sanitize_text_field( wp_unslash( $_POST['badge_position_vertical'] ) ) : '' );
		torobpay_save_responsive_number( 'badge_offset_horizontal', 'torobpay_badge_offset_horizontal', 0, 1000, 10 );
		torobpay_save_responsive_number( 'badge_offset_vertical', 'torobpay_badge_offset_vertical', 0, 1000, 10 );
		update_option( 'torobpay_badge_offset_horizontal_unit', isset( $_POST['badge_offset_horizontal_unit'] ) ? sanitize_text_field( wp_unslash( $_POST['badge_offset_horizontal_unit'] ) ) : 'px' );
		update_option( 'torobpay_badge_offset_vertical_unit', isset( $_POST['badge_offset_vertical_unit'] ) ? sanitize_text_field( wp_unslash( $_POST['badge_offset_vertical_unit'] ) ) : 'px' );
		update_option( 'torobpay_badge_background_color', isset( $_POST['badge_background_color'] ) ? sanitize_hex_color( wp_unslash( $_POST['badge_background_color'] ) ) : '' );
		update_option( 'torobpay_badge_text_color', isset( $_POST['badge_text_color'] ) ? sanitize_hex_color( wp_unslash( $_POST['badge_text_color'] ) ) : '' );
		torobpay_save_responsive_number( 'badge_border_radius', 'torobpay_badge_border_radius', 0, 50, 8 );
		torobpay_save_responsive_number( 'badge_font_size', 'torobpay_badge_font_size', 8, 24, 12 );
		update_option( 'torobpay_badge_font_weight', isset( $_POST['badge_font_weight'] ) ? sanitize_text_field( wp_unslash( $_POST['badge_font_weight'] ) ) : '' );
		torobpay_save_responsive_number( 'badge_logo_height', 'torobpay_badge_logo_height', 10, 48, 24 );
		update_option( 'torobpay_badge_compatibility_mode', isset( $_POST['badge_compatibility_mode'] ) ? 'yes' : 'no' );

		update_option( 'torobpay_marquee_bg_color', isset( $_POST['marquee_bg_color'] ) ? sanitize_hex_color( wp_unslash( $_POST['marquee_bg_color'] ) ) : '#ffffff' );
		update_option( 'torobpay_marquee_bg_opacity', isset( $_POST['marquee_bg_opacity'] ) ? min( 100, max( 0, absint( wp_unslash( $_POST['marquee_bg_opacity'] ) ) ) ) : 22 );
		update_option( 'torobpay_marquee_text_color', isset( $_POST['marquee_text_color'] ) ? sanitize_hex_color( wp_unslash( $_POST['marquee_text_color'] ) ) : '#003D01' );
		torobpay_save_responsive_number( 'marquee_font_size', 'torobpay_marquee_font_size', 8, 24, 11 );
		update_option( 'torobpay_marquee_font_weight', isset( $_POST['marquee_font_weight'] ) ? sanitize_text_field( wp_unslash( $_POST['marquee_font_weight'] ) ) : '600' );
		torobpay_save_responsive_number( 'marquee_height', 'torobpay_marquee_height', 16, 400, 26 );
		update_option( 'torobpay_marquee_scroll_speed', isset( $_POST['marquee_scroll_speed'] ) ? min( 60, max( 4, absint( wp_unslash( $_POST['marquee_scroll_speed'] ) ) ) ) : 14 );
		update_option( 'torobpay_marquee_position', ( isset( $_POST['marquee_position'] ) && 'top' === sanitize_text_field( wp_unslash( $_POST['marquee_position'] ) ) ) ? 'top' : 'bottom' );
		torobpay_save_responsive_number( 'marquee_gap', 'torobpay_marquee_gap', 0, 1000, 0 );
		update_option( 'torobpay_marquee_gap_unit', ( isset( $_POST['marquee_gap_unit'] ) && '%' === sanitize_text_field( wp_unslash( $_POST['marquee_gap_unit'] ) ) ) ? '%' : 'px' );
		update_option( 'torobpay_marquee_vertical_padding', isset( $_POST['marquee_vertical_padding'] ) ? max( 0, round( floatval( wp_unslash( $_POST['marquee_vertical_padding'] ) ), 2 ) ) : 0 );
		update_option( 'torobpay_marquee_vertical_padding_unit', ( isset( $_POST['marquee_vertical_padding_unit'] ) && in_array( sanitize_text_field( wp_unslash( $_POST['marquee_vertical_padding_unit'] ) ), [ 'px', '%', 'rem' ], true ) ) ? sanitize_text_field( wp_unslash( $_POST['marquee_vertical_padding_unit'] ) ) : 'px' );
		torobpay_save_responsive_number( 'marquee_radius_tl', 'torobpay_marquee_radius_tl', 0, 200, 0 );
		torobpay_save_responsive_number( 'marquee_radius_tr', 'torobpay_marquee_radius_tr', 0, 200, 0 );
		torobpay_save_responsive_number( 'marquee_radius_br', 'torobpay_marquee_radius_br', 0, 200, 0 );
		torobpay_save_responsive_number( 'marquee_radius_bl', 'torobpay_marquee_radius_bl', 0, 200, 0 );
		torobpay_save_responsive_number( 'marquee_icon_size', 'torobpay_marquee_icon_size', 8, 48, 14 );
		update_option( 'torobpay_marquee_z_index', isset( $_POST['marquee_z_index'] ) ? max( 0, absint( wp_unslash( $_POST['marquee_z_index'] ) ) ) : 10 );
		update_option( 'torobpay_marquee_height_unit', ( isset( $_POST['marquee_height_unit'] ) && '%' === sanitize_text_field( wp_unslash( $_POST['marquee_height_unit'] ) ) ) ? '%' : 'px' );
		update_option( 'torobpay_marquee_vertical_align', ( isset( $_POST['marquee_vertical_align'] ) && in_array( sanitize_text_field( wp_unslash( $_POST['marquee_vertical_align'] ) ), [ 'top', 'middle', 'bottom' ], true ) ) ? sanitize_text_field( wp_unslash( $_POST['marquee_vertical_align'] ) ) : 'middle' );
		update_option( 'torobpay_marquee_compatibility_mode', isset( $_POST['marquee_compatibility_mode'] ) ? 'yes' : 'no' );

		torobpay_save_responsive_number( 'topbar_height', 'torobpay_topbar_height', 20, 80, 44 );
		torobpay_save_responsive_number( 'topbar_font_size', 'torobpay_topbar_font_size', 10, 24, 14 );
		torobpay_save_responsive_number( 'topbar_logo_size', 'torobpay_topbar_logo_size', 16, 48, 24 );
		torobpay_save_responsive_number( 'topbar_ticker_width', 'torobpay_topbar_ticker_width', 100, 1200, 250 );
		update_option( 'torobpay_topbar_font_weight', isset( $_POST['topbar_font_weight'] ) ? sanitize_text_field( wp_unslash( $_POST['topbar_font_weight'] ) ) : '700' );
		update_option( 'torobpay_topbar_speed', isset( $_POST['topbar_speed'] ) ? max( 2, min( 20, absint( wp_unslash( $_POST['topbar_speed'] ) ) ) ) : 8 );
		$topbar_allowed_animations = [ 'slide-up', 'slide-down', 'fade', 'flip', 'zoom', 'blur', 'bounce', 'spread' ];
		update_option( 'torobpay_topbar_animation', ( isset( $_POST['topbar_animation'] ) && in_array( sanitize_key( wp_unslash( $_POST['topbar_animation'] ) ), $topbar_allowed_animations, true ) ) ? sanitize_key( wp_unslash( $_POST['topbar_animation'] ) ) : 'slide-up' );
		$topbar_custom_sentence = isset( $_POST['topbar_custom_sentence'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['topbar_custom_sentence'] ) ) ) : '';
		if ( function_exists( 'mb_substr' ) && mb_strlen( $topbar_custom_sentence ) > 80 ) {
			$topbar_custom_sentence = mb_substr( $topbar_custom_sentence, 0, 80 );
		} elseif ( strlen( $topbar_custom_sentence ) > 80 ) {
			$topbar_custom_sentence = substr( $topbar_custom_sentence, 0, 80 );
		}
		update_option( 'torobpay_topbar_custom_sentence', $topbar_custom_sentence );
		$topbar_allowed_bg_animations = [ 'none', 'perlin', 'rain', 'float', 'aurora' ];
		update_option( 'torobpay_topbar_bg_animation', ( isset( $_POST['topbar_bg_animation'] ) && in_array( sanitize_key( wp_unslash( $_POST['topbar_bg_animation'] ) ), $topbar_allowed_bg_animations, true ) ) ? sanitize_key( wp_unslash( $_POST['topbar_bg_animation'] ) ) : 'none' );
		$topbar_allowed_bg_density = [ 'low', 'medium', 'high', 'dense' ];
		update_option( 'torobpay_topbar_bg_density', ( isset( $_POST['topbar_bg_density'] ) && in_array( sanitize_key( wp_unslash( $_POST['topbar_bg_density'] ) ), $topbar_allowed_bg_density, true ) ) ? sanitize_key( wp_unslash( $_POST['topbar_bg_density'] ) ) : 'medium' );
		update_option( 'torobpay_topbar_bg_speed', isset( $_POST['topbar_bg_speed'] ) ? max( 1, min( 10, absint( wp_unslash( $_POST['topbar_bg_speed'] ) ) ) ) : 5 );
		list( $topbar_bg_custom_icon, $topbar_bg_custom_icon_id ) = torobpay_sanitize_bg_custom_icon(
			isset( $_POST['topbar_bg_custom_icon'] ) ? sanitize_text_field( wp_unslash( $_POST['topbar_bg_custom_icon'] ) ) : '',
			isset( $_POST['topbar_bg_custom_icon_id'] ) ? absint( wp_unslash( $_POST['topbar_bg_custom_icon_id'] ) ) : 0
		);
		update_option( 'torobpay_topbar_bg_custom_icon', $topbar_bg_custom_icon );
		update_option( 'torobpay_topbar_bg_custom_icon_id', $topbar_bg_custom_icon_id );
		$topbar_position = ( isset( $_POST['topbar_position'] ) && in_array( sanitize_key( wp_unslash( $_POST['topbar_position'] ) ), [ 'normal', 'sticky' ], true ) ) ? sanitize_key( wp_unslash( $_POST['topbar_position'] ) ) : 'normal';
		update_option( 'torobpay_topbar_position', $topbar_position );
		update_option( 'torobpay_topbar_sticky_offset', isset( $_POST['topbar_sticky_offset'] ) ? max( 0, min( 400, absint( wp_unslash( $_POST['topbar_sticky_offset'] ) ) ) ) : 0 );
		update_option( 'torobpay_topbar_sticky_header_selector', isset( $_POST['topbar_sticky_header_selector'] ) ? torobpay_sanitize_css_selector_list( sanitize_text_field( wp_unslash( $_POST['topbar_sticky_header_selector'] ) ) ) : '' );
		update_option( 'torobpay_topbar_z_index', isset( $_POST['topbar_z_index'] ) ? max( 0, min( 999999, absint( wp_unslash( $_POST['topbar_z_index'] ) ) ) ) : 9990 );
		$topbar_placement = ( isset( $_POST['topbar_placement'] ) && in_array( sanitize_key( wp_unslash( $_POST['topbar_placement'] ) ), [ 'automatic', 'manual' ], true ) ) ? sanitize_key( wp_unslash( $_POST['topbar_placement'] ) ) : 'automatic';
		update_option( 'torobpay_topbar_placement', $topbar_placement );

		/**
		 * Let self-contained visual features (e.g. Custom CSS) persist their own
		 * settings. Fires only after the page has verified its nonce above.
		 */
		do_action( 'torobpay_visual_settings_save' );

		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved successfully.', 'torobpay-for-woocommerce' ) . '</p></div>';
	}

	if ( isset( $_POST['reset'] ) && isset( $_POST['torobpay_widget_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['torobpay_widget_nonce'] ) ), 'torobpay_widget_settings' ) ) {
		update_option( 'torobpay_widget_enabled', 'yes' );
		update_option( 'torobpay_badge_enabled', 'yes' );
		update_option( 'torobpay_marquee_enabled', 'no' );
		update_option( 'torobpay_topbar_enabled', 'no' );
		update_option( 'torobpay_slider_enabled', 'no' );

		update_option( 'torobpay_widget_layout', 'classic' );
		update_option( 'torobpay_widget_background_color', '#ffffff' );
		update_option( 'torobpay_widget_border_color', '#ebecf2' );
		update_option( 'torobpay_widget_text_color', '#1a1c23' );
		torobpay_reset_responsive_number( 'torobpay_widget_border_size', 1 );
		torobpay_reset_responsive_number( 'torobpay_widget_border_radius', 16 );
		torobpay_reset_responsive_number( 'torobpay_widget_title_font_size', 14 );
		update_option( 'torobpay_widget_title_font_weight', '700' );
		torobpay_reset_responsive_number( 'torobpay_widget_desc_font_size', 12 );
		update_option( 'torobpay_widget_desc_font_weight', '400' );
		update_option( 'torobpay_widget_logo_animation', 'light-sweep' );
		update_option( 'torobpay_widget_display_hook', 'woocommerce_after_add_to_cart_form' );
		update_option( 'torobpay_widget_display_priority', 9 );
		if ( function_exists( 'torobpay_reset_pdp_promo_settings' ) ) {
			torobpay_reset_pdp_promo_settings();
		}

		update_option( 'torobpay_badge_position_horizontal', 'right' );
		update_option( 'torobpay_badge_position_vertical', 'top' );
		torobpay_reset_responsive_number( 'torobpay_badge_offset_horizontal', 10 );
		torobpay_reset_responsive_number( 'torobpay_badge_offset_vertical', 10 );
		update_option( 'torobpay_badge_offset_horizontal_unit', 'px' );
		update_option( 'torobpay_badge_offset_vertical_unit', 'px' );
		update_option( 'torobpay_badge_background_color', '#9CE76A' );
		update_option( 'torobpay_badge_text_color', '#003D01' );
		torobpay_reset_responsive_number( 'torobpay_badge_border_radius', 8 );
		torobpay_reset_responsive_number( 'torobpay_badge_font_size', 12 );
		update_option( 'torobpay_badge_font_weight', '600' );
		torobpay_reset_responsive_number( 'torobpay_badge_logo_height', 24 );
		update_option( 'torobpay_badge_compatibility_mode', 'no' );

		update_option( 'torobpay_marquee_bg_color', '#ffffff' );
		update_option( 'torobpay_marquee_bg_opacity', 22 );
		update_option( 'torobpay_marquee_text_color', '#003D01' );
		torobpay_reset_responsive_number( 'torobpay_marquee_font_size', 11 );
		update_option( 'torobpay_marquee_font_weight', '600' );
		torobpay_reset_responsive_number( 'torobpay_marquee_height', 26 );
		update_option( 'torobpay_marquee_scroll_speed', 14 );
		update_option( 'torobpay_marquee_position', 'bottom' );
		torobpay_reset_responsive_number( 'torobpay_marquee_gap', 0 );
		update_option( 'torobpay_marquee_gap_unit', 'px' );
		update_option( 'torobpay_marquee_vertical_padding', 0 );
		update_option( 'torobpay_marquee_vertical_padding_unit', 'px' );
		torobpay_reset_responsive_number( 'torobpay_marquee_radius_tl', 0 );
		torobpay_reset_responsive_number( 'torobpay_marquee_radius_tr', 0 );
		torobpay_reset_responsive_number( 'torobpay_marquee_radius_br', 0 );
		torobpay_reset_responsive_number( 'torobpay_marquee_radius_bl', 0 );
		torobpay_reset_responsive_number( 'torobpay_marquee_icon_size', 14 );
		update_option( 'torobpay_marquee_z_index', 10 );
		update_option( 'torobpay_marquee_height_unit', 'px' );
		update_option( 'torobpay_marquee_vertical_align', 'middle' );
		update_option( 'torobpay_marquee_compatibility_mode', 'no' );

		torobpay_reset_responsive_number( 'torobpay_topbar_height', 44 );
		torobpay_reset_responsive_number( 'torobpay_topbar_font_size', 14 );
		torobpay_reset_responsive_number( 'torobpay_topbar_logo_size', 24 );
		torobpay_reset_responsive_number( 'torobpay_topbar_ticker_width', 250 );
		update_option( 'torobpay_topbar_font_weight', '700' );
		update_option( 'torobpay_topbar_speed', 8 );
		update_option( 'torobpay_topbar_animation', 'slide-up' );
		update_option( 'torobpay_topbar_custom_sentence', '' );
		update_option( 'torobpay_topbar_bg_animation', 'none' );
		update_option( 'torobpay_topbar_bg_density', 'medium' );
		update_option( 'torobpay_topbar_bg_speed', 5 );
		update_option( 'torobpay_topbar_bg_custom_icon', plugins_url( 'assets/images/logo_favicon.svg', TOROBPAY_PLUGIN_FILE ) );
		update_option( 'torobpay_topbar_bg_custom_icon_id', 0 );
		update_option( 'torobpay_topbar_position', 'normal' );
		update_option( 'torobpay_topbar_sticky_offset', 0 );
		update_option( 'torobpay_topbar_sticky_header_selector', '' );
		update_option( 'torobpay_topbar_z_index', 9990 );
		update_option( 'torobpay_topbar_placement', 'automatic' );

		/** Let self-contained visual features reset their own settings too. */
		do_action( 'torobpay_visual_settings_reset' );

		echo '<div class="notice notice-info is-dismissible"><p>' . esc_html__( 'Settings were restored to defaults.', 'torobpay-for-woocommerce' ) . '</p></div>';

		$background_color  = '#ffffff';
		$border_color      = '#ebecf2';
		$text_color        = '#1a1c23';
		$border_size_r     = [ 'base' => 1, 'tablet' => '', 'mobile' => '' ];
		$border_radius_r   = [ 'base' => 16, 'tablet' => '', 'mobile' => '' ];
		$title_font_size_r = [ 'base' => 14, 'tablet' => '', 'mobile' => '' ];
		$title_font_weight = '700';
		$desc_font_size_r  = [ 'base' => 12, 'tablet' => '', 'mobile' => '' ];
		$desc_font_weight  = '400';
		$logo_animation    = 'light-sweep';
		$widget_layout     = 'classic';
		$widget_enabled    = 'yes';
		$badge_enabled     = 'yes';
		$marquee_enabled   = 'no';

		$badge_position_horizontal     = 'right';
		$badge_position_vertical       = 'top';
		$badge_offset_horizontal_r     = [ 'base' => 10, 'tablet' => '', 'mobile' => '' ];
		$badge_offset_vertical_r       = [ 'base' => 10, 'tablet' => '', 'mobile' => '' ];
		$badge_offset_horizontal_unit  = 'px';
		$badge_offset_vertical_unit    = 'px';
		$badge_background_color        = '#9CE76A';
		$badge_text_color              = '#003D01';
		$badge_border_radius_r         = [ 'base' => 8, 'tablet' => '', 'mobile' => '' ];
		$badge_font_size_r             = [ 'base' => 12, 'tablet' => '', 'mobile' => '' ];
		$badge_font_weight             = '600';
		$badge_logo_height_r           = [ 'base' => 24, 'tablet' => '', 'mobile' => '' ];
		$badge_compatibility_mode      = 'no';
		$marquee_bg_color              = '#ffffff';
		$marquee_bg_opacity            = 22;
		$marquee_text_color            = '#003D01';
		$marquee_font_size_r           = [ 'base' => 11, 'tablet' => '', 'mobile' => '' ];
		$marquee_font_weight           = '600';
		$marquee_height_r              = [ 'base' => 26, 'tablet' => '', 'mobile' => '' ];
		$marquee_scroll_speed          = 14;
		$marquee_position              = 'bottom';
		$marquee_gap_r                 = [ 'base' => 0, 'tablet' => '', 'mobile' => '' ];
		$marquee_gap_unit              = 'px';
		$marquee_vertical_padding      = 0;
		$marquee_vertical_padding_unit = 'px';
		$marquee_radius_tl_r           = [ 'base' => 0, 'tablet' => '', 'mobile' => '' ];
		$marquee_radius_tr_r           = [ 'base' => 0, 'tablet' => '', 'mobile' => '' ];
		$marquee_radius_br_r           = [ 'base' => 0, 'tablet' => '', 'mobile' => '' ];
		$marquee_radius_bl_r           = [ 'base' => 0, 'tablet' => '', 'mobile' => '' ];
		$marquee_icon_size_r           = [ 'base' => 14, 'tablet' => '', 'mobile' => '' ];
		$marquee_z_index               = 10;
		$marquee_height_unit           = 'px';
		$marquee_vertical_align        = 'middle';
		$marquee_compatibility_mode    = 'no';
		$topbar_enabled                = 'no';
		$topbar_height                 = 44;
		$topbar_font_size              = 14;
		$topbar_font_weight            = '700';
		$topbar_logo_size              = 24;
		$topbar_speed                  = 8;
		$topbar_ticker_width           = 250;
		$topbar_animation              = 'slide-up';
		$topbar_custom_sentence        = '';
		$topbar_bg_animation           = 'none';
		$topbar_bg_density             = 'medium';
		$topbar_bg_speed               = 5;
		$topbar_bg_custom_icon         = plugins_url( 'assets/images/logo_favicon.svg', TOROBPAY_PLUGIN_FILE );
		$topbar_bg_custom_icon_id      = 0;
		$topbar_position               = 'normal';
		$topbar_sticky_offset          = 0;
		$topbar_sticky_header_selector = '';
		$topbar_z_index                = 9990;
		$topbar_placement              = 'automatic';
	}

	$widget_enabled  = get_option( 'torobpay_widget_enabled', 'yes' );
	$badge_enabled   = get_option( 'torobpay_badge_enabled', 'yes' );
	$marquee_enabled = get_option( 'torobpay_marquee_enabled', 'no' );

	$background_color  = get_option( 'torobpay_widget_background_color', '#ffffff' );
	$border_color      = get_option( 'torobpay_widget_border_color', '#ebecf2' );
	$text_color        = get_option( 'torobpay_widget_text_color', '#1a1c23' );
	$border_size_r     = torobpay_get_responsive_setting( 'torobpay_widget_border_size', 1 );
	$border_radius_r   = torobpay_get_responsive_setting( 'torobpay_widget_border_radius', 16 );
	$title_font_size_r = torobpay_get_responsive_setting( 'torobpay_widget_title_font_size', 14 );
	$title_font_weight = get_option( 'torobpay_widget_title_font_weight', '700' );
	$desc_font_size_r  = torobpay_get_responsive_setting( 'torobpay_widget_desc_font_size', 12 );
	$desc_font_weight  = get_option( 'torobpay_widget_desc_font_weight', '300' );
	$logo_animation    = get_option( 'torobpay_widget_logo_animation', 'light-sweep' );
	$widget_layout     = torobpay_get_widget_setting( 'layout' );
	$display_hook      = get_option( 'torobpay_widget_display_hook', 'woocommerce_after_add_to_cart_form' );
	$display_priority  = get_option( 'torobpay_widget_display_priority', 9 );

	// Predefined hooks for the selector
	$predefined_hooks = array(
		'woocommerce_after_add_to_cart_form'       => __( 'After add-to-cart form (default)', 'torobpay-for-woocommerce' ),
		'woocommerce_single_product_summary'       => __( 'In product summary', 'torobpay-for-woocommerce' ),
		'woocommerce_after_single_product_summary' => __( 'After product summary', 'torobpay-for-woocommerce' ),
		'woocommerce_before_add_to_cart_form'      => __( 'Before add-to-cart form', 'torobpay-for-woocommerce' ),
		'woocommerce_product_meta_end'             => __( 'After product meta', 'torobpay-for-woocommerce' ),
		'woocommerce_share'                        => __( 'After share buttons', 'torobpay-for-woocommerce' ),
	);
	$is_custom_hook   = ! array_key_exists( $display_hook, $predefined_hooks );

	$badge_position_horizontal    = get_option( 'torobpay_badge_position_horizontal', 'right' );
	$badge_position_vertical      = get_option( 'torobpay_badge_position_vertical', 'top' );
	$badge_offset_horizontal_r    = torobpay_get_responsive_setting( 'torobpay_badge_offset_horizontal', 10 );
	$badge_offset_vertical_r      = torobpay_get_responsive_setting( 'torobpay_badge_offset_vertical', 10 );
	$badge_offset_horizontal_unit = get_option( 'torobpay_badge_offset_horizontal_unit', 'px' );
	$badge_offset_vertical_unit   = get_option( 'torobpay_badge_offset_vertical_unit', 'px' );
	$badge_background_color       = get_option( 'torobpay_badge_background_color', '#9CE76A' );
	$badge_text_color             = get_option( 'torobpay_badge_text_color', '#003D01' );
	$badge_border_radius_r        = torobpay_get_responsive_setting( 'torobpay_badge_border_radius', 8 );
	$badge_font_size_r            = torobpay_get_responsive_setting( 'torobpay_badge_font_size', 12 );
	$badge_font_weight            = get_option( 'torobpay_badge_font_weight', '600' );
	$badge_logo_height_r          = torobpay_get_responsive_setting( 'torobpay_badge_logo_height', 24 );
	$badge_compatibility_mode     = get_option( 'torobpay_badge_compatibility_mode', 'no' );

	$marquee_slogan                = __( 'Buy in installments with TorobPay — no fees', 'torobpay-for-woocommerce' );
	$marquee_bg_color              = get_option( 'torobpay_marquee_bg_color', '#ffffff' );
	$marquee_bg_opacity            = get_option( 'torobpay_marquee_bg_opacity', 22 );
	$marquee_text_color            = get_option( 'torobpay_marquee_text_color', '#003D01' );
	$marquee_font_size_r           = torobpay_get_responsive_setting( 'torobpay_marquee_font_size', 11 );
	$marquee_font_weight           = get_option( 'torobpay_marquee_font_weight', '600' );
	$marquee_height_r              = torobpay_get_responsive_setting( 'torobpay_marquee_height', 26 );
	$marquee_scroll_speed          = get_option( 'torobpay_marquee_scroll_speed', 14 );
	$marquee_position              = get_option( 'torobpay_marquee_position', 'bottom' );
	$marquee_gap_r                 = torobpay_get_responsive_setting( 'torobpay_marquee_gap', 0 );
	$marquee_gap_unit              = get_option( 'torobpay_marquee_gap_unit', 'px' );
	$marquee_vertical_padding      = get_option( 'torobpay_marquee_vertical_padding', 0 );
	$marquee_vertical_padding_unit = get_option( 'torobpay_marquee_vertical_padding_unit', 'px' );
	$marquee_radius_tl_r           = torobpay_get_responsive_setting( 'torobpay_marquee_radius_tl', 0 );
	$marquee_radius_tr_r           = torobpay_get_responsive_setting( 'torobpay_marquee_radius_tr', 0 );
	$marquee_radius_br_r           = torobpay_get_responsive_setting( 'torobpay_marquee_radius_br', 0 );
	$marquee_radius_bl_r           = torobpay_get_responsive_setting( 'torobpay_marquee_radius_bl', 0 );
	$marquee_icon_size_r           = torobpay_get_responsive_setting( 'torobpay_marquee_icon_size', 14 );
	$marquee_z_index               = get_option( 'torobpay_marquee_z_index', 10 );
	$marquee_height_unit           = get_option( 'torobpay_marquee_height_unit', 'px' );
	$marquee_vertical_align        = get_option( 'torobpay_marquee_vertical_align', 'middle' );
	$marquee_compatibility_mode    = get_option( 'torobpay_marquee_compatibility_mode', 'no' );

	$topbar_enabled        = get_option( 'torobpay_topbar_enabled', 'no' );
	$topbar_height_r       = torobpay_get_responsive_setting( 'torobpay_topbar_height', 44 );
	$topbar_font_size_r    = torobpay_get_responsive_setting( 'torobpay_topbar_font_size', 14 );
	$topbar_logo_size_r    = torobpay_get_responsive_setting( 'torobpay_topbar_logo_size', 24 );
	$topbar_ticker_width_r = torobpay_get_responsive_setting( 'torobpay_topbar_ticker_width', 250 );
	$topbar_font_weight    = get_option( 'torobpay_topbar_font_weight', '700' );
	$topbar_speed          = get_option( 'torobpay_topbar_speed', 8 );
	$topbar_animation        = get_option( 'torobpay_topbar_animation', 'slide-up' );
	$topbar_custom_sentence  = torobpay_get_topbar_setting( 'custom_sentence' );
	$topbar_bg_animation     = get_option( 'torobpay_topbar_bg_animation', 'none' );
	$topbar_bg_density     = get_option( 'torobpay_topbar_bg_density', 'medium' );
	$topbar_bg_speed       = get_option( 'torobpay_topbar_bg_speed', 5 );
	$topbar_bg_custom_icon    = get_option( 'torobpay_topbar_bg_custom_icon', plugins_url( 'assets/images/logo_favicon.svg', TOROBPAY_PLUGIN_FILE ) );
	$topbar_bg_custom_icon_id = (int) get_option( 'torobpay_topbar_bg_custom_icon_id', 0 );
	$topbar_position       = torobpay_get_topbar_setting( 'position' );
	$topbar_sticky_offset  = get_option( 'torobpay_topbar_sticky_offset', 0 );
	$topbar_sticky_header_selector = get_option( 'torobpay_topbar_sticky_header_selector', '' );
	$topbar_z_index        = (int) torobpay_get_topbar_setting( 'z_index' );
	$topbar_placement      = function_exists( 'torobpay_get_topbar_placement' ) ? torobpay_get_topbar_placement() : get_option( 'torobpay_topbar_placement', 'automatic' );

	$slider_enabled        = get_option( 'torobpay_slider_enabled', 'no' );

	$pdp_promo          = function_exists( 'torobpay_get_pdp_promo_settings' )
		? torobpay_get_pdp_promo_settings()
		: array(
			'enabled'        => 'no',
			'code'           => '',
			'discount_type'  => 'amount',
			'discount_value' => 0,
			'min_total'      => 0,
			'expires_at'     => 0,
			'style'          => 'marquee',
		);
	$pdp_promo_style    = isset( $pdp_promo['style'] ) ? $pdp_promo['style'] : 'marquee';
	$pdp_promo_expires  = function_exists( 'torobpay_pdp_promo_expires_local_value' )
		? torobpay_pdp_promo_expires_local_value( $pdp_promo['expires_at'] )
		: '';
	$pdp_promo_currency = function_exists( 'get_woocommerce_currency_symbol' )
		? get_woocommerce_currency_symbol()
		: '';

	$torobpay_loading_gif_url = esc_url( plugins_url( 'assets/images/loading.gif', TOROBPAY_PLUGIN_FILE ) );
	?>
	<div class="wrap torobpay-visual-settings-wrap" aria-busy="true">
		<div id="torobpay-visual-settings-loader" class="torobpay-visual-settings-loader" role="status" aria-live="polite">
			<img src="<?php echo esc_url( $torobpay_loading_gif_url ); ?>" alt="" width="48" height="48" decoding="async">
			<span class="screen-reader-text"><?php esc_html_e( 'Loading visual settings…', 'torobpay-for-woocommerce' ); ?></span>
		</div>
		<h1><?php esc_html_e( 'TorobPay visual settings', 'torobpay-for-woocommerce' ); ?></h1>

		<form method="post" action="">
			<?php wp_nonce_field( 'torobpay_widget_settings', 'torobpay_widget_nonce' ); ?>

			<?php
			$disabled_items = array();
			if ( $widget_enabled !== 'yes' ) {
				$disabled_items[] = __( 'Widget', 'torobpay-for-woocommerce' );
			}
			if ( $badge_enabled !== 'yes' ) {
				$disabled_items[] = __( 'Badge', 'torobpay-for-woocommerce' );
			}
			if ( $marquee_enabled !== 'yes' ) {
				$disabled_items[] = __( 'Marquee', 'torobpay-for-woocommerce' );
			}
			if ( $topbar_enabled !== 'yes' ) {
				$disabled_items[] = __( 'Top bar', 'torobpay-for-woocommerce' );
			}
			if ( $slider_enabled !== 'yes' ) {
				$disabled_items[] = __( 'Slider', 'torobpay-for-woocommerce' );
			}

			if ( ! empty( $disabled_items ) ) :
				?>
				<div class="torobpay-promo-notice torobpay-display-promo-notice">
					<span class="dashicons dashicons-chart-line torobpay-promo-notice-icon"></span>
					<div class="torobpay-promo-notice-body">
						<strong><?php esc_html_e( 'Enabling TorobPay elements can increase sales', 'torobpay-for-woocommerce' ); ?></strong>
						<p><?php esc_html_e( 'Showing the widget, badge, marquee, slider, and top bar informs shoppers about fee-free installment payments and can improve conversion by raising awareness of this payment method in your store.', 'torobpay-for-woocommerce' ); ?></p>
						<div class="torobpay-promo-notice-tags">
							<?php foreach ( $disabled_items as $item ) : ?>
								<span class="torobpay-promo-notice-tag">
									<span class="dashicons dashicons-hidden"></span>
									<?php echo esc_html( $item ); ?><?php esc_html_e( ' is disabled', 'torobpay-for-woocommerce' ); ?></span>
							<?php endforeach; ?>
						</div>
					</div>
				</div>
			<?php endif; ?>

			<div class="torobpay-display-settings-card is-loading">
				<div class="torobpay-display-settings-card-header">
					<div>
						<h2><span class="dashicons dashicons-visibility"></span><?php esc_html_e( 'Manage TorobPay element visibility and appearance', 'torobpay-for-woocommerce' ); ?><span id="torobpay-display-toggle-status" class="torobpay-display-toggle-status saving" aria-live="polite"><?php esc_html_e( 'Preparing…', 'torobpay-for-woocommerce' ); ?></span></h2>
							<p class="description"><?php
							printf(
								/* translators: %s: HTML link to TorobPay gateway settings */
								wp_kses_post( __( 'Manage widget, badge, marquee, slider, and top bar visibility from this page. Each element\'s visual settings are in the sections below. For gateway connection and payment settings, go to %s.', 'torobpay-for-woocommerce' ) ),
								'<a href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=checkout&section=WC_Gateway_TorobPay' ) ) . '">' . esc_html__( 'TorobPay gateway settings', 'torobpay-for-woocommerce' ) . '</a>'
							);
							?></p>
					</div>
				</div>
				<?php
				$torobpay_display_toggles = array(
					array(
						'id'       => 'torobpay_widget_enabled',
						'name'     => 'torobpay_widget_enabled',
						'label'    => __( 'Auto-show TorobPay widget', 'torobpay-for-woocommerce' ),
						'tooltip'  => __( 'When disabled, you can still use the [torobpay_widget] shortcode where you want.', 'torobpay-for-woocommerce' ),
						'enabled'  => $widget_enabled,
					),
					array(
						'id'       => 'torobpay_badge_enabled',
						'name'     => 'torobpay_badge_enabled',
						'label'    => __( 'Show TorobPay badge', 'torobpay-for-woocommerce' ),
						'tooltip'  => __( 'Show or hide the TorobPay badge on product lists', 'torobpay-for-woocommerce' ),
						'enabled'  => $badge_enabled,
					),
					array(
						'id'       => 'torobpay_marquee_enabled',
						'name'     => 'torobpay_marquee_enabled',
						'label'    => __( 'Show TorobPay marquee', 'torobpay-for-woocommerce' ),
						'tooltip'  => __( 'Show or hide the TorobPay marquee on product lists', 'torobpay-for-woocommerce' ),
						'enabled'  => $marquee_enabled,
					),
					array(
						'id'       => 'torobpay_topbar_enabled',
						'name'     => 'torobpay_topbar_enabled',
						'label'    => __( 'Show TorobPay top bar', 'torobpay-for-woocommerce' ),
						'tooltip'  => __( 'Show or hide the TorobPay promo bar at the top of the site. When disabled, you can still use [torobpay_topbar].', 'torobpay-for-woocommerce' ),
						'enabled'  => $topbar_enabled,
					),
					array(
						'id'       => 'torobpay_slider_enabled',
						'name'     => 'torobpay_slider_enabled',
						'label'    => __( 'Show TorobPay slider', 'torobpay-for-woocommerce' ),
						'tooltip'  => __( 'Show or hide the TorobPay promo slider. When disabled, you can still use [torobpay_slider].', 'torobpay-for-woocommerce' ),
						'enabled'  => $slider_enabled,
					),
				);
				?>
				<div class="torobpay-display-toggles-grid" role="group" aria-label="<?php echo esc_attr__( 'Manage TorobPay element visibility', 'torobpay-for-woocommerce' ); ?>">
					<?php foreach ( $torobpay_display_toggles as $torobpay_toggle ) : ?>
						<div class="torobpay-display-toggle-item">
							<label class="torobpay-display-setting-label" for="<?php echo esc_attr( $torobpay_toggle['id'] ); ?>">
								<input
									type="checkbox"
									id="<?php echo esc_attr( $torobpay_toggle['id'] ); ?>"
									name="<?php echo esc_attr( $torobpay_toggle['name'] ); ?>"
									value="yes"
									<?php checked( $torobpay_toggle['enabled'], 'yes' ); ?>
								>
								<span class="torobpay-display-toggle-name">
									<?php echo esc_html( $torobpay_toggle['label'] ); ?>
									<span class="torobpay-tooltip" data-tip="<?php echo esc_attr( $torobpay_toggle['tooltip'] ); ?>" title="<?php echo esc_attr( $torobpay_toggle['label'] ); ?>"><?php esc_html_e( '?', 'torobpay-for-woocommerce' ); ?></span>
								</span>
							</label>
						</div>
					<?php endforeach; ?>
				</div>
			</div>

			<?php
			$torobpay_settings_nav = array(
				array(
					'id'    => 'widget-settings',
					'icon'  => 'dashicons-admin-generic',
					'label' => __( 'Widget settings', 'torobpay-for-woocommerce' ),
				),
				array(
					'id'    => 'badge-settings',
					'icon'  => 'dashicons-tag',
					'label' => __( 'Badge settings', 'torobpay-for-woocommerce' ),
				),
				array(
					'id'    => 'marquee-settings',
					'icon'  => 'dashicons-slides',
					'label' => __( 'Marquee settings', 'torobpay-for-woocommerce' ),
				),
				array(
					'id'    => 'topbar-settings',
					'icon'  => 'dashicons-arrow-up-alt2',
					'label' => __( 'Top bar settings', 'torobpay-for-woocommerce' ),
				),
			);
			/**
			 * Self-contained visual features can register extra sections here.
			 * Each item: [ 'id' => slug, 'icon' => dashicon-class, 'label' => text ].
			 */
			$torobpay_extra_tabs = apply_filters( 'torobpay_visual_settings_tabs', array() );
			foreach ( (array) $torobpay_extra_tabs as $torobpay_tab ) {
				if ( empty( $torobpay_tab['id'] ) ) {
					continue;
				}
				$torobpay_settings_nav[] = $torobpay_tab;
			}
			?>
			<div class="torobpay-visual-settings-layout">
				<nav class="torobpay-visual-settings-nav" aria-label="<?php echo esc_attr__( 'Visual settings sections', 'torobpay-for-woocommerce' ); ?>">
					<div class="torobpay-visual-settings-nav-list" role="tablist" aria-orientation="vertical">
						<?php foreach ( $torobpay_settings_nav as $torobpay_nav_index => $torobpay_tab ) : ?>
							<?php
							$torobpay_tab_id    = sanitize_html_class( $torobpay_tab['id'] );
							$torobpay_tab_icon  = ! empty( $torobpay_tab['icon'] ) ? $torobpay_tab['icon'] : 'dashicons-admin-generic';
							$torobpay_tab_label = ! empty( $torobpay_tab['label'] ) ? $torobpay_tab['label'] : $torobpay_tab['id'];
							$torobpay_is_first  = 0 === (int) $torobpay_nav_index;
							?>
							<a
								href="#<?php echo esc_attr( $torobpay_tab_id ); ?>"
								class="torobpay-visual-settings-nav-item<?php echo $torobpay_is_first ? ' is-active' : ''; ?>"
								id="torobpay-nav-<?php echo esc_attr( $torobpay_tab_id ); ?>"
								data-tab="<?php echo esc_attr( $torobpay_tab_id ); ?>"
								role="tab"
								aria-selected="<?php echo $torobpay_is_first ? 'true' : 'false'; ?>"
								aria-controls="<?php echo esc_attr( $torobpay_tab_id ); ?>"
							>
								<span class="dashicons <?php echo esc_attr( $torobpay_tab_icon ); ?>" aria-hidden="true"></span>
								<span class="torobpay-visual-settings-nav-label"><?php echo esc_html( $torobpay_tab_label ); ?></span>
							</a>
						<?php endforeach; ?>
					</div>
				</nav>
				<div class="torobpay-visual-settings-main">

			<!-- Widget Settings Tab -->
			<div id="widget-settings" class="tab-content active" role="tabpanel" aria-labelledby="torobpay-nav-widget-settings">

				<?php
				$widget_layout_presets = torobpay_widget_layout_presets();
				$logo_url_for_presets  = esc_url( plugins_url( 'assets/images/logo_favicon.svg', TOROBPAY_PLUGIN_FILE ) );
				?>
				<!-- Layout Presets Section -->
				<h2 class="section-title" data-section-key="widget-layouts"><span class="dashicons dashicons-layout"></span><?php esc_html_e( 'Ready-made styles', 'torobpay-for-woocommerce' ); ?><span class="section-collapse-icon dashicons dashicons-arrow-up-alt2"></span></h2>
				<p class="torobpay-settings-note torobpay-layout-presets-hint"><?php esc_html_e( 'Select a style to apply the suggested layout and colors. You can then adjust colors and typography manually.', 'torobpay-for-woocommerce' ); ?></p>
				<div class="torobpay-layout-presets" role="radiogroup" aria-label="<?php echo esc_attr__( 'Widget presets', 'torobpay-for-woocommerce' ); ?>">
					<?php foreach ( $widget_layout_presets as $preset_slug => $preset ) : ?>
						<?php
						$swatch       = isset( $preset['swatch'] ) ? $preset['swatch'] : array();
						$swatch_bg    = isset( $swatch['bg'] ) ? $swatch['bg'] : '#ffffff';
						$swatch_border = isset( $swatch['border'] ) ? $swatch['border'] : '#ebecf2';
						$swatch_text  = isset( $swatch['text'] ) ? $swatch['text'] : '#1a1c23';
						$accents      = torobpay_get_widget_layout_accent_colors( $preset_slug );
						$is_selected  = ( $widget_layout === $preset_slug );
						?>
						<button
							type="button"
							class="torobpay-layout-preset-card<?php echo $is_selected ? ' is-selected' : ''; ?>"
							data-layout="<?php echo esc_attr( $preset_slug ); ?>"
							role="radio"
							aria-checked="<?php echo $is_selected ? 'true' : 'false'; ?>"
						>
							<span class="torobpay-layout-preset-swatch torobpay-widget torobpay-widget--<?php echo esc_attr( $preset_slug ); ?>"
								style="--torobpay-widget-bg: <?php echo esc_attr( $swatch_bg ); ?>; --torobpay-widget-border: <?php echo esc_attr( $swatch_border ); ?>; --torobpay-widget-text: <?php echo esc_attr( $swatch_text ); ?>; --torobpay-widget-accent: <?php echo esc_attr( $accents['accent'] ); ?>; --torobpay-widget-accent-dark: <?php echo esc_attr( $accents['accent_dark'] ); ?>; background-color: <?php echo esc_attr( $swatch_bg ); ?>; border-color: <?php echo esc_attr( $swatch_border ); ?>; color: <?php echo esc_attr( $swatch_text ); ?>;"
								aria-hidden="true"
							>
								<span class="torobpay-widget-media">
									<img src="<?php echo esc_url( $logo_url_for_presets ); ?>" alt="" class="torobpay-widget-image" width="60" height="60" />
								</span>
								<span class="torobpay-widget-content">
									<span class="torobpay-widget-title" style="color: <?php echo esc_attr( $swatch_text ); ?>;"><?php esc_html_e( 'Each installment with TorobPay', 'torobpay-for-woocommerce' ); ?></span>
									<span class="torobpay-widget-description" style="color: <?php echo esc_attr( $swatch_text ); ?>;"><?php esc_html_e( '4 installments', 'torobpay-for-woocommerce' ); ?></span>
								</span>
							</span>
							<span class="torobpay-layout-preset-meta">
								<span class="torobpay-layout-preset-label"><?php echo esc_html( $preset['label'] ); ?></span>
								<span class="torobpay-layout-preset-desc"><?php echo esc_html( $preset['description'] ); ?></span>
							</span>
						</button>
					<?php endforeach; ?>
				</div>
				<input type="hidden" id="widget_layout" name="widget_layout" value="<?php echo esc_attr( $widget_layout ); ?>">

				<!-- Color Settings Section -->
				<h2 class="section-title" data-section-key="widget-colors"><span class="dashicons dashicons-art"></span><?php esc_html_e( 'Color settings', 'torobpay-for-woocommerce' ); ?><span class="section-collapse-icon dashicons dashicons-arrow-up-alt2"></span></h2>
				<table class="form-table">
					<tbody>
						<tr>
							<th scope="row">
								<label for="widget_background_color"><?php esc_html_e( 'Background color', 'torobpay-for-woocommerce' ); ?></label>
								<span class="torobpay-tooltip" data-tip="<?php echo esc_attr__( 'TorobPay widget background color', 'torobpay-for-woocommerce' ); ?>" title="<?php echo esc_attr__( 'TorobPay widget background color', 'torobpay-for-woocommerce' ); ?>"><?php esc_html_e( '?', 'torobpay-for-woocommerce' ); ?></span>
							</th>
							<td>
								<input type="color"
									id="widget_background_color"
									name="widget_background_color"
									value="<?php echo esc_attr( $background_color ); ?>"
									class="color-picker">
							</td>
						</tr>

						<tr>
							<th scope="row">
								<label for="widget_border_color"><?php esc_html_e( 'Border color', 'torobpay-for-woocommerce' ); ?></label>
								<span class="torobpay-tooltip" data-tip="<?php echo esc_attr__( 'Widget border color', 'torobpay-for-woocommerce' ); ?>" title="<?php echo esc_attr__( 'Widget border color', 'torobpay-for-woocommerce' ); ?>"><?php esc_html_e( '?', 'torobpay-for-woocommerce' ); ?></span>
							</th>
							<td>
								<input type="color"
									id="widget_border_color"
									name="widget_border_color"
									value="<?php echo esc_attr( $border_color ); ?>"
									class="color-picker">
							</td>
						</tr>

						<tr>
							<th scope="row">
								<label for="widget_text_color"><?php esc_html_e( 'Text color', 'torobpay-for-woocommerce' ); ?></label>
								<span class="torobpay-tooltip" data-tip="<?php echo esc_attr__( 'Widget text color', 'torobpay-for-woocommerce' ); ?>" title="<?php echo esc_attr__( 'Widget text color', 'torobpay-for-woocommerce' ); ?>"><?php esc_html_e( '?', 'torobpay-for-woocommerce' ); ?></span>
							</th>
							<td>
								<input type="color"
									id="widget_text_color"
									name="widget_text_color"
									value="<?php echo esc_attr( $text_color ); ?>"
									class="color-picker">
							</td>
						</tr>
					</tbody>
				</table>

				<!-- Border Settings Section -->
				<h2 class="section-title" data-section-key="widget-border"><span class="dashicons dashicons-editor-indent"></span><?php esc_html_e( 'Border settings', 'torobpay-for-woocommerce' ); ?><span class="section-collapse-icon dashicons dashicons-arrow-up-alt2"></span></h2>
				<table class="form-table">
					<tbody>
						<?php
						torobpay_render_responsive_number_row(
							array(
								'label'  => __( 'Border width', 'torobpay-for-woocommerce' ),
								'tip'    => __( 'Widget border width (0 to 20 px)', 'torobpay-for-woocommerce' ),
								'name'   => 'widget_border_size',
								'base'   => $border_size_r['base'],
								'tablet' => $border_size_r['tablet'],
								'mobile' => $border_size_r['mobile'],
								'min'    => 0,
								'max'    => 20,
								'step'   => 1,
							)
						);
						?>

						<?php
						torobpay_render_responsive_number_row(
							array(
								'label'  => __( 'Border radius', 'torobpay-for-woocommerce' ),
								'tip'    => __( 'Widget corner radius (0 to 999 px; high values create a pill shape)', 'torobpay-for-woocommerce' ),
								'name'   => 'widget_border_radius',
								'base'   => $border_radius_r['base'],
								'tablet' => $border_radius_r['tablet'],
								'mobile' => $border_radius_r['mobile'],
								'min'    => 0,
								'max'    => 999,
							)
						);
						?>
					</tbody>
				</table>

				<!-- Typography Settings Section -->
				<h2 class="section-title" data-section-key="widget-typography"><span class="dashicons dashicons-edit"></span><?php esc_html_e( 'Typography settings', 'torobpay-for-woocommerce' ); ?><span class="section-collapse-icon dashicons dashicons-arrow-up-alt2"></span></h2>
				<table class="form-table">
					<tbody>
						<?php
						torobpay_render_responsive_number_row(
							array(
								'label'  => __( 'Title font size', 'torobpay-for-woocommerce' ),
								'tip'    => __( 'Widget title font size (10 to 30 px)', 'torobpay-for-woocommerce' ),
								'name'   => 'widget_title_font_size',
								'base'   => $title_font_size_r['base'],
								'tablet' => $title_font_size_r['tablet'],
								'mobile' => $title_font_size_r['mobile'],
								'min'    => 10,
								'max'    => 30,
							)
						);
						?>

						<tr>
							<th scope="row">
								<label for="widget_title_font_weight"><?php esc_html_e( 'Title font weight', 'torobpay-for-woocommerce' ); ?></label>
								<span class="torobpay-tooltip" data-tip="<?php echo esc_attr__( 'Widget title font weight', 'torobpay-for-woocommerce' ); ?>" title="<?php echo esc_attr__( 'Widget title font weight', 'torobpay-for-woocommerce' ); ?>"><?php esc_html_e( '?', 'torobpay-for-woocommerce' ); ?></span>
							</th>
							<td>
								<select id="widget_title_font_weight" name="widget_title_font_weight">
									<option value="300" <?php selected( $title_font_weight, '300' ); ?>><?php esc_html_e( 'Light (300)', 'torobpay-for-woocommerce' ); ?></option>
									<option value="400" <?php selected( $title_font_weight, '400' ); ?>><?php esc_html_e( 'Regular (400)', 'torobpay-for-woocommerce' ); ?></option>
									<option value="500" <?php selected( $title_font_weight, '500' ); ?>><?php esc_html_e( 'Medium (500)', 'torobpay-for-woocommerce' ); ?></option>
									<option value="600" <?php selected( $title_font_weight, '600' ); ?>><?php esc_html_e( 'Semi-bold (600)', 'torobpay-for-woocommerce' ); ?></option>
									<option value="700" <?php selected( $title_font_weight, '700' ); ?>><?php esc_html_e( 'Bold (700)', 'torobpay-for-woocommerce' ); ?></option>
									<option value="800" <?php selected( $title_font_weight, '800' ); ?>><?php esc_html_e( 'Extra bold (800)', 'torobpay-for-woocommerce' ); ?></option>
								</select>
							</td>
						</tr>

						<?php
						torobpay_render_responsive_number_row(
							array(
								'label'  => __( 'Description font size', 'torobpay-for-woocommerce' ),
								'tip'    => __( 'Widget description font size (8 to 24 px)', 'torobpay-for-woocommerce' ),
								'name'   => 'widget_desc_font_size',
								'base'   => $desc_font_size_r['base'],
								'tablet' => $desc_font_size_r['tablet'],
								'mobile' => $desc_font_size_r['mobile'],
								'min'    => 8,
								'max'    => 24,
							)
						);
						?>

						<tr>
							<th scope="row">
								<label for="widget_desc_font_weight"><?php esc_html_e( 'Description font weight', 'torobpay-for-woocommerce' ); ?></label>
								<span class="torobpay-tooltip" data-tip="<?php echo esc_attr__( 'Widget description font weight', 'torobpay-for-woocommerce' ); ?>" title="<?php echo esc_attr__( 'Widget description font weight', 'torobpay-for-woocommerce' ); ?>"><?php esc_html_e( '?', 'torobpay-for-woocommerce' ); ?></span>
							</th>
							<td>
								<select id="widget_desc_font_weight" name="widget_desc_font_weight">
									<option value="300" <?php selected( $desc_font_weight, '300' ); ?>><?php esc_html_e( 'Light (300)', 'torobpay-for-woocommerce' ); ?></option>
									<option value="400" <?php selected( $desc_font_weight, '400' ); ?>><?php esc_html_e( 'Regular (400)', 'torobpay-for-woocommerce' ); ?></option>
									<option value="500" <?php selected( $desc_font_weight, '500' ); ?>><?php esc_html_e( 'Medium (500)', 'torobpay-for-woocommerce' ); ?></option>
									<option value="600" <?php selected( $desc_font_weight, '600' ); ?>><?php esc_html_e( 'Semi-bold (600)', 'torobpay-for-woocommerce' ); ?></option>
									<option value="700" <?php selected( $desc_font_weight, '700' ); ?>><?php esc_html_e( 'Bold (700)', 'torobpay-for-woocommerce' ); ?></option>
									<option value="800" <?php selected( $desc_font_weight, '800' ); ?>><?php esc_html_e( 'Extra bold (800)', 'torobpay-for-woocommerce' ); ?></option>
								</select>
							</td>
						</tr>
					</tbody>
				</table>

				<!-- Logo Settings Section -->
				<h2 class="section-title" data-section-key="widget-logo"><span class="dashicons dashicons-format-image"></span><?php esc_html_e( 'Logo settings', 'torobpay-for-woocommerce' ); ?><span class="section-collapse-icon dashicons dashicons-arrow-up-alt2"></span></h2>
				<table class="form-table">
					<tbody>
						<tr>
							<th scope="row">
								<label for="widget_logo_animation"><?php esc_html_e( 'Logo animation', 'torobpay-for-woocommerce' ); ?></label>
								<span class="torobpay-tooltip" data-tip="<?php echo esc_attr__( 'Looping animation for the TorobPay logo', 'torobpay-for-woocommerce' ); ?>" title="<?php echo esc_attr__( 'Looping animation for the TorobPay logo', 'torobpay-for-woocommerce' ); ?>"><?php esc_html_e( '?', 'torobpay-for-woocommerce' ); ?></span>
							</th>
							<td>
								<select id="widget_logo_animation" name="widget_logo_animation">
									<option value="light-sweep" <?php selected( $logo_animation, 'light-sweep' ); ?>><?php esc_html_e( 'Light sweep', 'torobpay-for-woocommerce' ); ?></option>
									<option value="ping" <?php selected( $logo_animation, 'ping' ); ?>><?php esc_html_e( 'Ping', 'torobpay-for-woocommerce' ); ?></option>
									<option value="pulse" <?php selected( $logo_animation, 'pulse' ); ?>><?php esc_html_e( 'Pulse', 'torobpay-for-woocommerce' ); ?></option>
									<option value="wobble" <?php selected( $logo_animation, 'wobble' ); ?>><?php esc_html_e( 'Wobble', 'torobpay-for-woocommerce' ); ?></option>
									<option value="bounce" <?php selected( $logo_animation, 'bounce' ); ?>><?php esc_html_e( 'Bounce', 'torobpay-for-woocommerce' ); ?></option>
									<option value="status-dot" <?php selected( $logo_animation, 'status-dot' ); ?>><?php esc_html_e( 'Status dot', 'torobpay-for-woocommerce' ); ?></option>
									<option value="none" <?php selected( $logo_animation, 'none' ); ?>><?php esc_html_e( 'No animation', 'torobpay-for-woocommerce' ); ?></option>
								</select>
							</td>
						</tr>
					</tbody>
				</table>

				<!-- Display Position Settings Section -->
				<h2 class="section-title" data-section-key="widget-position"><span class="dashicons dashicons-location"></span> <?php esc_html_e( 'Display position settings', 'torobpay-for-woocommerce' ); ?><span class="section-collapse-icon dashicons dashicons-arrow-up-alt2"></span></h2>
				<table class="form-table">
					<tbody>
						<tr>
							<th scope="row">
								<label for="widget_display_hook"><?php esc_html_e( 'Widget display location', 'torobpay-for-woocommerce' ); ?></label>
								<span class="torobpay-tooltip" data-tip="<?php echo esc_attr__( 'Choose where to show the widget on the product page. If your theme has issues, try other options.', 'torobpay-for-woocommerce' ); ?>" title="<?php echo esc_attr__( 'Choose where to show the widget on the product page. If your theme has issues, try other options.', 'torobpay-for-woocommerce' ); ?>"><?php esc_html_e( '?', 'torobpay-for-woocommerce' ); ?></span>
							</th>
							<td>
								<select id="widget_display_hook" name="widget_display_hook">
									<?php foreach ( $predefined_hooks as $hook_name => $hook_label ) : ?>
										<option value="<?php echo esc_attr( $hook_name ); ?>" <?php selected( $display_hook, $hook_name ); ?>>
											<?php echo esc_html( $hook_label ); ?>
										</option>
									<?php endforeach; ?>
									<option value="custom" <?php echo $is_custom_hook ? 'selected' : ''; ?>><?php esc_html_e( 'Custom…', 'torobpay-for-woocommerce' ); ?></option>
								</select>
							</td>
						</tr>

						<tr id="custom_hook_row" style="<?php echo esc_attr( $is_custom_hook ? '' : 'display: none;' ); ?>">
							<th scope="row">
								<label for="widget_display_hook_custom"><?php esc_html_e( 'Custom hook', 'torobpay-for-woocommerce' ); ?></label>
								<span class="torobpay-tooltip" data-tip="<?php echo esc_attr__( 'Your WooCommerce or theme hook name (e.g. woocommerce_after_single_product)', 'torobpay-for-woocommerce' ); ?>" title="<?php echo esc_attr__( 'Your WooCommerce or theme hook name (e.g. woocommerce_after_single_product)', 'torobpay-for-woocommerce' ); ?>"><?php esc_html_e( '?', 'torobpay-for-woocommerce' ); ?></span>
							</th>
							<td>
								<input type="text"
									id="widget_display_hook_custom"
									name="widget_display_hook_custom"
									value="<?php echo $is_custom_hook ? esc_attr( $display_hook ) : ''; ?>"
									class="regular-text"
									placeholder="<?php echo esc_attr__( 'Example: my_theme_after_price', 'torobpay-for-woocommerce' ); ?>">
							</td>
						</tr>

						<tr>
							<th scope="row">
								<label for="widget_display_priority"><?php esc_html_e( 'Display priority', 'torobpay-for-woocommerce' ); ?></label>
								<span class="torobpay-tooltip" data-tip="<?php echo esc_attr__( 'Hook priority (lower number = higher priority). Default: 9', 'torobpay-for-woocommerce' ); ?>" title="<?php echo esc_attr__( 'Hook priority (lower number = higher priority). Default: 9', 'torobpay-for-woocommerce' ); ?>"><?php esc_html_e( '?', 'torobpay-for-woocommerce' ); ?></span>
							</th>
							<td>
								<input type="number"
									id="widget_display_priority"
									name="widget_display_priority"
									value="<?php echo esc_attr( $display_priority ); ?>"
									min="1"
									max="100"
									step="1">
							</td>
						</tr>
					</tbody>
				</table>

				<h2 class="section-title" data-section-key="widget-promo"><span class="dashicons dashicons-tickets-alt"></span><?php esc_html_e( 'Special discount code', 'torobpay-for-woocommerce' ); ?><span class="section-collapse-icon dashicons dashicons-arrow-up-alt2"></span></h2>
				<p class="torobpay-settings-note"><?php esc_html_e( 'Show a TorobPay gateway discount code on the product-page widget. Only the code is required. Amount, percent, minimum purchase or amount cap, and expiry are optional display fields and do not change the cart total. Shoppers enter the code on the TorobPay payment page, not in the store coupon field.', 'torobpay-for-woocommerce' ); ?></p>
				<table class="form-table">
					<tbody>
						<tr>
							<th scope="row">
								<label for="pdp_promo_enabled"><?php esc_html_e( 'Show discount code', 'torobpay-for-woocommerce' ); ?></label>
							</th>
							<td>
								<label>
									<input type="checkbox" id="pdp_promo_enabled" name="pdp_promo_enabled" value="1" <?php checked( $pdp_promo['enabled'], 'yes' ); ?>>
									<?php esc_html_e( 'Show the code on the product widget', 'torobpay-for-woocommerce' ); ?>
								</label>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="pdp_promo_code"><?php esc_html_e( 'Discount code', 'torobpay-for-woocommerce' ); ?> <span class="required" aria-hidden="true">*</span></label>
							</th>
							<td>
								<input type="text" id="pdp_promo_code" name="pdp_promo_code" value="<?php echo esc_attr( $pdp_promo['code'] ); ?>" class="regular-text" maxlength="40" autocomplete="off" dir="ltr" <?php echo 'yes' === $pdp_promo['enabled'] ? 'required' : ''; ?>>
								<p class="description"><?php esc_html_e( 'Required when the discount is shown.', 'torobpay-for-woocommerce' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="pdp_promo_discount_type"><?php esc_html_e( 'Discount display (optional)', 'torobpay-for-woocommerce' ); ?></label>
							</th>
							<td>
								<select id="pdp_promo_discount_type" name="pdp_promo_discount_type">
									<option value="amount" <?php selected( $pdp_promo['discount_type'], 'amount' ); ?>><?php esc_html_e( 'Amount', 'torobpay-for-woocommerce' ); ?></option>
									<option value="percent" <?php selected( $pdp_promo['discount_type'], 'percent' ); ?>><?php esc_html_e( 'Percent', 'torobpay-for-woocommerce' ); ?></option>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="pdp_promo_discount_value"><?php esc_html_e( 'Discount value (optional)', 'torobpay-for-woocommerce' ); ?></label>
							</th>
							<td>
								<input type="number" id="pdp_promo_discount_value" name="pdp_promo_discount_value" value="<?php echo esc_attr( (string) $pdp_promo['discount_value'] ); ?>" min="0" max="1000000000000" step="any" class="regular-text torobpay-promo-amount-input">
								<span id="pdp_promo_value_suffix" class="description"><?php echo 'percent' === $pdp_promo['discount_type'] ? esc_html__( '%', 'torobpay-for-woocommerce' ) : esc_html( $pdp_promo_currency ); ?></span>
								<p class="description"><?php esc_html_e( 'Shown in the lightbox only. Not applied to the WooCommerce cart.', 'torobpay-for-woocommerce' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="pdp_promo_min_total" id="pdp_promo_min_total_label"><?php
								echo 'percent' === $pdp_promo['discount_type']
									? esc_html__( 'Amount cap (optional)', 'torobpay-for-woocommerce' )
									: esc_html__( 'Minimum purchase (optional)', 'torobpay-for-woocommerce' );
								?></label>
							</th>
							<td>
								<input type="number" id="pdp_promo_min_total" name="pdp_promo_min_total" value="<?php echo esc_attr( (string) $pdp_promo['min_total'] ); ?>" min="0" max="1000000000000" step="any" class="regular-text torobpay-promo-amount-input">
								<span class="description"><?php echo esc_html( $pdp_promo_currency ); ?></span>
								<p class="description" id="pdp_promo_min_total_help"<?php echo 'percent' === $pdp_promo['discount_type'] ? '' : ' hidden'; ?>><?php esc_html_e( 'The percent is calculated on this amount even if the order total is higher.', 'torobpay-for-woocommerce' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="pdp_promo_expires_display"><?php esc_html_e( 'Expires (optional)', 'torobpay-for-woocommerce' ); ?></label>
							</th>
							<td>
								<div class="date-input-wrapper torobpay-promo-expires">
									<input type="text"
										id="pdp_promo_expires_display"
										class="jalali-date regular-text"
										data-jdp
										data-jdp-only-date
										data-jdp-target-value-input="#pdp_promo_expires_at"
										data-jdp-target-value-type="gregorian"
										placeholder="<?php echo esc_attr__( 'Date', 'torobpay-for-woocommerce' ); ?>"
										autocomplete="off"
										dir="ltr">
									<span class="dashicons dashicons-calendar-alt" aria-hidden="true"></span>
									<input type="hidden" id="pdp_promo_expires_at" name="pdp_promo_expires_at" value="<?php echo esc_attr( $pdp_promo_expires ); ?>">
								</div>
								<p class="description"><?php esc_html_e( 'Site timezone. The offer lasts until 12 at night on this date, for example Until 12 at night Friday 10 Mehr. Leave empty for no expiry.', 'torobpay-for-woocommerce' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="pdp_promo_style"><?php esc_html_e( 'Discount style', 'torobpay-for-woocommerce' ); ?></label>
								<span class="torobpay-tooltip" data-tip="<?php echo esc_attr__( 'Marquee is a scrolling bar along the bottom of the widget. It pauses while the pointer is over the widget. Simple keeps the label and code still.', 'torobpay-for-woocommerce' ); ?>" title="<?php echo esc_attr__( 'Marquee is a scrolling bar along the bottom of the widget. It pauses while the pointer is over the widget. Simple keeps the label and code still.', 'torobpay-for-woocommerce' ); ?>"><?php esc_html_e( '?', 'torobpay-for-woocommerce' ); ?></span>
							</th>
							<td>
								<select id="pdp_promo_style" name="pdp_promo_style">
									<option value="marquee" <?php selected( $pdp_promo_style, 'marquee' ); ?>><?php esc_html_e( 'Marquee', 'torobpay-for-woocommerce' ); ?></option>
									<option value="simple" <?php selected( $pdp_promo_style, 'simple' ); ?>><?php esc_html_e( 'Simple', 'torobpay-for-woocommerce' ); ?></option>
								</select>
							</td>
						</tr>
					</tbody>
				</table>

				<div class="widget-preview-section">
					<h2><?php esc_html_e( 'Widget preview', 'torobpay-for-woocommerce' ); ?><button type="button" class="torobpay-preview-toggle" onclick="torobpayTogglePreview(this)" title="<?php echo esc_attr__( 'Shrink/expand preview', 'torobpay-for-woocommerce' ); ?>"><span class="dashicons dashicons-minus"></span></button></h2>
					<div class="widget-preview-container">
						<?php
						$promo_preview_on      = function_exists( 'torobpay_is_pdp_promo_active' ) && torobpay_is_pdp_promo_active( $pdp_promo );
						$promo_preview_marquee = $promo_preview_on && 'simple' !== $pdp_promo_style;
						$promo_preview_html    = ( $promo_preview_on && function_exists( 'torobpay_get_pdp_promo_widget_html' ) )
							? torobpay_get_pdp_promo_widget_html( array( 'tabindex' => -1 ) )
							: '';
						?>
						<div id="torobpay-widget-preview" class="torobpay-widget torobpay-widget--<?php echo esc_attr( $widget_layout ); ?><?php echo $promo_preview_on ? ' has-torobpay-promo' : ''; ?><?php echo $promo_preview_marquee ? ' torobpay-promo-marquee' : ''; ?>" data-promo-currency="<?php echo esc_attr( $pdp_promo_currency ); ?>">
							<div class="torobpay-widget-media">
								<img src="<?php echo esc_url( plugins_url( 'assets/images/logo_favicon.svg', TOROBPAY_PLUGIN_FILE ) ); ?>"
									alt="<?php echo esc_attr__( 'TorobPay', 'torobpay-for-woocommerce' ); ?>"
									id="torobpay-widget-logo-preview"
									class="torobpay-widget-image"
									width="60"
									height="60" />
							</div>
							<div class="torobpay-widget-content">
								<div class="torobpay-widget-title"><?php esc_html_e( 'Each installment with TorobPay 250,000 Toman', 'torobpay-for-woocommerce' ); ?></div>
								<div class="torobpay-widget-description"><?php esc_html_e( '4 installments. No fee, checks, or guarantor.', 'torobpay-for-woocommerce' ); ?></div>
								<?php
								if ( '' !== $promo_preview_html && ! $promo_preview_marquee ) {
									echo wp_kses( $promo_preview_html, torobpay_kses_allowed_html() );
								}
								?>
							</div>
							<?php
							if ( '' !== $promo_preview_html && $promo_preview_marquee ) {
								echo wp_kses( $promo_preview_html, torobpay_kses_allowed_html() );
							}
							?>
						</div>
					</div>
				</div>

				<div class="torobpay-settings-note">
					<h2><?php esc_html_e( 'Using the widget elsewhere', 'torobpay-for-woocommerce' ); ?></h2>
					<p><?php esc_html_e( 'If the widget does not display well in your theme or you want it in a specific place, use the shortcode below:', 'torobpay-for-woocommerce' ); ?></p>
					<code style="background: #f1f1f1; padding: 8px; display: inline-block; font-size: 14px; border-radius: 4px;">[torobpay_widget]</code>
					<p class="description"><?php esc_html_e( 'Place this shortcode on any product page or in your product template to show the TorobPay widget with your settings.', 'torobpay-for-woocommerce' ); ?></p>
				</div>
			</div>
			<!-- End Widget Settings Tab -->

			<!-- Badge Settings Tab -->
			<div id="badge-settings" class="tab-content" role="tabpanel" aria-labelledby="torobpay-nav-badge-settings">

				<!-- Position Settings Section -->
				<h2 class="section-title" data-section-key="badge-position"><span class="dashicons dashicons-location"></span><?php esc_html_e( 'Position settings', 'torobpay-for-woocommerce' ); ?><span class="section-collapse-icon dashicons dashicons-arrow-up-alt2"></span></h2>
				<table class="form-table">
					<tbody>
						<tr>
							<th scope="row">
								<label for="badge_position_horizontal"><?php esc_html_e( 'Horizontal position', 'torobpay-for-woocommerce' ); ?></label>
								<span class="torobpay-tooltip" data-tip="<?php echo esc_attr__( 'Badge position on right or left of product', 'torobpay-for-woocommerce' ); ?>" title="<?php echo esc_attr__( 'Badge position on right or left of product', 'torobpay-for-woocommerce' ); ?>"><?php esc_html_e( '?', 'torobpay-for-woocommerce' ); ?></span>
							</th>
							<td>
								<select id="badge_position_horizontal" name="badge_position_horizontal">
									<option value="right" <?php selected( $badge_position_horizontal, 'right' ); ?>><?php esc_html_e( 'Right', 'torobpay-for-woocommerce' ); ?></option>
									<option value="left" <?php selected( $badge_position_horizontal, 'left' ); ?>><?php esc_html_e( 'Left', 'torobpay-for-woocommerce' ); ?></option>
								</select>
							</td>
						</tr>

						<tr>
							<th scope="row">
								<label for="badge_position_vertical"><?php esc_html_e( 'Vertical position', 'torobpay-for-woocommerce' ); ?></label>
								<span class="torobpay-tooltip" data-tip="<?php echo esc_attr__( 'Badge position at top or bottom of product', 'torobpay-for-woocommerce' ); ?>" title="<?php echo esc_attr__( 'Badge position at top or bottom of product', 'torobpay-for-woocommerce' ); ?>"><?php esc_html_e( '?', 'torobpay-for-woocommerce' ); ?></span>
							</th>
							<td>
								<select id="badge_position_vertical" name="badge_position_vertical">
									<option value="top" <?php selected( $badge_position_vertical, 'top' ); ?>><?php esc_html_e( 'Top', 'torobpay-for-woocommerce' ); ?></option>
									<option value="bottom" <?php selected( $badge_position_vertical, 'bottom' ); ?>><?php esc_html_e( 'Bottom', 'torobpay-for-woocommerce' ); ?></option>
								</select>
							</td>
						</tr>

						<?php
						torobpay_render_responsive_number_row(
							array(
								'label'        => __( 'Horizontal spacing', 'torobpay-for-woocommerce' ),
								'tip'          => __( 'Badge offset from right or left edge (same unit for all screens)', 'torobpay-for-woocommerce' ),
								'name'         => 'badge_offset_horizontal',
								'base'         => $badge_offset_horizontal_r['base'],
								'tablet'       => $badge_offset_horizontal_r['tablet'],
								'mobile'       => $badge_offset_horizontal_r['mobile'],
								'min'          => 0,
								'max'          => 1000,
								'step'         => 1,
								'unit_field'   => 'badge_offset_horizontal_unit',
								'unit_value'   => $badge_offset_horizontal_unit,
								'unit_options' => array( 'px' => __( 'px', 'torobpay-for-woocommerce' ), '%' => __( 'Percent', 'torobpay-for-woocommerce' ) ),
							)
						);

						torobpay_render_responsive_number_row(
							array(
								'label'        => __( 'Vertical spacing', 'torobpay-for-woocommerce' ),
								'tip'          => __( 'Badge offset from top or bottom edge (same unit for all screens)', 'torobpay-for-woocommerce' ),
								'name'         => 'badge_offset_vertical',
								'base'         => $badge_offset_vertical_r['base'],
								'tablet'       => $badge_offset_vertical_r['tablet'],
								'mobile'       => $badge_offset_vertical_r['mobile'],
								'min'          => 0,
								'max'          => 1000,
								'step'         => 1,
								'unit_field'   => 'badge_offset_vertical_unit',
								'unit_value'   => $badge_offset_vertical_unit,
								'unit_options' => array( 'px' => __( 'px', 'torobpay-for-woocommerce' ), '%' => __( 'Percent', 'torobpay-for-woocommerce' ) ),
							)
						);
						?>
					</tbody>
				</table>

				<!-- Badge Color Settings Section -->
				<h2 class="section-title" data-section-key="badge-colors"><span class="dashicons dashicons-art"></span><?php esc_html_e( 'Color settings', 'torobpay-for-woocommerce' ); ?><span class="section-collapse-icon dashicons dashicons-arrow-up-alt2"></span></h2>
				<table class="form-table">
					<tbody>
						<tr>
							<th scope="row">
								<label for="badge_background_color"><?php esc_html_e( 'Background color', 'torobpay-for-woocommerce' ); ?></label>
								<span class="torobpay-tooltip" data-tip="<?php echo esc_attr__( 'Badge background color', 'torobpay-for-woocommerce' ); ?>" title="<?php echo esc_attr__( 'Badge background color', 'torobpay-for-woocommerce' ); ?>"><?php esc_html_e( '?', 'torobpay-for-woocommerce' ); ?></span>
							</th>
							<td>
								<input type="color"
									id="badge_background_color"
									name="badge_background_color"
									value="<?php echo esc_attr( $badge_background_color ); ?>"
									class="color-picker">
							</td>
						</tr>

						<tr>
							<th scope="row">
								<label for="badge_text_color"><?php esc_html_e( 'Text color', 'torobpay-for-woocommerce' ); ?></label>
								<span class="torobpay-tooltip" data-tip="<?php echo esc_attr__( 'Badge text color', 'torobpay-for-woocommerce' ); ?>" title="<?php echo esc_attr__( 'Badge text color', 'torobpay-for-woocommerce' ); ?>"><?php esc_html_e( '?', 'torobpay-for-woocommerce' ); ?></span>
							</th>
							<td>
								<input type="color"
									id="badge_text_color"
									name="badge_text_color"
									value="<?php echo esc_attr( $badge_text_color ); ?>"
									class="color-picker">
							</td>
						</tr>

						<?php
						torobpay_render_responsive_number_row(
							array(
								'label'  => __( 'Border radius', 'torobpay-for-woocommerce' ),
								'tip'    => __( 'Badge corner radius (0 to 50 px)', 'torobpay-for-woocommerce' ),
								'name'   => 'badge_border_radius',
								'base'   => $badge_border_radius_r['base'],
								'tablet' => $badge_border_radius_r['tablet'],
								'mobile' => $badge_border_radius_r['mobile'],
								'min'    => 0,
								'max'    => 50,
								'step'   => 1,
							)
						);
						?>
					</tbody>
				</table>

				<!-- Badge Typography Settings Section -->
				<h2 class="section-title" data-section-key="badge-typography"><span class="dashicons dashicons-edit"></span><?php esc_html_e( 'Typography settings', 'torobpay-for-woocommerce' ); ?><span class="section-collapse-icon dashicons dashicons-arrow-up-alt2"></span></h2>
				<table class="form-table">
					<tbody>
						<?php
						torobpay_render_responsive_number_row(
							array(
								'label'  => __( 'Font size', 'torobpay-for-woocommerce' ),
								'tip'    => __( 'Badge text font size (8 to 24 px)', 'torobpay-for-woocommerce' ),
								'name'   => 'badge_font_size',
								'base'   => $badge_font_size_r['base'],
								'tablet' => $badge_font_size_r['tablet'],
								'mobile' => $badge_font_size_r['mobile'],
								'min'    => 8,
								'max'    => 24,
							)
						);
						?>

						<tr>
							<th scope="row">
								<label for="badge_font_weight"><?php esc_html_e( 'Font weight', 'torobpay-for-woocommerce' ); ?></label>
								<span class="torobpay-tooltip" data-tip="<?php echo esc_attr__( 'Badge text font weight', 'torobpay-for-woocommerce' ); ?>" title="<?php echo esc_attr__( 'Badge text font weight', 'torobpay-for-woocommerce' ); ?>"><?php esc_html_e( '?', 'torobpay-for-woocommerce' ); ?></span>
							</th>
							<td>
								<select id="badge_font_weight" name="badge_font_weight">
									<option value="300" <?php selected( $badge_font_weight, '300' ); ?>><?php esc_html_e( 'Light (300)', 'torobpay-for-woocommerce' ); ?></option>
									<option value="400" <?php selected( $badge_font_weight, '400' ); ?>><?php esc_html_e( 'Regular (400)', 'torobpay-for-woocommerce' ); ?></option>
									<option value="500" <?php selected( $badge_font_weight, '500' ); ?>><?php esc_html_e( 'Medium (500)', 'torobpay-for-woocommerce' ); ?></option>
									<option value="600" <?php selected( $badge_font_weight, '600' ); ?>><?php esc_html_e( 'Semi-bold (600)', 'torobpay-for-woocommerce' ); ?></option>
									<option value="700" <?php selected( $badge_font_weight, '700' ); ?>><?php esc_html_e( 'Bold (700)', 'torobpay-for-woocommerce' ); ?></option>
									<option value="800" <?php selected( $badge_font_weight, '800' ); ?>><?php esc_html_e( 'Extra bold (800)', 'torobpay-for-woocommerce' ); ?></option>
								</select>
							</td>
						</tr>
					</tbody>
				</table>

				<!-- Badge Logo Settings Section -->
				<h2 class="section-title" data-section-key="badge-logo"><span class="dashicons dashicons-format-image"></span><?php esc_html_e( 'Logo settings', 'torobpay-for-woocommerce' ); ?><span class="section-collapse-icon dashicons dashicons-arrow-up-alt2"></span></h2>
				<table class="form-table">
					<tbody>
						<?php
						torobpay_render_responsive_number_row(
							array(
								'label'  => __( 'Logo height', 'torobpay-for-woocommerce' ),
								'tip'    => __( 'Badge logo height (10 to 48 px)', 'torobpay-for-woocommerce' ),
								'name'   => 'badge_logo_height',
								'base'   => $badge_logo_height_r['base'],
								'tablet' => $badge_logo_height_r['tablet'],
								'mobile' => $badge_logo_height_r['mobile'],
								'min'    => 10,
								'max'    => 48,
							)
						);
						?>
					</tbody>
				</table>

				<!-- Compatibility Mode Section -->
				<h2 class="section-title" data-section-key="badge-advanced"><span class="dashicons dashicons-admin-generic"></span><?php esc_html_e( 'Advanced settings', 'torobpay-for-woocommerce' ); ?><span class="section-collapse-icon dashicons dashicons-arrow-up-alt2"></span></h2>
				<table class="form-table">
					<tbody>
						<tr>
							<th scope="row">
								<label for="badge_compatibility_mode"><?php esc_html_e( 'Compatibility mode', 'torobpay-for-woocommerce' ); ?></label>
								<span style="background:#f0ad4e;color:#fff;font-size:11px;padding:1px 6px;border-radius:3px;vertical-align:middle;"><?php esc_html_e( 'Beta', 'torobpay-for-woocommerce' ); ?></span>
								<span class="torobpay-tooltip" data-tip="<?php echo esc_attr__( 'Enable only if the badge does not appear in your theme. Clear the cache after enabling.', 'torobpay-for-woocommerce' ); ?>" title="<?php echo esc_attr__( 'Enable only if the badge does not appear in your theme. Clear the cache after enabling.', 'torobpay-for-woocommerce' ); ?>"><?php esc_html_e( '?', 'torobpay-for-woocommerce' ); ?></span>
							</th>
							<td>
								<input type="checkbox"
									id="badge_compatibility_mode"
									name="badge_compatibility_mode"
									value="yes"
									<?php checked( $badge_compatibility_mode, 'yes' ); ?>>
								<label for="badge_compatibility_mode"><?php esc_html_e( 'Enable compatibility mode', 'torobpay-for-woocommerce' ); ?></label>
							</td>
						</tr>
					</tbody>
				</table>

				<div class="badge-preview-section">
					<h2><?php esc_html_e( 'Badge preview', 'torobpay-for-woocommerce' ); ?><button type="button" class="torobpay-preview-toggle" onclick="torobpayTogglePreview(this)" title="<?php echo esc_attr__( 'Shrink/expand preview', 'torobpay-for-woocommerce' ); ?>"><span class="dashicons dashicons-minus"></span></button></h2>
					<div class="badge-preview-container">
						<div class="badge-product-mockup">
							<div class="badge-product-image">
								<div id="torobpay-badge-preview" class="torobpay-product-badge">
									<div class="torobpay-product-badge-inner">
										<img src="<?php echo esc_url( plugins_url( 'assets/images/logo_favicon.svg', TOROBPAY_PLUGIN_FILE ) ); ?>"
											alt="<?php echo esc_attr__( 'TorobPay installment payment', 'torobpay-for-woocommerce' ); ?>"
											class="torobpay-product-badge-icon"
											width="<?php echo esc_attr( $badge_logo_height_r['base'] ); ?>"
											height="<?php echo esc_attr( $badge_logo_height_r['base'] ); ?>" />
										<span class="torobpay-product-badge-text"><?php esc_html_e( 'Each installment 250,000 Toman', 'torobpay-for-woocommerce' ); ?></span>
									</div>
								</div>
							</div>
							<div class="badge-product-name"><?php esc_html_e( 'Sample product', 'torobpay-for-woocommerce' ); ?></div>
							<div class="badge-product-price"><?php esc_html_e( '1,000,000 Toman', 'torobpay-for-woocommerce' ); ?></div>
						</div>
					</div>
				</div>
			</div>
			<!-- End Badge Settings Tab -->

			<!-- Marquee Settings Tab -->
			<div id="marquee-settings" class="tab-content" role="tabpanel" aria-labelledby="torobpay-nav-marquee-settings">
				<!-- Marquee Colors Section -->
				<h2 class="section-title" data-section-key="marquee-colors"><span class="dashicons dashicons-art"></span><?php esc_html_e( 'Color settings', 'torobpay-for-woocommerce' ); ?><span class="section-collapse-icon dashicons dashicons-arrow-up-alt2"></span></h2>
				<table class="form-table">
					<tbody>
						<tr id="marquee_style_rows">
							<th scope="row">
								<label for="marquee_bg_color"><?php esc_html_e( 'Background color', 'torobpay-for-woocommerce' ); ?></label>
								<span class="torobpay-tooltip" data-tip="<?php echo esc_attr__( 'Bar glass background color and opacity (0 = fully transparent, 100 = fully opaque)', 'torobpay-for-woocommerce' ); ?>" title="<?php echo esc_attr__( 'Bar glass background color and opacity (0 = fully transparent, 100 = fully opaque)', 'torobpay-for-woocommerce' ); ?>"><?php esc_html_e( '?', 'torobpay-for-woocommerce' ); ?></span>
							</th>
							<td>
								<input type="color"
									id="marquee_bg_color"
									name="marquee_bg_color"
									value="<?php echo esc_attr( $marquee_bg_color ); ?>">
								&nbsp;
								<label for="marquee_bg_opacity"><?php esc_html_e( 'Opacity:', 'torobpay-for-woocommerce' ); ?></label>
								<input type="number"
									id="marquee_bg_opacity"
									name="marquee_bg_opacity"
									value="<?php echo esc_attr( $marquee_bg_opacity ); ?>"
									min="0" max="100" step="1" style="width:70px;">
								<span><?php esc_html_e( '%', 'torobpay-for-woocommerce' ); ?></span>
							</td>
						</tr>
						<tr id="marquee_text_color_row">
							<th scope="row">
								<label for="marquee_text_color"><?php esc_html_e( 'Text color', 'torobpay-for-woocommerce' ); ?></label>
								<span class="torobpay-tooltip" data-tip="<?php echo esc_attr__( 'Marquee text color', 'torobpay-for-woocommerce' ); ?>" title="<?php echo esc_attr__( 'Marquee text color', 'torobpay-for-woocommerce' ); ?>"><?php esc_html_e( '?', 'torobpay-for-woocommerce' ); ?></span>
							</th>
							<td>
								<input type="color"
									id="marquee_text_color"
									name="marquee_text_color"
									value="<?php echo esc_attr( $marquee_text_color ); ?>">
							</td>
						</tr>
					</tbody>
				</table>

				<!-- Marquee Typography Section -->
				<h2 class="section-title" data-section-key="marquee-typography"><span class="dashicons dashicons-edit"></span><?php esc_html_e( 'Typography settings', 'torobpay-for-woocommerce' ); ?><span class="section-collapse-icon dashicons dashicons-arrow-up-alt2"></span></h2>
				<table class="form-table">
					<tbody>
						<tr id="marquee_font_row">
							<th scope="row">
								<label for="marquee_font_weight"><?php esc_html_e( 'Font weight', 'torobpay-for-woocommerce' ); ?></label>
							</th>
							<td>
								<select id="marquee_font_weight" name="marquee_font_weight">
									<?php
									$fw_options = [
										'300' => __( 'Light (300)', 'torobpay-for-woocommerce' ),
										'400' => __( 'Regular (400)', 'torobpay-for-woocommerce' ),
										'500' => __( 'Medium (500)', 'torobpay-for-woocommerce' ),
										'600' => __( 'Semi-bold (600)', 'torobpay-for-woocommerce' ),
										'700' => __( 'Bold (700)', 'torobpay-for-woocommerce' ),
										'800' => __( 'Extra bold (800)', 'torobpay-for-woocommerce' ),
									];
									foreach ( $fw_options as $val => $label ) {
										printf( '<option value="%s"%s>%s</option>', esc_attr( $val ), selected( $marquee_font_weight, $val, false ), esc_html( $label ) );
									}
									?>
								</select>
							</td>
						</tr>
						<?php
						torobpay_render_responsive_number_row(
							array(
								'label'  => __( 'Font size', 'torobpay-for-woocommerce' ),
								'tip'    => __( 'Marquee text font size (8–24 px)', 'torobpay-for-woocommerce' ),
								'name'   => 'marquee_font_size',
								'base'   => $marquee_font_size_r['base'],
								'tablet' => $marquee_font_size_r['tablet'],
								'mobile' => $marquee_font_size_r['mobile'],
								'min'    => 8,
								'max'    => 24,
							)
						);
						?>
						<?php
						torobpay_render_responsive_number_row(
							array(
								'label'  => __( 'Icon size', 'torobpay-for-woocommerce' ),
								'tip'    => __( 'Marquee logo icon height (8–48 px)', 'torobpay-for-woocommerce' ),
								'name'   => 'marquee_icon_size',
								'base'   => $marquee_icon_size_r['base'],
								'tablet' => $marquee_icon_size_r['tablet'],
								'mobile' => $marquee_icon_size_r['mobile'],
								'min'    => 8,
								'max'    => 48,
							)
						);
						?>
					</tbody>
				</table>

				<!-- Marquee Dimensions & Position Section -->
				<h2 class="section-title" data-section-key="marquee-dimensions"><span class="dashicons dashicons-editor-expand"></span> <?php esc_html_e( 'Dimension and position settings', 'torobpay-for-woocommerce' ); ?><span class="section-collapse-icon dashicons dashicons-arrow-up-alt2"></span></h2>
				<table class="form-table">
					<tbody>
						<?php
						torobpay_render_responsive_number_row(
							array(
								'label'        => __( 'Bar height', 'torobpay-for-woocommerce' ),
								'tip'          => __( 'Marquee bar height — px or percent of image height (%)', 'torobpay-for-woocommerce' ),
								'name'         => 'marquee_height',
								'base'         => $marquee_height_r['base'],
								'tablet'       => $marquee_height_r['tablet'],
								'mobile'       => $marquee_height_r['mobile'],
								'min'          => 1,
								'max'          => 400,
								'step'         => 1,
								'unit_field'   => 'marquee_height_unit',
								'unit_value'   => $marquee_height_unit,
								'unit_options' => array( 'px' => 'px', '%' => '%' ),
							)
						);

						torobpay_render_responsive_number_row(
							array(
								'label'  => __( 'Top-left corner radius', 'torobpay-for-woocommerce' ),
								'tip'    => __( 'Top-left bar corner radius in px', 'torobpay-for-woocommerce' ),
								'name'   => 'marquee_radius_tl',
								'base'   => $marquee_radius_tl_r['base'],
								'tablet' => $marquee_radius_tl_r['tablet'],
								'mobile' => $marquee_radius_tl_r['mobile'],
								'min'    => 0,
								'max'    => 200,
								'step'   => 1,
							)
						);

						torobpay_render_responsive_number_row(
							array(
								'label'  => __( 'Top-right corner radius', 'torobpay-for-woocommerce' ),
								'tip'    => __( 'Top-right bar corner radius in px', 'torobpay-for-woocommerce' ),
								'name'   => 'marquee_radius_tr',
								'base'   => $marquee_radius_tr_r['base'],
								'tablet' => $marquee_radius_tr_r['tablet'],
								'mobile' => $marquee_radius_tr_r['mobile'],
								'min'    => 0,
								'max'    => 200,
								'step'   => 1,
							)
						);

						torobpay_render_responsive_number_row(
							array(
								'label'  => __( 'Bottom-left corner radius', 'torobpay-for-woocommerce' ),
								'tip'    => __( 'Bottom-left bar corner radius in px', 'torobpay-for-woocommerce' ),
								'name'   => 'marquee_radius_bl',
								'base'   => $marquee_radius_bl_r['base'],
								'tablet' => $marquee_radius_bl_r['tablet'],
								'mobile' => $marquee_radius_bl_r['mobile'],
								'min'    => 0,
								'max'    => 200,
								'step'   => 1,
							)
						);

						torobpay_render_responsive_number_row(
							array(
								'label'  => __( 'Bottom-right corner radius', 'torobpay-for-woocommerce' ),
								'tip'    => __( 'Bottom-right bar corner radius in px', 'torobpay-for-woocommerce' ),
								'name'   => 'marquee_radius_br',
								'base'   => $marquee_radius_br_r['base'],
								'tablet' => $marquee_radius_br_r['tablet'],
								'mobile' => $marquee_radius_br_r['mobile'],
								'min'    => 0,
								'max'    => 200,
								'step'   => 1,
							)
						);
						?>
						<tr id="marquee_vertical_align_row">
							<th scope="row">
								<label for="marquee_vertical_align"><?php esc_html_e( 'Vertical text alignment', 'torobpay-for-woocommerce' ); ?></label>
								<span class="torobpay-tooltip" data-tip="<?php echo esc_attr__( 'Vertical alignment of text and icon in the bar — padding is applied toward the selected alignment', 'torobpay-for-woocommerce' ); ?>" title="<?php echo esc_attr__( 'Vertical alignment of text and icon in the bar — padding is applied toward the selected alignment', 'torobpay-for-woocommerce' ); ?>"><?php esc_html_e( '?', 'torobpay-for-woocommerce' ); ?></span>
							</th>
							<td>
								<select id="marquee_vertical_align" name="marquee_vertical_align" style="min-width:180px;">
									<option value="top" <?php selected( $marquee_vertical_align, 'top' ); ?>><?php esc_html_e( 'Top', 'torobpay-for-woocommerce' ); ?></option>
									<option value="middle" <?php selected( $marquee_vertical_align, 'middle' ); ?>><?php esc_html_e( 'Middle (default)', 'torobpay-for-woocommerce' ); ?></option>
									<option value="bottom" <?php selected( $marquee_vertical_align, 'bottom' ); ?>><?php esc_html_e( 'Bottom', 'torobpay-for-woocommerce' ); ?></option>
								</select>
								&nbsp;&nbsp;
								<label for="marquee_vertical_padding"><?php esc_html_e( 'Padding:', 'torobpay-for-woocommerce' ); ?></label>
								<input type="number" id="marquee_vertical_padding" name="marquee_vertical_padding"
									value="<?php echo esc_attr( $marquee_vertical_padding ); ?>"
									min="0" step="0.5" style="width:70px;">
								<select id="marquee_vertical_padding_unit" name="marquee_vertical_padding_unit" style="width:auto; min-width:60px;">
									<option value="px" <?php selected( $marquee_vertical_padding_unit, 'px' ); ?>>px</option>
									<option value="%" <?php selected( $marquee_vertical_padding_unit, '%' ); ?>>%</option>
									<option value="rem" <?php selected( $marquee_vertical_padding_unit, 'rem' ); ?>>rem</option>
								</select>
							</td>
						</tr>
						<tr id="marquee_position_gap_row">
							<th scope="row">
								<label for="marquee_position"><?php esc_html_e( 'Bar position', 'torobpay-for-woocommerce' ); ?></label>
								<span class="torobpay-tooltip" data-tip="<?php echo esc_attr__( 'Bar position relative to image (top or bottom)', 'torobpay-for-woocommerce' ); ?>" title="<?php echo esc_attr__( 'Bar position relative to image (top or bottom)', 'torobpay-for-woocommerce' ); ?>"><?php esc_html_e( '?', 'torobpay-for-woocommerce' ); ?></span>
							</th>
							<td>
								<select id="marquee_position" name="marquee_position">
									<option value="bottom" <?php selected( $marquee_position, 'bottom' ); ?>><?php esc_html_e( 'Bottom of image', 'torobpay-for-woocommerce' ); ?></option>
									<option value="top" <?php selected( $marquee_position, 'top' ); ?>><?php esc_html_e( 'Top of image', 'torobpay-for-woocommerce' ); ?></option>
								</select>
							</td>
						</tr>
						<?php
						torobpay_render_responsive_number_row(
							array(
								'label'        => __( 'Edge offset', 'torobpay-for-woocommerce' ),
								'tip'          => __( 'Bar offset from top or bottom of image (0 = flush with edge)', 'torobpay-for-woocommerce' ),
								'name'         => 'marquee_gap',
								'base'         => $marquee_gap_r['base'],
								'tablet'       => $marquee_gap_r['tablet'],
								'mobile'       => $marquee_gap_r['mobile'],
								'min'          => 0,
								'max'          => 1000,
								'step'         => 1,
								'unit_field'   => 'marquee_gap_unit',
								'unit_value'   => $marquee_gap_unit,
								'unit_options' => array( 'px' => 'px', '%' => '%' ),
							)
						);
						?>
					</tbody>
				</table>

				<!-- Marquee Scroll Section -->
				<h2 class="section-title" data-section-key="marquee-scroll"><span class="dashicons dashicons-controls-play"></span><?php esc_html_e( 'Scroll settings', 'torobpay-for-woocommerce' ); ?><span class="section-collapse-icon dashicons dashicons-arrow-up-alt2"></span></h2>
				<table class="form-table">
					<tbody>
						<tr id="marquee_speed_row">
							<th scope="row">
								<label for="marquee_scroll_speed"><?php esc_html_e( 'Scroll speed', 'torobpay-for-woocommerce' ); ?></label>
								<span class="torobpay-tooltip" data-tip="<?php echo esc_attr__( 'Full scroll cycle duration in seconds (lower = faster)', 'torobpay-for-woocommerce' ); ?>" title="<?php echo esc_attr__( 'Full scroll cycle duration in seconds (lower = faster)', 'torobpay-for-woocommerce' ); ?>"><?php esc_html_e( '?', 'torobpay-for-woocommerce' ); ?></span>
							</th>
							<td>
								<input type="number"
									id="marquee_scroll_speed"
									name="marquee_scroll_speed"
									value="<?php echo esc_attr( $marquee_scroll_speed ); ?>"
									min="4" max="60" step="1" style="width:70px;">
								<span><?php esc_html_e( 'seconds', 'torobpay-for-woocommerce' ); ?></span>
							</td>
						</tr>
						<tr id="marquee_z_index_row">
							<th scope="row">
								<label for="marquee_z_index">z-index</label>
								<span class="torobpay-tooltip" data-tip="<?php echo esc_attr__( 'Bar z-order relative to other elements on the product image', 'torobpay-for-woocommerce' ); ?>" title="<?php echo esc_attr__( 'Bar z-order relative to other elements on the product image', 'torobpay-for-woocommerce' ); ?>"><?php esc_html_e( '?', 'torobpay-for-woocommerce' ); ?></span>
							</th>
							<td>
								<input type="number"
									id="marquee_z_index"
									name="marquee_z_index"
									value="<?php echo esc_attr( $marquee_z_index ); ?>"
									min="0" step="1" style="width:80px;">
							</td>
						</tr>
					</tbody>
				</table>

				<!-- Marquee Advanced Section -->
				<h2 class="section-title" data-section-key="marquee-advanced"><span class="dashicons dashicons-admin-generic"></span><?php esc_html_e( 'Advanced settings', 'torobpay-for-woocommerce' ); ?><span class="section-collapse-icon dashicons dashicons-arrow-up-alt2"></span></h2>
				<table class="form-table">
					<tbody>
						<tr id="marquee_compat_row">
							<th scope="row">
								<label for="marquee_compatibility_mode"><?php esc_html_e( 'Compatibility mode', 'torobpay-for-woocommerce' ); ?></label>
								<span style="background:#f0ad4e;color:#fff;font-size:11px;padding:1px 6px;border-radius:3px;vertical-align:middle;"><?php esc_html_e( 'Beta', 'torobpay-for-woocommerce' ); ?></span>
								<span class="torobpay-tooltip" data-tip="<?php echo esc_attr__( 'Enable only if the marquee does not appear in the correct place in your theme. The bar is then injected into the product image via JavaScript.', 'torobpay-for-woocommerce' ); ?>" title="<?php echo esc_attr__( 'Enable only if the marquee does not appear in the correct place in your theme. The bar is then injected into the product image via JavaScript.', 'torobpay-for-woocommerce' ); ?>"><?php esc_html_e( '?', 'torobpay-for-woocommerce' ); ?></span>
							</th>
							<td>
								<label>
									<input type="checkbox" id="marquee_compatibility_mode" name="marquee_compatibility_mode" value="yes" <?php checked( $marquee_compatibility_mode, 'yes' ); ?>><?php esc_html_e( 'Enable this if the marquee does not appear in the correct place in your theme.', 'torobpay-for-woocommerce' ); ?></label>
							</td>
						</tr>
					</tbody>
				</table>

				<div class="badge-preview-section">
					<h2><?php esc_html_e( 'Marquee preview', 'torobpay-for-woocommerce' ); ?><button type="button" class="torobpay-preview-toggle" onclick="torobpayTogglePreview(this)" title="<?php echo esc_attr__( 'Shrink/expand preview', 'torobpay-for-woocommerce' ); ?>"><span class="dashicons dashicons-minus"></span></button></h2>
					<div class="badge-preview-container">
						<div class="badge-product-mockup">
							<div class="badge-product-image">
								<div id="torobpay-marquee-preview" class="torobpay-product-marquee">
									<?php
									$logo_url     = esc_url( plugins_url( 'assets/images/logo_favicon.svg', TOROBPAY_PLUGIN_FILE ) );
									$slogan_text  = esc_html( $marquee_slogan );
									$icon_s       = (int) $marquee_icon_size_r['base'];
									$preview_item = '<span class="torobpay-product-marquee-item">'
										. '<img src="' . $logo_url . '" alt="" class="torobpay-product-marquee-logo" width="' . $icon_s . '" height="' . $icon_s . '" />'
										. esc_html__( 'Each installment with TorobPay 250,000 Toman', 'torobpay-for-woocommerce' )
										. '<span class="torobpay-product-marquee-sep">•</span>'
										. '<span class="torobpay-marquee-preview-slogan">' . $slogan_text . '</span>'
										. '&nbsp;&nbsp;&nbsp;'
										. '</span>';
									$preview_copy = str_replace(
										'class="torobpay-product-marquee-item"',
										'class="torobpay-product-marquee-item" aria-hidden="true"',
										$preview_item
									);
									?>
									<div class="torobpay-product-marquee-track">
										<?php
										echo wp_kses( $preview_item . $preview_copy . $preview_copy . $preview_copy, torobpay_kses_allowed_html() );
										?>
									</div>
								</div>
							</div>
							<div class="badge-product-name"><?php esc_html_e( 'Sample product', 'torobpay-for-woocommerce' ); ?></div>
							<div class="badge-product-price"><?php esc_html_e( '1,000,000 Toman', 'torobpay-for-woocommerce' ); ?></div>
						</div>
					</div>
				</div>
			</div>
			<!-- End Marquee Settings Tab -->

			<!-- Topbar Settings Tab -->
			<div id="topbar-settings" class="tab-content" role="tabpanel" aria-labelledby="torobpay-nav-topbar-settings">

				<h2 class="section-title" data-section-key="topbar-placement"><span class="dashicons dashicons-location"></span><?php esc_html_e( 'Display location', 'torobpay-for-woocommerce' ); ?><span class="section-collapse-icon dashicons dashicons-arrow-up-alt2"></span></h2>
				<table class="form-table">
					<tbody>
						<tr>
							<th scope="row">
								<label><?php esc_html_e( 'Placement mode', 'torobpay-for-woocommerce' ); ?></label>
								<span class="torobpay-tooltip" data-tip="<?php echo esc_attr__( 'Automatic: the bar is injected at the top of the body tag (sticky mode supported). Manual: a simple bar for shortcode display; your theme controls stickiness and position.', 'torobpay-for-woocommerce' ); ?>" title="<?php echo esc_attr__( 'Top bar display location', 'torobpay-for-woocommerce' ); ?>"><?php esc_html_e( '?', 'torobpay-for-woocommerce' ); ?></span>
							</th>
							<td>
								<fieldset>
									<label>
										<input type="radio" name="topbar_placement" value="automatic" <?php checked( $topbar_placement, 'automatic' ); ?>>
										<?php esc_html_e( 'Automatic: inject at top of', 'torobpay-for-woocommerce' ); ?> <code>&lt;body&gt;</code> <?php esc_html_e( '(default)', 'torobpay-for-woocommerce' ); ?>
									</label>
									<br>
									<label>
										<input type="radio" name="topbar_placement" value="manual" <?php checked( $topbar_placement, 'manual' ); ?>><?php esc_html_e( 'Manual: shortcode only in theme header or page builder', 'torobpay-for-woocommerce' ); ?></label>
								</fieldset>
							</td>
						</tr>
					</tbody>
				</table>

				<div class="torobpay-settings-note">
					<h2><?php esc_html_e( 'Using the top bar in the theme header', 'torobpay-for-woocommerce' ); ?></h2>
					<p><?php esc_html_e( 'To place the bar in the theme header, use the shortcode below in an Elementor header widget or any page builder that supports shortcodes:', 'torobpay-for-woocommerce' ); ?></p>
					<code style="background: #f1f1f1; padding: 8px; display: inline-block; font-size: 14px; border-radius: 4px;" dir="ltr">[torobpay_topbar]</code>
					<p class="description"><?php esc_html_e( 'Place this shortcode at the top of the header area. The bar renders inline (without sticky mode and without changing html spacing) so you can control header stickiness, layering, and effects with your theme. Select manual mode above to disable automatic injection.', 'torobpay-for-woocommerce' ); ?></p>
				</div>

				<h2 class="section-title" data-section-key="topbar-dimensions"><span class="dashicons dashicons-editor-expand"></span><?php esc_html_e( 'Dimension settings', 'torobpay-for-woocommerce' ); ?><span class="section-collapse-icon dashicons dashicons-arrow-up-alt2"></span></h2>
				<table class="form-table">
					<tbody>
						<?php
						torobpay_render_responsive_number_row(
							array(
								'label'  => __( 'Bar height', 'torobpay-for-woocommerce' ),
								'tip'    => __( 'Top site bar height (20 to 80 px)', 'torobpay-for-woocommerce' ),
								'name'   => 'topbar_height',
								'base'   => $topbar_height_r['base'],
								'tablet' => $topbar_height_r['tablet'],
								'mobile' => $topbar_height_r['mobile'],
								'min'    => 20,
								'max'    => 80,
							)
						);
						torobpay_render_responsive_number_row(
							array(
								'label'  => __( 'Text area width', 'torobpay-for-woocommerce' ),
								'tip'    => __( 'Max width of rotating text area (100 to 1200 px)', 'torobpay-for-woocommerce' ),
								'name'   => 'topbar_ticker_width',
								'base'   => $topbar_ticker_width_r['base'],
								'tablet' => $topbar_ticker_width_r['tablet'],
								'mobile' => $topbar_ticker_width_r['mobile'],
								'min'    => 100,
								'max'    => 1200,
								'step'   => 10,
							)
						);
						torobpay_render_responsive_number_row(
							array(
								'label'  => __( 'Logo size', 'torobpay-for-woocommerce' ),
								'tip'    => __( 'TorobPay logo height in bar (16 to 48 px)', 'torobpay-for-woocommerce' ),
								'name'   => 'topbar_logo_size',
								'base'   => $topbar_logo_size_r['base'],
								'tablet' => $topbar_logo_size_r['tablet'],
								'mobile' => $topbar_logo_size_r['mobile'],
								'min'    => 16,
								'max'    => 48,
							)
						);
						?>
					</tbody>
				</table>

				<h2 class="section-title" data-section-key="topbar-typography"><span class="dashicons dashicons-edit"></span><?php esc_html_e( 'Typography settings', 'torobpay-for-woocommerce' ); ?><span class="section-collapse-icon dashicons dashicons-arrow-up-alt2"></span></h2>
				<table class="form-table">
					<tbody>
						<?php
						torobpay_render_responsive_number_row(
							array(
								'label'  => __( 'Font size', 'torobpay-for-woocommerce' ),
								'tip'    => __( 'Bar text font size (10 to 24 px)', 'torobpay-for-woocommerce' ),
								'name'   => 'topbar_font_size',
								'base'   => $topbar_font_size_r['base'],
								'tablet' => $topbar_font_size_r['tablet'],
								'mobile' => $topbar_font_size_r['mobile'],
								'min'    => 10,
								'max'    => 24,
							)
						);
						?>
						<tr>
							<th scope="row">
								<label for="topbar_font_weight"><?php esc_html_e( 'Font weight', 'torobpay-for-woocommerce' ); ?></label>
								<span class="torobpay-tooltip" data-tip="<?php echo esc_attr__( 'Bar text font weight', 'torobpay-for-woocommerce' ); ?>" title="<?php echo esc_attr__( 'Bar text font weight', 'torobpay-for-woocommerce' ); ?>"><?php esc_html_e( '?', 'torobpay-for-woocommerce' ); ?></span>
							</th>
							<td>
								<select id="topbar_font_weight" name="topbar_font_weight">
									<?php
									$fw_options = [
										'300' => __( 'Light (300)', 'torobpay-for-woocommerce' ),
										'400' => __( 'Regular (400)', 'torobpay-for-woocommerce' ),
										'500' => __( 'Medium (500)', 'torobpay-for-woocommerce' ),
										'600' => __( 'Semi-bold (600)', 'torobpay-for-woocommerce' ),
										'700' => __( 'Bold (700)', 'torobpay-for-woocommerce' ),
										'800' => __( 'Extra bold (800)', 'torobpay-for-woocommerce' ),
									];
									foreach ( $fw_options as $val => $fw_label ) {
										printf( '<option value="%s"%s>%s</option>', esc_attr( $val ), selected( $topbar_font_weight, $val, false ), esc_html( $fw_label ) );
									}
									?>
								</select>
							</td>
						</tr>
					</tbody>
				</table>

				<h2 class="section-title" data-section-key="topbar-animation"><span class="dashicons dashicons-controls-play"></span> <?php esc_html_e( 'Animation settings', 'torobpay-for-woocommerce' ); ?><span class="section-collapse-icon dashicons dashicons-arrow-up-alt2"></span></h2>
				<table class="form-table">
					<tbody>
						<tr>
							<th scope="row">
								<label for="topbar_animation"><?php esc_html_e( 'Animation type', 'torobpay-for-woocommerce' ); ?></label>
								<span class="torobpay-tooltip" data-tip="<?php echo esc_attr__( 'Top bar rotating text display effect', 'torobpay-for-woocommerce' ); ?>" title="<?php echo esc_attr__( 'Top bar animation type', 'torobpay-for-woocommerce' ); ?>"><?php esc_html_e( '?', 'torobpay-for-woocommerce' ); ?></span>
							</th>
							<td>
								<select id="topbar_animation" name="topbar_animation">
									<?php
									$topbar_anim_options = [
										'slide-up'   => __( 'Move slide up', 'torobpay-for-woocommerce' ),
										'slide-down' => __( 'Move slide down', 'torobpay-for-woocommerce' ),
										'fade'       => __( 'Fade in', 'torobpay-for-woocommerce' ),
										'flip'       => __( '3D rotate', 'torobpay-for-woocommerce' ),
										'zoom'       => __( 'Zoom in', 'torobpay-for-woocommerce' ),
										'blur'       => __( 'Focus (blur to sharp)', 'torobpay-for-woocommerce' ),
										'bounce'     => __( 'Fern', 'torobpay-for-woocommerce' ),
										'spread'     => __( 'Side-to-side motion', 'torobpay-for-woocommerce' ),
									];
									foreach ( $topbar_anim_options as $val => $anim_label ) {
										printf( '<option value="%s"%s>%s</option>', esc_attr( $val ), selected( $topbar_animation, $val, false ), esc_html( $anim_label ) );
									}
									?>
								</select>
							</td>
						</tr>
						<tr class="torobpay-topbar-custom-sentence-row">
							<th scope="row">
								<label for="topbar_custom_sentence"><?php esc_html_e( 'Custom sentence', 'torobpay-for-woocommerce' ); ?></label>
								<span class="torobpay-tooltip" data-tip="<?php echo esc_attr__( 'Adds a line after the built-in top bar messages, including the side-to-side animation.', 'torobpay-for-woocommerce' ); ?>" title="<?php echo esc_attr__( 'Custom top bar sentence', 'torobpay-for-woocommerce' ); ?>"><?php esc_html_e( '?', 'torobpay-for-woocommerce' ); ?></span>
							</th>
							<td>
								<input type="text" id="topbar_custom_sentence" name="topbar_custom_sentence" value="<?php echo esc_attr( $topbar_custom_sentence ); ?>" maxlength="80" class="regular-text">
								<p class="description"><?php esc_html_e( 'Optional. Shown after the built-in messages.', 'torobpay-for-woocommerce' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="topbar_speed"><?php esc_html_e( 'Rotation speed', 'torobpay-for-woocommerce' ); ?></label>
								<span class="torobpay-tooltip" data-tip="<?php echo esc_attr__( 'Full cycle duration for three rotating texts (2 to 20 seconds)', 'torobpay-for-woocommerce' ); ?>" title="<?php echo esc_attr__( 'Text rotation speed', 'torobpay-for-woocommerce' ); ?>"><?php esc_html_e( '?', 'torobpay-for-woocommerce' ); ?></span>
							</th>
							<td>
								<input type="number" id="topbar_speed" name="topbar_speed" value="<?php echo esc_attr( $topbar_speed ); ?>" min="2" max="20" step="1" class="small-text">
								<span><?php esc_html_e( 'seconds', 'torobpay-for-woocommerce' ); ?></span>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="topbar_bg_animation"><?php esc_html_e( 'Background animation', 'torobpay-for-woocommerce' ); ?></label>
								<span class="torobpay-tooltip" data-tip="<?php echo esc_attr__( 'Dynamic graphic effect in the top bar background (payment-related icons). "None" keeps a plain background.', 'torobpay-for-woocommerce' ); ?>" title="<?php echo esc_attr__( 'Top bar background animation', 'torobpay-for-woocommerce' ); ?>"><?php esc_html_e( '?', 'torobpay-for-woocommerce' ); ?></span>
							</th>
							<td>
								<select id="topbar_bg_animation" name="topbar_bg_animation">
									<?php
									$topbar_bg_anim_options = [
										'none'   => __( 'None (plain)', 'torobpay-for-woocommerce' ),
										'perlin' => __( 'Perlin noise (floating icons with mask)', 'torobpay-for-woocommerce' ),
										'rain'   => __( 'Icon rain (mouse-reactive)', 'torobpay-for-woocommerce' ),
										'float'  => __( 'Gentle float (floating icons)', 'torobpay-for-woocommerce' ),
										'aurora' => __( 'Aurora (soft light blobs)', 'torobpay-for-woocommerce' ),
									];
									foreach ( $topbar_bg_anim_options as $val => $bg_anim_label ) {
										printf( '<option value="%s"%s>%s</option>', esc_attr( $val ), selected( $topbar_bg_animation, $val, false ), esc_html( $bg_anim_label ) );
									}
									?>
								</select>
								<p class="description"><?php esc_html_e( 'Payment-related icons move in the bar background. Automatically disabled for users who enable reduced motion in their browser or OS.', 'torobpay-for-woocommerce' ); ?></p>
							</td>
						</tr>
						<tr class="torobpay-topbar-bg-control" style="<?php echo esc_attr( 'none' === $topbar_bg_animation ? 'display: none;' : '' ); ?>">
							<th scope="row">
								<label for="topbar_bg_density"><?php esc_html_e( 'Icon density', 'torobpay-for-woocommerce' ); ?></label>
								<span class="torobpay-tooltip" data-tip="<?php echo esc_attr__( 'Number of icons shown in the background. Has no effect in Aurora mode.', 'torobpay-for-woocommerce' ); ?>" title="<?php echo esc_attr__( 'Background icon density', 'torobpay-for-woocommerce' ); ?>"><?php esc_html_e( '?', 'torobpay-for-woocommerce' ); ?></span>
							</th>
							<td>
								<select id="topbar_bg_density" name="topbar_bg_density">
									<?php
									$topbar_bg_density_options = [
										'low'    => __( 'Low', 'torobpay-for-woocommerce' ),
										'medium' => __( 'Medium', 'torobpay-for-woocommerce' ),
										'high'   => __( 'High', 'torobpay-for-woocommerce' ),
										'dense'  => __( 'Very dense', 'torobpay-for-woocommerce' ),
									];
									foreach ( $topbar_bg_density_options as $val => $density_label ) {
										printf( '<option value="%s"%s>%s</option>', esc_attr( $val ), selected( $topbar_bg_density, $val, false ), esc_html( $density_label ) );
									}
									?>
								</select>
							</td>
						</tr>
						<tr class="torobpay-topbar-bg-control" style="<?php echo esc_attr( 'none' === $topbar_bg_animation ? 'display: none;' : '' ); ?>">
							<th scope="row">
								<label for="topbar_bg_speed"><?php esc_html_e( 'Motion speed', 'torobpay-for-woocommerce' ); ?></label>
								<span class="torobpay-tooltip" data-tip="<?php echo esc_attr__( 'Background icon motion speed (1 slow to 10 fast). Has no effect in Aurora mode.', 'torobpay-for-woocommerce' ); ?>" title="<?php echo esc_attr__( 'Background motion speed', 'torobpay-for-woocommerce' ); ?>"><?php esc_html_e( '?', 'torobpay-for-woocommerce' ); ?></span>
							</th>
							<td>
								<input type="number" id="topbar_bg_speed" name="topbar_bg_speed" value="<?php echo esc_attr( $topbar_bg_speed ); ?>" min="1" max="10" step="1" class="small-text">
							</td>
						</tr>
						<tr class="torobpay-topbar-bg-control torobpay-topbar-bg-canvas-control" style="<?php echo esc_attr( 'none' === $topbar_bg_animation ? 'display: none;' : '' ); ?>">
							<th scope="row">
								<label><?php esc_html_e( 'Background logo', 'torobpay-for-woocommerce' ); ?></label>
								<span class="torobpay-tooltip" data-tip="<?php echo esc_attr__( 'PNG or SVG only. Your store logo appears beside the TorobPay logo in the background. A square logo works best.', 'torobpay-for-woocommerce' ); ?>" title="<?php echo esc_attr__( 'Top bar background logo', 'torobpay-for-woocommerce' ); ?>"><?php esc_html_e( '?', 'torobpay-for-woocommerce' ); ?></span>
							</th>
							<td>
								<div class="torobpay-slider-image-card torobpay-bg-icon-card torobpay-topbar-bg-icon-card<?php echo '' === $topbar_bg_custom_icon ? ' is-empty' : ''; ?>">
									<input type="hidden" class="torobpay-topbar-bg-icon-url" name="topbar_bg_custom_icon" value="<?php echo esc_attr( $topbar_bg_custom_icon ); ?>">
									<input type="hidden" class="torobpay-topbar-bg-icon-id" name="topbar_bg_custom_icon_id" value="<?php echo esc_attr( $topbar_bg_custom_icon_id ); ?>">
									<div class="torobpay-slider-image-card-preview">
										<?php if ( '' !== $topbar_bg_custom_icon ) : ?>
											<img class="torobpay-topbar-bg-icon-preview" src="<?php echo esc_url( $topbar_bg_custom_icon ); ?>" alt="">
										<?php else : ?>
											<img class="torobpay-topbar-bg-icon-preview" alt="">
										<?php endif; ?>
										<span class="torobpay-slider-image-placeholder" aria-hidden="true">
											<span class="dashicons dashicons-format-image"></span>
											<span><?php esc_html_e( 'No logo', 'torobpay-for-woocommerce' ); ?></span>
										</span>
									</div>
									<div class="torobpay-slider-image-card-actions">
										<button type="button" class="button button-secondary torobpay-topbar-bg-icon-select">
											<span class="dashicons dashicons-upload"></span><?php esc_html_e( 'Select logo', 'torobpay-for-woocommerce' ); ?></button>
										<button type="button" class="button button-link torobpay-topbar-bg-icon-remove"><?php esc_html_e( 'Remove logo', 'torobpay-for-woocommerce' ); ?></button>
									</div>
								</div>
							</td>
						</tr>
					</tbody>
				</table>

				<div id="topbar-automatic-behavior"<?php echo 'manual' === $topbar_placement ? ' style="' . esc_attr( 'display:none;' ) . '"' : ''; ?>>

				<h2 class="section-title" data-section-key="topbar-behavior"><span class="dashicons dashicons-admin-settings"></span><?php esc_html_e( 'Behavior settings', 'torobpay-for-woocommerce' ); ?><span class="section-collapse-icon dashicons dashicons-arrow-up-alt2"></span></h2>
				<p class="torobpay-settings-note"><?php esc_html_e( 'Applies only in automatic mode.', 'torobpay-for-woocommerce' ); ?></p>
				<table class="form-table">
					<tbody>
						<tr>
							<th scope="row">
								<label for="topbar_position"><?php esc_html_e( 'Bar position', 'torobpay-for-woocommerce' ); ?></label>
								<span class="torobpay-tooltip" data-tip="<?php echo esc_attr__( 'Normal: scrolls with the page. Sticky: like the WordPress admin bar, stays at the top and reserves space above the site.', 'torobpay-for-woocommerce' ); ?>" title="<?php echo esc_attr__( 'Top bar position', 'torobpay-for-woocommerce' ); ?>"><?php esc_html_e( '?', 'torobpay-for-woocommerce' ); ?></span>
							</th>
							<td>
								<select id="topbar_position" name="topbar_position">
									<?php
									$topbar_position_options = [
										'normal' => __( 'Normal (scrolls with the page)', 'torobpay-for-woocommerce' ),
										'sticky' => __( 'Sticky — like the WordPress admin bar', 'torobpay-for-woocommerce' ),
									];
									foreach ( $topbar_position_options as $val => $pos_label ) {
										printf( '<option value="%s"%s>%s</option>', esc_attr( $val ), selected( $topbar_position, $val, false ), esc_html( $pos_label ) );
									}
									?>
								</select>
							</td>
						</tr>
						<tr id="topbar_sticky_offset_row" style="<?php echo 'normal' === $topbar_position ? 'display: none;' : ''; ?>">
							<th scope="row">
								<label for="topbar_sticky_offset"><?php esc_html_e( 'Top offset', 'torobpay-for-woocommerce' ); ?></label>
								<span class="torobpay-tooltip" data-tip="<?php echo esc_attr__( 'Extra offset from top of page, in addition to the WordPress admin bar (0 to 400 px)', 'torobpay-for-woocommerce' ); ?>" title="<?php echo esc_attr__( 'Top offset', 'torobpay-for-woocommerce' ); ?>"><?php esc_html_e( '?', 'torobpay-for-woocommerce' ); ?></span>
							</th>
							<td>
								<input type="number" id="topbar_sticky_offset" name="topbar_sticky_offset" value="<?php echo esc_attr( $topbar_sticky_offset ); ?>" min="0" max="400" step="1" class="small-text">
								<span><?php esc_html_e( 'px', 'torobpay-for-woocommerce' ); ?></span>
							</td>
						</tr>
						<tr id="topbar_sticky_header_selector_row" style="<?php echo 'normal' === $topbar_position ? 'display: none;' : ''; ?>">
							<th scope="row">
								<label for="topbar_sticky_header_selector"><?php esc_html_e( 'Theme sticky header', 'torobpay-for-woocommerce' ); ?></label>
								<span class="torobpay-tooltip" data-tip="<?php echo esc_attr__( 'Optional: if your theme sticky menu overlaps the TorobPay bar, enter its CSS selector to position the bar lower.', 'torobpay-for-woocommerce' ); ?>" title="<?php echo esc_attr__( 'Theme sticky header', 'torobpay-for-woocommerce' ); ?>"><?php esc_html_e( '?', 'torobpay-for-woocommerce' ); ?></span>
							</th>
							<td>
								<input type="text" id="topbar_sticky_header_selector" name="topbar_sticky_header_selector" value="<?php echo esc_attr( $topbar_sticky_header_selector ); ?>" class="regular-text code" dir="ltr" placeholder=".site-header, #masthead">
							</td>
						</tr>
						<tr id="topbar_z_index_row" style="<?php echo 'normal' === $topbar_position ? 'display: none;' : ''; ?>">
							<th scope="row">
								<label for="topbar_z_index"><?php esc_html_e( 'Layer (z-index)', 'torobpay-for-woocommerce' ); ?></label>
								<span class="torobpay-tooltip" data-tip="<?php echo esc_attr__( 'Sticky bar z-index. If it overlaps WordPress admin submenus, set below 99999 (default: 9990).', 'torobpay-for-woocommerce' ); ?>" title="<?php echo esc_attr__( 'z-index layer', 'torobpay-for-woocommerce' ); ?>"><?php esc_html_e( '?', 'torobpay-for-woocommerce' ); ?></span>
							</th>
							<td>
								<input type="number" id="topbar_z_index" name="topbar_z_index" value="<?php echo esc_attr( $topbar_z_index ); ?>" min="0" max="999999" step="1" class="small-text">
							</td>
						</tr>
					</tbody>
				</table>
				</div>
			</div>
			<!-- End Topbar Settings Tab -->

			<?php
			/**
			 * Self-contained visual features render their tab panel here. Each panel
			 * must be a <div id="{tab-id}" class="tab-content">…</div> matching the
			 * id registered through the torobpay_visual_settings_tabs filter.
			 */
			do_action( 'torobpay_visual_settings_tab_content' );
			?>

			<p class="submit">
				<?php submit_button( __( 'Save settings', 'torobpay-for-woocommerce' ), 'primary torobpay-button-primary', 'submit', false ); ?>
				<input type="submit" name="reset" class="button button-secondary torobpay-button-secondary" value="<?php echo esc_attr__( 'Reset to defaults', 'torobpay-for-woocommerce' ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Are you sure you want to reset all element settings to their defaults?', 'torobpay-for-woocommerce' ) ); ?>');" />
			</p>
				</div>
			</div>
		</form>
	</div>

	<?php
}

