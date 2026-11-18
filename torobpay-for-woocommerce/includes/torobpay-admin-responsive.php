<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** TorobPay admin responsive field helpers. */

function torobpay_get_breakpoints() {
	return apply_filters(
		'torobpay_breakpoints',
		array(
			'tablet' => 1024,
			'mobile' => 768,
		)
	);
}

/**
 * Return the option suffix after the plugin prefix, or empty if invalid.
 *
 * Callers still pass full names such as `torobpay_widget_border_size`. The
 * `torobpay_` literal is concatenated at each get_option/update_option site
 * so directory scanners can see the prefix.
 *
 * @param string $option_base Full option name or unprefixed suffix.
 * @return string
 */
function torobpay_option_suffix( $option_base ) {
	if ( ! is_string( $option_base ) || '' === $option_base ) {
		return '';
	}
	if ( 0 === strpos( $option_base, 'torobpay_' ) ) {
		$option_base = substr( $option_base, 9 );
	}
	$option_base = sanitize_key( $option_base );
	return is_string( $option_base ) ? $option_base : '';
}

function torobpay_get_responsive_setting( $option_base, $default = '' ) {
	$suffix = torobpay_option_suffix( $option_base );
	if ( '' === $suffix ) {
		return array(
			'base'   => $default,
			'tablet' => '',
			'mobile' => '',
		);
	}

	return array(
		'base'   => get_option( 'torobpay_' . $suffix, $default ),
		'tablet' => get_option( 'torobpay_' . $suffix . '_tablet', '' ),
		'mobile' => get_option( 'torobpay_' . $suffix . '_mobile', '' ),
	);
}

/** Build per-device CSS; $preview_device emits a flat single-device rule for admin preview. */
function torobpay_responsive_style( $selector, $props, $preview_device = null ) {
	$selector = preg_replace( '/[^a-zA-Z0-9_\-#.\s,>+~:\[\]="()]/', '', wp_strip_all_tags( (string) $selector ) );
	if ( ! is_string( $selector ) || '' === $selector ) {
		return '';
	}

	if ( in_array( $preview_device, array( 'desktop', 'tablet', 'mobile' ), true ) ) {
		$decls = '';
		foreach ( $props as $p ) {
			$unit   = isset( $p['unit'] ) ? $p['unit'] : '';
			$base   = isset( $p['base'] ) ? $p['base'] : '';
			$tablet = ( isset( $p['tablet'] ) && '' !== $p['tablet'] && null !== $p['tablet'] ) ? $p['tablet'] : $base;
			$mobile = ( isset( $p['mobile'] ) && '' !== $p['mobile'] && null !== $p['mobile'] ) ? $p['mobile'] : $tablet;
			$val    = 'mobile' === $preview_device ? $mobile : ( 'tablet' === $preview_device ? $tablet : $base );
			$decls .= torobpay_css_declaration( isset( $p['prop'] ) ? $p['prop'] : '', $val, $unit );
		}
		return '' !== $decls ? $selector . '{' . $decls . '}' : '';
	}

	$devices = array( 'base', 'tablet', 'mobile' );
	$decls   = array(
		'base'   => '',
		'tablet' => '',
		'mobile' => '',
	);

	foreach ( $props as $p ) {
		$unit = isset( $p['unit'] ) ? $p['unit'] : '';
		foreach ( $devices as $device ) {
			$val = isset( $p[ $device ] ) ? $p[ $device ] : '';
			$decls[ $device ] .= torobpay_css_declaration( isset( $p['prop'] ) ? $p['prop'] : '', $val, $unit );
		}
	}

	$bp  = torobpay_get_breakpoints();
	$css = '';
	if ( '' !== $decls['base'] ) {
		$css .= $selector . '{' . $decls['base'] . '}';
	}
	if ( '' !== $decls['tablet'] ) {
		$css .= '@media (max-width:' . (int) $bp['tablet'] . 'px){' . $selector . '{' . $decls['tablet'] . '}}';
	}
	if ( '' !== $decls['mobile'] ) {
		$css .= '@media (max-width:' . (int) $bp['mobile'] . 'px){' . $selector . '{' . $decls['mobile'] . '}}';
	}

	return $css;
}

