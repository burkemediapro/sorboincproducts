    <?php

    // Defines
    define( 'FL_CHILD_THEME_DIR', get_stylesheet_directory() );
    define( 'FL_CHILD_THEME_URL', get_stylesheet_directory_uri() );

    // Classes
    require_once 'classes/class-fl-child-theme.php';

    // Actions
    add_action( 'wp_enqueue_scripts', 'FLChildTheme::enqueue_scripts', 1000 );



    // WooCommerce Tweaks


    // Change "Coupon" to "Discount"

    add_filter( 'gettext', 'change_coupon_to_discount', 10, 3 );
    add_filter( 'ngettext', 'change_coupon_to_discount', 10, 3 );

    function change_coupon_to_discount( $translated, $text, $domain ) {
        // Target text domain for WooCommerce
        if ( $domain === 'woocommerce' ) {
            // Replace "Coupon" with "Discount"
            $translated = str_ireplace( 'Coupon', 'Discount', $translated );
        }
        return $translated;
    }

    // Allow Pay By Invoice Online based on Custom Shipping Address 
    // Enable Pay By Invoice if custom_shipping_address contains "USA" or "United States"
    add_filter( 'woocommerce_available_payment_gateways', 'conditionally_show_pay_later_gateway', 10, 1 );
    function conditionally_show_pay_later_gateway( $available_gateways ) {
        // Retrieve custom shipping address from the session
        $custom_address = WC()->session->get('custom_shipping_address');

        // Check if address contains "USA" or "United States"
        if ( ! preg_match('/\b(USA|United States)\b/i', $custom_address) ) {
            if ( isset( $available_gateways['wf_pay_later'] ) ) {
                unset( $available_gateways['wf_pay_later'] ); // Remove Pay Later if condition fails
            }
        }

        return $available_gateways;
    }


    // Listen for custom_shipping_address field changes and update session
    add_action( 'wp_footer', 'custom_shipping_address_listener' );
    function custom_shipping_address_listener() {
        if ( is_checkout() ) :
        ?>
        <script type="text/javascript">
            jQuery(function($) {
                $('#custom_shipping_address').on('change', function() {
                    let custom_address = $(this).val();
                    $.ajax({
                        type: 'POST',
                        url: wc_add_to_cart_params.ajax_url,
                        data: {
                            action: 'update_custom_shipping_session',
                            custom_shipping_address: custom_address
                        },
                        success: function() {
                            $('body').trigger('update_checkout'); // Refresh checkout options
                        }
                    });
                });
            });
        </script>
        <?php
        endif;
    }

    // Handle AJAX request to update session
    add_action('wp_ajax_update_custom_shipping_session', 'update_custom_shipping_session');
    add_action('wp_ajax_nopriv_update_custom_shipping_session', 'update_custom_shipping_session');
    function update_custom_shipping_session() {
        if ( isset($_POST['custom_shipping_address']) ) {
            $address = sanitize_textarea_field($_POST['custom_shipping_address']);
            WC()->session->set('custom_shipping_address', $address); // Save to session
        }
        wp_die();
    }




    // Ensure WooCommerce updates payment methods dynamically
    add_action( 'wp_footer', 'enable_checkout_field_listener' );
    function enable_checkout_field_listener() {
        if ( is_checkout() && ! is_wc_endpoint_url( 'order-received' ) ) :
        ?>
            <script type="text/javascript">
                jQuery(function($) {
                    // Monitor the custom shipping address field for changes
                    $('#custom_shipping_address').on('change', function() {
                        $('body').trigger('update_checkout'); // Trigger WooCommerce AJAX to refresh payment methods
                    });
                });
            </script>
        <?php
        endif;
    }





    // Automatic Discounts for Customers Outside of the US
    add_action( 'woocommerce_cart_calculate_fees', 'apply_tiered_discounts_for_non_us_customers', 20 );
    function apply_tiered_discounts_for_non_us_customers( $cart ) {

        // Avoid applying discounts in admin or during AJAX requests
        if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
            return;
        }

        // Get the customer's billing country
        $billing_country = WC()->customer->get_billing_country();

        // Exit if the customer is from the US
        if ( strtoupper( $billing_country ) === 'US' ) {
            return;
        }

        // Get cart subtotal before taxes and discounts
        $subtotal = $cart->get_subtotal();

        // Initialize discount values
        $ten_percent_discount = 0;
        $two_percent_discount = 0;

        // Check for orders over $35,000 to apply 10% discount
        if ( $subtotal >= 35000 ) {
            $ten_percent_discount = $subtotal * 0.10; // 10% of the subtotal
            $discounted_total = $subtotal - $ten_percent_discount; // New total after 10% discount

            // Add 10% discount as a fee
            $cart->add_fee( __( '10% Discount for orders over $35,000.', 'woocommerce' ), -$ten_percent_discount );

            // If the discounted total is over $50,000, apply an additional 2% discount
            if ( $discounted_total >= 50000 ) {
                $two_percent_discount = $discounted_total * 0.02; // 2% of the discounted total
            }
        }

        // Add the 2% discount (after 10%) as a separate fee if applicable
        if ( $two_percent_discount > 0 ) {
            $cart->add_fee( __( 'Additional 2% Discount', 'woocommerce' ), -$two_percent_discount );
        }

        // Store the new total (final price after discounts) to display it
        $final_total = $discounted_total - $two_percent_discount;
        $cart->final_total = $final_total; // Store final total to display
    }

    // Display the new total after discounts on the cart and checkout pages
    add_action( 'woocommerce_cart_totals_after_order_total', 'display_final_total_after_discounts_for_non_us_customers', 20 );
    function display_final_total_after_discounts_for_non_us_customers() {
        global $cart;

        if ( isset( $cart->final_total ) ) {
            echo '<tr class="order-total">';
            echo '<th>' . __( 'Total After Discounts', 'woocommerce' ) . '</th>';
            echo '<td>' . wc_price( $cart->final_total ) . '</td>';
            echo '</tr>';
        }
    }

    // Message for Shipping TBD or Free Shipping

    add_action( 'woocommerce_cart_totals_before_order_total', 'add_shipping_status_line' );
    add_action( 'woocommerce_review_order_before_order_total', 'add_shipping_status_line' );

    function add_shipping_status_line() {
        $subtotal = WC()->cart->get_subtotal(); // Get the cart subtotal
        $billing_country = WC()->customer->get_billing_country(); // Get the billing country

        // Determine shipping message
        $shipping_message = 'Shipping TBD';
        if ( strtoupper( $billing_country ) === 'US' && $subtotal >= 2400 ) {
            $shipping_message = 'Free Shipping';
        }

        // Display the shipping message
        echo '<tr class="shipping-status">';
        echo '<th>' . __( 'Shipping', 'woocommerce' ) . '</th>';
        echo '<td>' . esc_html( $shipping_message ) . '</td>';
        echo '</tr>';
    }







    // Hide "Pay" Button Until Order is on Status "Pending Payment" and Shipping Country is the US
    add_filter( 'woocommerce_my_account_my_orders_actions', 'restrict_pay_button_to_pending_payment_and_us', 10, 2 );
    function restrict_pay_button_to_pending_payment_and_us( $actions, $order ) {
        // Check the status of the order and shipping country
        if ( $order->get_status() !== 'pending' || WC()->customer->get_shipping_country() !== 'US' ) {
            // Remove the "pay" action if the order is not pending or if shipping country is not the US
            unset( $actions['pay'] );
        }
        return $actions;
    }




    // Removing "Cancel Order" Button

    add_filter( 'woocommerce_my_account_my_orders_actions', 'remove_cancel_button_for_pending_payment', 10, 2 );
    function remove_cancel_button_for_pending_payment( $actions, $order ) {
        // Check if the order is in "pending payment" status
        if ( $order->get_status() === 'pending' ) {
            // Remove the "cancel" action
            unset( $actions['cancel'] );
        }
        return $actions;
    }




    // 3% Charge

    // Add a 3% processing fee when the payment method is "wf_pay_later" and the shipping country is the US
    add_action('woocommerce_cart_calculate_fees', 'add_surcharge_for_wf_pay_later_us_shipping');
    function add_surcharge_for_wf_pay_later_us_shipping() {
        if (is_admin() && !defined('DOING_AJAX')) {
            return; // Avoid applying the surcharge in admin or during AJAX operations
        }

        // Get the shipping country
        $shipping_country = WC()->customer->get_shipping_country();

        // Check if the payment method is "wf_pay_later" and the shipping country is the US
        if (isset($_POST['payment_method']) && $_POST['payment_method'] === 'wf_pay_later' && $shipping_country === 'US') {
            // Define the surcharge percentage
            $surcharge_percentage = 0.03; // 3%

            // Calculate the surcharge based on cart total (subtotal + shipping)
            $cart_total = WC()->cart->cart_contents_total + WC()->cart->get_shipping_total();
            $surcharge = $cart_total * $surcharge_percentage;

            // Add surcharge to the cart
            WC()->cart->add_fee(__('Processing Fee (3%)', 'woocommerce'), $surcharge, false);
        }
    }



    // Add meta field when "Pay By Invoice Online" is selected

    add_action('woocommerce_checkout_update_order_meta', 'set_pay_by_invoice_online_meta');
    function set_pay_by_invoice_online_meta($order_id) {
        $payment_method = isset($_POST['payment_method']) ? sanitize_text_field($_POST['payment_method']) : '';

        // Check if 'wf_pay_later' is selected and add meta
        if ($payment_method === 'wf_pay_later') {
            update_post_meta($order_id, '_pay_by_invoice_online', 'yes'); // Set meta field to 'yes'
        } else {
            update_post_meta($order_id, '_pay_by_invoice_online', 'no');  // Set meta field to 'no'
        }
    }


    // Display the custom meta field in the order admin area
    add_action('woocommerce_admin_order_data_after_billing_address', 'display_pay_by_invoice_online_meta', 10, 1);
    function display_pay_by_invoice_online_meta($order) {
        $pay_by_invoice_online = get_post_meta($order->get_id(), '_pay_by_invoice_online', true);
        echo '<p><strong>' . __('Pay By Invoice Online:', 'woocommerce') . '</strong> ' . esc_html($pay_by_invoice_online) . '</p>';
    }


    // Backend Order Statuses

    add_filter( 'wc_order_statuses', 'customize_backend_order_statuses', 10, 1 );

    function customize_backend_order_statuses( $order_statuses ) {
        // Remove the "Order Request" status from the backend
        if ( isset( $order_statuses['wc-order-request'] ) ) {
            unset( $order_statuses['wc-order-request'] );
        }

        // Reorder the remaining statuses
        $custom_order = array(
            'wc-draft'         => _x( 'Draft', 'Order status', 'woocommerce' ),
            'wc-on-hold'       => _x( 'On Hold', 'Order status', 'woocommerce' ),
            'wc-pending'       => _x( 'Pending Payment', 'Order status', 'woocommerce' ),
            'wc-processing'    => _x( 'Processing', 'Order status', 'woocommerce' ),
            'wc-completed'     => _x( 'Completed', 'Order status', 'woocommerce' ),
            'wc-cancelled'     => _x( 'Cancelled', 'Order status', 'woocommerce' ),
            'wc-failed'        => _x( 'Failed', 'Order status', 'woocommerce' ),
        );

        // Return only the custom order
        return array_intersect_key( $custom_order, $order_statuses );
    }


    //Email when client pays by credit card

    // Change the subject line based on payment method
    add_filter('woocommerce_email_subject_customer_processing_order', 'custom_subject_for_credit_card', 10, 2);
    function custom_subject_for_credit_card($subject, $order) {
        $payment_method = $order->get_payment_method();
        // Check for your credit card payment method (e.g., 'stripe')
        if ($payment_method === 'stripe') {
            $subject = 'Your Order Has Been Successfully Paid!';
        }
        return $subject;
    }

    // Change the heading based on payment method
    add_filter('woocommerce_email_heading_customer_processing_order', 'custom_heading_for_credit_card', 10, 2);
    function custom_heading_for_credit_card($heading, $order) {
        $payment_method = $order->get_payment_method();
        if ($payment_method === 'stripe') {
            $heading = 'Thank you for your payment!';
        }
        return $heading;
    }

    // Add custom content for credit card payments before the order table
    add_action('woocommerce_email_before_order_table', 'add_custom_content_for_credit_card', 10, 4);
    function add_custom_content_for_credit_card($order, $sent_to_admin, $plain_text, $email) {
        $payment_method = $order->get_payment_method();
        // Ensure this applies only to the customer_processing_order email
        if ($email && $email->id === 'customer_processing_order' && $payment_method === 'stripe') {
            echo '<p>We’ve received your credit card payment and will start processing your order right away. Thanks for choosing Sörbo Products, Inc.!</p>';
        }
    }


    // Different message on confirmation page after credit card payment
    add_filter( 'woocommerce_thankyou_order_received_text', 'change_thankyou_text_for_credit_card', 10, 2 );
    function change_thankyou_text_for_credit_card( $text, $order ) {
        // Check if order is an instance of WC_Order for safety
        if ( ! $order instanceof WC_Order ) {
            return $text;
        }

        // Get the payment method
        $payment_method = $order->get_payment_method();
        
        // If it's a credit card payment method, change the text
        if ( 'stripe' === $payment_method ) { // Adjust 'stripe' if your payment method ID differs
            return "Thank you. Your payment has been received.";
        }

        // Otherwise, return the original text
        return $text;
    }


    /**
    * Custom Shipping Address with URL Query Auto-Population for WooCommerce
    */

    // STEP 1: Replace Default Shipping Fields with a Single Custom Textarea Field
    add_filter('woocommerce_checkout_fields', 'replace_shipping_fields');
    function replace_shipping_fields($fields) {
        // Remove all default shipping fields
        unset($fields['shipping']);
        unset($fields['billing']['billing_address_1']); // Optional - remove billing address if needed
        
        // Add custom single-address textarea field (3 lines) with NO default value
        $fields['shipping']['custom_shipping_address'] = array(
            'type'        => 'textarea', // Textarea field
            'label'       => __('Shipping Address', 'woocommerce'),
            'placeholder' => __('Enter your shipping address', 'woocommerce'), // Placeholder text
            'required'    => true,
            'class'       => array('form-row-wide'),
            'priority'    => 10,
            'custom_attributes' => array(
                'rows' => '3', // Makes it 3 lines tall
            ),
        );
        return $fields;
    }

    // STEP 2: Auto-Populate Custom Textarea Field Using URL Queries
    add_action('wp_enqueue_scripts', 'auto_populate_custom_shipping_field');
    function auto_populate_custom_shipping_field() {
        if ( ! is_checkout() ) return;

        ?>
        <script>
            document.addEventListener("DOMContentLoaded", function() {
                // Function to get query parameters
                function getQueryParam(param) {
                    const urlParams = new URLSearchParams(window.location.search);
                    return urlParams.get(param) || ''; // Return value or empty string
                }

                // Build custom shipping address format with line breaks
                const address = getQueryParam('address');
                const city = getQueryParam('city');
                const state = getQueryParam('state');
                const zip = getQueryParam('zip');
                const country = getQueryParam('country');

                // Format the address with line breaks
                if (address && city && state && zip && country) {
                    const fullAddress = `${address}\n${city}, ${state} ${zip}\n${country}`; // Line breaks added
                    const shippingField = document.querySelector('[name="custom_shipping_address"]');
                    if (shippingField) {
                        shippingField.value = fullAddress; // Auto-fill field
                        shippingField.dispatchEvent(new Event('change')); // Trigger WooCommerce's AJAX validation
                    }
                }
            });
        </script>
        <?php
    }

    // STEP 3: Save Custom Shipping Address to Order Meta
    add_action('woocommerce_checkout_update_order_meta', 'save_custom_shipping_address');
    function save_custom_shipping_address($order_id) {
        if ( ! empty($_POST['custom_shipping_address']) ) {
            update_post_meta($order_id, '_custom_shipping_address', sanitize_textarea_field($_POST['custom_shipping_address']));
        }
    }

    // STEP 4: Display Custom Shipping Address in Admin Order Details
    add_action('woocommerce_admin_order_data_after_shipping_address', 'admin_display_custom_shipping_address', 10, 1);
    function admin_display_custom_shipping_address($order) {
        $custom_address = get_post_meta($order->get_id(), '_custom_shipping_address', true);
        if ($custom_address) {
            echo '<p><strong>Shipping Address:</strong><br>' . nl2br(esc_html($custom_address)) . '</p>'; // Preserve line breaks in admin view
        }
    }

    // STEP 5: Include Custom Address in Emails
    add_action('woocommerce_email_customer_details', 'email_stylized_custom_shipping_address', 25, 4);
    function email_stylized_custom_shipping_address($order, $sent_to_admin, $plain_text, $email) {
        // Fetch the custom shipping address
        $custom_address = get_post_meta($order->get_id(), '_custom_shipping_address', true);

        // Only output if a custom address exists
        if ($custom_address) {
            ?>
            <table cellspacing="0" cellpadding="6" border="0" width="100%" style="vertical-align:top;border-collapse:collapse">
                <tbody>
                    <tr>
                        <td valign="top" width="50%" style="text-align:left;font-family:'Helvetica Neue',Helvetica,Roboto,Arial,sans-serif;border:0;padding:0" align="left">
                            <h2 style="color:#2d286d;display:block;font-family:'Helvetica Neue',Helvetica,Roboto,Arial,sans-serif;font-size:18px;font-weight:bold;line-height:130%;margin:0 0 18px;text-align:left">
                                <?php esc_html_e( 'Shipping address', 'woocommerce' ); ?>
                            </h2>

                            <address style="padding:12px;color:#636363;border:1px solid #e5e5e5">
                                <?php echo nl2br(esc_html($custom_address)); ?>
                            </address>
                        </td>
                    </tr>
                </tbody>
            </table>
            <?php
        }
    }






    // Remove "Ship to a Different Address?" checkbox and always show shipping fields


    add_filter('woocommerce_checkout_fields', 'remove_ship_to_different_address_checkbox');
    function remove_ship_to_different_address_checkbox($fields) {
        // Force the shipping fields to always be visible
        add_filter('woocommerce_cart_needs_shipping_address', '__return_true');

        // Remove the checkbox from checkout
        add_filter('woocommerce_checkout_show_shipping', '__return_true');
        
        return $fields;
    }

    // Remove the checkbox itself from rendering
    add_filter('woocommerce_ship_to_different_address_checked', '__return_true');

    // Hide the "Ship to a different address?" label with CSS
    add_action('wp_head', 'hide_ship_to_different_address_css');
    function hide_ship_to_different_address_css() {
        if (is_checkout()) {
            echo '<style>#ship-to-different-address { display: none !important; }</style>';
        }
    }




    // Rename "Addresses" Tab to "Billing Address" and Change Link
    add_filter('woocommerce_account_menu_items', 'rename_addresses_tab', 10, 1);
    function rename_addresses_tab($items) {
        // Rename the "Addresses" tab
        if (isset($items['edit-address'])) {
            $items['edit-address'] = __('Billing Address', 'woocommerce'); // Change the tab name
        }
        return $items;
    }

    // Redirect the "Billing Address" tab to Billing Address Edit Page
    add_filter('woocommerce_get_endpoint_url', 'redirect_addresses_tab_to_billing', 10, 4);
    function redirect_addresses_tab_to_billing($url, $endpoint, $value, $permalink) {
        if ($endpoint === 'edit-address') {
            // Redirect to the billing address edit page
            return site_url('/my-account/edit-address/billing/');
        }
        return $url;
    }








    // Add "My Company" Tab in My Account Menu
    add_filter('woocommerce_account_menu_items', 'add_my_company_tab', 10, 1);
    function add_my_company_tab($items) {
        // Insert "My Company" between Dashboard and Orders
        $new_items = array();

        foreach ($items as $key => $item) {
            $new_items[$key] = $item;
            if ($key === 'dashboard') { // Add after Dashboard
                $new_items['my-company'] = __('My Company', 'woocommerce');
            }
        }

        return $new_items;
    }

    // Register Endpoint for "My Company" Tab
    add_action('init', 'add_my_company_endpoint');
    function add_my_company_endpoint() {
        add_rewrite_endpoint('my-company', EP_ROOT | EP_PAGES);
    }

    // Content for "My Company" Tab
    add_action('woocommerce_account_my-company_endpoint', 'my_company_content');
    function my_company_content() {
        echo do_shortcode('[template id=3559]'); // Load the shortcode
    }

    // Flush Rewrite Rules on Activation
    function my_company_flush_rewrite_rules() {
        add_my_company_endpoint();
        flush_rewrite_rules();
    }
    register_activation_hook(__FILE__, 'my_company_flush_rewrite_rules');



    // Add dynamic menu items to My Account navigation
    add_filter('woocommerce_account_menu_items', 'dynamic_order_checkout_and_support_links', 10, 1);

    function dynamic_order_checkout_and_support_links($items) {
        // Check if the cart has items
        $cart_count = WC()->cart->get_cart_contents_count();

        // Determine label and URL dynamically for the order/checkout link
        $label = $cart_count > 0 ? __('Checkout', 'woocommerce') : __('Start An Order', 'woocommerce');
        $url   = $cart_count > 0 
            ? wc_get_checkout_url() // Checkout URL if cart has items
            : wc_get_page_permalink('shop'); // Shop URL if cart is empty

        // Insert the "Order/Checkout" link as the 3rd item
        $items = array_slice($items, 0, 2, true) + // Take first two items
                ['order-checkout' => $label] +   // Insert order/checkout link
                array_slice($items, 2, null, true); // Append the rest

        // Add "Product Support" just before the "Log out" link (second-to-last)
        $logout = array_pop($items); // Remove the last item (Log out)
        $items['product-support'] = __('Product Support', 'woocommerce'); // Add Product Support
        $items['customer-logout'] = $logout; // Re-add Log out as the last item

        // Handle dynamic URL for "Order/Checkout"
        add_filter('woocommerce_get_endpoint_url', function ($url, $endpoint, $value, $permalink) use ($label) {
            if ($endpoint === 'order-checkout') {
                return $label === 'Checkout' ? wc_get_checkout_url() : wc_get_page_permalink('shop');
            }
            return $url;
        }, 10, 4);

        return $items;
    }

    // Set URL for "Product Support"
    add_filter('woocommerce_get_endpoint_url', function ($url, $endpoint, $value, $permalink) {
        if ($endpoint === 'product-support') {
            return 'https://sorbostaging.burkemedia.net/support/'; // Product Support URL
        }
        return $url;
    }, 10, 4);



    // Shipping Address

    add_action('woocommerce_checkout_update_order_meta', function($order_id) {
        $custom_address = isset($_POST['custom_shipping_address']) ? sanitize_textarea_field($_POST['custom_shipping_address']) : '';
        error_log('Saving custom shipping address: ' . $custom_address); // Log to debug.log
        update_post_meta($order_id, '_custom_shipping_address', $custom_address);
    });


    add_filter('wc_get_template', 'force_custom_template_for_order_details', 10, 5);
    function force_custom_template_for_order_details($located, $template_name, $args, $template_path, $default_path) {
        // Target the customer details template on the My Account page
        if ($template_name === 'order/order-details-customer.php') {
            $custom_template = get_stylesheet_directory() . '/woocommerce/order/order-details-customer.php';
            if (file_exists($custom_template)) {
                return $custom_template; // Use your custom template
            }
        }
        return $located;
    }









