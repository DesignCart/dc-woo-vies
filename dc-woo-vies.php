<?php
/**
 * Plugin Name: DC Woo VIES Validator (Modułowy)
 * Description: Weryfikacja NIP/VAT ID, kompatybilna z WooCommerce 8.8+ Blocks i klasycznym checkoutem.
 * Version: 1.0.0
 * Author: dc@designcart
 * Text Domain: dc-woo-vies
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'DC_WOO_VIES_PATH', plugin_dir_path( __FILE__ ) );
define( 'DC_WOO_VIES_URL', plugin_dir_url( __FILE__ ) );

function dc_woo_vies_load_dependencies() {
    // Klasa VIES Validator
    require_once DC_WOO_VIES_PATH . 'includes/class-dc-vies-validator.php'; 
    
    // Rdzeń: Rejestracja pól i Logika VAT/VIES
    require_once DC_WOO_VIES_PATH . 'includes/class-dc-vies-register.php'; // Zmieniona nazwa pliku!
    new DC_VIES_Register();
    
    // Zapis i Prezentacja w Adminie
    require_once DC_WOO_VIES_PATH . 'includes/class-dc-order-meta.php';
    new DC_Order_Meta();
}

function dc_woo_vies_enqueue_scripts() {
    if ( is_checkout() || is_account_page() ) {
        
        // 1. Rejestrujemy skrypt, ale go jeszcze nie wczytujemy
        wp_register_script( 'dc-vies-js', DC_WOO_VIES_URL . 'assets/js/checkout-vies.js', array( 'jquery' ), '1.0.0', true );
        wp_enqueue_style( 'dc-vies-css', DC_WOO_VIES_URL . 'assets/css/checkout-vies.css', array( 'woocommerce-general' ), '1.0.0' );

        // 2. Lokalizujemy skrypt, używając jego uchwytu (handle)
        wp_localize_script( 'dc-vies-js', 'dc_vies_params', array(
            'invoice_field_id' => 'dc-vies/request_invoice', 
            'nip_field_id'     => 'dc-vies/nip_vat',        
            'ajax_url'         => admin_url( 'admin-ajax.php' ), 
            'security_nonce'   => wp_create_nonce( 'dc-vies-nonce' ),
            'store_country'    => WC()->countries->get_base_country(),
        ) );

        // 3. Wczytujemy skrypt (po lokalizacji!)
        wp_enqueue_script( 'dc-vies-js' );
    }
}

function dc_woo_vies_load_textdomain() {
    load_plugin_textdomain( 'dc-woo-vies', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
}

add_action( 'woocommerce_init', 'dc_woo_vies_load_dependencies' );
add_action( 'wp_enqueue_scripts', 'dc_woo_vies_enqueue_scripts' );
add_action( 'init', 'dc_woo_vies_load_textdomain' );


