<?php
// includes/class-dc-vat-logic.php

class DC_VAT_Logic {

    private ViesValidator $validator; 
    
    public function __construct() {
        // Zakładamy, że ViesValidator jest załadowany
        $this->validator = new ViesValidator();
        
        // Hooki będą dodane w klasach Checkout i Blocks, aby się poprawnie podpiąć.
    }

    // ... (metody get_eu_countries() i get_eu_country_codes() z poprzednich odpowiedzi)
    
    // --- KLUCZOWA METODA WERYFIKUJĄCA ZWOLNIENIE Z VAT ---
    
    /**
     * Weryfikuje NIP/VAT ID w VIES i ustawia flagę zwolnienia z VAT w koszyku.
     * Używana zarówno przez klasyczny Checkout, jak i Store API.
     * * @param string $vatId Numer VAT ID (np. DE123456789).
     * @param string $countryCode Kod kraju (np. DE).
     * @return bool|array Zwraca tablicę danych VIES lub false.
     */
    public function verify_and_set_vat_exempt(string $vatId, string $countryCode) {
        
        $base_country = WC()->countries->get_base_country();
        
        // 1. Sprawdzenie warunków WDT: Kraj EU i różny od kraju sklepu
        if ( in_array( $countryCode, self::get_eu_country_codes() ) && $countryCode !== $base_country ) {
            
            // 2. Weryfikacja w VIES
            $vies_result = $this->validator->validateVatId( $vatId ); 

            if ( is_array( $vies_result ) ) {
                // Sukces: Zwolnienie z VAT
                WC()->customer->set_is_vat_exempt( true ); 
                WC()->session->set( 'dc_vies_status', 'valid' );
                WC()->session->set( 'dc_vies_verification_result', $vies_result );
                return $vies_result;

            } else {
                // Błąd: NIP nieprawidłowy, VAT NALICZONY
                WC()->customer->set_is_vat_exempt( false ); 
                WC()->session->set( 'dc_vies_status', 'invalid' );
                return false;
            }
        } else {
            // Kraj macierzysty lub spoza UE: VAT NALICZONY
            WC()->customer->set_is_vat_exempt( false ); 
            WC()->session->set( 'dc_vies_status', 'local_or_non_eu' );
            return false;
        }
    }
}