//  Part II of Functions Starts Here //



// Interactive Map //
// Enqueue Google Maps Script
function enqueue_google_maps_script() {
    // Load Google Maps API (Client-side Key)
    wp_enqueue_script('google-maps', 'https://maps.googleapis.com/maps/api/js?key=AIzaSyBB4qcPTlsPKzceBfaMcFwHyr19zH9oBqY', array(), null, true);

    // Load custom map script
    wp_enqueue_script('custom-map-script', get_stylesheet_directory_uri() . '/js/custom-map.js', array('google-maps', 'jquery'), null, true);

    // Pass AJAX URL to script
    wp_localize_script('custom-map-script', 'myAjax', array('ajaxurl' => admin_url('admin-ajax.php')));
}
add_action('wp_enqueue_scripts', 'enqueue_google_maps_script');


// AJAX function to get distributor locations
add_action('wp_ajax_get_distributor_locations', 'get_distributor_locations');
add_action('wp_ajax_nopriv_get_distributor_locations', 'get_distributor_locations');

function get_distributor_locations() {
    $locations = array();

   // Get the logged-in user's distributors
$current_user_id = get_current_user_id();

// Fetch distributor locations from the ACF relationship field
$user_distributors = get_field('your_disbtributor_locations', 'user_' . $current_user_id);

// Extract just the IDs if objects are returned
$user_distributors = wp_list_pluck($user_distributors, 'ID');

// Ensure it's an array (ACF sometimes returns null if no data exists)
$user_distributors = is_array($user_distributors) ? $user_distributors : [];

    // Query for 'distributor' post type
    $args = array(
        'post_type'      => 'distributor',
        'posts_per_page' => -1,
    );

    $query = new WP_Query($args);

    if ($query->have_posts()) {
        while ($query->have_posts()) {
            $query->the_post();

            // Get each field
            $street  = get_post_meta(get_the_ID(), 'street_address', true);
            $city    = get_post_meta(get_the_ID(), 'city', true);
            $zip     = get_post_meta(get_the_ID(), 'zip', true);

            // Get state and country from taxonomies
            $state_terms = wp_get_post_terms(get_the_ID(), 'state', array('fields' => 'names'));
            $state = !empty($state_terms) ? $state_terms[0] : '';

            $country_terms = wp_get_post_terms(get_the_ID(), 'country', array('fields' => 'names'));
            $country = !empty($country_terms) ? $country_terms[0] : '';

            // Get lat/lng from meta (already stored)
            $lat = get_post_meta(get_the_ID(), 'lat', true);
            $lng = get_post_meta(get_the_ID(), 'lng', true);

            // Check if this is a logged-in user's distributor
            $is_active = in_array(get_the_ID(), $user_distributors) ? true : false;

            // Skip posts with missing fields
            if (empty($street) || empty($city) || empty($state) || empty($zip) || empty($country) || empty($lat) || empty($lng)) {
                continue; // Skip this post
            }

            // Build full address for display
            $full_address = "{$street}, {$city}, {$state} {$zip}, {$country}";

            $locations[] = array(
                'title'   => get_the_title(),
                'address' => $full_address,
                'lat'     => $lat,
                'lng'     => $lng,
                'link'    => get_permalink(),
                'active'  => $is_active, // New property to flag active distributors
            );
        }
    }
    wp_reset_postdata();

    echo json_encode($locations);
    wp_die();
}





