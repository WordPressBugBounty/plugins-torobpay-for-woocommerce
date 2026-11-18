<?php
/**
 * TorobPay API error-code catalog.
 *
 * Single place describing every documented API error code and how it should be
 * shown to the merchant. A new code is one array entry. It becomes a
 * notification only when a provider persists that failure and `notify` is true.
 *
 * Codes are scoped by the endpoint context they arrive from, because the same
 * number means different things per endpoint — 1011 is "invalid Basic header"
 * on oauth, "invalid amount" on eligibility and "can't create order" on token.
 *
 * Definition keys:
 *   title    string  short headline (notification center, admin notice)
 *   message  string  merchant-facing explanation (notification body, order note)
 *   tier     string  'critical' (red) | 'notice' (orange)
 *   notify   bool    whether this code is *eligible* for a notification-center
 *                    item. The catalog does not emit notifications on its own:
 *                    only providers that persist a failure (auth, eligibility)
 *                    consult this flag. Order notes, campaign-page errors and
 *                    provision messages use `message` regardless of `notify`.
 *   action   string  CTA key resolved by torobpay_get_api_error_action()
 *
 * @package TorobPay
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Merchant panel URL used by contract / account CTAs.
 */
if ( ! defined( 'TOROBPAY_MERCHANT_PANEL_URL' ) ) {
	define( 'TOROBPAY_MERCHANT_PANEL_URL', 'https://panel.torobpay.com/s' );
}

/**
 * Key stamped onto a decoded API body with the HTTP status that carried it.
 * Codes are not unique per status: eligibility 1000 is 401 or 400.
 */
if ( ! defined( 'TOROBPAY_HTTP_STATUS_KEY' ) ) {
	define( 'TOROBPAY_HTTP_STATUS_KEY', 'torobpay_http_status' );
}

/**
 * Every documented API error code, keyed by endpoint context then code.
 *
 * @return array<string,array<string,array<string,mixed>>>
 */
