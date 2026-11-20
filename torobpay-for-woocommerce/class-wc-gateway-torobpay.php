<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function torobpay_load_gateway() {
	if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
		return;
	}

	if ( class_exists( 'WC_Gateway_TorobPay' ) ) {
		return;
	}

		add_filter( 'woocommerce_payment_gateways', 'torobpay_add_gateway' );

		// woocommerce_WC_Gateway_TorobPay_settings
		function torobpay_settings_after_save( $old_value, $new_value ) {
			$delete_token_transient = false;
			$keys                   = [ 'client_id', 'client_secret', 'client_username', 'client_password' ];

			foreach ( $keys as $key ) {
				$old_setting = is_array( $old_value ) && isset( $old_value[ $key ] ) ? $old_value[ $key ] : '';
				$new_setting = is_array( $new_value ) && isset( $new_value[ $key ] ) ? $new_value[ $key ] : '';

				if ( $old_setting !== $new_setting ) {
					$delete_token_transient = true;
					break;
				}
			}
			if ( $delete_token_transient ) {
				delete_transient( 'torobpay_bearer_token' );
				delete_transient( 'torobpay_online_features' );
				if ( function_exists( 'torobpay_forget_discount_campaigns_cache' ) ) {
					torobpay_forget_discount_campaigns_cache();
				} else {
					delete_transient( 'torobpay_discount_campaigns' );
				}
			}

			$has_all_credentials = is_array( $new_value )
				&& ! empty( $new_value['client_id'] )
				&& ! empty( $new_value['client_secret'] )
				&& ! empty( $new_value['client_username'] )
				&& ! empty( $new_value['client_password'] );

			$has_any_credential = is_array( $new_value )
				&& (
					! empty( $new_value['client_id'] )
					|| ! empty( $new_value['client_secret'] )
					|| ! empty( $new_value['client_username'] )
					|| ! empty( $new_value['client_password'] )
				);

			// Whenever settings are saved with credentials present, initialize the
			// auth state. This keeps display features recoverable after upgrades
			// even if the merchant re-saves unchanged credentials.
			if ( $has_all_credentials ) {
				// get_request_bearer_token() sets/clears torobpay_auth_ok and
				// torobpay_auth_error automatically.
				$torobPayAPI = new TorobPayAPI();
				$torobPayAPI->ensure_bearer_token();

				// Successful auth with all four credentials stops auto-provision
				// retries. Manual entry does NOT lock fields / hide fetch button.
				if ( get_option( 'torobpay_auth_ok' ) && function_exists( 'torobpay_stop_auto_provision' ) ) {
					if ( function_exists( 'torobpay_is_auto_provision_active' ) && torobpay_is_auto_provision_active() ) {
						torobpay_stop_auto_provision( 'stopped_auth', false );
					}
					delete_option( 'torobpay_provision_error' );
					delete_option( 'torobpay_provision_notice' );
				}
			} elseif ( $has_any_credential ) {
				// Partial fill only — do not warn when all four fields are empty.
				delete_option( 'torobpay_auth_ok' );
				update_option(
					'torobpay_auth_error',
					array(
						'type'             => 'missing_credentials',
						'friendly_message' => __( 'None of the TorobPay account fields may be empty.', 'torobpay-for-woocommerce' ),
						'timestamp'        => time(),
					)
				);
			} else {
				delete_option( 'torobpay_auth_ok' );

				$auth_error = get_option( 'torobpay_auth_error' );
				if ( is_array( $auth_error ) && isset( $auth_error['type'] ) && 'missing_credentials' === $auth_error['type'] ) {
					delete_option( 'torobpay_auth_error' );
				}
			}

			// Opt-in: whitelist TorobPay API host in HTTP Requests Manager after save.
			if ( function_exists( 'torobpay_maybe_allow_in_http_requests_manager' ) ) {
				torobpay_maybe_allow_in_http_requests_manager( $new_value );
			}
		}
		add_action( 'update_option_woocommerce_WC_Gateway_TorobPay_settings', 'torobpay_settings_after_save', 10, 2 );

		function torobpay_add_gateway( $methods ) {
			$methods[] = 'WC_Gateway_TorobPay';
			return $methods;
		}
		class WC_Gateway_TorobPay extends WC_Payment_Gateway {

			protected $order_id      = 0;
			protected $current_order = 0;
			protected $redirect_url;
			protected $api;

			public $author;
			public $client_id;
			public $client_secret;
			public $client_username;
			public $client_password;
			public $base_url;
			public $success_message;
			public $failed_message;

			/**
			 * Eligible description_type for this request. Unknown types are installment.
			 *
			 * @var string
			 */
			public $torobpay_description_type = 'installment';

			/**
			 * Discount sentence shown on the method label. Empty for installment.
			 *
			 * @var string
			 */
			public $torobpay_offer_text = '';

			public function __construct() {
				add_action( 'init', array( $this, 'init_gateway' ) );

				$this->api                = new TorobPayAPI();
				$this->author             = 'torobpay';
				$this->id                 = 'WC_Gateway_TorobPay'; // WooCommerce option key: woocommerce_{id}_settings.
				$this->icon               = $this->get_gateway_icon_url();
				$this->method_title       = __( 'TorobPay', 'torobpay-for-woocommerce' );
				$this->method_description = __( 'TorobPay payment gateway settings for WooCommerce', 'torobpay-for-woocommerce' );
				$this->has_fields         = false;

				$this->init_form_fields();
				$this->init_settings();

				$this->title       = __( 'Pay in installments with TorobPay', 'torobpay-for-woocommerce' ); // Default title, can be overridden by API response
				$this->description = __( 'TorobPay installment payment', 'torobpay-for-woocommerce' ); // Will be overridden by API response

				// A just-activated shop has no settings row. get_option() is false,
				// and reading an offset on false is a PHP 8 error. ?? only covers a
				// missing key once settings is already an array.
				if ( ! is_array( $this->settings ) ) {
					$this->settings = array();
				}

				$this->client_id       = $this->settings['client_id'] ?? '';
				$this->client_secret   = $this->settings['client_secret'] ?? '';
				$this->client_username = $this->settings['client_username'] ?? '';
				$this->client_password = $this->settings['client_password'] ?? '';
				$this->base_url        = $this->settings['base_url'] ?? '';
				$this->success_message = $this->settings['success_message'] ?? '';
				$this->failed_message  = $this->settings['failed_message'] ?? '';

				if ( version_compare( WOOCOMMERCE_VERSION, '2.0.0', '>=' ) ) {
					add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
				} else {
					add_action( 'woocommerce_update_options_payment_gateways', array( $this, 'process_admin_options' ) );
				}

				add_action( 'woocommerce_receipt_' . $this->id . '', array( $this, 'process_payment_request' ) );
				add_action( 'woocommerce_api_' . strtolower( get_class( $this ) ) . '', array( $this, 'process_payment_verify' ) );
				add_action( 'woocommerce_before_thankyou', array( $this, 'torobpay_before_thankyou' ), 98 );
				add_action( 'woocommerce_before_cart', array( $this, 'torobpay_before_thankyou' ), 10 );
				add_action( 'woocommerce_before_checkout_form', array( $this, 'torobpay_before_thankyou' ), 10 );
				add_action( 'before_woocommerce_pay', array( $this, 'torobpay_before_thankyou' ), 10 );
				// Fallback for Block Checkout - display notice via JavaScript
				add_action( 'wp_footer', array( $this, 'torobpay_block_checkout_notice' ), 10 );
			}

			/**
			 * Initialize gateway-specific properties after WordPress is fully loaded
			 */
			public function init_gateway() {
				$this->redirect_url = wc_get_checkout_url();
			}

			public function get_icon() {
				$icon_url  = $this->get_gateway_icon_url();
				$icon_html = '<img src="' . esc_url( $icon_url ) . '" alt="' . esc_attr( $this->get_title() ) . '" style="width:40px;" />';
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce core filter.
				return apply_filters( 'woocommerce_gateway_icon', $icon_html, $this->id );
			}

			/**
			 * Payment box copy.
			 *
			 * Discount sentences are rendered on the method label instead, so the
			 * box is left empty for those types.
			 */
			public function payment_fields() {
				$type = isset( $this->torobpay_description_type ) ? (string) $this->torobpay_description_type : 'installment';
				if ( function_exists( 'torobpay_description_type_is_offer' ) && torobpay_description_type_is_offer( $type ) ) {
					return;
				}

				parent::payment_fields();
			}

			protected function get_gateway_icon_url() {
				return apply_filters( 'torobpay_logo', plugins_url( 'assets/images/logo_favicon.svg', TOROBPAY_PLUGIN_FILE ) );
			}

			public function admin_options() {
				$action = $this->author;
				do_action( 'torobpay_gateway_payment_actions', $action );

				$widget_enabled  = get_option( 'torobpay_widget_enabled', 'yes' );
				$badge_enabled   = get_option( 'torobpay_badge_enabled', 'yes' );
				$marquee_enabled = get_option( 'torobpay_marquee_enabled', 'no' );
				$top_bar_enabled = get_option( 'torobpay_topbar_enabled', 'no' );
				$slider_enabled  = get_option( 'torobpay_slider_enabled', 'no' );

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
				if ( $top_bar_enabled !== 'yes' ) {
					$disabled_items[] = __( 'Top bar', 'torobpay-for-woocommerce' );
				}
				if ( $slider_enabled !== 'yes' ) {
					$disabled_items[] = __( 'Slider', 'torobpay-for-woocommerce' );
				}

				if ( ! empty( $disabled_items ) ) {
					$visual_url = admin_url( 'admin.php?page=torobpay-widget' );
					?>
					<div class="torobpay-promo-notice">
						<span class="dashicons dashicons-chart-line torobpay-promo-notice-icon"></span>
						<div class="torobpay-promo-notice-body">
							<strong><?php echo esc_html__( 'Enabling TorobPay elements can increase sales', 'torobpay-for-woocommerce' ); ?></strong>
							<p><?php echo esc_html__( 'Showing the widget, badge, marquee, slider, and top bar informs shoppers about fee-free installment payments and can improve conversion by raising awareness of this payment method in your store.', 'torobpay-for-woocommerce' ); ?></p>
							<div class="torobpay-promo-notice-tags">
								<?php foreach ( $disabled_items as $item ) : ?>
								<span class="torobpay-promo-notice-tag">
									<span class="dashicons dashicons-hidden"></span>
									<?php
									echo esc_html(
										sprintf(
											/* translators: %s: disabled TorobPay UI element name */
											__( '%s is disabled', 'torobpay-for-woocommerce' ),
											$item
										)
									);
									?>
								</span>
								<?php endforeach; ?>
							</div>
							<a href="<?php echo esc_url( $visual_url ); ?>" class="torobpay-promo-cta">
								<span class="dashicons dashicons-admin-appearance"></span>
								<?php echo esc_html__( 'Enable in visual settings', 'torobpay-for-woocommerce' ); ?>
							</a>
						</div>
					</div>
					<?php
				}

				parent::admin_options();
			}

			public function fields() {
				$fields = apply_filters(
					'torobpay_config',
					array(
						'base_config'               => array(
							'title'       => __( 'Basic settings', 'torobpay-for-woocommerce' ),
							'type'        => 'title',
							'description' => sprintf(
								/* translators: %s: URL to TorobPay visual settings */
								__( 'Widget and badge are enabled by default. To change them, go to <a href="%s">TorobPay visual settings</a>.', 'torobpay-for-woocommerce' ),
								esc_url( admin_url( 'admin.php?page=torobpay-widget' ) )
							),
						),
						'enabled'                   => array(
							'title'       => __( 'Enable/Disable', 'torobpay-for-woocommerce' ),
							'type'        => 'checkbox',
							'label'       => __( 'Enable TorobPay gateway', 'torobpay-for-woocommerce' ),
							'description' => __( 'Check this box to enable the TorobPay payment gateway', 'torobpay-for-woocommerce' ),
							'default'     => 'yes',
							'desc_tip'    => true,
						),
						'mobile_enabled'            => array(
							'title'       => __( 'Require mobile number', 'torobpay-for-woocommerce' ),
							'type'        => 'checkbox',
							'label'       => __( 'Supports block checkout and classic checkout.', 'torobpay-for-woocommerce' ),
							'description' => __( 'Check this box to require a mobile number', 'torobpay-for-woocommerce' ),
							'default'     => 'yes',
							'desc_tip'    => true,
						),

						'postal_enabled'            => array(
							'title'       => __( 'Require postal code', 'torobpay-for-woocommerce' ),
							'type'        => 'checkbox',
							'label'       => __( 'Supports block checkout and classic checkout.', 'torobpay-for-woocommerce' ),
							'description' => __( 'Check this box to require a postal code', 'torobpay-for-woocommerce' ),
							'default'     => 'no',
							'desc_tip'    => true,
						),
						'default_gateway'           => array(
							'title'       => __( 'Default gateway', 'torobpay-for-woocommerce' ),
							'type'        => 'checkbox',
							'label'       => sprintf(
								/* translators: %s: URL to WooCommerce Payments settings */
								__( 'Note: In WooCommerce 9.6 and later, you can set the default gateway by dragging TorobPay to the top of the list under <a href="%s">WooCommerce > Settings > Payments</a>.', 'torobpay-for-woocommerce' ),
								esc_url( admin_url( 'admin.php?page=wc-settings&tab=checkout' ) )
							),
							'description' => __( 'Check this box if you want TorobPay to be the default payment gateway', 'torobpay-for-woocommerce' ),
							'default'     => 'yes',
							'desc_tip'    => true,
						),
						// Temporarily hidden commission setting
						/*'has_comission' => array(
							'title'   =>  __( 'Enable/disable commission type for categories', 'torobpay-for-woocommerce' ),
							'type'    => 'checkbox',
							'description' =>  __( 'Check this box to enable commission types', 'torobpay-for-woocommerce' ),
							'default' => 'no',
							'desc_tip'    => true,
						),*/
						'direct_payment'            => array(
							'title'       => __( 'Redirect directly to gateway (deprecated)', 'torobpay-for-woocommerce' ),
							'type'        => 'checkbox',
							'label'       => __( 'Deprecated — checkout always goes directly to the gateway', 'torobpay-for-woocommerce' ),
							'description' => __( 'This option no longer affects placing an order at checkout. Checkout always redirects to TorobPay. It only still applies on the order-pay receipt page and will be removed in a later version.', 'torobpay-for-woocommerce' ),
							'default'     => 'yes',
							'desc_tip'    => true,
						),
						'disable_payment_retry'     => array(
							'title'       => __( 'Disable order payment retry', 'torobpay-for-woocommerce' ),
							'type'        => 'checkbox',
							'label'       => __( 'Disable retrying payment after a failed attempt', 'torobpay-for-woocommerce' ),
							'description' => __( 'When enabled, customers cannot retry failed orders and must place a new order', 'torobpay-for-woocommerce' ),
							'default'     => 'no',
							'desc_tip'    => true,
						),
						'account_config'            => array(
							'title'       => __( 'TorobPay account settings', 'torobpay-for-woocommerce' ),
							'type'        => 'title',
							'description' => '',
						),
						'client_id'                 => array(
							'title'             => __( 'TorobPay merchant ID', 'torobpay-for-woocommerce' ),
							'type'              => 'text',
							'default'           => '',
							'required'          => true,
							'desc_tip'          => true,
							'custom_attributes' => $this->get_credential_field_attributes(),
							'class'             => $this->get_credential_field_class(),
						),
						'client_secret'             => array(
							'title'             => __( 'TorobPay key', 'torobpay-for-woocommerce' ),
							'type'              => 'password',
							'default'           => '',
							'required'          => true,
							'desc_tip'          => true,
							'custom_attributes' => $this->get_credential_field_attributes(),
							'class'             => $this->get_credential_field_class(),
						),
						'client_username'           => array(
							'title'             => __( 'TorobPay username', 'torobpay-for-woocommerce' ),
							'type'              => 'text',
							'default'           => '',
							'required'          => true,
							'desc_tip'          => true,
							'custom_attributes' => $this->get_credential_field_attributes(),
							'class'             => $this->get_credential_field_class(),
						),
						'client_password'           => array(
							'title'             => __( 'TorobPay password', 'torobpay-for-woocommerce' ),
							'type'              => 'password',
							'default'           => '',
							'required'          => true,
							'desc_tip'          => true,
							'custom_attributes' => $this->get_credential_field_attributes(),
							'class'             => $this->get_credential_field_class(),
						),
						'fetch_credentials'         => array(
							'title'       => __( 'Fetch account details', 'torobpay-for-woocommerce' ),
							'type'        => 'torobpay_fetch_credentials',
							'description' => '',
						),
						'advanced_config'           => array(
							'title'       => __( 'Advanced settings', 'torobpay-for-woocommerce' ),
							'type'        => 'title',
							'description' => '',
						),
						'base_url'                  => array(
							'title'             => __( 'Gateway URL', 'torobpay-for-woocommerce' ),
							'type'              => 'torobpay_base_url',
							'description'       => __( 'TorobPay API base URL (HTTPS only)', 'torobpay-for-woocommerce' ),
							'default'           => 'https://cpg.torobpay.com/',
							'desc_tip'          => true,
							'required'          => true,
							'class'             => 'torobpay-readonly-input',
							'custom_attributes' => array(
								'readonly'      => 'readonly',
								'aria-disabled' => 'true',
							),
						),
						'dns_smart_resolve_enabled' => array(
							'title'       => __( 'Enable Smart DNS Resolve', 'torobpay-for-woocommerce' ),
							'type'        => 'checkbox',
							'label'       => __( 'Enable only on servers that fail to resolve the TorobPay API domain.', 'torobpay-for-woocommerce' ),
							'description' => __( 'When enabled, the plugin uses a fixed or cached IP to work around server DNS issues.', 'torobpay-for-woocommerce' ),
							'default'     => 'no',
							'desc_tip'    => true,
						),
						'dns_ip_override'           => array(
							'title'       => __( 'Fixed API IP (optional)', 'torobpay-for-woocommerce' ),
							'type'        => 'text',
							'description' => __( 'If you see DNS timeout (cURL error 28), enter <code>81.12.31.29</code> or get the correct IP from TorobPay support.', 'torobpay-for-woocommerce' ),
							'default'     => '',
							'placeholder' => __( 'Example: 81.12.31.29', 'torobpay-for-woocommerce' ),
							'desc_tip'    => false,
						),
						'hrm_whitelist_enabled'     => array(
							'title'       => __( 'HTTP Requests Manager', 'torobpay-for-woocommerce' ),
							'type'        => 'checkbox',
							'label'       => __( 'Allow TorobPay API host in the HTTP Requests Manager plugin', 'torobpay-for-woocommerce' ),
							'description' => __( 'Optional. Only takes effect if the HTTP Requests Manager plugin is installed. When enabled and you save settings, TorobPay adds its API host to that plugin’s allow list so outbound TorobPay calls are not blocked.', 'torobpay-for-woocommerce' ),
							'default'     => 'no',
							'desc_tip'    => true,
						),
						'http_unblock_enabled'      => array(
							'title'       => __( 'Blocked HTTP requests (Beta)', 'torobpay-for-woocommerce' ),
							'type'        => 'checkbox',
							'label'       => __( 'Allow TorobPay API calls when WordPress or another plugin blocks them', 'torobpay-for-woocommerce' ),
							'description' => __( 'Lets only the Gateway URL host through.', 'torobpay-for-woocommerce' ),
							'default'     => 'no',
							'desc_tip'    => true,
						),
						'utm_config'                => array(
							'title'       => __( 'UTM and marketing settings', 'torobpay-for-woocommerce' ),
							'type'        => 'title',
							'description' => __( 'Optional checkout behavior for Torob visitors and TorobPay visitors.', 'torobpay-for-woocommerce' ),
						),
						'utm_torob_enabled'         => array(
							'title'       => __( 'Auto-select for Torob visitors', 'torobpay-for-woocommerce' ),
							'type'        => 'checkbox',
							'label'       => __( 'Auto-select TorobPay for Torob visitors', 'torobpay-for-woocommerce' ),
							'description' => __( 'Applies when utm_source is torob. TorobPay is auto-selected and shown first for 24 hours.', 'torobpay-for-woocommerce' ),
							'default'     => 'no',
							'desc_tip'    => true,
						),
						'utm_exclude_others'        => array(
							'title'       => __( 'Only installment method for Torob visitors', 'torobpay-for-woocommerce' ),
							'type'        => 'checkbox',
							'label'       => __( 'Keep TorobPay as the only installment method for Torob visitors', 'torobpay-for-woocommerce' ),
							'description' => __( 'Applies when utm_source is torob. TorobPay stays the only installment method for 2 hours. Other payment methods stay available.', 'torobpay-for-woocommerce' ),
							'default'     => 'no',
							'desc_tip'    => true,
						),
						'utm_medium_enabled'        => array(
							'title'       => __( 'Auto-select for TorobPay visitors', 'torobpay-for-woocommerce' ),
							'type'        => 'checkbox',
							'label'       => __( 'Auto-select TorobPay for TorobPay visitors', 'torobpay-for-woocommerce' ),
							'description' => __( 'Applies when utm_medium contains "TorobPay". TorobPay is auto-selected and shown first for 24 hours.', 'torobpay-for-woocommerce' ),
							'default'     => 'yes',
							'desc_tip'    => true,
						),
						'utm_medium_exclude_others' => array(
							'title'       => __( 'Only installment method for TorobPay visitors', 'torobpay-for-woocommerce' ),
							'type'        => 'checkbox',
							'label'       => __( 'Keep TorobPay as the only installment method for TorobPay visitors', 'torobpay-for-woocommerce' ),
							'description' => __( 'Applies when utm_medium contains "TorobPay". TorobPay stays the only installment method for 2 hours. Other payment methods stay available.', 'torobpay-for-woocommerce' ),
							'default'     => 'no',
							'desc_tip'    => true,
						),
						'payment_config'            => array(
							'title'       => __( 'Payment operation settings', 'torobpay-for-woocommerce' ),
							'type'        => 'title',
							'description' => '',
						),
						'success_message'           => array(
							'title'       => __( 'Successful payment message', 'torobpay-for-woocommerce' ),
							'type'        => 'textarea',
							'description' => __( 'Enter the message shown after a successful payment. You can use the {referenceID} shortcode for the transaction reference code.', 'torobpay-for-woocommerce' ),
							'default'     => __( 'Thank you. Your order was paid successfully.', 'torobpay-for-woocommerce' ),
						),
						'failed_message'            => array(
							'title'       => __( 'Failed payment message', 'torobpay-for-woocommerce' ),
							'type'        => 'textarea',
							'description' => __( 'Enter the message shown after a failed payment. You can use the {fault} shortcode for the error reason returned by TorobPay.', 'torobpay-for-woocommerce' ),
							'default'     => __( 'Your payment failed. Please try again or contact the site administrator if the problem continues.', 'torobpay-for-woocommerce' ),
						),
						'shortcodes'                => array(
							'transactionId' => __( 'Transaction number', 'torobpay-for-woocommerce' ),
							'paymentToken'  => __( 'Payment token', 'torobpay-for-woocommerce' ),
							'referenceID'   => __( 'Transaction reference code', 'torobpay-for-woocommerce' ), // Maps to transactionId
						),
					)
				);

				return $fields;
			}

			public function init_form_fields() {
				$fields = $this->fields();
				array_pop( $fields );
				$this->form_fields = $fields;
			}

			/**
			 * Read-only attributes for provisioned credential inputs.
			 *
			 * @return array
			 */
			protected function get_credential_field_attributes() {
				if ( function_exists( 'torobpay_are_credentials_locked' ) && torobpay_are_credentials_locked() ) {
					return array(
						'readonly' => 'readonly',
					);
				}

				return array();
			}

			/**
			 * Extra CSS class when credential fields are locked.
			 *
			 * @return string
			 */
			protected function get_credential_field_class() {
				if ( function_exists( 'torobpay_are_credentials_locked' ) && torobpay_are_credentials_locked() ) {
					return 'torobpay-readonly-input';
				}

				return '';
			}

			/**
			 * Gateway URL row: readonly until the merchant checks the unlock box.
			 *
			 * The checkbox is not a saved setting. It only removes readonly for this page view.
			 *
			 * @param string $key  Field key.
			 * @param array  $data Field config.
			 * @return string
			 */
			public function generate_torobpay_base_url_html( $key, $data ) {
				$field_key = $this->get_field_key( $key );
				$defaults  = array(
					'title'             => '',
					'disabled'          => false,
					'class'             => '',
					'css'               => '',
					'placeholder'       => '',
					'desc_tip'          => false,
					'description'       => '',
					'custom_attributes' => array(),
				);
				$data      = wp_parse_args( $data, $defaults );

				$data['custom_attributes']['readonly']      = 'readonly';
				$data['custom_attributes']['aria-disabled'] = 'true';
				if ( false === strpos( $data['class'], 'torobpay-readonly-input' ) ) {
					$data['class'] = trim( $data['class'] . ' torobpay-readonly-input' );
				}

				ob_start();
				?>
				<tr valign="top">
					<th scope="row" class="titledesc">
						<label for="<?php echo esc_attr( $field_key ); ?>"><?php echo wp_kses_post( $data['title'] ); ?> <?php echo $this->get_tooltip_html( $data ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WooCommerce tooltip HTML. ?></label>
					</th>
					<td class="forminp">
						<fieldset>
							<legend class="screen-reader-text"><span><?php echo wp_kses_post( $data['title'] ); ?></span></legend>
							<div class="torobpay-base-url-field">
								<input class="input-text regular-text <?php echo esc_attr( $data['class'] ); ?>" type="text" name="<?php echo esc_attr( $field_key ); ?>" id="<?php echo esc_attr( $field_key ); ?>" style="<?php echo esc_attr( $data['css'] ); ?>" value="<?php echo esc_attr( $this->get_option( $key ) ); ?>" placeholder="<?php echo esc_attr( $data['placeholder'] ); ?>" <?php disabled( $data['disabled'], true ); ?> <?php echo $this->get_custom_attribute_html( $data ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WooCommerce attribute HTML. ?> />
								<label class="torobpay-base-url-unlock" for="torobpay_base_url_unlock">
									<input type="checkbox" id="torobpay_base_url_unlock" />
									<?php esc_html_e( 'Use a custom gateway URL', 'torobpay-for-woocommerce' ); ?>
								</label>
							</div>
							<?php echo $this->get_description_html( $data ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WooCommerce description HTML. ?>
						</fieldset>
					</td>
				</tr>
				<?php
				return ob_get_clean();
			}

			/**
			 * Custom settings row: AJAX button to fetch credentials from TorobPay.
			 *
			 * @param string $key  Field key.
			 * @param array  $data Field config.
			 * @return string
			 */
			public function generate_torobpay_fetch_credentials_html( $key, $data ) {
				if ( ! function_exists( 'torobpay_should_show_manual_fetch' ) || ! torobpay_should_show_manual_fetch() ) {
					return '';
				}

				if ( function_exists( 'torobpay_enqueue_provision_fetch_assets' ) ) {
					torobpay_enqueue_provision_fetch_assets();
				}

				$defaults = array(
					'title'       => '',
					'description' => '',
				);
				$data     = wp_parse_args( $data, $defaults );
				$field_id = $this->get_field_key( $key );

				$status = function_exists( 'torobpay_get_provision_status' ) ? torobpay_get_provision_status() : '';
				$help   = __( 'You can fetch account details directly from TorobPay; on success, fields are filled automatically.', 'torobpay-for-woocommerce' );

				if ( in_array( $status, array( 'stopped_expired', 'stopped_1036' ), true ) ) {
					$help = __( 'Automatic fetch has stopped. Use this button for a manual fetch, and check your account status in the TorobPay panel if needed.', 'torobpay-for-woocommerce' );
				} elseif ( 'active' === $status ) {
					$help = __( 'The plugin retries automatically every 4 hours for up to 30 days; you can also fetch manually now.', 'torobpay-for-woocommerce' );
				}

				ob_start();
				?>
				<tr valign="top">
					<th scope="row" class="titledesc">
						<label for="<?php echo esc_attr( $field_id ); ?>"><?php echo esc_html( $data['title'] ); ?></label>
					</th>
					<td class="forminp">
						<fieldset>
							<?php
							echo function_exists( 'torobpay_get_manual_fetch_button_html' )
								? wp_kses(
									torobpay_get_manual_fetch_button_html( 'button-primary torobpay-button-primary' ),
									torobpay_kses_allowed_html()
								)
								: '';
							?>
							<p class="description" id="<?php echo esc_attr( $field_id ); ?>"><?php echo esc_html( $help ); ?></p>
						</fieldset>
					</td>
				</tr>
				<?php
				return ob_get_clean();
			}

			public function process_admin_options() {
				$locked_credentials = array();

				if ( function_exists( 'torobpay_are_credentials_locked' ) && torobpay_are_credentials_locked() ) {
					foreach ( array( 'client_id', 'client_secret', 'client_username', 'client_password' ) as $key ) {
						$locked_credentials[ $key ] = $this->get_option( $key, '' );
					}
				}

				$saved = parent::process_admin_options();

				if ( ! empty( $locked_credentials ) ) {
					foreach ( $locked_credentials as $key => $value ) {
						$this->settings[ $key ] = $value;
					}
					// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce Settings API filter.
					update_option( $this->get_option_key(), apply_filters( 'woocommerce_settings_api_sanitized_fields_' . $this->id, $this->settings ), 'yes' );
				}

				$this->display_errors();
				return $saved;
			}

			public function validate_base_url_field( $key, $value ) {
				$value = trim( (string) $value );

				if ( $value === '' ) {
					$this->add_error( __( 'Gateway URL cannot be empty. The default URL will be used.', 'torobpay-for-woocommerce' ) );
					return $this->get_option( $key, 'https://cpg.torobpay.com/' );
				}

				$sanitized_url = esc_url_raw( $value, array( 'https' ) );

				if ( empty( $sanitized_url ) ) {
					$this->add_error( __( 'Invalid gateway URL. Only HTTPS URLs are allowed. The default URL will be used.', 'torobpay-for-woocommerce' ) );
					return $this->get_option( $key, 'https://cpg.torobpay.com/' );
				}

				$parsed = wp_parse_url( $sanitized_url );
				if ( empty( $parsed['scheme'] ) || strtolower( $parsed['scheme'] ) !== 'https' ) {
					$this->add_error( __( 'Gateway URL must use HTTPS. The default URL will be used.', 'torobpay-for-woocommerce' ) );
					return $this->get_option( $key, 'https://cpg.torobpay.com/' );
				}

				return untrailingslashit( $sanitized_url ) . '/';
			}

			protected function redirect( $url ) {
				$host = wp_parse_url( $url, PHP_URL_HOST );
				if ( $host ) {
					add_filter(
						'allowed_redirect_hosts',
						static function ( $hosts ) use ( $host ) {
							$hosts[] = $host;
							return array_unique( $hosts );
						}
					);
				}
				wp_safe_redirect( $url );
				exit;
			}

			public function process_payment( $order ) {
				$order = $this->get_order( $order );
				if ( ! $order ) {
					return $this->process_payment_failure( __( 'Error: order not found.', 'torobpay-for-woocommerce' ) );
				}

				$order = torobpay_get_fresh_order( $order->get_id() );
				if ( ! $order instanceof \WC_Order ) {
					return $this->process_payment_failure( __( 'Error: order not found.', 'torobpay-for-woocommerce' ) );
				}

				// This prevents old errors from interfering with new payment attempts
				if ( function_exists( 'wc_clear_notices' ) ) {
					wc_clear_notices();
				}

				// CRITICAL: Store order key in session for ownership validation
				// This helps prevent session/cache collision attacks
				if ( function_exists( 'WC' ) && WC()->session ) {
					WC()->session->set( 'order_awaiting_payment_key', $order->get_order_key() );
				}

				$order_data = [
					'items'    => $order->get_items(),
					'total'    => $order->get_total(),
					'discount' => $order->get_discount_total(),
				];
				$items_hash = md5( json_encode( $order_data ) );
				$order->update_meta_data( '_torobpay_items_hash', $items_hash );

				$order->save();

				$payment_page_url = $this->create_gateway_payment_url( $order );
				if ( is_wp_error( $payment_page_url ) ) {
					return $this->process_payment_failure( $payment_page_url->get_error_message() );
				}

				return array(
					'result'   => 'success',
					'redirect' => $payment_page_url,
				);
			}

			/**
			 * Classic checkout reads session notices. Block Checkout / Store API
			 * throws on `message` before clearing notices, so a queued notice
			 * would leak and 409 the next checkout request.
			 *
			 * @param string $message User-facing error.
			 * @return array
			 */
			protected function process_payment_failure( $message ) {
				$message = wp_strip_all_tags( (string) $message );

				$is_store_api = function_exists( 'WC' )
					&& is_object( WC() )
					&& method_exists( WC(), 'is_store_api_request' )
					&& WC()->is_store_api_request();

				if ( $is_store_api ) {
					return array(
						'result'  => 'failure',
						'message' => $message,
					);
				}

				if ( function_exists( 'wc_add_notice' ) ) {
					wc_add_notice( $message, 'error' );
				}

				return array(
					'result'   => 'failure',
					'messages' => $message,
				);
			}

			public function torobpay_before_thankyou( $order_id = 0 ) {
				// thankyou passes a numeric order id; before_checkout_form passes WC_Checkout.
				// Only treat numeric args as order ids — otherwise fall back to URL params.
				if ( is_numeric( $order_id ) ) {
					$order_id = absint( $order_id );
				} else {
					$order_id = 0;
				}

				if ( $order_id <= 0 ) {
					// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only thank-you / checkout URL order id.
					if ( isset( $_GET['wc_order'] ) && ! empty( $_GET['wc_order'] ) ) {
						$order_id = absint( $_GET['wc_order'] );
					} elseif ( ( $qv = absint( get_query_var( 'order-received' ) ) ) > 0 ) {
						$order_id = $qv;
					}
					// phpcs:enable WordPress.Security.NonceVerification.Recommended
				}

				if ( $order_id <= 0 ) {
					return;
				}

				// Prevent multiple executions for the same order in the same page load
				static $displayed_orders = array();
				if ( in_array( $order_id, $displayed_orders, true ) ) {
					return;
				}

				/** @var \WC_Order $order */
				$order = wc_get_order( $order_id );
				if ( ! $order ) {
					return;
				}

				$order_payment_method = $order->get_payment_method();
				if ( ! torobpay_is_torobpay_payment_method( $order_payment_method ) ) {
					return;
				}

				if ( ! torobpay_can_view_order_payment_notice( $order ) ) {
					return;
				}

				// SECURITY: Only show success for actually paid orders
				$order_status = $order->get_status();
				$is_paid      = $order->is_paid() || in_array( $order_status, array( 'processing', 'completed' ), true );

				$notice = $order->get_meta( 'wc_torobpay_order_notice' );

				if ( $is_paid ) {
					// Order is actually paid - show success
					$display_status = 'success';
					if ( function_exists( 'torobpay_get_paid_thankyou_notice' ) ) {
						$notice = torobpay_get_paid_thankyou_notice( $order, $notice );
					} elseif ( empty( $notice ) ) {
						$notice = __( 'Payment completed successfully.', 'torobpay-for-woocommerce' );
					}
				} else {
					// Order is not paid - show appropriate error
					$display_status = 'error';
					if ( empty( $notice ) ) {
						$payment_token = torobpay_get_order_payment_token( $order );
						if ( $payment_token ) {
							$notice = __( 'Payment failed. If the amount was deducted from your bank account, contact support.', 'torobpay-for-woocommerce' );
						} else {
							$notice = __( 'The order has not been paid yet.', 'torobpay-for-woocommerce' );
						}
					}
				}

				// Print the notice directly (works for Classic Checkout)
				// Block Checkout uses torobpay_block_checkout_notice() JS fallback instead
				wc_print_notice( $notice, $display_status );

				$displayed_orders[] = $order_id;
			}

			/**
			 * Check if the checkout page is using Block Checkout (Gutenberg block) or Classic (shortcode)
			 *
			 * @return bool True if using Block Checkout, false if using Classic shortcode
			 */
			private function is_block_checkout() {
				$checkout_page_id = wc_get_page_id( 'checkout' );
				if ( $checkout_page_id && has_block( 'woocommerce/checkout', $checkout_page_id ) ) {
					return true;
				}

				if ( class_exists( 'WC_Blocks_Utils' ) && method_exists( 'WC_Blocks_Utils', 'has_block_in_page' ) ) {
					if ( WC_Blocks_Utils::has_block_in_page( $checkout_page_id, 'woocommerce/checkout' ) ) {
						return true;
					}
				}

				return false;
			}

			/**
			 * Display payment result notice for Block Checkout via JavaScript
			 * This is a fallback since Block Checkout doesn't fire woocommerce_before_checkout_form
			 * For Classic Checkout, torobpay_before_thankyou() handles the notice via PHP hooks
			 */
			public function torobpay_block_checkout_notice() {
				// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only checkout notice from return URL.
				if ( ! isset( $_GET['wc_order'] ) || empty( $_GET['wc_order'] ) ) {
					return;
				}

				if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
					return;
				}

				// Exclude the thank you page (order-received) - it has its own notice handling
				if ( is_order_received_page() ) {
					return;
				}

				// PHP-based detection: Only run for Block Checkout
				// Classic Checkout is handled by torobpay_before_thankyou() via woocommerce_before_checkout_form hook
				if ( ! $this->is_block_checkout() ) {
					return;
				}

				$order_id = absint( $_GET['wc_order'] );
				// phpcs:enable WordPress.Security.NonceVerification.Recommended
				$order    = wc_get_order( $order_id );

				if ( ! $order ) {
					return;
				}

				$order_payment_method = $order->get_payment_method();
				if ( ! torobpay_is_torobpay_payment_method( $order_payment_method ) ) {
					return;
				}

				if ( ! torobpay_can_view_order_payment_notice( $order ) ) {
					return;
				}

				$notice = $order->get_meta( 'wc_torobpay_order_notice' );
				if ( empty( $notice ) ) {
					return;
				}

				$is_paid     = $order->is_paid() || in_array( $order->get_status(), array( 'processing', 'completed' ), true );
				$notice_type = $is_paid ? 'success' : 'error';

				$version = defined( 'TOROBPAY_VERSION' ) ? TOROBPAY_VERSION : '1.0.0';
				$js      = sprintf(
					'(function() {
						var notice = %1$s;
						var noticeType = %2$s;

						// Wait for DOM to be ready
						document.addEventListener(\'DOMContentLoaded\', function() {
							// Try to find WooCommerce Block notice container
							var container = document.querySelector(\'.wc-block-components-notices, .woocommerce-notices-wrapper\');

							if (!container) {
								container = document.createElement(\'div\');
								container.className = \'woocommerce-notices-wrapper\';
								var checkoutForm = document.querySelector(\'.wc-block-checkout, .woocommerce-checkout\');
								if (checkoutForm) {
									checkoutForm.parentNode.insertBefore(container, checkoutForm);
								} else {
									// Fallback: insert at beginning of main content
									var main = document.querySelector(\'main, .site-content, #content, .content-area\');
									if (main) {
										main.insertBefore(container, main.firstChild);
									}
								}
							}

							// Create notice element with Block Checkout styling
							var noticeEl = document.createElement(\'div\');
							noticeEl.className = \'woocommerce-\' + (noticeType === \'error\' ? \'error\' : \'message\') + \' wc-block-components-notice-banner is-\' + noticeType;
							noticeEl.setAttribute(\'role\', \'alert\');
							noticeEl.innerHTML = notice;

							// Insert notice
							container.appendChild(noticeEl);

							// Scroll to notice
							noticeEl.scrollIntoView({
								behavior: \'smooth\',
								block: \'center\'
							});
						});
					})();',
					wp_json_encode( wp_kses_post( $notice ) ),
					wp_json_encode( $notice_type )
				);

				wp_register_script( 'torobpay-block-checkout-notice', '', array(), $version, true );
				wp_enqueue_script( 'torobpay-block-checkout-notice' );
				wp_add_inline_script( 'torobpay-block-checkout-notice', $js );
				wp_print_scripts( 'torobpay-block-checkout-notice' );
			}

			/**
			 * Request a TorobPay hosted payment URL for an order.
			 *
			 * @param WC_Order $order Order being paid.
			 * @return string|\WP_Error Payment page URL on success.
			 */
			public function request_gateway_payment_url( $order ) {
				return $this->create_gateway_payment_url( $order );
			}

			/**
			 * Reuse a stored paid token instead of minting another one.
			 *
			 * @param WC_Order $order Order being paid.
			 * @return string|\WP_Error|null Thank-you URL, an error, or null to mint.
			 */
			protected function reuse_paid_history_or_mint( $order ) {
				if ( ! function_exists( 'torobpay_mint_action_for_history' ) ) {
					return null;
				}

				$history = torobpay_get_token_history( $order );
				$action  = torobpay_mint_action_for_history( $history );

				if ( 'needs_status' === $action ) {
					$action = $this->record_latest_history_status( $order );
				}

				if ( 'already_settled' === $action ) {
					$paid = torobpay_find_best_paid_history_entry( torobpay_get_token_history( $order ) );
					if ( is_array( $paid ) ) {
						torobpay_promote_paid_history_entry( $order, $paid );
					}
					return $this->redirect_already_paid_order(
						$order,
						__( 'TorobPay: This order was already paid.', 'torobpay-for-woocommerce' )
					);
				}

				if ( 'needs_settle' === $action ) {
					return $this->settle_verified_history_token( $order );
				}

				return null;
			}

			/**
			 * One short status read for a history entry that has no stored status.
			 *
			 * @param WC_Order $order Order being paid.
			 * @return string Mint action after the read: already_settled, needs_settle, or mint.
			 */
			protected function record_latest_history_status( $order ) {
				$latest = torobpay_latest_token_history_entry( $order );
				$token  = ( is_array( $latest ) && ! empty( $latest['token'] ) ) ? (string) $latest['token'] : '';
				if ( '' === $token ) {
					return 'mint';
				}

				$response = $this->api->status( $token, 3 );
				$status   = '';
				if ( is_array( $response ) && ! empty( $response['successful'] ) && isset( $response['response']['status'] ) ) {
					$status = torobpay_normalize_history_status( $response['response']['status'] );
				}

				if ( '' === $status ) {
					return 'mint';
				}

				torobpay_set_token_history_status( $order, $token, $status );
				if ( in_array( $status, array( 'VERIFY', 'SETTLE' ), true ) ) {
					$entry = torobpay_latest_token_history_entry( $order );
					if ( is_array( $entry ) ) {
						torobpay_promote_paid_history_entry( $order, $entry );
					}
					return ( 'SETTLE' === $status ) ? 'already_settled' : 'needs_settle';
				}

				return 'mint';
			}

			/**
			 * Settle a token that history already marks VERIFY, then treat a capture as SETTLE.
			 *
			 * @param WC_Order $order Order being paid.
			 * @return string|\WP_Error Thank-you URL, or an error when settle cannot be confirmed.
			 */
			protected function settle_verified_history_token( $order ) {
				$paid  = torobpay_find_best_paid_history_entry( torobpay_get_token_history( $order ) );
				$token = ( is_array( $paid ) && ! empty( $paid['token'] ) ) ? (string) $paid['token'] : '';
				if ( '' === $token ) {
					return new WP_Error( 'torobpay_settle_missing_token', __( 'An error occurred while connecting to the gateway. Please try again.', 'torobpay-for-woocommerce' ) );
				}

				torobpay_promote_paid_history_entry( $order, $paid );

				$settle_response = $this->api->settle( $token );
				$settled         = is_array( $settle_response ) && ! empty( $settle_response['successful'] );
				if ( ! $settled && '1053' === torobpay_extract_api_error_code( $settle_response ) ) {
					$status_response = $this->api->status( $token, 3 );
					$remote_status   = ( is_array( $status_response ) && ! empty( $status_response['successful'] ) && isset( $status_response['response']['status'] ) )
						? torobpay_normalize_history_status( $status_response['response']['status'] )
						: '';
					if ( 'SETTLE' === $remote_status ) {
						$settled = true;
					}
				}

				if ( ! $settled ) {
					return new WP_Error( 'torobpay_settle_unconfirmed', __( 'An error occurred while connecting to the gateway. Please try again.', 'torobpay-for-woocommerce' ) );
				}

				torobpay_set_token_history_status( $order, $token, 'SETTLE' );
				$entry = torobpay_latest_token_history_entry( $order );
				if ( ! is_array( $entry ) || (string) ( $entry['token'] ?? '' ) !== $token ) {
					$entry = array(
						'token'          => $token,
						'transaction_id' => isset( $paid['transaction_id'] ) ? (string) $paid['transaction_id'] : '',
						'status'         => 'SETTLE',
					);
				} else {
					$entry['status'] = 'SETTLE';
				}
				torobpay_promote_paid_history_entry( $order, $entry );

				return $this->redirect_already_paid_order(
					$order,
					__( 'TorobPay: Payment was completed.', 'torobpay-for-woocommerce' )
				);
			}

			/**
			 * Thank-you redirect. Finalize only while the order is still pending or failed.
			 *
			 * @param WC_Order $order   Order being paid.
			 * @param string   $notice Customer notice.
			 * @return string
			 */
			protected function redirect_already_paid_order( $order, $notice ) {
				if ( torobpay_order_can_heal_paid_token( $order ) && function_exists( 'torobpay_finalize_paid_order' ) ) {
					$transaction_id = (string) $order->get_meta( '_torobpay_transaction_id' );
					torobpay_finalize_paid_order( $order, $transaction_id );
					$fresh = function_exists( 'torobpay_get_fresh_order' ) ? torobpay_get_fresh_order( $order->get_id() ) : null;
					if ( $fresh instanceof WC_Order ) {
						$order = $fresh;
					}
					$this->empty_cart();
				}

				$order->update_meta_data( 'wc_torobpay_order_notice', $notice );
				$order->add_order_note( '<span class="torobpay-success">' . esc_html( $notice ) . '</span>', 0 );
				$order->save();

				return $this->get_return_url( $order );
			}

			/**
			 * Request a TorobPay hosted payment URL for an order.
			 *
			 * @param WC_Order $order            Order being paid.
			 * @return string|\WP_Error Payment page URL on success.
			 */
			protected function create_gateway_payment_url( $order ) {
				$healed = $this->reuse_paid_history_or_mint( $order );
				if ( null !== $healed ) {
					return $healed;
				}

				$order_id      = $order->get_id();
				$transactionId = time() . '-' . $order_id;

				$order->update_meta_data( '_torobpay_transaction_id', $transactionId );
				$order->save();

				$response = torobpay_retry_api_call(
					function () use ( $order, $transactionId ) {
						return $this->api->get_payment_token( $order, $transactionId );
					},
					2,
					'GetPaymentToken',
					$order_id
				);

				$response_success       = false;
				$response_error_message = '';

				if ( is_wp_error( $response ) ) {
					$response_error_message = $response->get_error_message();
				} elseif ( isset( $response['successful'] ) ) {
					$response_success = $response['successful'];
					if ( ! $response_success ) {
						// Catalogued token codes (1011 "can't create order", 1099, …)
						// get merchant-readable copy in the order note; the raw API
						// text is the fallback.
						$response_error_message = torobpay_get_api_error_message( 'token', $response );
					}
				}

				if ( $response_success ) {
					$expected_amount = isset( $response['_torobpay_requested_amount'] ) ? $response['_torobpay_requested_amount'] : null;
					$stored_amount   = isset( $response['response']['amount'] ) ? $response['response']['amount'] : null;
					if ( ! torobpay_price_integrity_rial_amounts_match( $expected_amount, $stored_amount ) ) {
						$notice = __( 'The payment amount could not be confirmed. Please try again.', 'torobpay-for-woocommerce' );
						$detail = torobpay_price_integrity_mismatch_message( $expected_amount, $stored_amount );
						$order->update_meta_data( 'wc_torobpay_order_notice', $notice );
						$order->add_order_note(
							'<span class="torobpay-notice">' . esc_html( $detail ) . '</span>',
							0
						);
						torobpay_debug_log( 'TorobPay amount mismatch: ' . $detail );
						$order->save();
						return new WP_Error( 'torobpay_amount_mismatch', $notice );
					}
				}

				if ( ! $response_success ) {
					$notice = __( 'An error occurred while connecting to the gateway. Please try again.', 'torobpay-for-woocommerce' );
					$order->update_meta_data( 'wc_torobpay_order_notice', $notice );
					$order->add_order_note(
						'<span class="torobpay-notice">' . esc_html__( 'Error retrieving payment token:', 'torobpay-for-woocommerce' ) . '<br>' . esc_html( $response_error_message ) . '</span>',
						0
					);
					torobpay_debug_log( 'TorobPay API Error: ' . $response_error_message );
					$order->save();
					return new WP_Error( 'torobpay_token_failed', $notice );
				}

				$payment_token          = $response['response']['paymentToken'];
				$payment_page_url       = $response['response']['paymentPageUrl'];
				$previous_payment_token = (string) $order->get_meta( '_order_torobpay_token', true );

				$api_host         = wp_parse_url( $this->api->get_api_base_url(), PHP_URL_HOST );
				$redirect_host    = wp_parse_url( $payment_page_url, PHP_URL_HOST );
				$is_torobpay_host = $redirect_host && ( strtolower( $redirect_host ) === 'torobpay.com' || substr( strtolower( $redirect_host ), -12 ) === '.torobpay.com' );
				$is_expected_host = $api_host && $redirect_host && strtolower( $api_host ) === strtolower( $redirect_host );
				if ( ! $redirect_host || ( ! $is_torobpay_host && ! $is_expected_host ) ) {
					$order->add_order_note( '<span class="torobpay-notice">' . esc_html__( 'The return URL from the gateway was invalid and payment was stopped.', 'torobpay-for-woocommerce' ) . '</span>', 0 );
					$order->save();
					return new WP_Error( 'torobpay_invalid_redirect', __( 'Error redirecting to the gateway. Please try again.', 'torobpay-for-woocommerce' ) );
				}

				torobpay_reset_state_for_new_payment_token( $order, $payment_token, $previous_payment_token );
				$order->update_meta_data( '_order_torobpay_token', $payment_token );

				// Append to token history so verify/rescue can recover older paid tokens.
				$token_history = $order->get_meta( '_torobpay_token_history' );
				if ( ! is_array( $token_history ) ) {
					$token_history = array();
				}
				$token_history[] = array(
					'token'          => $payment_token,
					'transaction_id' => $transactionId,
					'timestamp'      => time(),
					'status'         => 'PENDING',
				);
				$order->update_meta_data( '_torobpay_token_history', $token_history );
				$order->update_meta_data( '_torobpay_payment_page_url', $payment_page_url );

				// Save before the note: add_order_note() writes immediately while meta is
				// only buffered, so note-first can leave a note whose token was never stored.
				$order->save();

				$order->add_order_note(
					'<span class="torobpay-notice">' . esc_html__( 'Redirecting to TorobPay gateway with payment token:', 'torobpay-for-woocommerce' ) . '<br>' . esc_html( $payment_token ) . '<br>' . esc_html__( 'Transaction ID:', 'torobpay-for-woocommerce' ) . '<br>' . esc_html( $transactionId ) . '</span>',
					0
				);

				return $payment_page_url;
			}

			public function process_payment_request( $order_id ) {
				global $woocommerce;

				// Prevent caching of this page
				if ( ! defined( 'DONOTCACHEPAGE' ) ) {
					// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Cache plugin convention.
					define( 'DONOTCACHEPAGE', true );
				}
				nocache_headers();

				// Clear all WP/WC/HPOS cache layers for this order before any operations
				torobpay_invalidate_order_cache( $order_id );

				$this->order_id = $order_id;

				/** @var WC_Order $order */
				$order = wc_get_order( $order_id );

				torobpay_debug_log( 'TorobPay: Processing payment request for order ID ' . $order_id );

				$is_direct = torobpay_direct_payment();

				if ( ! $is_direct ) {
					$action = $this->author;
					do_action( 'torobpay_gateway_payment_actions', $action );

					$form = '<form action="" method="POST" class="torobpay-checkout-form" id="torobpay-checkout-form">
						<input type="submit" name="torobpay_submit" class="button alt" id="torobpay-payment-button" value="' . esc_attr__( 'Payment', 'torobpay-for-woocommerce' ) . '"/>
						<a class="button cancel" href="' . esc_url( wc_get_checkout_url() ) . '">' . esc_html__( 'Return', 'torobpay-for-woocommerce' ) . '</a>
					 </form><br/>';

					$form = apply_filters( 'torobpay_form', $form, $order_id, $woocommerce );

					do_action( 'torobpay_gateway_before_form', $order_id, $woocommerce );
					echo wp_kses(
						$form,
						array(
							'form'  => array(
								'action' => true,
								'method' => true,
								'class'  => true,
								'id'     => true,
							),
							'input' => array(
								'type'  => true,
								'name'  => true,
								'class' => true,
								'id'    => true,
								'value' => true,
							),
							'a'     => array(
								'class' => true,
								'href'  => true,
							),
							'br'    => array(),
						)
					);
					do_action( 'torobpay_gateway_after_form', $order_id, $woocommerce );
				}

				if ( isset( $_POST['torobpay_submit'] ) || $is_direct ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Customer payment form submit after order created; order context validated above.

					// Re-fetch order from DB with full cache bypass (HPOS + Redis safe)
					$order = torobpay_get_fresh_order( $order_id );
					if ( ! $order ) {
						$this->set_message( 'failed', __( 'Error: order not found.', 'torobpay-for-woocommerce' ), true, wc_get_checkout_url() );
						return;
					}

					$payment_page_url = $this->create_gateway_payment_url( $order );
					if ( is_wp_error( $payment_page_url ) ) {
						$this->set_message( 'failed', $payment_page_url->get_error_message(), true, wc_get_checkout_url() );
						return;
					}

					$this->current_order = $order;
					$this->redirect( $payment_page_url );
				}
			}

			public function process_payment_verify() {
				if ( ! defined( 'DONOTCACHEPAGE' ) ) {
					// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Cache plugin convention.
					define( 'DONOTCACHEPAGE', true );
				}
				nocache_headers();

				$request_scheme      = is_ssl() ? 'https' : 'http';
				$request_host        = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';
				$request_uri         = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
				$actual_callback_url = $request_host ? $request_scheme . '://' . $request_host . $request_uri : '';

				// phpcs:disable WordPress.Security.NonceVerification.Recommended -- External payment gateway callback; order key verified below.
				$order_id = ( isset( $_GET['wc_order'] ) && ! empty( $_GET['wc_order'] ) ) ? absint( $_GET['wc_order'] ) : $this->session( 'get', 'order_id' );
				if ( empty( $order_id ) ) {
					$this->set_message( 'failed', __( 'Order number is missing.', 'torobpay-for-woocommerce' ), true, $this->redirect_url );
					exit;
				}

				// Fresh order load — clears CPT, HPOS, and Redis/Memcached caches
				$order = torobpay_get_fresh_order( $order_id );

				if ( ! $order ) {
					$this->set_message( 'failed', __( 'Order not found.', 'torobpay-for-woocommerce' ), true, $this->redirect_url );
					exit;
				}

				$this->order_id = $order_id;

				$received_order_key    = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
				$expected_order_key    = (string) $order->get_order_key();
				$expected_callback_url = add_query_arg(
					array(
						'wc_order' => $order_id,
						'key'      => $expected_order_key,
					),
					WC()->api_request_url( strtolower( get_class( $this ) ) )
				);

				if ( isset( $_GET['wc_order'] ) && ! empty( $_GET['wc_order'] ) ) {
					$order_key = $received_order_key;
					if ( empty( $order_key ) || ! hash_equals( $expected_order_key, $order_key ) ) {
						$this->log_gateway_event(
							'Order key mismatch detected during callback verification.',
							array(
								'order_id'              => $order_id,
								'expected_order_key'    => $expected_order_key,
								'received_order_key'    => $order_key,
								'expected_callback_url' => $expected_callback_url,
								'actual_callback_url'   => $actual_callback_url,
							)
						);
						$this->set_message( 'failed', __( 'Invalid order key.', 'torobpay-for-woocommerce' ), true, $this->redirect_url );
						exit;
					}
				}
				// phpcs:enable WordPress.Security.NonceVerification.Recommended

				if ( $order->get_payment_method() !== $this->id ) {
					return;
				}

				$client_state = isset( $_POST['state'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_POST['state'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- External gateway callback; order key verified above.
				torobpay_debug_log( 'TorobPay callback - Post State: ' . ( $client_state ?: 'not set' ) );

				$callback_transaction_id = isset( $_GET['transactionId'] ) ? sanitize_text_field( wp_unslash( $_GET['transactionId'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- External gateway callback; order key verified above.
				if (
					$order->is_paid() ||
					in_array( $order->get_status(), array( 'processing', 'completed' ), true ) ||
					'yes' === $order->get_meta( '_torobpay_settle_handled', true ) ||
					'yes' === $order->get_meta( '_torobpay_verified_by_api', true )
				) {
					$paid_transaction_id = (string) $order->get_meta( '_torobpay_transaction_id' );
					if ( empty( $paid_transaction_id ) ) {
						$paid_transaction_id = (string) $order->get_meta( '_transactionId' );
					}

					if ( ! empty( $callback_transaction_id ) && ! empty( $paid_transaction_id ) && $callback_transaction_id !== $paid_transaction_id ) {
						$order->update_meta_data(
							'wc_torobpay_order_notice',
							sprintf(
								/* translators: 1: original payment/transaction ID, 2: duplicate payment/transaction ID */
								__( 'This order was already paid with payment ID (%1$s), so your payment with payment ID (%2$s) is a duplicate and will be refunded shortly. If the amount is not returned, contact support.', 'torobpay-for-woocommerce' ),
								$paid_transaction_id,
								$callback_transaction_id
							)
						);
						$order->save();
					}

					wp_safe_redirect( $this->get_customer_return_url( $order, 'success' ) );
					exit;
				}

				// Only POST state=OK is success and may verify. FAILED, or no state at all,
				// means the payment failed: cache FAILED, skip verify, do not reuse this URL.
				if ( '' === $client_state || 'FAILED' === $client_state ) {
					if ( function_exists( 'torobpay_record_failed_gateway_return' ) ) {
						torobpay_record_failed_gateway_return( $order );
					} elseif ( function_exists( 'torobpay_cache_payment_status' ) ) {
						torobpay_cache_payment_status( $order, 'FAILED' );
					}

					$error_msg = ( '' === $client_state )
						? __( 'An error occurred when returning from the gateway.', 'torobpay-for-woocommerce' )
						: __( 'Payment failed at the gateway.', 'torobpay-for-woocommerce' );
					$order->update_meta_data( 'wc_torobpay_order_notice', $error_msg );
					$order->save();

					$this->log_gateway_event(
						'TorobPay callback did not report a successful payment; the payment token will not be reused.',
						array(
							'order_id'     => $order_id,
							'client_state' => '' === $client_state ? 'not set' : $client_state,
						)
					);

					$this->set_message( 'failed', $error_msg, true, wc_get_checkout_url() );
					exit;
				}

				$payment_token = torobpay_get_order_payment_token( $order, true );

				// Primary flow: if the callback includes transactionId, resolve the
				// exact paid token from token history and use it directly for verify.
				if ( ! empty( $callback_transaction_id ) ) {
					$token_history = $order->get_meta( '_torobpay_token_history' );
					if ( is_array( $token_history ) ) {
						foreach ( $token_history as $entry ) {
							if ( ! empty( $entry['transaction_id'] ) && (string) $entry['transaction_id'] === $callback_transaction_id ) {
								$resolved_token = isset( $entry['token'] ) ? $entry['token'] : '';
								if ( ! empty( $resolved_token ) ) {
									$payment_token = $resolved_token;
									$order->update_meta_data( '_order_torobpay_token', $resolved_token );
									$order->update_meta_data( '_torobpay_transaction_id', $callback_transaction_id );
									$order->save();
								}
								break;
							}
						}
					}
				}

				if ( ! $payment_token ) {
					$this->set_message( 'failed', __( 'Order error: invalid payment token.', 'torobpay-for-woocommerce' ), true, wc_get_checkout_url(), true );
					exit;
				}

				$response = torobpay_retry_api_call(
					function () use ( $payment_token ) {
						return $this->api->verify( $payment_token );
					},
					3,
					'Verify',
					$order->get_id()
				);

				$verify_success       = false;
				$verify_error_message = '';

				if ( is_wp_error( $response ) ) {
					$verify_error_message = $response->get_error_message();
				} elseif ( isset( $response['successful'] ) ) {
					$verify_success = $response['successful'];
					if ( ! $verify_success && ! empty( $response['errorData']['message'] ) ) {
						$verify_error_message = $response['errorData']['message'];
						$verify_error_code    = $response['errorData']['errorCode'];
					}
				}

				if ( ! $verify_success && empty( $verify_error_message ) ) {
					$verify_error_message = ( $client_state === 'FAILED' )
						? __( 'Payment failed at the gateway.', 'torobpay-for-woocommerce' )
						: __( 'An error occurred when returning from the gateway.', 'torobpay-for-woocommerce' );
				}

				if ( ! $verify_success ) {
					// 1053 means "invalid order state"
					$error_msg = isset( $verify_error_code ) && $verify_error_code == '1053'
						? __( 'Gateway error: payment failed.', 'torobpay-for-woocommerce' )
						: sprintf(
							/* translators: %s: gateway error message */
							__( 'Gateway error: %s', 'torobpay-for-woocommerce' ),
							$verify_error_message
						);

					$this->log_gateway_event(
						'TorobPay verify failed and the customer will be redirected with an error.',
						array(
							'order_id'             => $order_id,
							'payment_token'        => $payment_token,
							'error_message'        => $error_msg,
							'client_state'         => $client_state,
							'verify_error_message' => $verify_error_message,
							'verify_error_code'    => isset( $verify_error_code ) ? (string) $verify_error_code : '',
						)
					);

					$order->update_meta_data( 'wc_torobpay_order_notice', $error_msg );
					$order->save();

					// Note: set_message will add the order note, so we don't add it here
					$this->set_message( 'failed', $error_msg, true, wc_get_checkout_url() );
					exit;
				}

				// trigger another verify/settle cycle for the same successful payment.
				$order->update_meta_data( '_torobpay_verified_by_api', 'yes' );
				$order->save();
				if ( function_exists( 'torobpay_set_token_history_status' ) ) {
					torobpay_set_token_history_status( $order, $payment_token, 'VERIFY' );
				}

				$redirect = $this->get_return_url( $order );

				$transaction_id = $order->get_meta( '_torobpay_transaction_id' );

				// Backward compatibility
				if ( empty( $transaction_id ) ) {
					$transaction_id = $order->get_meta( '_transactionId' );
					if ( ! empty( $transaction_id ) ) {
						$order->update_meta_data( '_torobpay_transaction_id', $transaction_id );
						$order->save();
					}
				}

				$settle_response = torobpay_retry_api_call(
					function () use ( $payment_token ) {
						return $this->api->settle( $payment_token );
					},
					3,
					'Settle',
					$order->get_id()
				);

				$settle_success = isset( $settle_response['successful'] ) && $settle_response['successful'];

				if ( $settle_success ) {
					$order->update_meta_data( '_torobpay_settle_handled', 'yes' );
					$order->update_meta_data( '_torobpay_verified_by_api', 'yes' );
					$order->save();
					if ( function_exists( 'torobpay_set_token_history_status' ) ) {
						torobpay_set_token_history_status( $order, $payment_token, 'SETTLE' );
					}

					// Cache SETTLE before payment_complete() so the status hook does not
					// read the mint-time PENDING value and write a false warning.
					$settle_txn = ( is_array( $settle_response ) && isset( $settle_response['response']['transactionId'] ) )
						? (string) $settle_response['response']['transactionId']
						: '';
					if ( function_exists( 'torobpay_cache_payment_status' ) ) {
						torobpay_cache_payment_status( $order, 'SETTLE', $settle_txn ?: (string) $transaction_id );
					}

					$finalize_ok = torobpay_finalize_paid_order( $order, $transaction_id );
					if ( ! $finalize_ok ) {
						torobpay_debug_log(
							sprintf(
								'TorobPay: finalize postcondition failed for order #%d after settle (WC still unpaid; remediated if pending).',
								$order_id
							)
						);
					}

					// Prefer a fresh load so later save()/notes cannot clobber on-hold remediation.
					$refreshed = torobpay_get_fresh_order( $order_id );
					if ( $refreshed ) {
						$order = $refreshed;
					}

					$this->empty_cart();

					$success_note_added = $order->get_meta( '_torobpay_success_note_added', true );
					if ( 'yes' !== $success_note_added ) {
						$transaction_id_meta = $order->get_meta( '_torobpay_transaction_id' );
						$payment_token_meta  = torobpay_get_order_payment_token( $order );

						$notice_parts = array(
							'<span class="torobpay-success">' . esc_html__( 'Transaction was successful.', 'torobpay-for-woocommerce' ) . '</span>',
						);

						if ( ( $finalize_ok || $order->is_paid() ) && function_exists( 'torobpay_is_instore_order' ) && torobpay_is_instore_order( $order ) ) {
							$notice_parts[] = esc_html__( 'In-store payment was successful.', 'torobpay-for-woocommerce' );
						}

						if ( ! empty( $transaction_id_meta ) ) {
							$notice_parts[] = esc_html__( 'Transaction ID:', 'torobpay-for-woocommerce' ) . '<br>' . $transaction_id_meta;
						}

						if ( ! empty( $payment_token_meta ) ) {
							$notice_parts[] = esc_html__( 'Payment token:', 'torobpay-for-woocommerce' ) . '<br>' . $payment_token_meta;
						}

						$notice = implode( '<br>', $notice_parts );

						$order->update_meta_data( '_torobpay_success_note_added', 'yes' );
						$order->add_order_note( __( 'TorobPay: ', 'torobpay-for-woocommerce' ) . $notice, 0 );
					}
				} else {
					// complete after retries. Do NOT leave the order silently
					// unpaid: move it to on-hold, flag it for attention, and
					// the remote SETTLE state (whether via auto-settle or a later
					// retry). The customer still sees the success page because the
					// payment itself is authorized.
					$order->add_order_note( '<span class="torobpay-notice">' . esc_html__( 'TorobPay: Payment was confirmed but final settlement did not complete. The order was flagged for review and automatic finalization.', 'torobpay-for-woocommerce' ) . '</span>' );

					if ( ! $order->is_paid() && ! $order->has_status( 'on-hold' ) ) {
						$order->update_status( 'on-hold' );
					}

					if ( function_exists( 'torobpay_cache_payment_status' ) ) {
						$verify_txn = ( is_array( $response ) && isset( $response['response']['transactionId'] ) )
							? (string) $response['response']['transactionId']
							: '';
						torobpay_cache_payment_status( $order, 'VERIFY', $verify_txn ?: (string) $transaction_id );
					}
					if ( function_exists( 'torobpay_set_action_required' ) ) {
						torobpay_set_action_required( $order, 'settle_pending' );
					}

					// Payment is authorized — clear the cart so the customer is
					// not prompted to pay again.
					$this->empty_cart();
				}

				$order->save();

				$this->set_message( 'success', '', true, $redirect );
				exit;
			}

			protected function empty_cart() {
				if ( function_exists( 'wc_empty_cart' ) ) {
					wc_empty_cart();
				} elseif ( function_exists( 'WC' ) && ! empty( WC()->cart ) && method_exists( WC()->cart, 'empty_cart' ) ) {
					WC()->cart->empty_cart();
				} else {
					global $woocommerce;
					$woocommerce->cart->empty_cart();
				}
			}

			protected function order_id( $order ) {
				if ( is_numeric( $order ) ) {
					$order_id = $order;
				} elseif ( method_exists( $order, 'get_id' ) ) {
					$order_id = $order->get_id();
				} elseif ( ! ( $order_id = absint( get_query_var( 'order-pay' ) ) ) ) {
					$order_id = $order->id;
				}

				if ( ! empty( $order_id ) ) {
					$this->order_id = $order_id;
				}

				return $order_id;
			}

			protected function get_order( $order = 0, $force_fresh = false ): ?\WC_Order {
				if ( empty( $order ) ) {
					$order = $this->order_id;
				}

				if ( empty( $order ) && isset( $_GET['wc_order'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only order id from payment return URL.
					$order = absint( $_GET['wc_order'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only order id from payment return URL.
				}

				if ( is_numeric( $order ) ) {
					$this->order_id = $order;

					if ( $force_fresh ) {
						torobpay_invalidate_order_cache( $order );
					}

					$order = wc_get_order( $order );
				}

				// wc_get_order() returns false on failure; typed return must be null.
				return $order instanceof \WC_Order ? $order : null;
			}

			protected function get_order_props( $prop, $default = '' ) {
				if ( empty( $this->order_id ) ) {
					return '';
				}

				$order = $this->get_order();

				$method = 'get_' . $prop;

				if ( method_exists( $order, $method ) ) {
					$prop = $order->$method();
				} elseif ( ! empty( $order->{$prop} ) ) {
					$prop = $order->{$prop};
				} else {
					$prop = '';
				}

				return ! empty( $prop ) ? $prop : $default;
			}

			protected function session( $action, $name, $value = '' ) {
				global $woocommerce;

				$name = $this->id . '_' . $name;

				$wc_session = function_exists( 'WC' ) && ! empty( WC()->session );

				if ( $action === 'set' ) {
					if ( $wc_session && method_exists( WC()->session, 'set' ) ) {
						WC()->session->set( $name, $value );
					} else {
						$woocommerce->session->{$name} = $value;
					}
				} elseif ( $action === 'get' ) {
					if ( $wc_session && method_exists( WC()->session, 'get' ) ) {
						$value = WC()->session->get( $name );
						unset( WC()->session->{$name} );
					} else {
						$value = $woocommerce->session->{$name};
						unset( $woocommerce->session->{$name} );
					}

					return $value;
				}

				return '';
			}

			protected function option( $name ) {
				$option = '';
				if ( method_exists( $this, 'get_option' ) ) {
					$option = $this->get_option( $name );
				} elseif ( is_array( $this->settings ) && ! empty( $this->settings[ $name ] ) ) {
					$option = $this->settings[ $name ];
				}

				if ( in_array( strtolower( $option ), array( 'yes', 'on', 'true' ) ) ) {
					$option = '1';
				}
				if ( in_array( strtolower( $option ), array( 'no', 'off', 'false' ) ) ) {
					$option = false;
				}

				return $option;
			}

			protected function fields_shortcodes( $fields = array() ) {
				$fields = ! empty( $fields ) ? $fields : $this->fields();

				return ! empty( $fields['shortcodes'] ) && is_array( $fields['shortcodes'] ) ? $fields['shortcodes'] : array();
			}

			protected function get_shortcodes_values() {
				$shortcodes = array();

				$order = $this->get_order();
				if ( ! $order ) {
					return $shortcodes;
				}

				$order_id = $order->get_id();

				foreach ( $this->fields_shortcodes() as $key => $value ) {
					$key = trim( $key, '\{\}' );

					if ( $key === 'referenceID' || $key === 'transactionId' ) {
						$shortcodes[ $key ] = $order->get_meta( '_torobpay_transaction_id', true );
						if ( empty( $shortcodes[ $key ] ) ) {
							$shortcodes[ $key ] = $order->get_meta( '_transactionId', true );
						}
					} else {
						$shortcodes[ $key ] = $order->get_meta( '_' . $key, true );
						if ( empty( $shortcodes[ $key ] ) ) {
							$shortcodes[ $key ] = $order->get_meta( $key, true );
						}
					}
				}

				return $shortcodes;
			}

			/**
			 * Write structured diagnostics to WooCommerce logs (and WP debug log when enabled).
			 *
			 * @param string $message Log message
			 * @param array  $context Additional context fields
			 * @return void
			 */
			protected function log_gateway_event( $message, $context = array() ) {
				$context = array_merge( array( 'source' => 'torobpay-gateway' ), (array) $context );

				if ( function_exists( 'wc_get_logger' ) ) {
					wc_get_logger()->info( $message, $context );
				}

				torobpay_debug_log( 'TorobPay: ' . $message . ' | ' . wp_json_encode( $context ) );
			}

			/**
			 * Customer landing URL after a TorobPay return.
			 *
			 * In-store orders use the plugin result page for both success and failure.
			 *
			 * @param object $order  Order.
			 * @param string $status success|failed|cancelled.
			 * @return string
			 */
			protected function get_customer_return_url( $order, $status = 'success' ) {
				$fallback = ( 'success' === $status )
					? $this->get_return_url( $order )
					: ( function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : $this->get_return_url( $order ) );

				if ( function_exists( 'torobpay_get_payment_result_redirect_url' ) ) {
					return torobpay_get_payment_result_redirect_url( $order, $status, $fallback );
				}

				return $fallback;
			}

			protected function set_message( $status, $error = '', $notice = true, $redirect = false, $failed_note = true ) {
				$redirect_status = $status;

				if ( $redirect_status === 'failed' ) {
					$redirect_status = 'error';
				}

				if ( ! in_array( $status, array( 'success', 'cancelled', 'failed' ) ) ) {
					$status = 'failed';
				}

				$order      = $this->get_order();
				$is_instore = $order && function_exists( 'torobpay_is_instore_order' ) && torobpay_is_instore_order( $order );

				if ( ! empty( $error ) && $failed_note && ! empty( $order ) ) {
					$order->add_order_note( '<span class="torobpay-notice">' . esc_html__( 'TorobPay: ', 'torobpay-for-woocommerce' ) . $error . '</span>', 0 );
				}

				$shortcodes = array_merge( $this->get_shortcodes_values(), array( '{fault}' => $error ) );

				$message = $this->option( $status . '_message' );
				$find    = array_map(
					function ( $value ) {
						return '{' . trim( $value, '\{\}' ) . '}';
					},
					array_keys( $shortcodes )
				);
				$message = str_ireplace( $find, array_values( $shortcodes ), $message );
				$message = wpautop( wptexturize( trim( $message ) ) );

				$message = $message ?: $error;
				if ( $notice && $order && ! $is_instore ) {
					// Save the message to order meta for display after redirect
					// - Classic Checkout: torobpay_before_thankyou() via woocommerce_before_checkout_form hook
					// - Block Checkout: torobpay_block_checkout_notice() via wp_footer hook (JavaScript)
					// We do NOT use wc_add_notice() because it causes 409 Conflict errors in Block Checkout
					$order->update_meta_data( 'wc_torobpay_order_notice', $message );
					$order->save();
				}

				if ( $redirect ) {
					// Failed/cancelled online payments go to checkout. In-store
					// orders use the plugin result page for both outcomes.
					$fallback = $redirect;
					if ( in_array( $status, array( 'failed', 'cancelled' ), true ) ) {
						$fallback = function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : $redirect;
					} else {
						$fallback = ! empty( $redirect ) ? $redirect : $this->get_return_url( $order );
					}

					$safe_redirect_url = $this->get_customer_return_url( $order, $status );
					if ( ! $is_instore ) {
						$safe_redirect_url = $fallback;
					}

					wp_safe_redirect(
						add_query_arg(
							[
								'wc_status' => $redirect_status,
								'wc_order'  => ( $order ? $order->get_id() : 0 ),
							],
							$safe_redirect_url
						)
					);
					exit;
				} else {
					return $message;
				}
			}
		}
}
add_action( 'plugins_loaded', 'torobpay_load_gateway', 0 );
