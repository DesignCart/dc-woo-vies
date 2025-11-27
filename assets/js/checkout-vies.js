// assets/js/checkout-vies.js

jQuery(document).ready(function($) {
    var input        = $("#order-dc-vies-nip_vat");
    var input_panel  = input.parent();
    var checkbox     = $("#order-dc-vies-request_invoice");
    var countryInput = $("#billing-country");
    var params       = dc_vies_params;

    if (!checkbox.is(':checked')) {
        input_panel.slideUp(); 
    }

    checkbox.on('change', toggleField);
    input.on('keyup', cleanInput);

    input.on('change', checkVies);

    function toggleField() {
        if (checkbox.is(':checked')) {
            input_panel.slideDown(300);
            
        } else {
            input_panel.slideUp(300);
            input.val(''); 
            input_panel.removeClass('vies-valid vies-invalid');
            $('form.checkout').trigger('update_checkout');
        }
    }

    function cleanInput() {
        var $this       = $(this);
        var value       = $this.val();
        var countryCode = countryInput.val();
        
        var cleanedValue = value.replace(/[^0-9]/g, ''); 
        
        if (cleanedValue.length > 10 && countryCode === 'PL') {
            cleanedValue = cleanedValue.substring(0, 10);
        }

        $this.val(cleanedValue);

        if (cleanedValue.length === 10) {
            //setTimeout(checkVies, 500);
        }
        
    }

    function checkVies() {
    
        var vat         = input.val();
        var countryCode = countryInput.val();
        
        // 1. Walidacja wewnętrzna: Sprawdź, czy walidacja VIES jest potrzebna
        if (!checkbox.is(':checked')) {
            // Jeśli NIP jest niekompletny lub checkbox odznaczony, po prostu zresetuj VAT i przerwij
            $('form.checkout').trigger('update_checkout');
            input_panel.removeClass('vies-loading vies-valid vies-invalid');
            return;
        }
        
        input_panel.addClass('vies-loading');
        input_panel.removeClass('vies-valid vies-invalid');

        input.prop('disabled', true);

        $.ajax({
            type: 'POST',
            url: params.ajax_url,
            data: {
                action: 'dc_vies_validate_nip',
                security: params.security_nonce,
                nip: vat,
                country: countryCode,
            },
            success: function(response) {
                input.prop('disabled', false);
                input_panel.removeClass('vies-loading');
                
                if (response.success && response.data.status) {
                    
                    var status = response.data.status; 
                    if (status === 'valid') { 
                        console.log('1:' + status);
                        input_panel.addClass('vies-valid');
                        // W tym momencie PHP (AJAX endpoint) ustawił flagę VAT EXEMPT
                        // Musimy zaktualizować koszyk, by zobaczyć 0% VAT
                    } else if (status === 'invalid') { 
                        console.log('2:' + status);
                        input_panel.addClass('vies-invalid');
                        // PHP ustawił VAT na 23%
                    } else {
                        // status === 'not_applicable' (Kraj macierzysty/spoza UE)
                        console.log('3:' + status);
                        input_panel.removeClass('vies-valid');
                        input_panel.addClass('vies-invalid');
                    }
                } else {
                    // Nieoczekiwany błąd (np. błąd serwisu VIES)
                    input_panel.addClass('vies-invalid');
                    console.error('Błąd VIES (odpowiedź serwera): ', response);
                }
                
                if (window.wp && wp.data && wp.data.dispatch) {

                    const cartStore = wp.data.dispatch('wc/store/cart');
                    const checkoutStore = wp.data.dispatch('wc/store/checkout');

                    if (cartStore?.invalidateResolutionForStore) {
                        cartStore.invalidateResolutionForStore();
                    }

                    if (checkoutStore?.invalidateResolutionForStore) {
                        checkoutStore.invalidateResolutionForStore();
                    }
                }

                setTimeout(function() {
                    $(document.body).trigger('wc_fragment_refresh');
                }, 600);
            },
            error: function(jqXHR, textStatus, errorThrown) {
                input.prop('disabled', false);
                input_panel.removeClass('vies-loading').addClass('vies-invalid');
                console.error('Błąd komunikacji AJAX:', textStatus, errorThrown);
                // Wymuś przeliczenie na wypadek błędu, by naliczyć VAT domyślnie
                $('form.checkout').trigger('update_checkout');
            }
        });
    }

});


