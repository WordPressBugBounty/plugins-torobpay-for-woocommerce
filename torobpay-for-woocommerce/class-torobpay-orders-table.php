<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * TorobPay Orders List Table Class
 *
 * A custom table implementation for displaying TorobPay payment orders
 */
class TorobPay_Orders_Table extends WP_List_Table {

	/**
	 * Constructor
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'order',
				'plural'   => 'orders',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Get payment status display text based on TorobPay API status
	 *
	 * @param string $status The payment status from API
	 * @param string $transaction_id The transaction ID
	 * @return string The display text for the status
	 */
	private function get_payment_status_display( $status, $transaction_id = '' ) {
		$meta = function_exists( 'torobpay_get_payment_status_meta' )
			? torobpay_get_payment_status_meta( $status )
			: array(
				'text'       => __( 'Unknown status', 'torobpay-for-woocommerce' ),
				'is_success' => false,
			);

		if ( $meta['is_success'] && $transaction_id ) {
			return $transaction_id . __( ' (Paid)', 'torobpay-for-woocommerce' );
		}

		return $meta['text'];
	}

	/**
	 * Format a row as a CSV line.
	 *
	 * @param array $fields Row values.
	 * @return string
	 */
	private function format_csv_row( array $fields ) {
		$escaped = array();

		foreach ( $fields as $field ) {
			$field = (string) $field;
			if ( false !== strpos( $field, '"' ) || false !== strpos( $field, ',' ) || false !== strpos( $field, "\n" ) || false !== strpos( $field, "\r" ) ) {
				$field = '"' . str_replace( '"', '""', $field ) . '"';
			}
			$escaped[] = $field;
		}

		return implode( ',', $escaped ) . "\n";
	}

	/**
	 * CSV column headers for selected and full-table exports.
	 *
	 * @return array
	 */
	private function get_csv_headers() {
		return array(
			__( 'Order ID', 'torobpay-for-woocommerce' ),
			__( 'Channel', 'torobpay-for-woocommerce' ),
			__( 'Payment date', 'torobpay-for-woocommerce' ),
			__( 'Mobile number', 'torobpay-for-woocommerce' ),
			__( 'Customer name', 'torobpay-for-woocommerce' ),
			__( 'Order status', 'torobpay-for-woocommerce' ),
			__( 'TorobPay transaction status', 'torobpay-for-woocommerce' ),
			__( 'Total amount', 'torobpay-for-woocommerce' ),
		);
	}

