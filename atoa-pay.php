<?php
/*
 * Plugin Name: Atoa Pay
 * Plugin URI: https://atoa.me/
 * Description: Integrates Atoa Pay with WooCommerce.
 * 
 * Version: 1.1.4
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * 
 * WC requires at least: 5.0
 * WC tested up to: 7.8.2
 * 
 * Author: paywithatoa
 * Author URI: https://paywithatoa.co.uk/
 *
 * Text Domain: atoa-pay
 * Domain Path: /languages/
 * 
 * License: GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

if ( ! class_exists( 'AtoaPay\API' ) ) {
	require_once 'API.php';
}
use AtoaPay\API;

// Add the Atoa Payment Gateway to WooCommerce
add_filter( 'woocommerce_payment_gateways', 'atoa_pay_add_payment_gateway' );
function atoa_pay_add_payment_gateway( $gateways ) {
	$gateways[] = 'Atoa_Pay_Payment_Gateway';
	return $gateways;
}

// look for redirect from Atoa.
add_action( 'template_redirect', 'atoa_pay_redirect_handler' );
function atoa_pay_redirect_handler() {
	if ( get_query_var( 'paymentRequestId' ) && get_query_var( 'atoaSignature' ) ) {
		nocache_headers();
		$gateway = new Atoa_Pay_Payment_Gateway();
		$gateway->update_order_status( get_query_var( 'paymentRequestId' ), get_query_var( 'status' ) );
	}
}

// register our GET variables
add_filter( 'query_vars', 'atoa_pay_add_query_vars' );
function atoa_pay_add_query_vars( $vars ) {
	$vars[] = 'orderId';
	$vars[] = 'paymentIdempotencyId';
	$vars[] = 'paymentRequestId';
	$vars[] = 'atoaSignature';
	$vars[] = 'status';
	return $vars;
}

// Add custom link on plugin page
$plugin = plugin_basename( __FILE__ );
add_filter( "network_admin_plugin_action_links_$plugin", 'atoa_pay_plugin_action_links' );
add_filter( "plugin_action_links_$plugin", 'atoa_pay_plugin_action_links' );
function atoa_pay_plugin_action_links( array $actions ) {
	return array_merge(
		[
			'configurations' => '<a href="https://docs.atoa.me/">' . esc_html__( 'Docs', 'atoa-pay' ) . '</a>',
			'payments'       => '<a href="admin.php?page=wc-settings&tab=checkout&section=atoa">' . esc_html__( 'Settings', 'atoa-pay' ) . '</a>',
		],
		$actions
	);
}

// Load Atoa_Pay_Payment_Gateway class after all plugins are loaded.
add_action( 'plugins_loaded', 'atoa_pay_init' );
function atoa_pay_init() {
	if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
		return;
	}
	
	// Define the Atoa Payment Gateway Class
	class Atoa_Pay_Payment_Gateway extends WC_Payment_Gateway {
		public $access_secret;
		
		public $testmode;
		
		public function __construct() {
			$this->id                 = 'atoa';
			$this->method_title       = 'Atoa';
			$this->method_description = 'Integrate Atoa with WooCommerce';
			$this->icon               = plugins_url( 'images/', __FILE__ ) . 'logo.svg';
			$this->has_fields         = false;

			$this->init_form_fields();
			$this->init_settings();

			$this->title         = $this->get_option( 'title' );
			$this->access_secret = $this->get_option( 'access_secret' );
			$this->testmode      = 'yes' === $this->get_option( 'testmode', 'no' );
			$this->redirect_url  = $this->get_option( 'redirect_url' );

			add_action(
				'woocommerce_update_options_payment_gateways_' . $this->id,
				[
					$this,
					'process_admin_options',
				]
			);

			// Payment status check events are scheduled when payments are started.
			add_action(
				'atoa_pay_payment_status_check',
				[
					$this,
					'check_status',
				],
				10,
				2
			);
		}

		public function init_form_fields() {
			$this->form_fields = [
				'enabled'       => [
					'title'   => __( 'Enable/Disable', 'atoa-pay' ),
					'type'    => 'checkbox',
					'label'   => __( 'Enable Atoa Payment Gateway', 'atoa-pay' ),
					'default' => 'yes',
				],
				'title'         => [
					'title'       => __( 'Title', 'atoa-pay' ),
					'type'        => 'text',
					'description' => __( 'This controls the title which the user sees during checkout.', 'atoa-pay' ),
					'default'     => __( 'Atoa', 'atoa-pay' ),
					'desc_tip'    => true,
				],
				'access_secret' => [
					'title'       => __( 'Access Secret', 'atoa-pay' ),
					'type'        => 'password',
					'description' => __( 'Enter your Atoa Access Secret.', 'atoa-pay' ),
					'default'     => '',
					'desc_tip'    => true,
				],
				'testmode'      => [
					'title'       => __( 'Atoa sandbox', 'atoa-pay' ),
					'type'        => 'checkbox',
					'label'       => __( 'Enable Atoa sandbox', 'atoa-pay' ),
					'default'     => 'no',
					'description' => __( 'Atoa sandbox can be used to test payments.', 'atoa-pay' ),
				],
				'redirect_url' => [
					'title'       => __( 'Redirect URL (Optional)', 'atoa-pay' ),
					'type'        => 'text',
					'description' => __( 'Enter the URL to redirect to after payment.', 'atoa-pay' ),
					'default'     => '',
					'desc_tip'    => true,
				],
			];
		}

		public function process_payment( $order_id ) {
			$order = wc_get_order( $order_id );

			// Generate payment link
			try {
				$api             = new API( $this->access_secret, $this->testmode );
				$payment_request = $api->create_payment_request( $this->get_payment_data( $order ) );

				$order->add_meta_data( 'atoa_payment_request_id', $payment_request->paymentRequestId );
				$order->save();
				// Get delay seconds for first status check.
				$delay = self::get_status_check_delay_seconds( 1 );
				\as_schedule_single_action(
					time() + $delay,
					'atoa_pay_payment_status_check',
					[
						'order_id' => $order_id,
						'try'      => 1,
					],
					'atoa-pay'
				);

				// Redirect to payment URL
				return [
					'result'   => 'success',
					'redirect' => $payment_request->paymentUrl,
				];
			} catch ( Exception $e ) {
				wc_add_notice( $e->getMessage(), 'error' );
				
				return [ 'result' => 'failure' ];
			}
		}

		private function get_payment_data( $order ) {
			$order_id = $order->get_id();
			$amount   = $order->get_total();
			$currency = $order->get_currency();

			$billing_phone      = $order->get_billing_phone();
			$billing_email      = $order->get_billing_email();
			$billing_first_name = $order->get_billing_first_name();
			$billing_last_name  = $order->get_billing_last_name();

			$redirectUrl = $this->redirect_url;
			if ( empty( $redirectUrl ) ) {
				if(isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on'){
					$urlProtocol = "https";
				} else {
					$urlProtocol = "http";
				}
				$baseUrlRaw = $_SERVER["SERVER_NAME"];
				$baseUrl = $urlProtocol."://".$baseUrlRaw."/";
			} else {
				$baseUrl = $redirectUrl;
			}

			$api_args = [
				'customerId'      => $billing_email,
				'orderId'         => $order_id,
				'amount'          => $amount,
				'currency'        => $currency,
				'paymentType'     => 'DOMESTIC',
				'autoRedirect'    => false,
				'consumerDetails' => [
					'phoneNumber' => $billing_phone,
					'email'       => $billing_email,
					'firstName'   => $billing_first_name,
					'lastName'    => $billing_last_name,
				],
				'enableTips'      => false,
				'redirectUrl'     => $baseUrl
			];

			return $api_args;
		}

		public function check_status( $order_id, $try = 1 ) {
			$order = \wc_get_order( $order_id );

			// - No status request if payment status is already changed.
			if ( 'pending' !== $order->get_status() ) {
				return;
			}

			$order = $this->update_order_status( $order->get_meta( 'atoa_payment_request_id' ) );

			// Limit number of tries.
			if ( 5 === $try ) {
				return;
			}

			// Don't Schedule if payment status is changed in this attempt.
			if ( 'pending' !== $order->get_status() ) {
				return;
			}

			$next_try = ( $try + 1 );

			// Get delay seconds for next status check.
			$delay = self::get_status_check_delay_seconds( $next_try );

			\as_schedule_single_action(
				time() + $delay,
				'atoa_pay_payment_status_check',
				[
					'order_id' => $order_id,
					'try'      => $next_try,
				],
				'atoa-pay'
			);
		}

		public function update_order_status( $payment_request_id, $received_status = '' ) {
			try {
				$api            = new API( $this->access_secret, $this->testmode );
				$payment_status = $api->get_payment_status( $payment_request_id );
	
				$order = new WC_Order( $payment_status->orderId );
			} catch ( Exception $e ) {
				return null;
			}
			
			// For some payments we don't receive ISO Status.
			if ( ! isset( $payment_status->statusDetails->isoStatus->code ) ) {
				if ( 'CANCELLED' === $payment_status->status ) {
					// Cancel.
					$order->update_status( 'cancelled', $payment_status->errorDescription );
					if ( ! empty( $received_status ) ) {
						wp_safe_redirect( $order->get_checkout_payment_url( false ) );
						exit();
					}
				} elseif ( 'EXPIRED' === $payment_status->status ) {
					// Fail.
					$order->update_status( 'failed' );
					if ( ! empty( $received_status ) ) {
						wp_safe_redirect( $order->get_checkout_payment_url( false ) );
						exit();
					}
				}
			}

			switch ( $payment_status->statusDetails->isoStatus->code ) {
				case 'ACSC':
				case 'ACWP':
				case 'ACCC':
					// Complete.
					$order->payment_complete( $payment_status->paymentIdempotencyId );
					if ( ! empty( $received_status ) ) {
						wp_safe_redirect( $this->get_return_url( $order ) );
						exit();
					}
					break;

				case 'CANC':
					// Cancel.
					$order->update_status( 'cancelled', $payment_status->errorDescription );
					if ( ! empty( $received_status ) ) {
						wp_safe_redirect( $order->get_checkout_payment_url( false ) );
						exit();
					}
					break;

				case 'RJCT':
					// Fail.
					$order->update_status( 'failed', $payment_status->errorDescription );
					if ( ! empty( $received_status ) ) {
						wp_safe_redirect( $order->get_checkout_payment_url( false ) );
						exit();
					}
					break;

				case 'ACSP':
				default:
					if ( ! empty( $received_status ) ) {
						wp_safe_redirect( $order->get_view_order_url() );
						exit();
					}
			}

			return $order;
		}

		private static function get_status_check_delay_seconds( $try ) {
			switch ( $try ) {
				case 1:
					return 15 * MINUTE_IN_SECONDS;

				case 2:
					return 30 * MINUTE_IN_SECONDS;

				case 3:
					return HOUR_IN_SECONDS;

				case 4:
					return 4 * HOUR_IN_SECONDS;

				case 5:
				default:
					return DAY_IN_SECONDS;
			}
		}
	}
}
