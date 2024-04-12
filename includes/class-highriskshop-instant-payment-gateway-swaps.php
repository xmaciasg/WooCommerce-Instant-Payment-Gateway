<?php
if (!defined('ABSPATH')) {
    exit;
}

add_action('plugins_loaded', 'init_hrs_swaps_gateway');

function init_hrs_swaps_gateway() {
    if (!class_exists('WC_Payment_Gateway')) {
        return;
    }
	
class HighRiskShop_Instant_Payment_Gateway_Swaps extends WC_Payment_Gateway {

    public function __construct() {
        $this->id                 = 'highriskshop-instant-payment-gateway-swaps';
        $this->icon = esc_url($this->get_option('icon_url'));
        $this->method_title       = esc_html__('Instant Approval Payment Gateway with Instant Payouts (swaps.app)', 'highriskshopgateway'); // Escaping title
        $this->method_description = esc_html__('Instant Approval High Risk Merchant Gateway with instant payouts to your USDT POLYGON wallet using swaps.app infrastructure', 'highriskshopgateway'); // Escaping description
        $this->has_fields         = false;

        $this->init_form_fields();
        $this->init_settings();

        $this->title       = $this->get_option('title');
        $this->description = $this->get_option('description');

        // Use the configured settings for redirect and icon URLs
        $this->swapsapp_wallet_address = sanitize_text_field($this->get_option('swapsapp_wallet_address'));
        $this->icon_url     = esc_url($this->get_option('icon_url'));

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
    }

    public function init_form_fields() {
        $this->form_fields = array(
            'enabled' => array(
                'title'   => esc_html__('Enable/Disable', 'highriskshopgateway'), // Escaping title
                'type'    => 'checkbox',
                'label'   => esc_html__('Enable swaps.app payment gateway', 'highriskshopgateway'), // Escaping label
                'default' => 'no',
            ),
            'title' => array(
                'title'       => esc_html__('Title', 'highriskshopgateway'), // Escaping title
                'type'        => 'text',
                'description' => esc_html__('Payment method title that users will see during checkout.', 'highriskshopgateway'), // Escaping description
                'default'     => esc_html__('Credit Card', 'highriskshopgateway'), // Escaping default value
                'desc_tip'    => true,
            ),
            'description' => array(
                'title'       => esc_html__('Description', 'highriskshopgateway'), // Escaping title
                'type'        => 'textarea',
                'description' => esc_html__('Payment method description that users will see during checkout.', 'highriskshopgateway'), // Escaping description
                'default'     => esc_html__('Pay via credit card', 'highriskshopgateway'), // Escaping default value
                'desc_tip'    => true,
            ),
            'swapsapp_wallet_address' => array(
                'title'       => esc_html__('Wallet Address', 'highriskshopgateway'), // Escaping title
                'type'        => 'text',
                'description' => esc_html__('Insert your USDT (Polygon) wallet address to receive instant payouts.', 'highriskshopgateway'), // Escaping description
                'desc_tip'    => true,
            ),
            'icon_url' => array(
                'title'       => esc_html__('Icon URL', 'highriskshopgateway'), // Escaping title
                'type'        => 'url',
                'description' => esc_html__('Enter the URL of the icon image for the payment method.', 'highriskshopgateway'), // Escaping description
                'desc_tip'    => true,
            ),
        );
    }
    public function process_payment($order_id) {
        $order = wc_get_order($order_id);
        $hrs_swapsapp_currency = get_woocommerce_currency();
		$hrs_swapsapp_total = $order->get_total();
		$hrs_swapsapp_nonce = wp_create_nonce( 'hrs_swapsapp_nonce_' . $order_id );
		$hrs_swapsapp_callback = add_query_arg(array('order_id' => $order_id, 'nonce' => $hrs_swapsapp_nonce,), rest_url('custom-route/v1/hrs-swapsapp/'));
		$hrs_swapsapp_email = urlencode($order->get_billing_email());
		$hrs_swapsapp_final_total = $hrs_swapsapp_total;
	
$hrs_swapsapp_gen_wallet = wp_remote_get('https://api.highriskshop.com/control/wallet.php?address=' . $this->swapsapp_wallet_address .'&callback=' . urlencode($hrs_swapsapp_callback));

if (is_wp_error($hrs_swapsapp_gen_wallet)) {
    // Handle error
    wc_add_notice(__('Wallet error:', 'woocommerce') . __('Payment could not be processed due to incorrect payout wallet settings, please contact website admin', 'hrsswapsapp'), 'error');
    return null;
} else {
	$hrs_swapsapp_wallet_body = wp_remote_retrieve_body($hrs_swapsapp_gen_wallet);
	$hrs_swapsapp_wallet_decbody = json_decode($hrs_swapsapp_wallet_body, true);

 // Check if decoding was successful
    if ($hrs_swapsapp_wallet_decbody && isset($hrs_swapsapp_wallet_decbody['address_in'])) {
        // Store the address_in as a variable
        $hrs_swapsapp_gen_addressIn = $hrs_swapsapp_wallet_decbody['address_in'];
		$hrs_swapsapp_gen_callback = $hrs_swapsapp_wallet_decbody['callback_url'];
		// Save $swapsappresponse in order meta data
    $order->update_meta_data('highriskshop_swapsapp_tracking_address', $hrs_swapsapp_gen_addressIn);
    $order->update_meta_data('highriskshop_swapsapp_callback', $hrs_swapsapp_gen_callback);
	$order->update_meta_data('highriskshop_swapsapp_converted_amount', $hrs_swapsapp_final_total);
    $order->save();
    } else {
        wc_add_notice(__('Payment error:', 'woocommerce') . __('Payment could not be processed, please try again (wallet address error)', 'swapsapp'), 'error');

        return null;
    }
}

        // Redirect to payment page
        return array(
            'result'   => 'success',
            'redirect' => 'https://api.highriskshop.com/control/process-payment.php?address=' . $hrs_swapsapp_gen_addressIn . '&amount=' . $hrs_swapsapp_final_total . '&provider=swaps&email=' . $hrs_swapsapp_email . '&currency=' . $hrs_swapsapp_currency,
        );
    }

}

function highriskshop_add_instant_payment_gateway_swaps($gateways) {
    $gateways[] = 'HighRiskShop_Instant_Payment_Gateway_Swaps';
    return $gateways;
}
add_filter('woocommerce_payment_gateways', 'highriskshop_add_instant_payment_gateway_swaps');
}