// Convert address to latitude and longitude using Geocoding API
    function get_lat_lng_from_address($address) {
        $address = urlencode($address);
        $api_key = 'AIzaSyD2lF250iyLmgMEKz7Bq39YId1ZhCtYgKw'; // Server-side API Key (IP Restricted)
        $url = "https://maps.googleapis.com/maps/api/geocode/json?address={$address}&key={$api_key}";

        $response = wp_remote_get($url);

        if (is_wp_error($response)) {
            error_log('Geocoding API request failed: ' . $response->get_error_message());
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body);

        if ($data->status === 'OK') {
            $lat = $data->results[0]->geometry->location->lat;
            $lng = $data->results[0]->geometry->location->lng;
            return array('lat' => $lat, 'lng' => $lng);
        }

        return false;
    }




// Autopopulate Latitude and Longitude  
// Automatically populate lat/lng when a distributor post is saved
add_action('save_post', 'populate_lat_lng_on_save', 10, 3);

function populate_lat_lng_on_save($post_id, $post, $update) {
    // Run only for the 'distributor' post type
    if ($post->post_type !== 'distributor') {
        return;
    }

    // Skip if lat and lng are already set
    $lat = get_post_meta($post_id, 'lat', true);
    $lng = get_post_meta($post_id, 'lng', true);
    if (!empty($lat) && !empty($lng)) {
        return; // Already has coordinates, no need to fetch again
    }

    // Get address fields
    $street  = get_post_meta($post_id, 'street_address', true);
    $city    = get_post_meta($post_id, 'city', true);
    $zip     = get_post_meta($post_id, 'zip', true);

    // Fetch state and country from taxonomy terms
    $state_terms = wp_get_post_terms($post_id, 'state', array('fields' => 'names'));
    $state = !empty($state_terms) ? $state_terms[0] : '';

    $country_terms = wp_get_post_terms($post_id, 'country', array('fields' => 'names'));
    $country = !empty($country_terms) ? $country_terms[0] : '';

    // Skip if any address field is missing
    if (empty($street) || empty($city) || empty($state) || empty($zip) || empty($country)) {
        error_log("Skipping post ID {$post_id} due to missing address fields.");
        return;
    }

    // Build full address
    $full_address = "{$street}, {$city}, {$state} {$zip}, {$country}";
    error_log("Processing address: {$full_address}");

    // Fetch coordinates using the Geocoding API
    $geo = get_lat_lng_from_address($full_address);

    if ($geo) {
        // Save lat and lng to post meta
        update_post_meta($post_id, 'lat', $geo['lat']);
        update_post_meta($post_id, 'lng', $geo['lng']);
        error_log("Saved lat/lng for post ID {$post_id}: {$geo['lat']}, {$geo['lng']}");
    } else {
        error_log("Failed to geocode address for post ID {$post_id}");
    }
}




