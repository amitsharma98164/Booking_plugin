<?php
/**
 * Plugin Name: Clinic Appointment Calendar
 * Description: A plugin to show a calendar for clinic appointments with time slots based on selected dates.
 * Version: 1.0
 * Author: Your Name
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}


// Check if WooCommerce is active
function is_woocommerce_active() {
    return in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')));
}

// Enqueue scripts and styles
// Create database table on plugin activation
function create_appointments_table() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'clinic_appointments';
    
    $charset_collate = $wpdb->get_charset_collate();
    
    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        user_type varchar(50) NOT NULL,
        name varchar(100) NOT NULL,
        email varchar(100) NOT NULL,
        phone varchar(20) NOT NULL,
        appointment_date date NOT NULL,
        appointment_time time NOT NULL,
        product_id bigint(20) NOT NULL,
        payment_id varchar(100),
        payment_status varchar(20) DEFAULT 'pending',
        amount decimal(10,2) NOT NULL,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id)
    ) $charset_collate;";
    
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}
register_activation_hook(__FILE__, 'create_appointments_table');

// Enqueue scripts and styles
// Enqueue scripts and styles
function clinic_calendar_enqueue_scripts() {
    wp_enqueue_style('flatpickr-css', 'https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css');
    wp_enqueue_script('flatpickr-js', 'https://cdn.jsdelivr.net/npm/flatpickr', [], null, true);
    wp_enqueue_script('razorpay-js', 'https://checkout.razorpay.com/v1/checkout.js', [], null, true);
    
    wp_enqueue_script(
        'clinic-calendar-js',
        plugins_url('js/clinic-calendar.js', __FILE__),
        ['jquery', 'flatpickr-js', 'razorpay-js'],
        null,
        true
    );
    
    wp_enqueue_style(
        'clinic-calendar-css',
        plugins_url('css/clinic-calendar.css', __FILE__)
    );
    
    // Pass PHP variables to JavaScript
    wp_localize_script('clinic-calendar-js', 'clinicData', array(
        'ajaxurl' => admin_url('admin-ajax.php'),
        'razorpay_key' => RAZORPAY_KEY_ID
    ));
}
add_action('wp_enqueue_scripts', 'clinic_calendar_enqueue_scripts');

// Generate time slots based on the day
function clinic_generate_time_slots($day_of_week) {
    $time_slots = [];
    
    if (in_array($day_of_week, ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'])) {
        $start_time = strtotime('10:00 AM');
        $end_time = strtotime('7:00 PM');
    } else {
        $start_time = strtotime('10:00 AM');
        $end_time = strtotime('5:00 PM');
    }

    while ($start_time < $end_time) {
        $time_slots[] = date('h:i A', $start_time);
        $start_time = strtotime('+15 minutes', $start_time);
    }

    return $time_slots;
}


// Get WooCommerce products
// Get WooCommerce products
function get_clinic_products() {
    if (!is_woocommerce_active()) {
        return array();
    }

    $products = array();
    
    $args = array(
        'post_type' => 'product',
        'post_status' => 'publish',
        'posts_per_page' => -1,
        'orderby' => 'title',
        'order' => 'ASC',
    );

    $query = new WP_Query($args);
    
    if ($query->have_posts()) {
        while ($query->have_posts()) {
            $query->the_post();
            $product = wc_get_product(get_the_ID());
            
            $products[] = array(
                'id' => $product->get_id(),
                'name' => $product->get_name(),
                'price' => $product->get_price(),
                'regular_price' => $product->get_regular_price(),
                'sale_price' => $product->get_sale_price(),
            );
        }
    }
    
    wp_reset_postdata();
    return $products;
}

// AJAX handler to get time slots for a selected date
function clinic_get_time_slots() {
    // Verify nonce
    // if (!check_ajax_referer('clinic_nonce', 'security', false)) {
    //     wp_send_json_error('Invalid security token sent.');
    //     wp_die();
    // }

    $selected_date = sanitize_text_field($_POST['selected_date']);
    $day_of_week = date('l', strtotime($selected_date));
    $time_slots = clinic_generate_time_slots($day_of_week);

    wp_send_json_success($time_slots);
    wp_die();
}
add_action('wp_ajax_get_time_slots', 'clinic_get_time_slots');
add_action('wp_ajax_nopriv_get_time_slots', 'clinic_get_time_slots');

// AJAX handler to get products
function clinic_get_products() {
    // Verify nonce
    // if (!check_ajax_referer('clinic_nonce', 'security', false)) {
    //     wp_send_json_error('Invalid security token sent.');
    //     wp_die();
    // }

    $products = get_clinic_products();
    wp_send_json_success($products);
    wp_die();
}
add_action('wp_ajax_get_products', 'clinic_get_products');
add_action('wp_ajax_nopriv_get_products', 'clinic_get_products');



// Add these constants at the top of your file after plugin header
define('RAZORPAY_KEY_ID', 'rzp_live_NPO2nKMQw0W7cv');
define('RAZORPAY_KEY_SECRET', 'nl471G7u6kY2d0knr7zACIQF');


// Add new AJAX handler for booking submission
function handle_appointment_booking() {
    try {
        global $wpdb;
        $table_name = $wpdb->prefix . 'clinic_appointments';
        
        // Validate and sanitize input data
        $data = array(
            'user_type' => sanitize_text_field($_POST['user_type']),
            'name' => sanitize_text_field($_POST['name']),
            'email' => sanitize_email($_POST['email']),
            'phone' => sanitize_text_field($_POST['phone']),
            'appointment_date' => sanitize_text_field($_POST['date']),
            'appointment_time' => sanitize_text_field($_POST['time']),
            'product_id' => intval($_POST['product_id']),
            'amount' => floatval($_POST['amount']),
            'payment_status' => 'pending'
        );
        
        // Insert appointment record
        $wpdb->insert($table_name, $data);
        $appointment_id = $wpdb->insert_id;

        if (!$appointment_id) {
            throw new Exception('Failed to create appointment record');
        }

        // Prepare Razorpay order creation request
        $amount = round($data['amount'] * 100); // Convert to paise
        
        $order_data = array(
            'amount' => $amount,
            'currency' => 'INR',
            'receipt' => 'appointment_' . $appointment_id,
            'payment_capture' => 1
        );

        // Create Razorpay order using API
        $response = wp_remote_post('https://api.razorpay.com/v1/orders', array(
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode(RAZORPAY_KEY_ID . ':' . RAZORPAY_KEY_SECRET),
                'Content-Type' => 'application/json'
            ),
            'body' => json_encode($order_data)
        ));

        if (is_wp_error($response)) {
            throw new Exception('Failed to connect to Razorpay: ' . $response->get_error_message());
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = json_decode(wp_remote_retrieve_body($response));

        if ($response_code !== 200) {
            throw new Exception('Razorpay API Error: ' . $response_body->error->description);
        }

        // Update appointment with order ID
        $wpdb->update(
            $table_name,
            array('razorpay_order_id' => $response_body->id),
            array('id' => $appointment_id)
        );

        wp_send_json_success(array(
            'order_id' => $response_body->id,
            'appointment_id' => $appointment_id
        ));

    } catch (Exception $e) {
        error_log('Razorpay Order Creation Error: ' . $e->getMessage());
        wp_send_json_error(array(
            'message' => 'Failed to create order. Please try again.',
            'error' => $e->getMessage()
        ));
    }
}
add_action('wp_ajax_handle_booking', 'handle_appointment_booking');
add_action('wp_ajax_nopriv_handle_booking', 'handle_appointment_booking');

// Handle Razorpay payment verification
function verify_razorpay_payment() {
    try {
        global $wpdb;
        $table_name = $wpdb->prefix . 'clinic_appointments';
        
        $payment_id = sanitize_text_field($_POST['razorpay_payment_id']);
        $appointment_id = intval($_POST['appointment_id']);
        
        if (!$payment_id || !$appointment_id) {
            throw new Exception('Invalid payment or appointment data');
        }

        // Verify payment using Razorpay API
        $response = wp_remote_get('https://api.razorpay.com/v1/payments/' . $payment_id, array(
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode(RAZORPAY_KEY_ID . ':' . RAZORPAY_KEY_SECRET)
            )
        ));

        if (is_wp_error($response)) {
            throw new Exception('Failed to connect to Razorpay: ' . $response->get_error_message());
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $payment_data = json_decode(wp_remote_retrieve_body($response));

        if ($response_code !== 200) {
            throw new Exception('Razorpay API Error: ' . $payment_data->error->description);
        }

        if ($payment_data->status === 'captured') {
            // Update appointment record
            $update_result = $wpdb->update(
                $table_name,
                array(
                    'payment_id' => $payment_id,
                    'payment_status' => 'completed'
                ),
                array('id' => $appointment_id)
            );
            
            if ($update_result === false) {
                throw new Exception('Failed to update appointment record');
            }
            
            // Send success response
            wp_send_json_success(array(
                'message' => 'Payment successful',
                'payment_id' => $payment_id
            ));
        } else {
            throw new Exception('Payment not captured. Status: ' . $payment_data->status);
        }
        
    } catch (Exception $e) {
        error_log('Razorpay Payment Verification Error: ' . $e->getMessage());
        wp_send_json_error(array(
            'message' => 'Payment verification failed',
            'error' => $e->getMessage()
        ));
    }
}
add_action('wp_ajax_verify_payment', 'verify_razorpay_payment');
add_action('wp_ajax_nopriv_verify_payment', 'verify_razorpay_payment');


// Shortcode to display the calendar
function clinic_calendar_shortcode() {
    if (!is_woocommerce_active()) {
        return '<p>This calendar requires WooCommerce to be installed and activated.</p>';
    }

    $calendar_html = '<div id="clinic-calendar" style="max-width: 100%; margin: auto; text-align: center; font-family: Arial, sans-serif;">';
    
    // Calendar container
    $calendar_html .= '<div id="calendar-container"></div>';
    
    // Time Slots container
    $calendar_html .= '<div id="time-slots" style="display: flex; flex-wrap: wrap; gap: 10px; justify-content: center;"></div>';
    
    // Modal container
    $calendar_html .= '<div id="booking-modal" style="display: none;">';
    $calendar_html .= '<div style="background: #fff; padding: 20px; border-radius: 10px; max-width: 400px; margin: auto; box-shadow: 0px 4px 6px rgba(0,0,0,0.1); text-align: left;">';
    $calendar_html .= '<h3 style="color: #333; margin-bottom: 10px;">Book Appointment</h3>';
    $calendar_html .= '<form id="booking-form">';
    
    // Add nonce field
    // $calendar_html .= wp_nonce_field('clinic_nonce', 'booking_nonce', true, false);

    // User Type Dropdown
    $calendar_html .= '<label for="user_type" style="display: block; font-weight: bold; margin-bottom: 5px;">User Type:</label>';
    $calendar_html .= '<select id="user_type" name="user_type" style="width: 100%; padding: 10px; margin-bottom: 10px; border: 1px solid #ccc; border-radius: 5px;" required>';
    $calendar_html .= '<option value="">Select User Type</option>';
    $calendar_html .= '<option value="new">New User</option>';
    $calendar_html .= '<option value="existing">Existing User</option>';
    $calendar_html .= '</select>';

    // Product Dropdown
    $calendar_html .= '<label for="product" style="display: block; font-weight: bold; margin-bottom: 5px;">Select Service:</label>';
    $calendar_html .= '<select id="product" name="product" style="width: 100%; padding: 10px; margin-bottom: 10px; border: 1px solid #ccc; border-radius: 5px;" required>';
    $calendar_html .= '<option value="">Select a Service</option>';
    
    // Add WooCommerce products to dropdown
    $products = get_clinic_products();
    foreach ($products as $product) {
        $price = $product['sale_price'] ? $product['sale_price'] : $product['price'];
        $price_display = $product['sale_price'] ? 
            sprintf('%s (Sale: %s)', wc_price($product['regular_price']), wc_price($product['sale_price'])) : 
            wc_price($product['price']);
            
        $calendar_html .= sprintf(
            '<option value="%d" data-price="%s">%s - %s</option>',
            $product['id'],
            esc_attr($price),
            esc_html($product['name']),
            $price_display
        );
    }
    $calendar_html .= '</select>';

    // Standard form fields
    $calendar_html .= '<label for="name" style="display: block; font-weight: bold; margin-bottom: 5px;">Name:</label>';
    $calendar_html .= '<input type="text" id="name" name="name" style="width: 92%; padding: 10px; margin-bottom: 10px; border: 1px solid #ccc; border-radius: 5px;" required />';
    $calendar_html .= '<label for="email" style="display: block; font-weight: bold; margin-bottom: 5px;">Email:</label>';
    $calendar_html .= '<input type="email" id="email" name="email" style="width: 92%; padding: 10px; margin-bottom: 10px; border: 1px solid #ccc; border-radius: 5px;" required />';
    $calendar_html .= '<label for="phone" style="display: block; font-weight: bold; margin-bottom: 5px;">Phone:</label>';
    $calendar_html .= '<input type="text" id="phone" name="phone" style="width: 92%; padding: 10px; margin-bottom: 20px; border: 1px solid #ccc; border-radius: 5px;" required />';
    $calendar_html .= '<button type="submit" style="background-color: #28a745; color: #fff; padding: 10px 20px; border: none; border-radius: 5px; cursor: pointer;">Proceed to Payment</button>';
    $calendar_html .= '<button type="button" id="close-modal" style="margin-left: 10px; background-color: #ccc; color: #333; padding: 10px 20px; border: none; border-radius: 5px; cursor: pointer;">Cancel</button>';
    $calendar_html .= '</form>';
    $calendar_html .= '</div>';
    $calendar_html .= '</div>';

    $calendar_html .= '<div id="booking-modal-overlay" style="display: none;"></div>';
    $calendar_html .= '</div>';

    return $calendar_html;
}
add_shortcode('clinic_calendar', 'clinic_calendar_shortcode');