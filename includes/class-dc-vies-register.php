<?php
// includes/class-dc-vies-register.php

use Automattic\WooCommerce\StoreApi\Exceptions\StoreApiException; // Konieczne dla rzucania wyjątków w Blocks

class DC_VIES_Register {

    private ViesValidator $validator; 
    
    private const NIP_FIELD_KEY = 'dc-vies/nip_vat';
    private const INV_FIELD_KEY = 'dc-vies/request_invoice';
    
    public function __construct() {
        $this->validator = new ViesValidator();
        
        // HOOK KLUCZOWY: Rejestracja pól w WC 8.8+ (automatycznie Blocks Ready)
        add_action( 'woocommerce_init', array( $this, 'register_vies_fields' ), 20 );

        // Walidacja dla Blocks (Store API)
        add_action( 'woocommerce_store_api_checkout_validate_additional_fields', array( $this, 'validate_vat_logic_in_blocks' ), 10, 2 );

        // Walidacja dla klasycznego shortcode
        add_action( 'woocommerce_after_checkout_validation', array( $this, 'validate_vat_logic_classic' ), 10, 2 );
        
        // Hook do przeliczania VAT (dla obu trybów)
        add_action( 'woocommerce_checkout_update_order_review', array( $this, 'update_vat_status' ), 10 );

        // HOOKI AJAX DLA WALIDACJI W CZASIE RZECZYWISTYM (onkeyup/onchange)
        add_action( 'wp_ajax_dc_vies_validate_nip', array( $this, 'ajax_validate_nip' ) );
        add_action( 'wp_ajax_nopriv_dc_vies_validate_nip', array( $this, 'ajax_validate_nip' ) );

        add_filter( 'woocommerce_product_get_tax_class', array( $this, 'set_zero_tax_class' ), 9999, 2 );
        add_filter( 'woocommerce_product_variation_get_tax_class', array( $this, 'set_zero_tax_class' ), 9999, 2 );
    }

    /**
     * Rejestruje pole NIP/VAT i Checkbox Faktury.
     */
    public function register_vies_fields() {
        if ( ! function_exists( 'woocommerce_register_additional_checkout_field' ) ) {
            return; // Wymaga WC 8.8+
        }
        
        // --- 1. CHECKBOX "CHCE FAKTURĘ" ---
        woocommerce_register_additional_checkout_field(
            array(
                'id'            => self::INV_FIELD_KEY,
                'label'         => __( 'Chcę otrzymać fakturę VAT', 'dc-woo-vies' ),
                'location'      => 'order', // Umieszczenie w sekcji "Twoje zamówienie"
                'type'          => 'checkbox',
                'required'      => false,
            )
        );

        // --- 2. POLE NIP/VAT ID ---
        woocommerce_register_additional_checkout_field(
            array(
                'id'            => self::NIP_FIELD_KEY,
                'label'         => __( 'NIP/VAT ID', 'dc-woo-vies' ),
                'location'      => 'order', // Umieszczenie w sekcji "Twoje zamówienie"
                'type'          => 'text',
                'required'      => false
            )
        );
    }
    
    // --- 1. METODY WALIDACJI CHECKOUT (PHP) ---

    /**
     * Walidacja i logika VAT dla WC Blocks (Store API).
     */
    public function validate_vat_logic_in_blocks( $fields_data, $request ) {
        
        $request_invoice = $fields_data[ self::INV_FIELD_KEY ] ?? false;
        
        if ( ! $request_invoice ) {
            WC()->customer->set_is_vat_exempt( false );
            return;
        }
        
        $nip_vat_id = $fields_data[ self::NIP_FIELD_KEY ] ?? '';
        $billing_country = $request->get_billing_country();
        
        // Wymagaj pola NIP, jeśli checkbox jest zaznaczony
        if ( empty( $nip_vat_id ) ) {
            // Rzucenie wyjątku dla Store API (blokuje finalizację)
            throw new StoreApiException( 
                'dc_vies_nip_required', 
                __( 'Wymagane pole: NIP/VAT ID jest puste, jeśli zaznaczono fakturę.', 'dc-woo-vies' ),
                400
            );
        }
        
        // Uruchomienie logiki VIES
        $is_valid = $this->run_vies_logic_and_set_vat_status( $nip_vat_id, $billing_country );

        if (!$is_valid) {
             // Jeśli walidacja VIES się nie powiodła, ale jest to transakcja WDT, blokujemy zamówienie
             $base_country = WC()->countries->get_base_country();
             if (in_array($billing_country, $this->get_eu_country_codes()) && $billing_country !== $base_country) {
                 throw new StoreApiException( 
                    'dc_vies_invalid', 
                    __( 'NIP jest nieprawidłowy w systemie VIES. Proszę go poprawić, aby uzyskać zwolnienie z VAT.', 'dc-woo-vies' ),
                    400
                );
             }
        }

        // Zapis danych do sesji, aby DC_Order_Meta mogła je przechwycić po finalizacji
        WC()->session->set( 'dc_vies_temp_data', [
            'nip'             => $nip_vat_id,
            'country'         => $billing_country,
            'request_invoice' => true,
        ]);
    }
    