// Uncomment to manually run lat & lng import.
// add_action('init', 'populate_lat_lng_fields');


//Single Page Map //
add_action('wp_enqueue_scripts', 'enqueue_single_distributor_map');

function enqueue_single_distributor_map() {
    if (is_singular('distributor')) { // Load only for distributor single pages
        wp_enqueue_script('google-maps', 'https://maps.googleapis.com/maps/api/js?key=AIzaSyBB4qcPTlsPKzceBfaMcFwHyr19zH9oBqY', array(), null, true);
        wp_enqueue_script('custom-map-script', get_stylesheet_directory_uri() . '/js/custom-map.js', array('google-maps', 'jquery'), null, true);
    }
}



// Allow Users to Update Location Address on Front End //

add_filter('gform_pre_render', 'prefill_distributor_address_with_taxonomy');
add_filter('gform_pre_validation', 'prefill_distributor_address_with_taxonomy');
add_filter('gform_pre_submission_filter', 'prefill_distributor_address_with_taxonomy');
add_filter('gform_admin_pre_render', 'prefill_distributor_address_with_taxonomy');

function prefill_distributor_address_with_taxonomy($form) {
    if (is_singular('distributor')) {
        $post_id = get_the_ID();

        $street  = get_post_meta($post_id, 'street_address', true);
        $city    = get_post_meta($post_id, 'city', true);
        $zip     = get_post_meta($post_id, 'zip', true);

        // Get state and country from taxonomy
        $state_terms = wp_get_post_terms($post_id, 'state', array('fields' => 'names'));
        $state = !empty($state_terms) ? $state_terms[0] : '';

        $country_terms = wp_get_post_terms($post_id, 'country', array('fields' => 'names'));
        $country = !empty($country_terms) ? $country_terms[0] : '';

        foreach ($form['fields'] as &$field) {
            switch ($field->id) {
                case 1: // Street Address
                    $field->defaultValue = $street;
                    break;
                case 3: // City
                    $field->defaultValue = $city;
                    break;
                case 4: // State (taxonomy)
                    $field->defaultValue = $state;
                    break;
                case 5: // ZIP
                    $field->defaultValue = $zip;
                    break;
                case 6: // Country (taxonomy)
                    $field->defaultValue = $country;
                    break;
                case 7: // Distributor ID (hidden)
                    $field->defaultValue = $post_id;
                    break;
            }
        }
    }
    return $form;
}





