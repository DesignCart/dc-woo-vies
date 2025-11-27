<?php

class ViesValidator
{
    /**
     * @var string Adres URL usługi VIES SOAP
     */
    private const VIES_WSDL_URL = 'https://ec.europa.eu/taxation_customs/vies/checkVatService.wsdl';

    /**
     * Weryfikuje numer NIP (VAT ID) w systemie VIES.
     *
     * @param string $vatId Numer NIP do sprawdzenia (np. "PL1234567890").
     * @return bool|array Zwraca true, jeśli NIP jest ważny, false, jeśli jest nieprawidłowy, 
     * lub tablicę z pełnymi danymi, jeśli weryfikacja się powiedzie 
     * (zgodnie z Twoim życzeniem).
     */
    public function validateVatId(string $vatId): bool|array
    {
        // 1. Oczyszczenie i walidacja formatu NIP-u
        $vatId = trim($vatId);
        
        // Sprawdzenie, czy NIP jest pusty
        if (empty($vatId)) {
            return false;
        }

        // Wydzielenie kodu kraju i numeru NIP
        // Zakładamy, że kod kraju to pierwsze 2 litery (np. PL, DE, FR)
        $countryCode = strtoupper(substr($vatId, 0, 2));
        $vatNumber   = substr($vatId, 2);

        // Krótka weryfikacja, czy mamy kod kraju i numer. 
        // W bardziej rozbudowanej klasie można dodać walidację RegExp dla każdego kraju.
        if (strlen($countryCode) !== 2 || empty($vatNumber)) {
            // Można tu rzucić wyjątek lub zwrócić false, w zależności od potrzeb
            return false; 
        }

        try {
            // 2. Utworzenie klienta SOAP
            $client = new SoapClient(self::VIES_WSDL_URL, [
                'trace'      => 1, // Włączenie śledzenia błędów
                'exceptions' => true, // Użycie wyjątków dla błędów SOAP
                'cache_wsdl' => WSDL_CACHE_BOTH // Cache WSDL
            ]);

            // 3. Wywołanie metody checkVat
            $response = $client->checkVat([
                'countryCode' => $countryCode,
                'vatNumber'   => $vatNumber
            ]);
            
            // 4. Analiza odpowiedzi
            if (isset($response->valid) && $response->valid === true) {
                // Jeśli jest ważny, zbieramy i zwracamy pełne dane w tablicy
                $result = [
                    'valid'              => $response->valid,
                    'countryCode'        => $response->countryCode,
                    'vatNumber'          => $response->vatNumber,
                    'requestDate'        => $response->requestDate,
                    'name'               => $response->name,
                    'address'            => $response->address,
                    'identifier'         => $response->identifier ?? null, // Nowsze pola
                    'traderName'         => $response->traderName ?? null,
                    'traderCompanyType'  => $response->traderCompanyType ?? null,
                    'traderAddress'      => $response->traderAddress ?? null,
                ];
                
                // Zgodnie z Twoim życzeniem, zwracamy tablicę z danymi, jeśli są dostępne
                // i NIP jest ważny.
                return $result;
            } else {
                // NIP jest nieprawidłowy lub VIES go nie odnalazł
                return false; 
            }

        } catch (SoapFault $e) {
            // Obsługa błędów SOAP (np. usługa niedostępna, przekroczono limit zapytań)
            error_log("Błąd VIES SOAP: " . $e->getMessage());

            // W przypadku błędu VIES, zgodnie z Twoją logiką, 
            // jeśli VIES "nie zwraca danych" z powodu błędu, zwracamy false.
            return false;

        } catch (\Exception $e) {
            // Inne nieoczekiwane błędy
            error_log("Nieoczekiwany błąd w ViesValidator: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Alternatywna metoda zwracająca tylko true/false, jeśli jest to preferowane.
     * * @param string $vatId Numer NIP.
     * @return bool True, jeśli ważny, false w przeciwnym razie lub w razie błędu.
     */
    public function isVatIdValid(string $vatId): bool
    {
        $result = $this->validateVatId($vatId);
        
        // Sprawdza, czy wynik jest tablicą (czyli był ważny), lub czy jest prostym true
        return is_array($result) || $result === true;
    }
}