	/**
	 * Build one CSV data row from an order.
	 *
	 * @param WC_Order $order Order object.
	 * @return array
	 */
	private function get_csv_order_row( $order ) {
		$payment_status     = $order->get_meta( '_torobpay_verification_status' );
		$api_transaction_id = $order->get_meta( '_torobpay_api_transaction_id' );
		$wc_transaction_id  = $order->get_transaction_id();
		$transaction_id     = $api_transaction_id ? $api_transaction_id : $wc_transaction_id;

		$channel = function_exists( 'torobpay_get_order_channel_label' )
			? torobpay_get_order_channel_label( $order )
			: __( 'Online', 'torobpay-for-woocommerce' );

		return array(
			$order->get_id(),
			$channel,
			$order->get_date_created()->date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ),
			$order->get_billing_phone(),
			$order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
			wc_get_order_status_name( $order->get_status() ),
			$payment_status ? $this->get_payment_status_display( $payment_status, $transaction_id ) : __( 'No response', 'torobpay-for-woocommerce' ),
			$order->get_total(),
		);
	}

	/**
	 * Clear cached verification data for an order.
	 *
	 * Delegates to the shared clearer so both cache layers stay in lock-step; the
	 * table no longer keeps its own duplicate copy of the key list.
	 *
	 * @param WC_Order $order The order object
	 */
	public function clear_verification_cache( $order ) {
		if ( function_exists( 'torobpay_clear_order_cache' ) ) {
			torobpay_clear_order_cache( $order );
			return;
		}

		torobpay_reset_payment_status_cache( $order );
	}

	/**
	 * Define the columns that are going to be used in the table
	 *
	 * @return array $columns Array of columns
	 */
	public function get_columns() {
		$columns = array(
			'cb'             => '<input type="checkbox" />',
			'order_id'       => __( 'Order ID', 'torobpay-for-woocommerce' ),
			'date'           => __( 'Payment date', 'torobpay-for-woocommerce' ),
			'mobile'         => __( 'Mobile number', 'torobpay-for-woocommerce' ),
			'customer_name'  => __( 'Customer name', 'torobpay-for-woocommerce' ),
			'status'         => __( 'Order status', 'torobpay-for-woocommerce' ),
			'transaction_id' => __( 'TorobPay transaction status', 'torobpay-for-woocommerce' ),
			'amount'         => __( 'Total amount', 'torobpay-for-woocommerce' ),
			'actions'        => __( 'Actions', 'torobpay-for-woocommerce' ),
		);

		return $columns;
	}

	/**
	 * Get a list of hidden columns
	 *
	 * @return array
	 */
	public function get_hidden_columns() {
		$hidden = get_user_option( 'managetorobpay_orderscolumnshidden' );

		if ( ! $hidden ) {
			$hidden = get_user_option( 'managetoplevelpagetorobpay-orderscolumnshidden' );
		}

		return $hidden ? (array) $hidden : array();
	}

	/**
	 * Define which columns are sortable
	 *
	 * @return array $sortable_columns Array of sortable columns
	 */
	public function get_sortable_columns() {
		$sortable_columns = array(
			'order_id'      => array( 'order_id', true ),
			'date'          => array( 'date', true ),
			'customer_name' => array( 'customer_name', false ),
			'status'        => array( 'status', false ),
			'amount'        => array( 'amount', false ),
		);

		return $sortable_columns;
	}

	/**
	 * Define bulk actions
	 *
	 * @return array $actions Array of bulk actions
	 */
	public function get_bulk_actions() {
		$actions = array(
			'refresh_verification' => __( 'Refresh transaction status', 'torobpay-for-woocommerce' ),
			'refund'               => __( 'Refund order', 'torobpay-for-woocommerce' ),
			'export_csv'           => __( 'Export CSV', 'torobpay-for-woocommerce' ),
		);

		return $actions;
	}

	/**
	 * Process bulk actions
	 */
	public function process_bulk_action() {
		$action = $this->current_action();
		if ( empty( $action ) && isset( $_REQUEST['torobpay_export'] ) && 'all' === sanitize_key( wp_unslash( $_REQUEST['torobpay_export'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified below with check_admin_referer.
			$action = 'export_all_csv';
		}
		if ( ! empty( $action ) ) {
			check_admin_referer( 'bulk-' . $this->_args['plural'] );
		}

		if ( 'refresh_verification' === $action ) {
			if ( isset( $_POST['order'] ) ) {
				$order_ids        = array_map( 'absint', (array) wp_unslash( $_POST['order'] ) );
				$orders           = array();
				$tokens_to_verify = array();
				$tokens_to_orders = array();

				foreach ( $order_ids as $order_id ) {
					$order = wc_get_order( $order_id );
					if ( $order ) {
						$payment_token = torobpay_get_order_payment_token( $order );
						if ( $payment_token ) {
							$orders[]                           = $order;
							$tokens_to_verify[]                 = $payment_token;
							$tokens_to_orders[ $payment_token ] = $order;
						}
					}
				}

				if ( ! empty( $tokens_to_verify ) ) {
					$torobPayAPI   = new TorobPayAPI();
					$payments_info = $torobPayAPI->batch_verify_payments( $tokens_to_verify );

					foreach ( $tokens_to_orders as $token => $order ) {
						$items_hash = $order->get_meta( '_torobpay_items_hash' );

						if ( isset( $payments_info[ $token ] ) ) {
							$payment_info   = $payments_info[ $token ];
							$payment_status = $payment_info['status'];
							$transaction_id = $payment_info['transactionId'];

							// Shared writer keeps both cache layers in sync (one SSoT).
							torobpay_cache_payment_status( $order, $payment_status, (string) $transaction_id );
							$order->update_meta_data( '_torobpay_verification_hash', $items_hash );

							if ( in_array( $payment_status, [ 'VERIFY', 'SETTLE' ] ) && $transaction_id ) {
								$order->set_transaction_id( $transaction_id );
								$order->update_meta_data( '_torobpay_transaction_id', $transaction_id );
							}

							$order->save();

							if ( function_exists( 'torobpay_flag_divergence_from_status' ) ) {
								torobpay_flag_divergence_from_status( $order, $payment_status );
							}
						} else {
							torobpay_cache_payment_status( $order, 'FAILED', '' );
							$order->update_meta_data( '_torobpay_verification_hash', $items_hash );
							$order->save();
						}
					}

					update_option( 'torobpay_last_status_update', time() );
				}

				wp_safe_redirect( admin_url( 'admin.php?page=torobpay-orders&verification_refreshed=true' . $this->get_preserved_filters() ) );
				exit;
			}
		}

		if ( 'refund' === $action ) {
			if ( isset( $_POST['order'] ) ) {
				$order_ids      = array_map( 'absint', (array) wp_unslash( $_POST['order'] ) );
				$refunded_count = 0;
				$failed_orders  = array();

				foreach ( $order_ids as $order_id ) {
					$order = wc_get_order( $order_id );
					if ( ! $order || ! in_array( $order->get_status(), array( 'processing', 'completed' ), true ) ) {
						continue;
					}
					if ( ! torobpay_is_torobpay_payment_method( $order->get_payment_method() ) ) {
						$failed_orders[] = $order_id;
						continue;
					}

					// Sub-orders carry no payment token; only the parent can be refunded.
					if ( torobpay_should_bypass_order( $order, 'torobpay_orders_table_bulk_refund' ) ) {
						$failed_orders[] = $order_id;
						continue;
					}

					// Confirm remote cancel before any local refund (same guard as AJAX refund).
					$from_status = $order->get_status();
					$cancel_ok   = function_exists( 'torobpay_cancel_order' )
						? torobpay_cancel_order( $order->get_id(), $from_status )
						: false;
					$fresh       = function_exists( 'torobpay_get_fresh_order' )
						? ( torobpay_get_fresh_order( $order->get_id() ) ?: $order )
						: $order;
					$confirmed   = function_exists( 'torobpay_has_confirmed_cancellation' )
						? torobpay_has_confirmed_cancellation( $fresh, torobpay_get_order_payment_token( $fresh ) )
						: false;

					if ( ! $cancel_ok || ! $confirmed ) {
						$failed_orders[] = $order_id;
						continue;
					}

					$order = $fresh;
					torobpay_clear_order_cache( $order );

					$line_items = array();
					foreach ( $order->get_items() as $item_id => $item ) {
						$line_items[ $item_id ] = array(
							'qty'          => $item->get_quantity(),
							'refund_total' => $item->get_total(),
							'refund_tax'   => $item->get_total_tax(),
						);
					}

					$refund = wc_create_refund(
						array(
							'order_id'      => $order->get_id(),
							'amount'        => $order->get_total(),
							'reason'        => __( 'TorobPay refund', 'torobpay-for-woocommerce' ),
							'line_items'    => $line_items,
							'restock_items' => true,
						)
					);

					if ( is_wp_error( $refund ) ) {
						$failed_orders[] = $order_id;
						continue;
					}

					torobpay_mark_refund_torobpay_confirmed( $refund );
					$order->update_meta_data( '_torobpay_stock_restored', 'yes' );
					$order->save();

					$fresh_order = function_exists( 'torobpay_get_fresh_order' )
						? torobpay_get_fresh_order( $order->get_id() )
						: $order;
					if ( $fresh_order ) {
						torobpay_cache_payment_status( $fresh_order, 'REVERT' );
						if ( function_exists( 'torobpay_clear_action_required' ) ) {
							torobpay_clear_action_required( $fresh_order );
						}
					}

					$this->clear_verification_cache( $order );
					++$refunded_count;
				}

				$redirect_params = 'refunds_processed=true&refunded=' . $refunded_count;
				if ( ! empty( $failed_orders ) ) {
					$redirect_params .= '&failed_orders=' . implode( ',', $failed_orders );
				}

				wp_safe_redirect( admin_url( 'admin.php?page=torobpay-orders&' . $redirect_params . $this->get_preserved_filters() ) );
				exit;
			}
		}

		if ( 'export_csv' === $action ) {
			if ( isset( $_POST['order'] ) ) {
				$order_ids = array_map( 'absint', (array) wp_unslash( $_POST['order'] ) );

				if ( ! empty( $order_ids ) ) {
					while ( ob_get_level() ) {
						ob_end_clean();
					}

					remove_all_filters( 'wp_die_handler' );
					remove_all_actions( 'shutdown' );

					header( 'Content-Type: text/csv; charset=utf-8' );
					header( 'Content-Disposition: attachment; filename=torobpay-orders-export-' . gmdate( 'Y-m-d' ) . '.csv' );
					header( 'Pragma: no-cache' );
					header( 'Expires: 0' );
					header( 'Cache-Control: must-revalidate' );

					$csv_content = "\xEF\xBB\xBF";
					$csv_content .= $this->format_csv_row( $this->get_csv_headers() );

					foreach ( $order_ids as $order_id ) {
						$order = wc_get_order( $order_id );

						if ( $order ) {
							$csv_content .= $this->format_csv_row( $this->get_csv_order_row( $order ) );
						}
					}

					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV download body.
					echo $csv_content;

					die();
				}
			}
		}

		if ( 'export_all_csv' === $action ) {
			$per_page = $this->get_items_per_page( 'torobpay_orders_per_page', 20 );

			$base_args = torobpay_merge_marketplace_orders_query_exclusions(
				array(
					'paginate'       => true,
					'payment_method' => array( 'WC_Gateway_TorobPay', 'torobpay' ),
				)
			);

			$base_args = torobpay_apply_order_date_range_query( $base_args, $this->get_request_date_range() );

			if ( ! empty( $_GET['order_status'] ) ) {
				$base_args['status'] = str_replace( 'wc-', '', sanitize_text_field( wp_unslash( $_GET['order_status'] ) ) );
			}

			if ( ! empty( $_GET['mobile_search'] ) ) {
				$base_args['billing_phone'] = sanitize_text_field( wp_unslash( $_GET['mobile_search'] ) );
			}

			if ( ! empty( $_GET['order_id_search'] ) ) {
				$base_args['id'] = absint( wp_unslash( $_GET['order_id_search'] ) );
			}

			$base_args = torobpay_apply_order_channel_query( $base_args, $this->get_request_order_channel() );

			if ( ! empty( $_GET['torobpay_status'] ) ) {
				$torobpay_status_filter = sanitize_text_field( wp_unslash( $_GET['torobpay_status'] ) );
				$status_meta_condition  = null;
				if ( 'PAID' === $torobpay_status_filter ) {
					$status_meta_condition = array(
						'key'     => '_torobpay_verification_status',
						'value'   => array( 'VERIFY', 'SETTLE' ),
						'compare' => 'IN',
					);
				} elseif ( 'EDITING' === $torobpay_status_filter ) {
					$status_meta_condition = array(
						'key'     => '_torobpay_editing_items',
						'value'   => 'yes',
						'compare' => '=',
					);
				} elseif ( 'ACTION_NEEDED' === $torobpay_status_filter ) {
					$status_meta_condition = array(
						'key'     => '_torobpay_action_required',
						'value'   => 'yes',
						'compare' => '=',
					);
				} elseif ( in_array( $torobpay_status_filter, array( 'PENDING', 'REVERT', 'FAILED' ), true ) ) {
					$status_meta_condition = array(
						'key'     => '_torobpay_verification_status',
						'value'   => $torobpay_status_filter,
						'compare' => '=',
					);
				}
				if ( $status_meta_condition ) {
					// Avoid meta_query on CPT (WC 9.2+ doing_it_wrong); HPOS still uses meta_query.
					$base_args = torobpay_orders_apply_meta_clause( $base_args, $status_meta_condition );
				}
			}

			if ( ! empty( $_REQUEST['orderby'] ) ) {
				$orderby = sanitize_text_field( wp_unslash( $_REQUEST['orderby'] ) );
				$order   = ! empty( $_REQUEST['order'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['order'] ) ) : 'desc';

				switch ( $orderby ) {
					case 'order_id':
						$base_args['orderby'] = 'ID';
						break;
					case 'date':
						$base_args['orderby'] = 'date';
						break;
					case 'customer_name':
						$base_args['orderby'] = 'customer';
						break;
					case 'status':
						$base_args['orderby'] = 'status';
						break;
					case 'amount':
						$base_args['orderby'] = 'total';
						break;
					default:
						$base_args['orderby'] = 'date';
						break;
				}

				$base_args['order'] = $order;
			} else {
				$base_args['orderby'] = 'date';
				$base_args['order']   = 'desc';
			}

			$first_page_args = array_merge(
				$base_args,
				array(
					'limit'  => $per_page,
					'offset' => 0,
				)
			);

			$first_result = wc_get_orders( $first_page_args );
			$total_items  = is_object( $first_result ) && property_exists( $first_result, 'total' ) ? $first_result->total : count( $first_result );
			$total_pages  = ceil( $total_items / $per_page );

			if ( $total_items > 0 ) {
				while ( ob_get_level() ) {
					ob_end_clean();
				}

				remove_all_filters( 'wp_die_handler' );
				remove_all_actions( 'shutdown' );

				header( 'Content-Type: text/csv; charset=utf-8' );
				header( 'Content-Disposition: attachment; filename=torobpay-all-orders-export-' . gmdate( 'Y-m-d' ) . '.csv' );
				header( 'Pragma: no-cache' );
				header( 'Expires: 0' );
				header( 'Cache-Control: must-revalidate' );

				$csv_content = "\xEF\xBB\xBF";
				$csv_content .= $this->format_csv_row( $this->get_csv_headers() );

				for ( $page = 1; $page <= $total_pages; $page++ ) {
					$offset = ( $page - 1 ) * $per_page;

					$page_args = array_merge(
						$base_args,
						array(
							'limit'  => $per_page,
							'offset' => $offset,
						)
					);

					$page_result = wc_get_orders( $page_args );
					$page_orders = is_object( $page_result ) && property_exists( $page_result, 'orders' ) ? $page_result->orders : $page_result;

					foreach ( $page_orders as $order ) {
						$csv_content .= $this->format_csv_row( $this->get_csv_order_row( $order ) );
					}
				}

				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV download body.
				echo $csv_content;

				die();
			}
		}
	}

	/**
	 * Prepare the table data
	 */
	public function prepare_items() {
		global $wpdb;

		$this->process_bulk_action();

		$columns               = $this->get_columns();
		$hidden                = $this->get_hidden_columns();
		$sortable              = $this->get_sortable_columns();
		$this->_column_headers = array( $columns, $hidden, $sortable );

		$per_page     = $this->get_items_per_page( 'torobpay_orders_per_page', 20 );
		$current_page = $this->get_pagenum();
		$offset       = ( $current_page - 1 ) * $per_page;

		$args = torobpay_merge_marketplace_orders_query_exclusions(
			array(
				'limit'          => $per_page,
				'offset'         => $offset,
				'paginate'       => true,
				'payment_method' => array( 'WC_Gateway_TorobPay', 'torobpay' ),
			)
		);

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Admin list-table filter args; page is capability-gated.
		$args = torobpay_apply_order_date_range_query( $args, $this->get_request_date_range() );

		if ( ! empty( $_GET['order_status'] ) ) {
			$args['status'] = str_replace( 'wc-', '', sanitize_text_field( wp_unslash( $_GET['order_status'] ) ) );
		}

		if ( ! empty( $_GET['mobile_search'] ) ) {
			$args['billing_phone'] = sanitize_text_field( wp_unslash( $_GET['mobile_search'] ) );
		}

		if ( ! empty( $_GET['order_id_search'] ) ) {
			$args['id'] = absint( wp_unslash( $_GET['order_id_search'] ) );
		}

		$args = torobpay_apply_order_channel_query( $args, $this->get_request_order_channel() );

		if ( ! empty( $_GET['torobpay_status'] ) ) {
			$torobpay_status_filter = sanitize_text_field( wp_unslash( $_GET['torobpay_status'] ) );
			$status_meta_condition = null;
			if ( 'PAID' === $torobpay_status_filter ) {
				$status_meta_condition = array(
					'key'     => '_torobpay_verification_status',
					'value'   => array( 'VERIFY', 'SETTLE' ),
					'compare' => 'IN',
				);
			} elseif ( 'EDITING' === $torobpay_status_filter ) {
				$status_meta_condition = array(
					'key'     => '_torobpay_editing_items',
					'value'   => 'yes',
					'compare' => '=',
				);
			} elseif ( 'ACTION_NEEDED' === $torobpay_status_filter ) {
				$status_meta_condition = array(
					'key'     => '_torobpay_action_required',
					'value'   => 'yes',
					'compare' => '=',
				);
			} elseif ( in_array( $torobpay_status_filter, array( 'PENDING', 'REVERT', 'FAILED' ), true ) ) {
				$status_meta_condition = array(
					'key'     => '_torobpay_verification_status',
					'value'   => $torobpay_status_filter,
					'compare' => '=',
				);
			}
			if ( $status_meta_condition ) {
				// Avoid meta_query on CPT (WC 9.2+ doing_it_wrong); HPOS still uses meta_query.
				$args = torobpay_orders_apply_meta_clause( $args, $status_meta_condition );
			}
		}

		if ( ! empty( $_REQUEST['orderby'] ) ) {
			$orderby = sanitize_text_field( wp_unslash( $_REQUEST['orderby'] ) );
			$order   = ! empty( $_REQUEST['order'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['order'] ) ) : 'desc';

			switch ( $orderby ) {
				case 'order_id':
					$args['orderby'] = 'ID';
					break;
				case 'date':
					$args['orderby'] = 'date';
					break;
				case 'customer_name':
					$args['orderby'] = 'customer';
					break;
				case 'status':
					$args['orderby'] = 'status';
					break;
				case 'amount':
					$args['orderby'] = 'total';
					break;
				default:
					$args['orderby'] = 'date';
					break;
			}

			$args['order'] = $order;
		} else {
			$args['orderby'] = 'date';
			$args['order']   = 'desc';
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$result = wc_get_orders( $args );
		if ( is_object( $result ) && property_exists( $result, 'orders' ) ) {
			$this->items = $result->orders;
			$total_items = $result->total;
		} else {
			$this->items = $result;
			$total_items = count( $result );
		}

		$this->batch_verify_current_items();

		$this->set_pagination_args(
			array(
				'total_items' => $total_items,
				'per_page'    => $per_page,
				'total_pages' => ceil( $total_items / $per_page ),
			)
		);
	}

	/**
	 * Refresh payment statuses for current page items (5 min cache; always re-project divergence).
	 */
	private function batch_verify_current_items() {
		if ( empty( $this->items ) ) {
			return;
		}

		$cache_ttl          = 5 * MINUTE_IN_SECONDS;
		$tokens_to_verify   = array();
		$tokens_to_orders   = array();
		$cached_for_flagging = array();

		foreach ( $this->items as $order ) {
			if ( ! $order instanceof WC_Order ) {
				continue;
			}

			$payment_token = torobpay_get_order_payment_token( $order );
			if ( ! $payment_token ) {
				continue;
			}

			$payment_status = function_exists( 'torobpay_get_cached_payment_status' )
				? torobpay_get_cached_payment_status( $order )
				: (string) $order->get_meta( '_torobpay_verification_status' );

			$items_hash        = $order->get_meta( '_torobpay_items_hash' );
			$verification_hash = $order->get_meta( '_torobpay_verification_hash' );
			$order_updated     = $items_hash && $verification_hash && $items_hash !== $verification_hash;

			$age           = function_exists( 'torobpay_cached_status_age' ) ? torobpay_cached_status_age( $order ) : null;
			$cache_expired = ( null === $age ) || $age >= $cache_ttl;

			if ( '' === $payment_status || $cache_expired || $order_updated ) {
				$tokens_to_verify[]                 = $payment_token;
				$tokens_to_orders[ $payment_token ] = $order;
				continue;
			}

			// Fresh cache: still re-project divergence.
			$cached_for_flagging[] = array(
				'order'  => $order,
				'status' => $payment_status,
			);
		}

		foreach ( $cached_for_flagging as $entry ) {
			if ( function_exists( 'torobpay_flag_divergence_from_status' ) ) {
				torobpay_flag_divergence_from_status( $entry['order'], $entry['status'] );
			}
		}

		if ( empty( $tokens_to_verify ) ) {
			return;
		}

		$torobPayAPI   = new TorobPayAPI();
		$payments_info = $torobPayAPI->batch_verify_payments( $tokens_to_verify );

		foreach ( $tokens_to_orders as $token => $order ) {
			$items_hash = $order->get_meta( '_torobpay_items_hash' );

			if ( isset( $payments_info[ $token ] ) ) {
				$payment_info   = $payments_info[ $token ];
				$payment_status = $payment_info['status'];
				$transaction_id = $payment_info['transactionId'];

				torobpay_cache_payment_status( $order, $payment_status, (string) $transaction_id );
				$order->update_meta_data( '_torobpay_verification_hash', $items_hash );

				if ( in_array( $payment_status, array( 'VERIFY', 'SETTLE' ), true ) && $transaction_id ) {
					$order->set_transaction_id( $transaction_id );
					$order->update_meta_data( '_torobpay_transaction_id', $transaction_id );
				}

				$order->save();

				if ( function_exists( 'torobpay_flag_divergence_from_status' ) ) {
					torobpay_flag_divergence_from_status( $order, $payment_status );
				}
			} else {
				torobpay_cache_payment_status( $order, 'FAILED', '' );
				$order->update_meta_data( '_torobpay_verification_hash', $items_hash );
				$order->save();
			}
		}

		update_option( 'torobpay_last_status_update', time() );
	}

	/**
	 * Render a column when no column specific method exists
	 *
	 * @param object $item
	 * @param string $column_name
	 * @return mixed
	 */
	public function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'order_id':
				$order_link = '<a href="' . esc_url( admin_url( 'post.php?post=' . $item->get_id() . '&action=edit' ) ) . '">#' . esc_html( $item->get_id() ) . '</a>';
				if ( function_exists( 'torobpay_is_instore_order' ) && torobpay_is_instore_order( $item ) ) {
					$order_link .= ' <span class="torobpay-instore-badge">' . esc_html__( 'In-store', 'torobpay-for-woocommerce' ) . '</span>';
				}
				return $order_link;
			case 'date':
				return $item->get_date_created()->date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) );
			case 'mobile':
				return $item->get_billing_phone();
			case 'customer_name':
				return $item->get_billing_first_name() . ' ' . $item->get_billing_last_name();
			case 'status':
				return wc_get_order_status_name( $item->get_status() );
			case 'transaction_id':
				$payment_status     = $item->get_meta( '_torobpay_verification_status' );
				$api_transaction_id = $item->get_meta( '_torobpay_api_transaction_id' );
				$wc_transaction_id  = $item->get_transaction_id();

				$transaction_id = $api_transaction_id ? $api_transaction_id : $wc_transaction_id;

				if ( $payment_status ) {
					return $this->get_payment_status_display( $payment_status, $transaction_id );
				}

				return $transaction_id ? $transaction_id : __( 'No response', 'torobpay-for-woocommerce' );

			case 'amount':
				return $item->get_formatted_order_total();
			case 'actions':
				// Use the shared builder so the orders table and the single order
				// edit page render identical action buttons (same markup, classes,
				// visibility rules and JS handlers bound by admin-order-actions.js).
				$buttons = function_exists( 'torobpay_build_order_actions_buttons_html' )
					? torobpay_build_order_actions_buttons_html( $item )
					: '';

				return wp_kses( '<div class="trb-actions">' . $buttons . '</div>', torobpay_kses_allowed_html() );
			default:
				return '';
		}
	}

	/**
	 * Render the checkbox column
	 *
	 * @param object $item
	 * @return string
	 */
	public function column_cb( $item ) {
		return sprintf(
			'<input type="checkbox" name="order[]" value="%s" />',
			$item->get_id()
		);
	}

	/**
	 * Display custom text when no items are found
	 */
	public function no_items() {
		echo esc_html__( 'No TorobPay gateway orders found.', 'torobpay-for-woocommerce' );
	}

	/**
	 * Get human-readable time difference in Persian
	 *
	 * @param int $timestamp Unix timestamp
	 * @return string Human-readable time difference
	 */
	private function get_time_ago( $timestamp ) {
		$timestamp = intval( $timestamp );

		if ( $timestamp <= 0 ) {
			return __( 'Not updated yet', 'torobpay-for-woocommerce' );
		}

		$diff = time() - $timestamp;

		if ( $diff < 5 ) {
			return __( 'Just now', 'torobpay-for-woocommerce' );
		}

		if ( $diff < 60 ) {
			return $diff . __( ' seconds ago', 'torobpay-for-woocommerce' );
		}

		if ( $diff < 3600 ) {
			$minutes = floor( $diff / 60 );
			$seconds = $diff % 60;
			if ( $seconds > 0 ) {
				return $minutes . __( ' minutes and ', 'torobpay-for-woocommerce' ) . $seconds . __( ' seconds ago', 'torobpay-for-woocommerce' );
			}
			return $minutes . __( ' minutes ago', 'torobpay-for-woocommerce' );
		}

		if ( $diff < 86400 ) {
			$hours   = floor( $diff / 3600 );
			$minutes = floor( ( $diff % 3600 ) / 60 );
			if ( $minutes > 0 ) {
				return $hours . __( ' hours and ', 'torobpay-for-woocommerce' ) . $minutes . __( ' minutes ago', 'torobpay-for-woocommerce' );
			}
			return $hours . __( ' hours ago', 'torobpay-for-woocommerce' );
		}

		$days  = floor( $diff / 86400 );
		$hours = floor( ( $diff % 86400 ) / 3600 );
		if ( $hours > 0 ) {
			return $days . __( ' days and ', 'torobpay-for-woocommerce' ) . $hours . __( ' hours ago', 'torobpay-for-woocommerce' );
		}
		return $days . __( ' days ago', 'torobpay-for-woocommerce' );
	}

	/**
	 * Display the filter box
	 */
	public function display_filter_box() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Admin list-table filter UI; page is capability-gated.
		$current_status          = isset( $_REQUEST['order_status'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['order_status'] ) ) : '';
		$current_mobile          = isset( $_REQUEST['mobile_search'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['mobile_search'] ) ) : '';
		$current_order_id        = isset( $_REQUEST['order_id_search'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['order_id_search'] ) ) : '';
		$current_date_range      = $this->get_request_date_range();
		$current_torobpay_status = isset( $_REQUEST['torobpay_status'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['torobpay_status'] ) ) : '';
		$current_order_channel   = $this->get_request_order_channel();
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$order_statuses = wc_get_order_statuses();

		?>
		<div class="torobpay-settings-note" style="font-size: 13px; color:#0f0f0fff;">
			<strong><?php echo esc_html__( 'Important note:', 'torobpay-for-woocommerce' ); ?></strong>
			<?php
			echo wp_kses(
				sprintf(
					/* translators: %s: TorobPay merchant panel URL */
					__( 'The authoritative order status is the one shown in the TorobPay merchants panel. To open the panel, <a href="%s" target="_blank">click here</a>.', 'torobpay-for-woocommerce' ),
					'https://panel.torobpay.com/s'
				),
				array(
					'a' => array(
						'href'   => true,
						'target' => true,
					),
				)
			);
			?>
			<br>
			<span><strong><?php echo esc_html__( 'Last TorobPay order status fetch:', 'torobpay-for-woocommerce' ); ?></strong> <?php echo esc_html( $this->get_time_ago( get_option( 'torobpay_last_status_update' ) ) ); ?></span>
		</div>
		<div class="torobpay-filters">
			<div class="filter-section-header">
				<span class="filter-section-title">
					<span class="dashicons dashicons-filter"></span>
					<?php echo esc_html__( 'Filter orders', 'torobpay-for-woocommerce' ); ?>
				</span>
				<?php
				$active_filter_count = count( array_filter( [ $current_status, $current_mobile, $current_order_id, $current_date_range, $current_torobpay_status, $current_order_channel ] ) );
				if ( $active_filter_count > 0 ) :
					?>
					<span class="active-filters-badge"><?php echo esc_html( sprintf( /* translators: %d: number of active filters */ _n( '%d active filter', '%d active filters', $active_filter_count, 'torobpay-for-woocommerce' ), $active_filter_count ) ); ?></span>
				<?php endif; ?>
			</div>
			<div id="filter-container">
				<div class="filter-grid">
					<div class="filter-item order-id">
						<label for="order_id_search"><?php echo esc_html__( 'Order number:', 'torobpay-for-woocommerce' ); ?></label>
						<input type="number"
							id="order_id_search"
							name="order_id_search"
							value="<?php echo esc_attr( $current_order_id ); ?>"
							placeholder="<?php echo esc_attr__( 'Search order number', 'torobpay-for-woocommerce' ); ?>"
							min="1">
					</div>

					<div class="filter-item status">
						<label for="order_status"><?php echo esc_html__( 'Order status:', 'torobpay-for-woocommerce' ); ?></label>
						<select id="order_status" name="order_status">
							<option value=""><?php echo esc_html__( 'All', 'torobpay-for-woocommerce' ); ?></option>
							<?php foreach ( $order_statuses as $status_key => $status_label ) : ?>
								<option value="<?php echo esc_attr( $status_key ); ?>"
									<?php selected( $current_status, $status_key ); ?>>
									<?php echo esc_html( $status_label ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</div>

					<div class="filter-item torobpay-status">
						<label for="torobpay_status"><?php echo esc_html__( 'TorobPay transaction status:', 'torobpay-for-woocommerce' ); ?></label>
						<select id="torobpay_status" name="torobpay_status">
							<option value=""><?php echo esc_html__( 'All', 'torobpay-for-woocommerce' ); ?></option>
							<option value="PAID" <?php selected( $current_torobpay_status, 'PAID' ); ?>><?php echo esc_html__( 'Paid', 'torobpay-for-woocommerce' ); ?></option>
							<option value="PENDING" <?php selected( $current_torobpay_status, 'PENDING' ); ?>><?php echo esc_html__( 'Awaiting payment', 'torobpay-for-woocommerce' ); ?></option>
							<option value="REVERT" <?php selected( $current_torobpay_status, 'REVERT' ); ?>><?php echo esc_html__( 'Cancelled / Failed', 'torobpay-for-woocommerce' ); ?></option>
							<option value="EDITING" <?php selected( $current_torobpay_status, 'EDITING' ); ?>><?php echo esc_html__( 'Incomplete edit', 'torobpay-for-woocommerce' ); ?></option>
							<option value="ACTION_NEEDED" <?php selected( $current_torobpay_status, 'ACTION_NEEDED' ); ?>><?php echo esc_html__( 'Action required', 'torobpay-for-woocommerce' ); ?></option>
						</select>
					</div>

					<div class="filter-item order-channel">
						<label for="order_channel"><?php echo esc_html__( 'Channel:', 'torobpay-for-woocommerce' ); ?></label>
						<select id="order_channel" name="order_channel">
							<option value=""><?php echo esc_html__( 'All', 'torobpay-for-woocommerce' ); ?></option>
							<option value="instore" <?php selected( $current_order_channel, 'instore' ); ?>><?php echo esc_html__( 'In-store', 'torobpay-for-woocommerce' ); ?></option>
							<option value="online" <?php selected( $current_order_channel, 'online' ); ?>><?php echo esc_html__( 'Online', 'torobpay-for-woocommerce' ); ?></option>
						</select>
					</div>

					<div class="filter-item date-range">
						<label for="date_range_display"><?php echo esc_html__( 'Date range:', 'torobpay-for-woocommerce' ); ?></label>
						<div class="date-input-wrapper">
							<input type="text"
								id="date_range_display"
								class="jalali-date"
								data-jdp
								data-jdp-mode="range"
								data-jdp-max-date="today"
								data-jdp-target-value-input="#date_range"
								data-jdp-target-value-type="gregorian"
								placeholder="<?php echo esc_attr__( 'Start date - End date', 'torobpay-for-woocommerce' ); ?>"
								autocomplete="off">
							<i class="dashicons dashicons-calendar-alt"></i>
							<input type="hidden"
								id="date_range"
								name="date_range"
								value="<?php echo esc_attr( $current_date_range ); ?>">
						</div>
					</div>

					<div class="filter-item mobile">
						<label for="mobile_search"><?php echo esc_html__( 'Mobile number:', 'torobpay-for-woocommerce' ); ?></label>
						<input type="text"
							id="mobile_search"
							name="mobile_search"
							value="<?php echo esc_attr( $current_mobile ); ?>"
							placeholder="<?php echo esc_attr__( 'Search mobile number', 'torobpay-for-woocommerce' ); ?>">
					</div>

					<div class="filter-actions">
						<button type="submit" class="button button-primary torobpay-button-primary">
							<i class="dashicons dashicons-filter"></i>
							<?php echo esc_html__( 'Apply filters', 'torobpay-for-woocommerce' ); ?>
						</button>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=torobpay-orders' ) ); ?>"
							class="button trb-btn">
							<i class="dashicons dashicons-dismiss"></i>
							<?php echo esc_html__( 'Clear filters', 'torobpay-for-woocommerce' ); ?>
						</a>
					</div>
				</div>
			</div>
		</div>

		<?php
	}

	/**
	 * Sanitized Gregorian date_range query arg, or empty string.
	 *
	 * @return string
	 */
	private function get_request_date_range() {
		if ( empty( $_REQUEST['date_range'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Admin list-table filter arg; page is capability-gated.
			return '';
		}

		return sanitize_text_field( wp_unslash( $_REQUEST['date_range'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Admin list-table filter arg; page is capability-gated.
	}

	/**
	 * Whitelisted order_channel query arg, or empty string.
	 *
	 * @return string
	 */
	private function get_request_order_channel() {
		if ( empty( $_REQUEST['order_channel'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Admin list-table filter arg; page is capability-gated.
			return '';
		}

		return torobpay_sanitize_order_channel( wp_unslash( $_REQUEST['order_channel'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Admin list-table filter arg; page is capability-gated.
	}

	/**
	 * Get preserved filter parameters as URL query string
	 *
	 * @return string URL query parameters
	 */
	private function get_preserved_filters() {
		$preserved_params = array();
		$filter_keys      = array(
			'order_status',
			'mobile_search',
			'order_id_search',
			'date_range',
			'torobpay_status',
			'order_channel',
			'orderby',
			'order',
			'paged',
		);

		foreach ( $filter_keys as $key ) {
			if ( ! empty( $_REQUEST[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Preserving list-table filter query args.
				$preserved_params[ $key ] = sanitize_text_field( wp_unslash( $_REQUEST[ $key ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Preserving list-table filter query args.
			}
		}

		return ! empty( $preserved_params ) ? '&' . http_build_query( $preserved_params ) : '';
	}

	/**
	 * Add extra tablenav div
	 * Adds a separate button for CSV Export function
	 * @param string $which The location of the nav: 'top' or 'bottom'
	 */
	public function extra_tablenav( $which ) {
		if ( $which === 'top' ) {
			?>
			<div class="alignleft actions">
				<button type="button"
					id="export-all-csv-button"
					class="button button-secondary torobpay-button-secondary">
					<i class="dashicons dashicons-download" style="margin: 4px 0 0 5px; line-height:normal;"></i>
					<?php echo esc_html__( 'Export all', 'torobpay-for-woocommerce' ); ?>
				</button>
				<button type="button"
					id="export-selected-csv-button"
					class="button button-secondary torobpay-button-secondary">
					<i class="dashicons dashicons-download" style="margin: 4px 0 0 5px; line-height:normal;"></i>
					<?php echo esc_html__( 'Export selected', 'torobpay-for-woocommerce' ); ?>
				</button>
			</div>
			<?php
		}
	}
}