function torobpay_get_api_error_catalog() {
	$inactive = array(
		'title'   => __( 'TorobPay account is inactive', 'torobpay-for-woocommerce' ),
		'message' => __( 'Your TorobPay account is inactive. Please contact TorobPay support.', 'torobpay-for-woocommerce' ),
		'tier'    => 'critical',
		'notify'  => true,
		'action'  => 'support',
	);

	$contract_expired = array(
		'title'   => __( 'Your store contract has expired', 'torobpay-for-woocommerce' ),
		'message' => __( 'To renew the contract, open the TorobPay panel, go to the Store information menu, and click “Register a new contract”. Or contact TorobPay support.', 'torobpay-for-woocommerce' ),
		'tier'    => 'critical',
		'notify'  => true,
		'action'  => 'panel',
	);

	$not_authenticated = array(
		'title'   => __( 'TorobPay authentication failed', 'torobpay-for-woocommerce' ),
		'message' => __( 'TorobPay did not accept the stored credentials. Please review the gateway settings.', 'torobpay-for-woocommerce' ),
		'tier'    => 'critical',
		'notify'  => false,
		'action'  => 'settings',
	);

	$invalid_credentials = array(
		'title'   => __( 'Invalid authentication credentials', 'torobpay-for-woocommerce' ),
		'message' => __( 'Invalid login details. Please check the TorobPay merchant ID and key in settings.', 'torobpay-for-woocommerce' ),
		'tier'    => 'critical',
		'notify'  => true,
		'action'  => 'settings',
	);

	$bad_username_password = array(
		'title'   => __( 'Incorrect username or password', 'torobpay-for-woocommerce' ),
		'message' => __( 'Incorrect username or password. Please check the settings.', 'torobpay-for-woocommerce' ),
		'tier'    => 'critical',
		'notify'  => true,
		'action'  => 'settings',
	);

	$catalog = array(
		// POST api/online/v1/oauth/token
		// Current codes: 1000, 1011, 1017, 1023, 1024. OAuth does not check the
		// merchant contract, so a 200 here can still be followed by eligibility 1100.
		'auth'        => array(
			'1000' => $invalid_credentials,
			'1011' => $invalid_credentials,
			'1017' => $invalid_credentials,
			'1023' => $bad_username_password,
			'1024' => $bad_username_password,
		),

		// GET api/online/offer/v1/eligible
		// Order: 1000 auth, 1099 inactive, 1100 no active contract, 1000 missing
		// amount, 1011 invalid amount, then 200. 1100 is eligibility-only.
		// 1011 means the contract check already passed.
		'eligibility' => array(
			// Same number, two HTTP statuses. Neither is a badge: 401 is an auth
			// failure, 400 is a missing amount after the contract check passed.
			'1000' => array(
				'statuses' => array(
					'401' => $not_authenticated,
					'400' => array(
						'title'   => __( 'Order amount was not sent', 'torobpay-for-woocommerce' ),
						'message' => __( 'TorobPay did not receive an order amount.', 'torobpay-for-woocommerce' ),
						'tier'    => 'notice',
						'notify'  => false,
						'action'  => '',
					),
				),
			),
			'1011' => array(
				'title'   => __( 'Cart amount outside the TorobPay range', 'torobpay-for-woocommerce' ),
				'message' => __( 'TorobPay accepts orders between 200,000 and 1,000,000,000 Rial.', 'torobpay-for-woocommerce' ),
				'tier'    => 'notice',
				'notify'  => false,
				'action'  => '',
			),
			'1099' => $inactive,
			'1100' => $contract_expired,
		),

		// POST api/online/payment/v1/token
		'token'       => array(
			'1000' => $not_authenticated,
			'1003' => array(
				'title'   => __( 'TorobPay rejected the order details', 'torobpay-for-woocommerce' ),
				'message' => __( 'TorobPay rejected the order details. Check the customer address, postal code and mobile number.', 'torobpay-for-woocommerce' ),
				'tier'    => 'notice',
				'notify'  => false,
				'action'  => '',
			),
			'1011' => array(
				'title'   => __( 'The order could not be created', 'torobpay-for-woocommerce' ),
				'message' => __( 'The order could not be created.', 'torobpay-for-woocommerce' ),
				'tier'    => 'notice',
				'notify'  => false,
				'action'  => '',
			),
			'1099' => array_merge( $inactive, array( 'notify' => false ) ),
		),

		// verify / settle / revert / cancel / update / status
		'order'       => array(
			'1005' => array(
				'title'   => __( 'Invalid payment token', 'torobpay-for-woocommerce' ),
				'message' => __( 'The payment token is not valid.', 'torobpay-for-woocommerce' ),
				'tier'    => 'notice',
				'notify'  => false,
				'action'  => '',
			),
			'1007' => array(
				'title'   => __( 'Order not found at TorobPay', 'torobpay-for-woocommerce' ),
				'message' => __( 'No TorobPay order matches this payment token.', 'torobpay-for-woocommerce' ),
				'tier'    => 'notice',
				'notify'  => false,
				'action'  => '',
			),
			'1012' => array(
				'title'   => __( 'Amount cannot be increased', 'torobpay-for-woocommerce' ),
				'message' => __( 'New amount must be less than the original amount.', 'torobpay-for-woocommerce' ),
				'tier'    => 'notice',
				'notify'  => false,
				'action'  => '',
			),
			'1013' => array(
				'title'   => __( 'Amount must be greater than zero', 'torobpay-for-woocommerce' ),
				'message' => __( 'New amount must be greater than zero.', 'torobpay-for-woocommerce' ),
				'tier'    => 'notice',
				'notify'  => false,
				'action'  => '',
			),
			'1014' => array(
				'title'   => __( 'Amount cannot be increased', 'torobpay-for-woocommerce' ),
				'message' => __( 'New payable amount cannot exceed the previous amount after discount removal.', 'torobpay-for-woocommerce' ),
				'tier'    => 'notice',
				'notify'  => false,
				'action'  => '',
			),
			'1016' => array(
				'title'   => __( 'Orders with discounts cannot be updated', 'torobpay-for-woocommerce' ),
				'message' => __( 'Orders with discounts cannot be updated.', 'torobpay-for-woocommerce' ),
				'tier'    => 'notice',
				'notify'  => false,
				'action'  => '',
			),
			'1048' => array(
				'title'   => __( 'Order belongs to another merchant', 'torobpay-for-woocommerce' ),
				'message' => __( 'This payment token does not belong to your TorobPay account.', 'torobpay-for-woocommerce' ),
				'tier'    => 'notice',
				'notify'  => false,
				'action'  => '',
			),
			'1053' => array(
				'title'   => __( 'Order status cannot be changed', 'torobpay-for-woocommerce' ),
				'message' => __( 'Order status cannot be changed.', 'torobpay-for-woocommerce' ),
				'tier'    => 'notice',
				'notify'  => false,
				'action'  => '',
			),
			'1054' => array(
				'title'   => __( 'Editing window has expired', 'torobpay-for-woocommerce' ),
				'message' => __( 'The 30-day window to edit or cancel this order has expired and it can no longer be changed.', 'torobpay-for-woocommerce' ),
				'tier'    => 'notice',
				'notify'  => false,
				'action'  => '',
			),
			'1065' => array(
				'title'   => __( 'Too late to revert this payment', 'torobpay-for-woocommerce' ),
				'message' => __( 'More than 30 minutes have passed since payment, so it can no longer be reverted. Cancel the order instead.', 'torobpay-for-woocommerce' ),
				'tier'    => 'notice',
				'notify'  => false,
				'action'  => '',
			),
			'1099' => array_merge( $inactive, array( 'notify' => false ) ),
		),

		// GET  api/online/features/v1/list
		// POST api/online/features/v1/set-order-campaign-status
		// 1099 is merchant inactive here too — same meaning as eligibility 1099.
		// Campaign-page errors stay on that page; they do not badge the
		// notification center (`notify` => false).
		'campaign'    => array(
			'1000' => $not_authenticated,
			'1003' => array(
				'title'   => __( 'Campaign status request was incomplete', 'torobpay-for-woocommerce' ),
				'message' => __( 'The campaign status could not be updated because the enabled flag was missing from the request.', 'torobpay-for-woocommerce' ),
				'tier'    => 'notice',
				'notify'  => false,
				'action'  => '',
			),
			'1099' => array_merge( $inactive, array( 'notify' => false ) ),
		),

		// POST api/online/v1/oauth/credential (credential provisioning)
		'provision'   => array(
			'1025' => array(
				'title'   => __( 'Invalid store domain', 'torobpay-for-woocommerce' ),
				'message' => __( 'Invalid store domain.', 'torobpay-for-woocommerce' ),
				'tier'    => 'notice',
				'notify'  => false,
				'action'  => '',
			),
			'1026' => array(
				'title'   => __( 'Store not found', 'torobpay-for-woocommerce' ),
				'message' => __( 'Store was not found in TorobPay. Make sure registration is complete in the TorobPay panel.', 'torobpay-for-woocommerce' ),
				'tier'    => 'notice',
				'notify'  => false,
				'action'  => '',
			),
			'1028' => array(
				'title'   => __( 'Store verification failed', 'torobpay-for-woocommerce' ),
				'message' => __( 'Store verification failed. Registration may not be complete or approved yet.', 'torobpay-for-woocommerce' ),
				'tier'    => 'notice',
				'notify'  => false,
				'action'  => '',
			),
			'1029' => array(
				'title'   => __( 'Confirmation token mismatch', 'torobpay-for-woocommerce' ),
				'message' => __( 'Confirmation token does not match the submitted value. Please try again.', 'torobpay-for-woocommerce' ),
				'tier'    => 'notice',
				'notify'  => false,
				'action'  => '',
			),
			'1030' => array(
				'title'   => __( 'Invalid verification URL', 'torobpay-for-woocommerce' ),
				'message' => __( 'Invalid store verification URL.', 'torobpay-for-woocommerce' ),
				'tier'    => 'notice',
				'notify'  => false,
				'action'  => '',
			),
			'1031' => array(
				'title'   => __( 'TorobPay account not found', 'torobpay-for-woocommerce' ),
				'message' => __( 'TorobPay account was not found. Check your account status in the TorobPay panel.', 'torobpay-for-woocommerce' ),
				'tier'    => 'notice',
				'notify'  => false,
				'action'  => '',
			),
			'1032' => array(
				'title'   => __( 'Invalid account details request', 'torobpay-for-woocommerce' ),
				'message' => __( 'TorobPay rejected the account-details request because the submitted data was invalid.', 'torobpay-for-woocommerce' ),
				'tier'    => 'notice',
				'notify'  => false,
				'action'  => '',
			),
			'1033' => array(
				'title'   => __( 'Automatic fetch already used', 'torobpay-for-woocommerce' ),
				'message' => __( 'Automatic account fetch already ran once, so automatic fetch was stopped. Enter details manually from the TorobPay panel.', 'torobpay-for-woocommerce' ),
				'tier'    => 'notice',
				'notify'  => false,
				'action'  => '',
			),
			'1034' => array(
				'title'   => __( 'Automatic activation unavailable', 'torobpay-for-woocommerce' ),
				'message' => __( 'Automatic activation is not available for your account. After registration is approved, activate manually in the TorobPay panel by fetching gateway credentials.', 'torobpay-for-woocommerce' ),
				'tier'    => 'notice',
				'notify'  => false,
				'action'  => '',
			),
			'1035' => array(
				'title'   => __( 'Registration under review', 'torobpay-for-woocommerce' ),
				'message' => __( 'Your TorobPay registration is under review. If approved, your gateway will be activated automatically when possible.', 'torobpay-for-woocommerce' ),
				'tier'    => 'notice',
				'notify'  => false,
				'action'  => '',
			),
			'1036' => array(
				'title'   => __( 'Registration was not approved', 'torobpay-for-woocommerce' ),
				'message' => __( 'Your TorobPay registration was not approved. Click this link to see why your request was rejected.', 'torobpay-for-woocommerce' ),
				'tier'    => 'notice',
				'notify'  => false,
				'action'  => '',
			),
			'1099' => array(
				'title'   => __( 'TorobPay account is inactive', 'torobpay-for-woocommerce' ),
				'message' => __( 'Your TorobPay merchant account is inactive. Please contact TorobPay support.', 'torobpay-for-woocommerce' ),
				'tier'    => 'critical',
				'notify'  => false,
				'action'  => 'support',
			),
		),
	);

	/**
	 * Filter the API error catalog.
	 *
	 * @param array $catalog Error definitions keyed by context then code.
	 */
	return apply_filters( 'torobpay_api_error_catalog', $catalog );
}