    /**
     * Walidacja i logika VAT dla klasycznego shortcode.
     */
    public function validate_vat_logic_classic( $posted_data, $errors ) {
        // Dane klasyczne są w $_POST
        $request_invoice = isset( $_POST[ self::INV_FIELD_KEY ] ) && $_POST[ self::INV_FIELD_KEY ] === '1';
        
        if ( ! $request_invoice ) {
            WC()->customer->set_is_vat_exempt( false );
            return; 
        }

        $nip_vat_id      = isset( $_POST[ self::NIP_FIELD_KEY ] ) ? sanitize_text_field( $_POST[ self::NIP_FIELD_KEY ] ) : '';
        $billing_country = isset( $_POST['billing_country'] ) ? sanitize_text_field( $_POST['billing_country'] ) : '';

        if ( empty( $nip_vat_id ) ) {
            $errors->add( 'validation', __( 'Wymagane pole: NIP/VAT ID jest puste, jeśli zaznaczono fakturę.', 'dc-woo-vies' ) );
            return;
        }

        // Uruchomienie logiki VIES
        $is_valid = $this->run_vies_logic_and_set_vat_status( $nip_vat_id, $billing_country );
        
        // Jeśli walidacja VIES się nie powiodła, ale jest to transakcja WDT, dodajemy błąd
        $base_country = WC()->countries->get_base_country();
        if (!$is_valid && in_array($billing_country, $this->get_eu_country_codes()) && $billing_country !== $base_country) {
            $errors->add( 'validation', __( 'NIP jest nieprawidłowy w systemie VIES. Proszę go poprawić, aby uzyskać zwolnienie z VAT.', 'dc-woo-vies' ) );
        }
        
        // Zapis danych do sesji dla DC_Order_Meta
        WC()->session->set( 'dc_vies_temp_data', [
            'nip'             => $nip_vat_id,
            'country'         => $billing_country,
            'request_invoice' => true,
        ]);
    }
    
    /**
     * Wymusza przeliczenie statusu VAT przy aktualizacji koszyka.
     */
    public function update_vat_status() {
         // Tutaj logikę VIES wykonuje się w validate_vat_logic_in_blocks (dla Blocks)
         // lub w validate_vat_logic_classic (dla Classic).
         // Ta funkcja jest niezbędna, aby WooCommerce wiedział, że coś się zmieniło.
         // W tym przypadku polegamy głównie na hookach walidacyjnych.
         
         // Zapewniamy, że zwolnienie z VAT jest usuwane, jeśli sesja jest pusta
         if (WC()->session->get('dc_vies_status') === 'default' || !WC()->session->get('dc_vies_status')) {
             WC()->customer->set_is_vat_exempt( false );
         }
    }

    // --- 2. METODA AJAX (Walidacja na Onkeyup) ---