add_action('gform_after_submission', 'save_distributor_address_with_taxonomy', 10, 2);

function save_distributor_address_with_taxonomy($entry, $form) {
    if (is_singular('distributor')) {
        $post_id = rgar($entry, '7'); // Hidden field for Distributor ID

        // Update meta fields
        update_post_meta($post_id, 'street_address', sanitize_text_field(rgar($entry, '1')));
        update_post_meta($post_id, 'city', sanitize_text_field(rgar($entry, '3')));
        update_post_meta($post_id, 'zip', sanitize_text_field(rgar($entry, '5')));

        // Update state and country as taxonomy terms
        $state = sanitize_text_field(rgar($entry, '4'));
        wp_set_post_terms($post_id, $state, 'state', false);

        $country = sanitize_text_field(rgar($entry, '6'));
        wp_set_post_terms($post_id, $country, 'country', false);

        // Update Lat/Lng
        $full_address = sanitize_text_field(rgar($entry, '1')) . ', ' . sanitize_text_field(rgar($entry, '3')) . ', ' . $state . ' ' . sanitize_text_field(rgar($entry, '5')) . ', ' . $country;
        $geo = get_lat_lng_from_address($full_address);

        if ($geo) {
            update_post_meta($post_id, 'lat', $geo['lat']);
            update_post_meta($post_id, 'lng', $geo['lng']);
            error_log("Saved lat/lng via Gravity Form for post ID {$post_id}: {$geo['lat']}, {$geo['lng']}");
        } else {
            error_log("Failed to geocode address via Gravity Form for post ID {$post_id}");
        }
    }
}