/** Caller must verify nonce/capability before persisting $_POST values. */
function torobpay_save_responsive_number( $post_key, $option_base, $min, $max, $default ) {
	$suffix = torobpay_option_suffix( $option_base );
	if ( '' === $suffix ) {
		return;
	}

	$base = isset( $_POST[ $post_key ] ) ? max( $min, min( $max, absint( wp_unslash( $_POST[ $post_key ] ) ) ) ) : $default; // phpcs:ignore WordPress.Security.NonceVerification
	update_option( 'torobpay_' . $suffix, $base );

	$tablet_raw = isset( $_POST[ $post_key . '_tablet' ] ) ? sanitize_text_field( wp_unslash( $_POST[ $post_key . '_tablet' ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	if ( '' === $tablet_raw ) {
		update_option( 'torobpay_' . $suffix . '_tablet', '' );
	} else {
		update_option( 'torobpay_' . $suffix . '_tablet', max( $min, min( $max, absint( $tablet_raw ) ) ) );
	}

	$mobile_raw = isset( $_POST[ $post_key . '_mobile' ] ) ? sanitize_text_field( wp_unslash( $_POST[ $post_key . '_mobile' ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	if ( '' === $mobile_raw ) {
		update_option( 'torobpay_' . $suffix . '_mobile', '' );
	} else {
		update_option( 'torobpay_' . $suffix . '_mobile', max( $min, min( $max, absint( $mobile_raw ) ) ) );
	}
}

function torobpay_reset_responsive_number( $option_base, $default ) {
	$suffix = torobpay_option_suffix( $option_base );
	if ( '' === $suffix ) {
		return;
	}

	update_option( 'torobpay_' . $suffix, $default );
	delete_option( 'torobpay_' . $suffix . '_tablet' );
	delete_option( 'torobpay_' . $suffix . '_mobile' );
}

function torobpay_render_responsive_number_row( $args ) {
	$a = wp_parse_args(
		$args,
		array(
			'label'  => '',
			'tip'    => '',
			'name'   => '',
			'base'   => '',
			'tablet' => '',
			'mobile' => '',
			'min'          => 0,
			'max'          => 9999,
			'step'         => 1,
			'unit'         => __( 'px', 'torobpay-for-woocommerce' ),
			'unit_field'   => '',   // When set, render a <select> instead of a static unit label.
			'unit_options' => array(), // value => label pairs for the unit <select>.
			'unit_value'   => '',   // Currently-selected unit value.
		)
	);

	$placeholder = '' !== $a['base'] ? (string) $a['base'] : '';
	$devices     = array(
		'desktop' => array(
			'field' => $a['name'],
			'icon'  => 'dashicons-desktop',
			'title' => __( 'Desktop (default for all screens)', 'torobpay-for-woocommerce' ),
			'value' => $a['base'],
			'ph'    => '',
		),
		'tablet'  => array(
			'field' => $a['name'] . '_tablet',
			'icon'  => 'dashicons-tablet',
			'title' => __( 'Tablet (empty = inherit from desktop)', 'torobpay-for-woocommerce' ),
			'value' => $a['tablet'],
			'ph'    => $placeholder,
		),
		'mobile'  => array(
			'field' => $a['name'] . '_mobile',
			'icon'  => 'dashicons-smartphone',
			'title' => __( 'Mobile (empty = inherit from desktop)', 'torobpay-for-woocommerce' ),
			'value' => $a['mobile'],
			'ph'    => $placeholder,
		),
	);
	?>
	<tr>
		<th scope="row">
			<label for="<?php echo esc_attr( $a['name'] ); ?>"><?php echo esc_html( $a['label'] ); ?></label>
			<?php if ( '' !== $a['tip'] ) : ?>
				<span class="torobpay-tooltip" data-tip="<?php echo esc_attr( $a['tip'] ); ?>" title="<?php echo esc_attr( $a['label'] ); ?>"><?php echo esc_html__( '?', 'torobpay-for-woocommerce' ); ?></span>
			<?php endif; ?>
		</th>
		<td>
			<div class="torobpay-responsive-field" data-active-device="desktop" data-field-name="<?php echo esc_attr( $a['name'] ); ?>">
				<div class="torobpay-rf-switcher" role="group">
					<?php foreach ( $devices as $device_key => $d ) : ?>
						<button type="button"
							class="torobpay-rf-btn<?php echo 'desktop' === $device_key ? ' is-active' : ''; ?>"
							data-device="<?php echo esc_attr( $device_key ); ?>"
							title="<?php echo esc_attr( $d['title'] ); ?>">
							<span class="dashicons <?php echo esc_attr( $d['icon'] ); ?>"></span>
						</button>
					<?php endforeach; ?>
				</div>
				<?php foreach ( $devices as $device_key => $d ) : ?>
					<input type="number"
						<?php echo 'desktop' === $device_key ? 'id="' . esc_attr( $a['name'] ) . '"' : ''; ?>
						name="<?php echo esc_attr( $d['field'] ); ?>"
						value="<?php echo esc_attr( $d['value'] ); ?>"
						min="<?php echo esc_attr( $a['min'] ); ?>"
						max="<?php echo esc_attr( $a['max'] ); ?>"
						step="<?php echo esc_attr( $a['step'] ); ?>"
						class="small-text torobpay-rf-input"
						data-device="<?php echo esc_attr( $device_key ); ?>"
						<?php echo '' !== $d['ph'] ? 'placeholder="' . esc_attr( $d['ph'] ) . '"' : ''; ?>>
				<?php endforeach; ?>
				<?php if ( '' !== $a['unit_field'] && ! empty( $a['unit_options'] ) ) : ?>
					<select name="<?php echo esc_attr( $a['unit_field'] ); ?>" id="<?php echo esc_attr( $a['unit_field'] ); ?>" class="torobpay-rf-unit-select" style="width:auto;min-width:60px;">
						<?php foreach ( $a['unit_options'] as $u_val => $u_label ) : ?>
							<option value="<?php echo esc_attr( $u_val ); ?>" <?php selected( $a['unit_value'], $u_val ); ?>><?php echo esc_html( $u_label ); ?></option>
						<?php endforeach; ?>
					</select>
				<?php else : ?>
					<span class="torobpay-rf-unit"><?php echo esc_html( $a['unit'] ); ?></span>
				<?php endif; ?>
			</div>
		</td>
	</tr>
	<?php
}
