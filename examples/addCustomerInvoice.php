<?php
// Critical: Configure error reporting before any output to ensure XML responses
error_reporting(E_ALL & ~E_NOTICE & ~E_STRICT & ~E_DEPRECATED);
ini_set('display_errors', 0);

try {
    $loader = require __DIR__.'/../vendor/autoload.php';

    $obj = new \QBWCServer\applications\AddCustomerInvoiceApp([
        'login' => 'Admin',
        'password' => '1',
        'iterator' => null
    ]);

    \QBWCServer\launcher\SoapLauncher::start($obj);
} catch (\Throwable $e) {
    // Log error but don't display it to client
    error_log('AddCustomerInvoiceApp Error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    // Return a proper SOAP fault instead of displaying the error
    http_response_code(500);
    header('Content-Type: text/xml');
    echo '<?xml version="1.0"?><soap:Fault xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><faultcode>Server</faultcode><faultstring>Internal Server Error</faultstring></soap:Fault>';
}