// Add Company Details to My Account > Account details
add_action('woocommerce_edit_account_form', 'add_company_details_section');

function add_company_details_section() {
    echo '<fieldset>';
    echo do_shortcode('[template id=3559]'); // Insert your shortcode
    echo '</fieldset>';
}



// Emails on My Account > Accoutn details page 
add_action('woocommerce_edit_account_form', 'add_email_legend_section');

function add_email_legend_section() {
    // Output the legend above email options
    echo '<fieldset style="margin-top: 30px;">'; // Add spacing above
    echo '<legend style="font-size: 18px; font-weight: bold; margin-bottom: 10px;">Emails</legend>';
}



// =====================================
// LOGIN AND ACCESS CONTROL
// =====================================

// 1. Redirect /login to /my-account/ for all users
add_action('template_redirect', function () {
    if (is_page('login')) { // Replace 'login' with your login page slug
        wp_redirect(site_url('/my-account/'));
        exit;
    }
});

// 2. Redirect wp-login.php and wp-admin for NON-LOGGED-IN users
add_action('init', function () {
    // Redirect wp-login.php to My Account if not logged in
    if (strpos($_SERVER['REQUEST_URI'], 'wp-login.php') !== false && !is_user_logged_in()) {
        wp_redirect(site_url('/my-account/'));
        exit;
    }

    // Redirect wp-admin if not logged in (unless AJAX)
    if (is_admin() && !is_user_logged_in() && !(defined('DOING_AJAX') && DOING_AJAX)) {
        wp_redirect(site_url('/my-account/'));
        exit;
    }
});

