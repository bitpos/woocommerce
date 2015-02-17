<?php
/*
Plugin Name: BitPOS plugin for WooCommerce 2.2.11
Plugin URI: https://bitpos.me	
Description: Accept Bitcoin payments with BitPOS!
Version: 1.0
Author URI: https://bitpos.me
*/

//---------------------------------------------------------------------------
add_action('plugins_loaded', 'woocommerce_load_bitpos_gateway', 0);
//---------------------------------------------------------------------------

//###########################################################################
// Hook payment gateway into WooCommerce

function woocommerce_load_bitpos_gateway ()
{

    /**
     * BitPOS Payment Gateway
     *
     */
    class BPS_Gateway extends WC_Payment_Gateway {

        var $notify_url;

        /**
         * Constructor for the gateway.
         *
         * @access public
         * @return void
         */
        public function __construct() {
            global $woocommerce;

            $this->id           = 'bitpos';
            $this->icon         = plugins_url('/assets/images/icons/bitpos_logo.png', __FILE__);
            $this->has_fields   = false;
            $this->prodgateway      = 'https://rest.bitpos.me';
            $this->testgateway      = 'https://rest.test.bitpos.me';
            $this->prodredirect     = 'https://payment.bitpos.me/payment.jsp';
            $this->testredirect     = 'https://payment.test.bitpos.me/payment.jsp';


            $this->method_title = __( 'BitPOS', 'woocommerce' );
            $this->notify_url   = str_replace( 'https:', 'http:', add_query_arg( 'wc-api', 'BPS_Gateway', home_url( '/' ) ) );

            // Load the settings.
            $this->init_form_fields();
            $this->init_settings();

            // Define user set variables
            $this->title                  = $this->get_option( 'title' );
            $this->description            = $this->get_option( 'description' );
            $this->email                  = $this->get_option( 'email' );
            $this->apikey                  = $this->get_option( 'apikey' );

            $this->receiver_email         = $this->get_option( 'receiver_email', $this->email );
            $this->testmode               = $this->get_option( 'testmode' );
            $this->debug                  = $this->get_option( 'debug' );
            $this->form_submission_method = $this->get_option( 'form_submission_method' ) == 'yes' ? true : false;
            $this->page_style             = $this->get_option( 'page_style' );
            $this->invoice_prefix         = $this->get_option( 'invoice_prefix', 'WC-' );

            // Logs
            if ( 'yes' == $this->debug )
                $this->log = new WC_Logger();

            // Actions
            add_action( 'woocommerce_receipt_bitpos', array( $this, 'receipt_page' ) );
            add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );

            add_action('woocommerce_payment_complete', 'custom_process_order', 10, 1);
            add_action('woocommerce_thankyou', array($this, 'orderSubmit'));


            if ( !$this->is_valid_for_use() ) $this->enabled = false;
        }


        /**
         * Check if this gateway is enabled and available in the user's country
         *
         * @access public
         * @return bool
         */
        function is_valid_for_use() {
            if ( ! in_array( get_woocommerce_currency(), apply_filters( 'woocommerce_bitpos_supported_currencies', array( 'AUD', 'BRL', 'CAD', 'MXN', 'NZD', 'HKD', 'SGD', 'USD', 'EUR', 'JPY', 'TRY', 'NOK', 'CZK', 'DKK', 'HUF', 'ILS', 'MYR', 'PHP', 'PLN', 'SEK', 'CHF', 'TWD', 'THB', 'GBP', 'RMB' ) ) ) ) return false;

            return true;
        }

        /**
         * Admin Panel Options
         * - Options for bits like 'title' and availability on a country-by-country basis
         *
         * @since 1.0.0
         */
        public function admin_options() {

            ?>
            <h3><?php _e( 'BitPOS WebPay', 'woocommerce' ); ?></h3>
            <p><?php _e( 'You will be redirected to BitPOS WebPay to complete your order with Bitcoin', 'woocommerce' ); ?></p>

            <?php if ( $this->is_valid_for_use() ) : ?>

                <table class="form-table">
                <?php
                    // Generate the HTML For the settings form.
                    $this->generate_settings_html();
                ?>
                </table><!--/.form-table-->

            <?php else : ?>
                <div class="inline error"><p><strong><?php _e( 'Gateway Disabled', 'woocommerce' ); ?></strong>: <?php _e( 'Sorry, BitPOS does not support your store currency.', 'woocommerce' ); ?></p></div>
            <?php
                endif;
        }


        /**
         * Initialise Gateway Settings Form Fields
         *
         * @access public
         * @return void
         */
        function init_form_fields() {

            $this->form_fields = array(
                'enabled' => array(
                                'title' => __( 'Enable/Disable', 'woocommerce' ),
                                'type' => 'checkbox',
                                'label' => __( 'Enable BitPOS WebPay', 'woocommerce' ),
                                'default' => 'yes'
                            ),
                'title' => array(
                                'title' => __( 'Title', 'woocommerce' ),
                                'type' => 'text',
                                'description' => __( 'This controls the title which the user sees during checkout.', 'woocommerce' ),
                                'default' => __( 'Bitcoin payment with BitPOS WebPay', 'woocommerce' ),
                                'desc_tip'      => true,
                            ),
                'description' => array(
                                'title' => __( 'Description', 'woocommerce' ),
                                'type' => 'textarea',
                                'description' => __( 'This controls the description which the user sees during checkout.', 'woocommerce' ),
                                'default' => __( 'Your payment will be completed using BitPOS WebPay', 'woocommerce' )
                            ),
                'email' => array(
                                'title' => __( 'BitPOS API Key', 'woocommerce' ),
                                'type'             => 'text',
                                'description' => __( 'Please enter your BitPOS API Key', 'woocommerce' ),
                                'default' => '',
                                'desc_tip'      => true,
                                'placeholder'    => 'dlcDIe9HftPrWDhMgn59nWwJcY1g2WGwXwZlXKpZ'
                            ),
                'apikey' => array(
                                'title' => __( 'BitPOS API Password', 'woocommerce' ),
                                'type'             => 'password',
                                'description' => __( 'Please enter your BitPOS API password', 'woocommerce' ),
                                'default' => '',
                                'desc_tip'      => true,
                                'placeholder'    => 'Enter your API Key'
                ),

                'invoice_prefix' => array(
                                'title' => __( 'Invoice Prefix', 'woocommerce' ),
                                'type' => 'text',
                                'description' => __( 'Please enter a prefix for your invoice numbers. ', 'woocommerce' ),
                                'default' => 'WC-',
                                'desc_tip'      => true,
                            ),

                'testing' => array(
                                'title' => __( 'Gateway Testing', 'woocommerce' ),
                                'type' => 'title',
                                'description' => '',
                            ),
                'testmode' => array(
                                'title' => __( 'BitPOS Test Server', 'woocommerce' ),
                                'type' => 'checkbox',
                                'label' => __( 'Enable BitPOS Test mode', 'woocommerce' ),
                                'default' => 'yes',
                                'description' => sprintf( __( 'BitPOS Test Mode can be used to test integration.  Signup <a href="%s">here</a> for a test account.', 'woocommerce' ), 'https://signup.test.bitpos.me' ),
                            ),
                'debug' => array(
                                'title' => __( 'Debug Log', 'woocommerce' ),
                                'type' => 'checkbox',
                                'label' => __( 'Enable logging', 'woocommerce' ),
                                'default' => 'no',
                                'description' => sprintf( __( 'Log BitPOS error messages, at <code>woocommerce/logs/bitpos-%s.txt</code>', 'woocommerce' ), sanitize_file_name( wp_hash( 'bitpos' ) ) ),
                            )
                );
        }


        /**
         * Get BitPOS Args for passing to PP
         *
         * @access public
         * @param mixed $order
         * @return array
         */
        function get_bitpos_args( $order ) {
            global $woocommerce;

            $order_id = $order->id;

            if ( 'yes' == $this->debug )
                $this->log->add( 'bitpos', 'Generating payment form for order ' . $order->get_order_number() . '. Notify URL: ' . $this->notify_url );

            if ( in_array( $order->billing_country, array( 'US','CA' ) ) ) {
                $order->billing_phone = str_replace( array( '( ', '-', ' ', ' )', '.' ), '', $order->billing_phone );
                $phone_args = array(
                    'night_phone_a' => substr( $order->billing_phone, 0, 3 ),
                    'night_phone_b' => substr( $order->billing_phone, 3, 3 ),
                    'night_phone_c' => substr( $order->billing_phone, 6, 4 ),
                    'day_phone_a'   => substr( $order->billing_phone, 0, 3 ),
                    'day_phone_b'   => substr( $order->billing_phone, 3, 3 ),
                    'day_phone_c'   => substr( $order->billing_phone, 6, 4 )
                );
            } else {
                $phone_args = array(
                    'night_phone_b' => $order->billing_phone,
                    'day_phone_b'     => $order->billing_phone
                );
            }

            //BitPOS Args
            $bitpos_args = array_merge(
                array(
                    'cmd'           => '_cart',
                    'business'      => $this->email,
                    'no_note'       => 1,
                    'currency_code' => get_woocommerce_currency(),
                    'charset'       => 'UTF-8',
                    'rm'            => is_ssl() ? 2 : 1,
                    'upload'        => 1,
                    'return'        => add_query_arg( 'utm_nooverride', '1', $this->get_return_url( $order ) ),
                    'cancel_return' => $order->get_cancel_order_url(),
                    'page_style'    => $this->page_style,

                    // Order key + ID
                    'invoice'       => $this->invoice_prefix . ltrim( $order->get_order_number(), '#' ),
                    'custom'        => serialize( array( $order_id, $order->order_key ) ),

                    // Billing Address info
                    'first_name'    => $order->billing_first_name,
                    'last_name'     => $order->billing_last_name,
                    'company'       => $order->billing_company,
                    'address1'      => $order->billing_address_1,
                    'address2'      => $order->billing_address_2,
                    'city'          => $order->billing_city,
                    'state'         => $order->billing_state,
                    'zip'           => $order->billing_postcode,
                    'country'       => $order->billing_country,
                    'email'         => $order->billing_email
                ),
                $phone_args
            );

            $bitpos_args['no_shipping'] = 1;

            $this->log->add('bitpos', 'Prices include tax: ' . get_option( 'woocommerce_prices_include_tax' ));
            $this->log->add('bitpos', 'Order discount: ' . $order->get_order_discount());


            // If prices include tax or have order discounts, send the whole order as a single item
            if ( get_option( 'woocommerce_prices_include_tax' ) == 'yes' || $order->get_order_discount() > 0 ) {
                $this->log->add('bitpos', 'Prices include tax, or there is an order discount');

                // Discount
                $bitpos_args['discount_amount_cart'] = $order->get_order_discount();

                $item_names = array();

                if ( sizeof( $order->get_items() ) > 0 )
                    foreach ( $order->get_items() as $item )
                        if ( $item['qty'] )
                            $item_names[] = $item['name'] . ' x ' . $item['qty'];

                $bitpos_args['item_name_1']     = sprintf( __( 'Order %s' , 'woocommerce'), $order->get_order_number() ) . " - " . implode( ', ', $item_names );
                $bitpos_args['quantity_1']         = 1;
                $bitpos_args['amount_1']         = number_format( $order->get_total(), 2, '.', ''); // - $order->get_shipping() - $order->get_shipping_tax() + $order->get_order_discount(), 2, '.', '' );


                if ( ( $order->get_shipping() + $order->get_shipping_tax() ) > 0 ) {
                    $bitpos_args['item_name_2'] = __( 'Shipping via', 'woocommerce' ) . ' ' . ucwords( $order->shipping_method_title );
                    $bitpos_args['quantity_2']     = '1';
                    $bitpos_args['amount_2']     = number_format( $order->get_shipping() + $order->get_shipping_tax() , 2, '.', '' );
                }

            } else {

                // Tax
                $bitpos_args['tax_cart'] = $order->get_total_tax();

                $this->log->add('bitpos', 'Prices exclude tax');

                $amount_total = 0;

                // Cart Contents
                $item_loop = 0;
                if ( sizeof( $order->get_items() ) > 0 ) {
                    foreach ( $order->get_items() as $item ) {
                        if ( $item['qty'] ) {

                            $item_loop++;

                            $product = $order->get_product_from_item( $item );

                            $item_name     = $item['name'];

                            $item_meta = new WC_Order_Item_Meta( $item['item_meta'] );
                            if ( $meta = $item_meta->display( true, true ) )
                                $item_name .= ' ( ' . $meta . ' )';

                            $bitpos_args[ 'item_name_' . $item_loop ]     = $item_name;
                            $bitpos_args[ 'quantity_' . $item_loop ]     = $item['qty'];
                            $bitpos_args[ 'amount_' . $item_loop ]         = $order->get_item_subtotal( $item, false );

                            $this->log->add('bitpos', 'Item ' . $item_loop . ' name: ' . $item_name);
                            $this->log->add('bitpos', 'Item ' . $item_loop . ' quantity: ' . $item['qty']);
                            $this->log->add('bitpos', 'Item ' . $item_loop . ' amount: ' . $order->get_item_subtotal($item, false));

                            if ( $product->get_sku() )
                                $bitpos_args[ 'item_number_' . $item_loop ] = $product->get_sku();
                        }
                    }
                }

                $this->log->add('bitpos', 'Order discount: ' . $order->get_order_discount());
                $this->log->add('bitpos', 'Cart discount: ' . $order->get_cart_discount());


                // Discount
                if ( $order->get_cart_discount() > 0 )
                    $bitpos_args['discount_amount_cart'] = round( $order->get_cart_discount(), 2 );

                // Fees
                if ( sizeof( $order->get_fees() ) > 0 ) {
                    foreach ( $order->get_fees() as $item ) {
                        $item_loop++;

                        $bitpos_args[ 'item_name_' . $item_loop ]     = $item['name'];
                        $bitpos_args[ 'quantity_' . $item_loop ]     = 1;
                        $bitpos_args[ 'amount_' . $item_loop ]         = $item['line_total'];
                    }
                }

                if ( $order->get_shipping() > 0 ) {
                    $item_loop++;
                    $bitpos_args[ 'item_name_' . $item_loop ]     = __( 'Shipping via', 'woocommerce' ) . ' ' . ucwords( $order->shipping_method_title );
                    $bitpos_args[ 'quantity_' . $item_loop ]     = '1';
                    $bitpos_args[ 'amount_' . $item_loop ]         = number_format( $order->get_shipping(), 2, '.', '' );
                }

                $bitpos_args['amount_1']         = number_format( $order->get_total(), 2, '.', '');
                $this->log->add('bitpos', 'Args total: ' . $bitpos_args['amount_1']);



                $this->log->add('bitpos', 'Order Total: ' . $order->get_total());
                $this->log->add('bitpos', 'Order Shipping: ' . $order->get_shipping());
                $this->log->add('bitpos', 'Order Shipping tax: ' . $order->get_shipping_tax());
                $this->log->add('bitpos', 'Order Discount: ' . $order->get_order_discount());


            }

            $bitpos_args = apply_filters( 'woocommerce_bitpos_args', $bitpos_args );

            return $bitpos_args;
        }


        function orderSubmit($order_id)
        {
            global $woocommerce;
            $this->log->add('bitpos', 'Received order'); // happens 4 times

            $order = new WC_Order( $order_id );
            $myuser_id = (int)$order->user_id;
            $user_info = get_userdata($myuser_id);


            $this->log->add('bitpos', 'Order id: ' . $order_id);

            $notes = $order->get_customer_order_notes();
            foreach ($notes as $note)
            {
                $this->log->add('bitpos', 'Note: ' . $note->comment_content);

                if (strpos($note->comment_content, "OID:") == 0) {
                    $realOrderId = str_replace("OID:", "", $note->comment_content);

                    //Get which gateway we're using of the order...
                    if ( $this->testmode == 'yes' ):
                        $url = $this->testgateway;
                    else:
                        $url = $this->prodgateway;
                    endif;

                    $url .= "/services/webpay/order/status/" . $realOrderId;
                    $this->log->add('bitpos', ' ***1*** URL: ' . $url);

                    $ch = curl_init();
                    curl_setopt($ch,CURLOPT_URL,$url);
                    curl_setopt($ch, CURLOPT_USERPWD, $this->email . ":" . $this->apikey);
                    curl_setopt($ch, CURLOPT_HTTPHEADER,array('Content-Type: application/json'));
                    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
                    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

                    $result = curl_exec($ch);
                    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

                    $this->log->add( 'bitpos', 'HTTP Result: ' . $result);
                    $this->log->add( 'bitpos', 'HTTP Code: ' . $httpCode);
                    $this->log->add( 'bitpos', 'User:  ' . $this->email);
//                    $this->log->add( 'bitpos', 'Key: ' . $this->apikey);
                    $this->log->add( 'bitpos', 'Curl error: ' . curl_error($ch));
                    $this->log->add( 'bitpos', 'Result: ' . $result);

                    $resArray = json_decode($result);

                    if ($resArray->{'status'} == 'RECEIVED_BROADCAST' || $resArray->{'status'} == 'CONFIRMED')
                    {
                        $order->payment_complete();
                    }

                }
            }
        }

        function custom_process_order($order_id) {
            $this->log->add('bitpos', 'Order received: ' . $order_id);

            $order = new WC_Order( $order_id );
            $myuser_id = (int)$order->user_id;
            $user_info = get_userdata($myuser_id);
            $items = $order->get_items();
            foreach ($items as $item) {
                if ($item['product_id']==24) {
                    // Do something clever
                }
            }
            return $order_id;
        }


        /**
         * Generate the bitpos button link
         *
         * @access public
         * @param mixed $order_id
         * @return string
         */
        function generate_bitpos_form( $order_id ) {
            global $woocommerce;

            $order = new WC_Order( $order_id );


            ///////////////
            $bitpos_args = $this->get_bitpos_args( $order );

            $inputArgs = array('currency' => $bitpos_args['currency_code'], 'amount' => intval($bitpos_args['amount_1'] * 100),
                'reference' => $bitpos_args['invoice'], 'description' => $bitpos_args['item_name_1'], 'successURL' => $bitpos_args['return'], 'failureURL' => $bitpos_args['cancel_return']);

            if ( $this->testmode == 'yes' ):
                $url = $this->testgateway;
            else :
                $url = $this->prodgateway;
            endif;

            $url .= "/services/webpay/order/create";

            $data = json_encode($inputArgs);
            $ch = curl_init();
            curl_setopt($ch,CURLOPT_URL,$url);
            curl_setopt($ch, CURLOPT_USERPWD, $this->email . ":" . $this->apikey);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");  //for updating we have to use PUT method.
            curl_setopt($ch, CURLOPT_HTTPHEADER,array('Content-Type: application/json'));
            curl_setopt($ch,CURLOPT_POSTFIELDS,$data);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

            $result = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

            $this->log->add( 'bitpos', 'HTTP Result: ' . $result);
            $this->log->add( 'bitpos', 'HTTP Code: ' . $httpCode);
            $this->log->add( 'bitpos', 'JSON Data: ' . $data);
            $this->log->add( 'bitpos', 'User:  ' . $this->email);
//            $this->log->add( 'bitpos', 'Key: ' . $this->apikey);
            $this->log->add( 'bitpos', 'Curl error: ' . curl_error($ch));
            $this->log->add( 'bitpos', 'Result: ' . $result);
            $this->log->add( 'bitpos', 'ReturnURL:' . $bitpos_args['return']);
            $this->log->add( 'bitpos', 'CancelReturnURL:' . $bitpos_args['cancel_return']);


            $resArray = json_decode($result);
            $encodedOrderId = $resArray->{'encodedOrderId'};

            $order->add_order_note('OID:' . $encodedOrderId, 1);

            curl_close($ch);
            ////////////////

            if ( $this->testmode == 'yes' ):
                $bitpos_adr = $this->testredirect;
            else :
                $bitpos_adr = $this->prodredirect;
            endif;

            $bitpos_adr .= '?orderId=' . $encodedOrderId;


            $bitpos_args_array = array();

            foreach ($bitpos_args as $key => $value) {
                $bitpos_args_array[] = '<input type="hidden" name="'.esc_attr( $key ).'" value="'.esc_attr( $value ).'" />';
            }

            $woocommerce->add_inline_js( '
                jQuery("body").block({
                        message: "' . __( 'Thank you for your order. We are now redirecting you to BitPOS to make payment.', 'woocommerce' ) . '",
                        baseZ: 99999,
                        overlayCSS:
                        {
                            background: "#fff",
                            opacity: 0.6
                        },
                        css: {
                            padding:        "20px",
                            zindex:         "9999999",
                            textAlign:      "center",
                            color:          "#555",
                            border:         "3px solid #aaa",
                            backgroundColor:"#fff",
                            cursor:         "wait",
                            lineHeight:     "24px",
                        }
                    });
                jQuery("#submit_bitpos_payment_form").click();
            ' );

            return '<form action="'.esc_url( $bitpos_adr ).'" method="post" id="bitpos_payment_form" target="_top">
                    ' . implode( '', $bitpos_args_array) . '
                    <input type="submit" class="button-alt" id="submit_bitpos_payment_form" value="'.__( 'Pay via BitPOS', 'woocommerce' ).'" /> <a class="button cancel" href="'.esc_url( $order->get_cancel_order_url() ).'">'.__( 'Cancel order &amp; restore cart', 'woocommerce' ).'</a>
                </form>';

        }


        /**
         * Process the payment and return the result
         *
         * @access public
         * @param int $order_id
         * @return array
         */
        function process_payment( $order_id ) {

            $order = new WC_Order( $order_id );


                return array(
                    'result'     => 'success',
                    'redirect'    => add_query_arg('order', $order->id, add_query_arg('key', $order->order_key,  $order->get_checkout_payment_url(true)))
                );

  //          }

        }


        /**
         * Output for the order received page.
         *
         * @access public
         * @return void
         */
        function receipt_page( $order ) {

            $this->log = new WC_Logger();
            echo '<p>'.__( 'Thank you for your order, please click the button below to pay with BitPOS.', 'woocommerce' ).'</p>';

            $this->log->add('bitpos', '----------------------------');

            echo $this->generate_bitpos_form( $order );

        }


        /**
         * get_bitpos_order function.
         *
         * @access public
         * @param mixed $posted
         * @return void
         */
        function get_bitpos_order( $posted ) {
            $custom = maybe_unserialize( $posted['custom'] );

            if ( is_numeric( $custom ) ) {
                $order_id = (int) $custom;
                $order_key = $posted['invoice'];
            } elseif( is_string( $custom ) ) {
                $order_id = (int) str_replace( $this->invoice_prefix, '', $custom );
                $order_key = $custom;
            } else {
                list( $order_id, $order_key ) = $custom;
            }

            $order = new WC_Order( $order_id );

            if ( ! isset( $order->id ) ) {
                // We have an invalid $order_id, probably because invoice_prefix has changed
                $order_id     = woocommerce_get_order_id_by_order_key( $order_key );
                $order         = new WC_Order( $order_id );
            }

            // Validate key
            if ( $order->order_key !== $order_key ) {
                if ( $this->debug=='yes' )
                    $this->log->add( 'bitpos', 'Error: Order Key does not match invoice.' );
                exit;
            }

            return $order;
        }

    }

    class WC_BitPOS extends BPS_Gateway {
        public function __construct() {
            _deprecated_function( 'WC_BitPOS', '1.4', 'BPS_Gateway' );
            parent::__construct();
        }
    }
}

add_filter ('woocommerce_payment_gateways', 'BPS__add_bitpos_gateway' );
add_filter ('woocommerce_currencies',       'BPS__add_btc_currency');
add_filter ('woocommerce_currency_symbol',  'BPS__add_btc_currency_symbol', 10, 2);

/**
 * Add the gateway to WooCommerce
 *
 * @access public
 * @param array $methods
 * @package
 * @return array
 */
function BPS__add_bitpos_gateway($methods)
{
    $methods[] = 'BPS_Gateway';
    return $methods;
}

function BPS__add_btc_currency($currencies)
{
    $currencies['BTC'] = __( 'Bitcoin (฿)', 'woocommerce' );
    return $currencies;
}
//=======================================================================

//=======================================================================
function BPS__add_btc_currency_symbol($currency_symbol, $currency)
{
    switch( $currency )
    {
        case 'BTC':
            $currency_symbol = '฿';
            break;
    }

    return $currency_symbol;
}