// Add custom endpoint for changing order status
function hrs_swapsapp_change_order_status_rest_endpoint() {
    // Register custom route
    register_rest_route( 'custom-route/v1', '/hrs-swapsapp/', array(
        'methods'  => 'GET',
        'callback' => 'hrs_swapsapp_change_order_status_callback',
    ));
}
add_action( 'rest_api_init', 'hrs_swapsapp_change_order_status_rest_endpoint' );

// Callback function to change order status
function hrs_swapsapp_change_order_status_callback( $request ) {
    $order_id = absint($request->get_param( 'order_id' ));
	$hrs_swapsappgetnonce = sanitize_text_field($request->get_param( 'nonce' ));
	
	 // Verify nonce
    if ( empty( $hrs_swapsappgetnonce ) || ! wp_verify_nonce( $hrs_swapsappgetnonce, 'hrs_swapsapp_nonce_' . $order_id ) ) {
        return new WP_Error( 'invalid_nonce', __( 'Invalid nonce.', 'highriskshop-instant-payment-gateway-swaps' ), array( 'status' => 403 ) );
    }

    // Check if order ID parameter exists
    if ( empty( $order_id ) ) {
        return new WP_Error( 'missing_order_id', __( 'Order ID parameter is missing.', 'highriskshop-instant-payment-gateway-swaps' ), array( 'status' => 400 ) );
    }

    // Get order object
    $order = wc_get_order( $order_id );

    // Check if order exists
    if ( ! $order ) {
        return new WP_Error( 'invalid_order', __( 'Invalid order ID.', 'highriskshop-instant-payment-gateway-swaps' ), array( 'status' => 404 ) );
    }

    // Check if the order is pending and payment method is 'highriskshop-instant-payment-gateway-swaps'
    if ( $order && $order->get_status() === 'pending' && 'highriskshop-instant-payment-gateway-swaps' === $order->get_payment_method() ) {
        // Change order status to processing
		 $order->payment_complete();
        $order->update_status( 'processing' );
        // Return success response
        return array( 'message' => 'Order status changed to processing.' );
    } else {
        // Return error response if conditions are not met
        return new WP_Error( 'order_not_eligible', __( 'Order is not eligible for status change.', 'highriskshop-instant-payment-gateway-swaps' ), array( 'status' => 400 ) );
    }
}
?>