/**
 * Normalize an error code (string/int/null) to a digit string.
 *
 * @param  mixed $error_code Raw code from the API or a stored option.
 * @return string Empty when the value is not a usable code.
 */
function torobpay_normalize_api_error_code( $error_code ) {
	if ( ! is_scalar( $error_code ) ) {
		return '';
	}

	$code = trim( (string) $error_code );
	if ( '' === $code ) {
		return '';
	}

	if ( is_numeric( $code ) ) {
		return (string) (int) $code;
	}

	return $code;
}

/**
 * Look up the definition for one error code in one context.
 *
 * @param  string   $context     Endpoint context: auth|eligibility|token|order|provision.
 * @param  mixed    $code        Raw error code.
 * @param  int|null $http_status HTTP status, required when one code has several.
 * @return array|null Definition, or null when the code is not catalogued.
 */
function torobpay_get_api_error_definition( $context, $code, $http_status = null ) {
	$code = torobpay_normalize_api_error_code( $code );
	if ( '' === $code ) {
		return null;
	}

	$catalog = torobpay_get_api_error_catalog();
	if ( ! isset( $catalog[ $context ][ $code ] ) ) {
		return null;
	}

	$definition = $catalog[ $context ][ $code ];
	if ( empty( $definition['statuses'] ) || ! is_array( $definition['statuses'] ) ) {
		return $definition;
	}

	$status = (int) $http_status;
	if ( $status > 0 && isset( $definition['statuses'][ (string) $status ] ) ) {
		return $definition['statuses'][ (string) $status ];
	}

	return null;
}

