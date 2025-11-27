<?php
class DC_Order_Meta {
    
    public function __construct() {
        // Zapis danych firmy do zamówienia po finalizacji
        add_action( 'woocommerce_checkout_create_order', array( $this, 'save_company_data_to_order' ), 10, 2 );
        
        // Wyświetlanie danych w panelu administracyjnym
        add_action( 'woocommerce_admin_order_data_after_billing_address', array( $this, 'display_order_data_in_admin' ) );
    }

    /**
     * Zapisuje dane firmy i status VIES do metadanych zamówienia.
     */
    public function save_company_data_to_order( $order, $data ) {
        if ( isset( $data['dc_vies_request_invoice'] ) && '1' === $data['dc_vies_request_invoice'] ) {
            
            // Zapis danych z pól formularza
            $order->update_meta_data( '_dc_vies_request_invoice', 'yes' );
            $order->update_meta_data( '_dc_vies_company_name', sanitize_text_field( $data['dc_vies_company_name'] ) );
            $order->update_meta_data( '_dc_vies_country_code', sanitize_text_field( $data['dc_vies_country_code'] ) );
            $order->update_meta_data( '_dc_vies_nip', sanitize_text_field( $data['dc_vies_nip'] ) );

            // Zapis statusu VIES i pełnego wyniku z sesji (ustawionego w DC_VAT_Logic)
            if ( $vies_status = WC()->session->get( 'dc_vies_status' ) ) {
                $order->update_meta_data( '_dc_vies_status', $vies_status );
            }
            if ( $vies_result = WC()->session->get( 'dc_vies_verification_result' ) ) {
                $order->update_meta_data( '_dc_vies_full_result', $vies_result );
            }
            
            // Po zapisie, usuń dane z sesji, aby nie zostały w kolejnym zamówieniu
            WC()->session->__unset( 'dc_vies_status' );
            WC()->session->__unset( 'dc_vies_verification_result' );
        }
    }

    /**
     * Wyświetla dane firmy i status VIES w panelu administracyjnym zamówienia.
     */
    public function display_order_data_in_admin( $order ) {
        
        if ( 'yes' !== $order->get_meta( '_dc_vies_request_invoice' ) ) {
            return;
        }

        echo '<div class="address">';
        echo '<h3>' . __( 'Dane do Faktury (DC VIES)', 'dc-woo-vies' ) . '</h3>';

        $nip_status = $order->get_meta( '_dc_vies_status' );
        
        echo '<p><strong>' . __( 'Nazwa Firmy', 'dc-woo-vies' ) . ':</strong> ' . $order->get_meta( '_dc_vies_company_name' ) . '</p>';
        echo '<p><strong>' . __( 'Kraj Rejestracji', 'dc-woo-vies' ) . ':</strong> ' . $order->get_meta( '_dc_vies_country_code' ) . '</p>';
        echo '<p><strong>' . __( 'NIP/VAT ID', 'dc-woo-vies' ) . ':</strong> ' . $order->get_meta( '_dc_vies_nip' ) . '</p>';
        
        // Informacja dla admina o weryfikacji
        if ( 'valid' === $nip_status ) {
            echo '<p style="color: green;"><strong>' . __( 'Status VIES', 'dc-woo-vies' ) . ':</strong> ' . __( 'NIP WAŻNY. Klient zwolniony z VAT (wewnątrzwspólnotowa dostawa).', 'dc-woo-vies' ) . '</p>';
        } elseif ( 'invalid' === $nip_status ) {
            echo '<p style="color: orange;"><strong>' . __( 'Status VIES', 'dc-woo-vies' ) . ':</strong> ' . __( 'NIP NIEPRAWIDŁOWY/BŁĄD WERYFIKACJI. VAT NALICZONY.', 'dc-woo-vies' ) . '</p>';
        } else {
             echo '<p><strong>' . __( 'Status VIES', 'dc-woo-vies' ) . ':</strong> ' . __( 'Brak weryfikacji (Kraj macierzysty lub spoza EU). VAT NALICZONY.', 'dc-woo-vies' ) . '</p>';
        }
        
        echo '</div>';
    }
}