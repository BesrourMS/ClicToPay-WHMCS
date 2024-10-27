<?php
/**
 * WHMCS Clictopay Payment Gateway Module
 *
 * @see https://developers.whmcs.com/payment-gateways/
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

/**
 * Define module related meta data.
 *
 * @return array
 */
function clictopay_MetaData()
{
    return array(
        'DisplayName' => 'Clictopay',
        'APIVersion' => '1.1',
        'DisableLocalCreditCardInput' => true,
        'TokenisedStorage' => false,
    );
}

/**
 * Define gateway configuration options.
 *
 * @return array
 */
function clictopay_config()
{
    return array(
        'FriendlyName' => array(
            'Type' => 'System',
            'Value' => 'Clictopay',
        ),
        'username' => array(
            'FriendlyName' => 'Merchant Username',
            'Type' => 'text',
            'Size' => '25',
            'Default' => '',
            'Description' => 'Enter your Clictopay merchant username',
        ),
        'password' => array(
            'FriendlyName' => 'Merchant Password',
            'Type' => 'password',
            'Size' => '25',
            'Default' => '',
            'Description' => 'Enter your Clictopay merchant password',
        ),
        'testMode' => array(
            'FriendlyName' => 'Test Mode',
            'Type' => 'yesno',
            'Description' => 'Tick to enable test mode',
        ),
    );
}

/**
 * Payment link generation.
 *
 * @param array $params Payment Gateway Module Parameters
 * @return string
 */
function clictopay_link($params)
{
    // Gateway Configuration Parameters
    $username = $params['username'];
    $password = $params['password'];
    $testMode = $params['testMode'];
    
    // System Parameters
    $companyName = $params['companyname'];
    $systemUrl = $params['systemurl'];
    $returnUrl = $params['returnurl'];
    $langPayNow = $params['langpaynow'];
    $moduleDisplayName = $params['name'];
    $moduleName = $params['paymentmethod'];
    $whmcsVersion = $params['whmcsVersion'];

    // Invoice Parameters
    $invoiceId = $params['invoiceid'];
    $description = $params["description"];
    $amount = $params['amount'] * 100; // Convert to cents
    $currencyCode = $params['currency'];
    
    // Convert currency code to ISO 4217 numeric
    $currencyMapping = array(
        'TND' => 788,
        // Add other currencies if supported
    );
    
    $numericCurrency = $currencyMapping[$currencyCode] ?? 788; // Default to TND
    
    // API Endpoint
    $url = $testMode ? 'https://test.clictopay.com/payment/rest' : 'https://ipay.clictopay.com/payment/rest';
    
    // Transaction data
    $postfields = array(
        'userName' => $username,
        'password' => $password,
        'orderNumber' => $invoiceId,
        'amount' => $amount,
        'currency' => $numericCurrency,
        'returnUrl' => $returnUrl,
        'language' => 'fr' // You can make this configurable if needed
    );

    try {
        // Initialize cURL
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url . '/register.do');
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postfields));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        
        $response = curl_exec($ch);
        
        if (curl_errno($ch)) {
            throw new Exception('cURL Error: ' . curl_error($ch));
        }
        
        curl_close($ch);
        
        $result = json_decode($response, true);
        
        if (isset($result['formUrl'])) {
            // Store orderId in a database or session for later verification
            $_SESSION['clictopay_order_id_' . $invoiceId] = $result['orderId'];
            
            return '<form method="GET" action="' . $result['formUrl'] . '">'
                . '<input type="submit" value="' . $langPayNow . '" />'
                . '</form>';
        } else {
            throw new Exception('Invalid response from Clictopay');
        }
        
    } catch (Exception $e) {
        return 'Unable to initialize payment: ' . $e->getMessage();
    }
}

/**
 * Callback Handler
 *
 * @param array $params Payment Gateway Module Parameters
 * @return array
 */
function clictopay_callback($params)
{
    // Retrieve stored orderId
    $orderId = $_SESSION['clictopay_order_id_' . $params['invoiceid']] ?? '';
    
    if (!$orderId) {
        return array(
            'status' => 'error',
            'message' => 'Invalid Order ID',
        );
    }

    // Gateway Configuration Parameters
    $username = $params['username'];
    $password = $params['password'];
    $testMode = $params['testMode'];
    
    // API Endpoint
    $url = $testMode ? 'https://test.clictopay.com/payment/rest' : 'https://ipay.clictopay.com/payment/rest';
    
    // Check payment status
    $queryParams = http_build_query([
        'userName' => $username,
        'password' => $password,
        'orderId' => $orderId,
        'language' => 'fr'
    ]);
    
    try {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url . '/getOrderStatus.do?' . $queryParams);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        
        $response = curl_exec($ch);
        
        if (curl_errno($ch)) {
            throw new Exception('cURL Error: ' . curl_error($ch));
        }
        
        curl_close($ch);
        
        $result = json_decode($response, true);
        
        // Check if payment was successful
        // You may need to adjust these status codes based on Clictopay's documentation
        if ($result['ErrorCode'] === '0' && $result['OrderStatus'] === 2) {
            return array(
                'status' => 'success',
                'transid' => $orderId,
                'amount' => $result['Amount'] / 100, // Convert back from cents
            );
        } else {
            return array(
                'status' => 'declined',
                'declinetype' => 'card', // Other options: 'carderror', 'card', 'provider'
                'rawdata' => $response,
            );
        }
        
    } catch (Exception $e) {
        return array(
            'status' => 'error',
            'message' => $e->getMessage(),
            'rawdata' => $response ?? null,
        );
    }
}
