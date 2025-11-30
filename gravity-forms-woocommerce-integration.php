<?php
/*
Plugin Name: Gravity Forms WooCommerce Integration
Plugin URI: https://alphasvision.com
Description: Integrates Gravity Forms with WooCommerce to dynamically add event/ticket data into cart with dynamic pricing.
Version: 1.4
Author: aidendev
Author URI: https://alphasvision.com
License: GPL2
*/

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'GF_WOO_OPTION_KEY', 'gf_woo_settings' );

/*
|--------------------------------------------------------------------------
| SETTINGS
|--------------------------------------------------------------------------
*/
function gf_woo_get_settings() {
    $defaults = array(
        'allowed_form_ids'       => '37,36',
        'reservation_product_id' => '3622',
        'ticket_type_field_id'   => '6',
        'price_field_id'         => '5',
        'ticket_count_field_id'  => '7',
    );
    return wp_parse_args( get_option( GF_WOO_OPTION_KEY, array() ), $defaults );
}

function gf_woo_get_allowed_form_ids() {
    $settings = gf_woo_get_settings();
    $parts    = explode( ',', $settings['allowed_form_ids'] );
    return array_filter( array_map( 'intval', array_map( 'trim', $parts ) ) );
}


/*
|--------------------------------------------------------------------------
| ADD TO CART AFTER GF SUBMISSION
|--------------------------------------------------------------------------
*/
add_action( 'gform_after_submission', 'gf_woo_add_reservation_to_cart', 10, 2 );
function gf_woo_add_reservation_to_cart( $entry, $form ) {

    if ( is_admin() || ! function_exists('WC') || ! WC()->cart ) return;

    $settings         = gf_woo_get_settings();
    $allowed_form_ids = gf_woo_get_allowed_form_ids();

    if ( empty( $form['id'] ) || ! in_array( (int)$form['id'], $allowed_form_ids, true ) ) return;

    $product_id = (int) $settings['reservation_product_id'];
    if ( $product_id <= 0 ) return;

    $event_name   = sanitize_text_field( $form['title'] );
    $ticket_type  = sanitize_text_field( rgar( $entry, $settings['ticket_type_field_id'] ) );

    $raw_price = str_replace( [',',' '], ['.',''], rgar( $entry, $settings['price_field_id'] ) );
    $price     = is_numeric( $raw_price ) ? (float)$raw_price : 0;

    $ticket_count = (int) rgar( $entry, $settings['ticket_count_field_id'] );
    if ( $ticket_count <= 0 ) $ticket_count = 1;

    WC()->cart->add_to_cart(
        $product_id,
        $ticket_count,
        0,
        [],
        [
            'event_name'   => $event_name,
            'ticket_type'  => $ticket_type,
            'ticket_count' => $ticket_count,
            'price'        => $price
        ]
    );
}


/*
|--------------------------------------------------------------------------
| APPLY DYNAMIC PRICE
|--------------------------------------------------------------------------
*/
add_action( 'woocommerce_before_calculate_totals', 'gf_woo_update_price', 10 );
function gf_woo_update_price( $cart ) {
    if ( is_admin() && ! defined('DOING_AJAX') ) return;

    foreach ( $cart->get_cart() as $item ) {
        if ( isset( $item['price'] ) && $item['price'] > 0 ) {
            $item['data']->set_price( (float)$item['price'] );
        }
    }
}


/*
|--------------------------------------------------------------------------
| CUSTOM CART ITEM TITLE (NO META)
|--------------------------------------------------------------------------
*/
add_filter( 'woocommerce_cart_item_name', 'gf_woo_cart_item_title', 10, 3 );
function gf_woo_cart_item_title( $name, $item, $key ) {

    $event_name   = $item['event_name']   ?? '';
    $ticket_type  = $item['ticket_type']  ?? '';
    $ticket_count = $item['ticket_count'] ?? 0;

    if ( $event_name ) {
        $new = esc_html( $event_name );

        if ( $ticket_type ) {
            $new .= ' – ' . esc_html( $ticket_type );
        }

        if ( $ticket_count ) {
            $new .= ' – تعداد بلیط: ' . intval( $ticket_count );
        }

        $name = $new . ' (' . $name . ')';
    }

    return $name;
}


/*
|--------------------------------------------------------------------------
| ADMIN PAGE
|--------------------------------------------------------------------------
*/
add_action( 'admin_menu', 'gf_woo_menu' );
function gf_woo_menu() {
    add_submenu_page(
        'woocommerce',
        'GF Woo Integration',
        'GF Woo Integration',
        'manage_woocommerce',
        'gf-woo-settings',
        'gf_woo_settings_page'
    );
}

add_action( 'admin_init', 'gf_woo_register_settings' );
function gf_woo_register_settings() {

    register_setting( 'gf_woo_settings_group', GF_WOO_OPTION_KEY, 'gf_woo_sanitize' );

    add_settings_section(
        'gf_main',
        'Gravity Forms & WooCommerce Mapping',
        function(){ echo '<p>Configure Gravity Forms-to-WooCommerce mapping.</p>'; },
        'gf_woo_settings'
    );

    $fields = [
        'allowed_form_ids'       => 'Allowed Gravity Form IDs (comma-separated)',
        'reservation_product_id' => 'WooCommerce Product ID',
        'ticket_type_field_id'   => 'GF Field ID – Ticket Type',
        'price_field_id'         => 'GF Field ID – Price',
        'ticket_count_field_id'  => 'GF Field ID – Ticket Count'
    ];

    foreach ( $fields as $key => $label ) {
        add_settings_field(
            $key, $label,
            function() use ( $key ) {
                $settings = gf_woo_get_settings();
                echo '<input type="text" name="gf_woo_settings[' . esc_attr($key) . ']" value="' . esc_attr($settings[$key]) . '" class="regular-text">';
            },
            'gf_woo_settings', 'gf_main'
        );
    }
}

function gf_woo_sanitize( $input ) {
    foreach ( $input as $k => $v ) {
        $input[$k] = sanitize_text_field( $v );
    }
    return $input;
}

function gf_woo_settings_page() { ?>
    <div class="wrap">
        <h1>Gravity Forms WooCommerce Integration</h1>
        <form method="post" action="options.php">
            <?php
            settings_fields( 'gf_woo_settings_group' );
            do_settings_sections( 'gf_woo_settings' );
            submit_button();
            ?>
        </form>
    </div>
<?php }
