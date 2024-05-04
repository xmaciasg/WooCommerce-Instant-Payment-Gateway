<?php
if (!defined('ABSPATH')) {
    exit;
}

add_action('plugins_loaded', 'init_hrs_banxa_gateway');

function init_hrs_banxa_gateway() {
    if (!class_exists('WC_Payment_Gateway')) {
        return;
    }
	
class HighRiskShop_Instant_Payment_Gateway_Banxa extends WC_Payment_Gateway {

    public function __construct() {
        $this->id                 = 'highriskshop-instant-payment-gateway-banxa';
        $this->icon = sanitize_url($this->get_option('icon_url'));
        $this->method_title       = esc_html__('Instant Approval Payment Gateway with Instant Payouts (banxa.com)', 'highriskshopgateway'); // Escaping title
        $this->method_description = esc_html__('Instant Approval High Risk Merchant Gateway with instant payouts to your USDT POLYGON wallet using banxa.com infrastructure', 'highriskshopgateway'); // Escaping description
        $this->has_fields         = false;

        $this->init_form_fields();
        $this->init_settings();

        $this->title       = sanitize_text_field($this->get_option('title'));
        $this->description = sanitize_text_field($this->get_option('description'));

        // Use the configured settings for redirect and icon URLs
        $this->banxacom_wallet_address = sanitize_text_field($this->get_option('banxacom_wallet_address'));
        $this->icon_url     = sanitize_url($this->get_option('icon_url'));

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
    }

    public function init_form_fields() {
        $this->form_fields = array(
            'enabled' => array(
                'title'   => esc_html__('Enable/Disable', 'highriskshopgateway'), // Escaping title
                'type'    => 'checkbox',
                'label'   => esc_html__('Enable banxa.com payment gateway', 'highriskshopgateway'), // Escaping label
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
            'banxacom_wallet_address' => array(
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
        $hrs_banxacom_currency = get_woocommerce_currency();
		$hrs_banxacom_total = $order->get_total();
		$hrs_banxacom_nonce = wp_create_nonce( 'hrs_banxacom_nonce_' . $order_id );
		$hrs_banxacom_callback = add_query_arg(array('order_id' => $order_id, 'nonce' => $hrs_banxacom_nonce,), rest_url('custom-route/v1/hrs-banxacom/'));
		$hrs_banxacom_email = urlencode(sanitize_email($order->get_billing_email()));
		$hrs_banxacom_final_total = $hrs_banxacom_total;
	
$hrs_banxacom_gen_wallet = wp_remote_get('https://api.highriskshop.com/control/wallet.php?address=' . $this->banxacom_wallet_address .'&callback=' . urlencode($hrs_banxacom_callback));

if (is_wp_error($hrs_banxacom_gen_wallet)) {
    // Handle error
    wc_add_notice(__('Wallet error:', 'woocommerce') . __('Payment could not be processed due to incorrect payout wallet settings, please contact website admin', 'hrsbanxacom'), 'error');
    return null;
} else {
	$hrs_banxacom_wallet_body = wp_remote_retrieve_body($hrs_banxacom_gen_wallet);
	$hrs_banxacom_wallet_decbody = json_decode($hrs_banxacom_wallet_body, true);

 // Check if decoding was successful
    if ($hrs_banxacom_wallet_decbody && isset($hrs_banxacom_wallet_decbody['address_in'])) {
        // Store the address_in as a variable
        $hrs_banxacom_gen_addressIn = wp_kses_post($hrs_banxacom_wallet_decbody['address_in']);
        $hrs_banxacom_gen_polygon_addressIn = sanitize_text_field($hrs_banxacom_wallet_decbody['polygon_address_in']);
		$hrs_banxacom_gen_callback = sanitize_url($hrs_banxacom_wallet_decbody['callback_url']);
		// Save $banxacomresponse in order meta data
    $order->update_meta_data('highriskshop_banxacom_tracking_address', $hrs_banxacom_gen_addressIn);
    $order->update_meta_data('highriskshop_banxacom_polygon_temporary_order_wallet_address', $hrs_banxacom_gen_polygon_addressIn);
    $order->update_meta_data('highriskshop_banxacom_callback', $hrs_banxacom_gen_callback);
	$order->update_meta_data('highriskshop_banxacom_converted_amount', $hrs_banxacom_final_total);
    $order->save();
    } else {
        wc_add_notice(__('Payment error:', 'woocommerce') . __('Payment could not be processed, please try again (wallet address error)', 'banxacom'), 'error');

        return null;
    }
}

        // Redirect to payment page
        return array(
            'result'   => 'success',
            'redirect' => 'https://api.highriskshop.com/control/process-payment.php?address=' . $hrs_banxacom_gen_addressIn . '&amount=' . (float)$hrs_banxacom_final_total . '&provider=banxa&email=' . $hrs_banxacom_email . '&currency=' . $hrs_banxacom_currency,
        );
    }

}

function highriskshop_add_instant_payment_gateway_banxa($gateways) {
    $gateways[] = 'HighRiskShop_Instant_Payment_Gateway_Banxa';
    return $gateways;
}
add_filter('woocommerce_payment_gateways', 'highriskshop_add_instant_payment_gateway_banxa');
}

// Add custom endpoint for changing order status
function hrs_banxacom_change_order_status_rest_endpoint() {
    // Register custom route
    register_rest_route( 'custom-route/v1', '/hrs-banxacom/', array(
        'methods'  => 'GET',
        'callback' => 'hrs_banxacom_change_order_status_callback',
        'permission_callback' => '__return_true',
    ));
}
add_action( 'rest_api_init', 'hrs_banxacom_change_order_status_rest_endpoint' );

// Callback function to change order status
function hrs_banxacom_change_order_status_callback( $request ) {
    $order_id = absint($request->get_param( 'order_id' ));
	$hrs_banxacomgetnonce = sanitize_text_field($request->get_param( 'nonce' ));
	
	 // Verify nonce
    if ( empty( $hrs_banxacomgetnonce ) || ! wp_verify_nonce( $hrs_banxacomgetnonce, 'hrs_banxacom_nonce_' . $order_id ) ) {
        return new WP_Error( 'invalid_nonce', __( 'Invalid nonce.', 'highriskshop-instant-payment-gateway-banxa' ), array( 'status' => 403 ) );
    }

    // Check if order ID parameter exists
    if ( empty( $order_id ) ) {
        return new WP_Error( 'missing_order_id', __( 'Order ID parameter is missing.', 'highriskshop-instant-payment-gateway-banxa' ), array( 'status' => 400 ) );
    }

    // Get order object
    $order = wc_get_order( $order_id );

    // Check if order exists
    if ( ! $order ) {
        return new WP_Error( 'invalid_order', __( 'Invalid order ID.', 'highriskshop-instant-payment-gateway-banxa' ), array( 'status' => 404 ) );
    }

    // Check if the order is pending and payment method is 'highriskshop-instant-payment-gateway-banxa'
    if ( $order && $order->get_status() === 'pending' && 'highriskshop-instant-payment-gateway-banxa' === $order->get_payment_method() ) {
        // Change order status to processing
		 $order->payment_complete();
        $order->update_status( 'processing' );
        // Return success response
        return array( 'message' => 'Order status changed to processing.' );
    } else {
        // Return error response if conditions are not met
        return new WP_Error( 'order_not_eligible', __( 'Order is not eligible for status change.', 'highriskshop-instant-payment-gateway-banxa' ), array( 'status' => 400 ) );
    }
}
?>