    /**
     * Obsługuje zapytanie AJAX: Weryfikuje NIP w VIES i zwraca status.
     */
    public function ajax_validate_nip() {
        
        check_ajax_referer( 'dc-vies-nonce', 'security' );
        
        $vat_id = sanitize_text_field( $_POST['nip'] ?? '' );
        $country_code = sanitize_text_field( $_POST['country'] ?? '' );
        
        if ( empty( $vat_id ) || empty( $country_code ) ) {
            wp_send_json_error( array( 'message' => __( 'Brak NIP lub kraju.', 'dc-woo-vies' ) ) );
        }
        
        $is_eu = in_array( $country_code, $this->get_eu_country_codes() );
        $base_country = WC()->countries->get_base_country();
        
        if ( $is_eu && $country_code !== $base_country ) {
            
            $vies_result = $this->validator->validateVatId( $country_code . $vat_id );

            if ( is_array( $vies_result ) ) {
                // Ustaw flagę zwolnienia VAT w sesji (tymczasowo)
                WC()->customer->set_is_vat_exempt( true ); 
                WC()->session->set( 'dc_vies_status', 'valid' );

                //WC()->cart->empty_woocommerce_session_and_cache(); // Opróżnia cache cen
                //WC()->cart->calculate_totals(); // Wymusza przeliczenie

                wp_send_json_success( array( 
                    'status' => 'valid', 
                    'message' => __( 'NIP zweryfikowany: VAT zostanie usunięty.', 'dc-woo-vies' ) 
                ) );
            } else {
                WC()->customer->set_is_vat_exempt( false ); 
                WC()->session->set( 'dc_vies_status', 'invalid' );
                
                wp_send_json_success( array( 
                    'status' => 'invalid', 
                    'message' => __( 'NIP nieprawidłowy w VIES. VAT zostanie naliczony.', 'dc-woo-vies' ) 
                ) );
            }
        } else {
            // Kraj macierzysty lub spoza UE: VIES nie dotyczy
            WC()->customer->set_is_vat_exempt( false ); 
            WC()->session->set( 'dc_vies_status', 'local_or_non_eu' );
            
            wp_send_json_success( array( 
                'status' => 'not_applicable', 
                'message' => __( 'Weryfikacja VIES nie wymagana.', 'dc-woo-vies' ) 
            ) );
        }
    }
    
    // --- 3. METODA VIES I POMOCNICZE ---

    /**
     * Weryfikuje NIP w VIES i ustawia flagę VAT EXEMPT.
     */
    private function run_vies_logic_and_set_vat_status(string $vatId, string $countryCode): bool {
        
        $eu_codes = $this->get_eu_country_codes(); 
        $base_country = WC()->countries->get_base_country();
        
        if ( in_array( $countryCode, $eu_codes ) && $countryCode !== $base_country ) {
            
            $vies_result = $this->validator->validateVatId( $countryCode . $vatId );
            
            if ( is_array( $vies_result ) ) {
                WC()->customer->set_is_vat_exempt( true ); 
                WC()->session->set( 'dc_vies_status', 'valid' );
                WC()->session->set( 'dc_vies_verification_result', $vies_result );
                return true;
            } else {
                WC()->customer->set_is_vat_exempt( false ); 
                WC()->session->set( 'dc_vies_status', 'invalid' );
                return false;
            }
        } else {
            WC()->customer->set_is_vat_exempt( false ); 
            WC()->session->set( 'dc_vies_status', 'local_or_non_eu' );
            return true; // Prawidłowy, bo nie wymaga VIES
        }
    }

    public function set_zero_tax_class( $tax_class, $product ) {
    
        // Sprawdź, czy flaga zwolnienia jest ustawiona dla klienta
        if ( WC()->customer && WC()->customer->get_is_vat_exempt() ) {
            
            // Zwracamy 'zero-rate' (domyślna klasa 0% w WC)
            return 'zero-rate';
        }
        
        return $tax_class;
    }
    
    private function get_eu_countries(): array { 
        return [
            'AT' => 'Austria',
            'BE' => 'Belgia',
            'BG' => 'Bułgaria',
            'HR' => 'Chorwacja',
            'CY' => 'Cypr',
            'CZ' => 'Czechy',
            'DK' => 'Dania',
            'EE' => 'Estonia',
            'FI' => 'Finlandia',
            'FR' => 'Francja',
            'DE' => 'Niemcy',
            'GR' => 'Grecja',
            'HU' => 'Węgry',
            'IE' => 'Irlandia',
            'IT' => 'Włochy',
            'LV' => 'Łotwa',
            'LT' => 'Litwa',
            'LU' => 'Luksemburg',
            'MT' => 'Malta',
            'NL' => 'Holandia',
            'PL' => 'Polska',
            'PT' => 'Portugalia',
            'RO' => 'Rumunia',
            'SK' => 'Słowacja',
            'SI' => 'Słowenia',
            'ES' => 'Hiszpania',
            'SE' => 'Szwecja',
        ];
    }

    

    private function get_eu_country_codes(): array { 
        // Wymagane do działania VIES (zgodne kody państw członkowskich VIES)
        return array_keys( $this->get_eu_countries() );
    }
}