// 3. Restrict Shop Managers from "My Account" Page (Admins Allowed)
add_action('template_redirect', function () {
    // Get current user and roles
    $user = wp_get_current_user();

    // Check if logged-in user is a Shop Manager
    if (is_page('my-account') && !empty($user) && in_array('shop_manager', $user->roles)) {
        // Redirect to Admin Dashboard for Shop Managers
        wp_redirect(admin_url());
        exit;
    }
});

// 4. Redirect Admin Roles to Dashboard IMMEDIATELY AFTER LOGIN
add_filter('login_redirect', function ($redirect_to, $request, $user) {
    // If login is successful and user has a valid role
    if (isset($user->roles) && is_array($user->roles)) {
        $admin_roles = array('administrator', 'shop_manager', 'content_manager');

        // Redirect admin roles to the dashboard
        if (array_intersect($admin_roles, $user->roles)) {
            return admin_url(); // Admin Dashboard
        }

        // Redirect other roles to My Account page
        return wc_get_page_permalink('myaccount'); // WooCommerce My Account
    }

    // Default redirect fallback
    return $redirect_to;
}, 10, 3);



// Add Dashboard Widget for WooCommerce Orders
function woocommerce_orders_dashboard_widget() {
    global $wp_meta_boxes;

    // Add the widget
    wp_add_dashboard_widget(
        'woocommerce_orders_widget', // Widget ID
        'Recent Orders', // Widget Title
        'woocommerce_orders_dashboard_widget_content' // Callback function
    );

    // Move the widget to the TOP of the SECOND column
    $dashboard = $wp_meta_boxes['dashboard']['normal']['core'];

    // Remove it and place in the second column
    $orders_widget = $dashboard['woocommerce_orders_widget'];
    unset($dashboard['woocommerce_orders_widget']);

    $wp_meta_boxes['dashboard']['side']['core']['woocommerce_orders_widget'] = $orders_widget; // Second column (top)
}
add_action('wp_dashboard_setup', 'woocommerce_orders_dashboard_widget');