/**
 * Resolve a CTA key into a link for notifications and notices.
 *
 * @param  string $action_key Value of a definition's `action` key.
 * @return array|null { url, label, new_tab } or null when there is no CTA.
 */
function torobpay_get_api_error_action( $action_key ) {
	switch ( $action_key ) {
		case 'panel':
			return array(
				'url'     => TOROBPAY_MERCHANT_PANEL_URL,
				'label'   => __( 'Open TorobPay panel', 'torobpay-for-woocommerce' ),
				'new_tab' => true,
			);
		case 'support':
			return array(
				'url'     => admin_url( 'admin.php?page=torobpay-support' ),
				'label'   => __( 'Contact support', 'torobpay-for-woocommerce' ),
				'new_tab' => false,
			);
		case 'settings':
			return array(
				'url'     => admin_url( 'admin.php?page=wc-settings&tab=checkout&section=wc_gateway_torobpay' ),
				'label'   => __( 'Review settings', 'torobpay-for-woocommerce' ),
				'new_tab' => false,
			);
	}

	return null;
}

/**
 * Read the error code out of a decoded API response.
 *
 * Handles the `errorData.errorCode` envelope the gateway returns in practice
 * and the `error.code` shape used by the published specification.
 *
 * @param  mixed $response Decoded response body.
 * @return string Empty when the response carries no code.
 */