// Widget Content Callback
function woocommerce_orders_dashboard_widget_content() {
    // Get WooCommerce orders that are NOT completed
    $args = array(
        'limit'      => -1, // No limit
        'status'     => array('processing', 'pending', 'on-hold'), // Filter by status
        'orderby'    => 'date',
        'order'      => 'DESC',
    );

    $orders = wc_get_orders($args);

    if (empty($orders)) {
        echo '<p>No pending, processing, or on-hold orders found.</p>';
        return;
    }

    // Group orders by status
    $grouped_orders = [];
    foreach ($orders as $order) {
        $status = $order->get_status(); // Get order status
        if (!isset($grouped_orders[$status])) {
            $grouped_orders[$status] = [];
        }
        $grouped_orders[$status][] = $order;
    }

    // Display Orders by Status
    foreach ($grouped_orders as $status => $status_orders) {
        echo '<div style="background:#eee; box-shadow:0px 0px 9px #b1b1b1; padding:7px; margin-bottom:15px;">';
        echo '<div style="background:#2d276d;padding:5px 15px;" ><h3 style="font-size:15px;font-weight:600;color:#fff;">' . ucfirst($status) . ' Orders</h3></div>';
        echo '<ul style="list-style: none; padding: 0; margin: 0;">';

        foreach ($status_orders as $order) {
            $order_id = $order->get_id();
            $customer_name = $order->get_billing_first_name() . ' ' . $order->get_billing_last_name();
            $total = $order->get_total();
            $currency = $order->get_currency();

            echo '<li style="margin-bottom: 10px; padding: 8px; border-bottom: 1px solid #ddd;">';
            echo '<strong>Order #' . $order_id . '</strong> - ' . esc_html($customer_name) . ' - ';
            echo wc_price($total, array('currency' => $currency)) . ' - ';
            echo '<a href="' . esc_url(admin_url('post.php?post=' . $order_id . '&action=edit')) . '" target="_blank" style="text-decoration: none; color: #0073aa;">View</a>';
            echo '</li>';
        }

        echo '</ul></div>';
    }
}