function torobpay_extract_api_error_code( $response ) {
	if ( ! is_array( $response ) ) {
		return '';
	}

	if ( isset( $response['errorData']['errorCode'] ) ) {
		return torobpay_normalize_api_error_code( $response['errorData']['errorCode'] );
	}

	if ( isset( $response['error']['code'] ) ) {
		return torobpay_normalize_api_error_code( $response['error']['code'] );
	}

	return '';
}

/**
 * HTTP status stamped onto a decoded API body by TorobPayAPI::process_response().
 *
 * @param  mixed $response Decoded response body.
 * @return int Zero when the body has no status.
 */
function torobpay_extract_api_http_status( $response ) {
	if ( ! is_array( $response ) || ! isset( $response[ TOROBPAY_HTTP_STATUS_KEY ] ) ) {
		return 0;
	}

	$status = (int) $response[ TOROBPAY_HTTP_STATUS_KEY ];

	return $status > 0 ? $status : 0;
}

/**
 * Read the raw API-supplied error text out of a decoded response.
 *
 * @param  mixed $response Decoded response body.
 * @return string Empty when the response carries no message.
 */
function torobpay_extract_api_error_text( $response ) {
	if ( ! is_array( $response ) ) {
		return '';
	}

	foreach ( array( $response['errorData']['message'] ?? null, $response['error']['message'] ?? null, $response['message'] ?? null ) as $candidate ) {
		if ( ! empty( $candidate ) && is_string( $candidate ) ) {
			return $candidate;
		}
	}

	return '';
}

/**
 * Merchant-facing message for an API failure, catalogued copy when the code is
 * known and the raw API text otherwise.
 *
 * @param  string $context  Endpoint context.
 * @param  mixed  $response Decoded response body.
 * @param  string $fallback Used when neither the catalog nor the API supplies text.
 * @return string
 */
function torobpay_get_api_error_message( $context, $response, $fallback = '' ) {
	$definition = torobpay_get_api_error_definition(
		$context,
		torobpay_extract_api_error_code( $response ),
		torobpay_extract_api_http_status( $response )
	);
	if ( $definition ) {
		return $definition['message'];
	}

	$raw = torobpay_extract_api_error_text( $response );

	return '' !== $raw ? $raw : $fallback;
}
