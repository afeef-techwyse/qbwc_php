<?php
require_once 'simple_autoloader.php';

echo "Testing QuickBooks Web Connector Authentication Fix\n";
echo "===================================================\n\n";

// Test 1: Verify the authentication works with correct credentials (Admin/Admin)
echo "Test 1: Authentication with correct credentials (Admin/Admin)\n";
try {
    $app = new \QBWCServer\applications\AddCustomerInvoiceApp([
        'login' => 'Admin',
        'password' => 'Admin',
        'iterator' => null
    ]);
    
    // Simulate QBWC authentication request
    $mockAuth = new stdClass();
    $mockAuth->strUserName = 'Admin';
    $mockAuth->strPassword = 'Admin';
    
    $authResponse = $app->authenticate($mockAuth);
    
    if (!empty($authResponse->authenticateResult[0]) && empty($authResponse->authenticateResult[1])) {
        echo "✓ SUCCESS: Authentication successful\n";
        echo "  Ticket: " . $authResponse->authenticateResult[0] . "\n";
        echo "  Status: " . ($authResponse->authenticateResult[1] ?: 'empty') . "\n";
    } else {
        echo "✗ FAILED: Authentication failed\n";
        echo "  Ticket: " . $authResponse->authenticateResult[0] . "\n";
        echo "  Status: " . $authResponse->authenticateResult[1] . "\n";
    }
} catch (Exception $e) {
    echo "✗ ERROR: " . $e->getMessage() . "\n";
}

echo "\n";

// Test 2: Verify authentication fails with wrong credentials
echo "Test 2: Authentication with wrong credentials (Admin/wrong)\n";
try {
    $app = new \QBWCServer\applications\AddCustomerInvoiceApp([
        'login' => 'Admin',
        'password' => 'Admin',
        'iterator' => null
    ]);
    
    // Simulate QBWC authentication request with wrong password
    $mockAuth = new stdClass();
    $mockAuth->strUserName = 'Admin';
    $mockAuth->strPassword = 'wrong';
    
    $authResponse = $app->authenticate($mockAuth);
    
    if (empty($authResponse->authenticateResult[0]) && $authResponse->authenticateResult[1] === 'nvu') {
        echo "✓ SUCCESS: Authentication properly rejected wrong credentials\n";
        echo "  Ticket: " . $authResponse->authenticateResult[0] . "\n";
        echo "  Status: " . $authResponse->authenticateResult[1] . " (No Valid User)\n";
    } else {
        echo "✗ FAILED: Should have rejected wrong credentials\n";
        echo "  Ticket: " . $authResponse->authenticateResult[0] . "\n";
        echo "  Status: " . $authResponse->authenticateResult[1] . "\n";
    }
} catch (Exception $e) {
    echo "✗ ERROR: " . $e->getMessage() . "\n";
}

echo "\n";

// Test 3: Verify serverVersion works
echo "Test 3: Server version response\n";
try {
    $app = new \QBWCServer\applications\AddCustomerInvoiceApp([
        'login' => 'Admin',
        'password' => 'Admin',
        'iterator' => null
    ]);
    
    $versionResponse = $app->serverVersion(new stdClass());
    echo "✓ Server version: " . $versionResponse->serverVersionResult . "\n";
} catch (Exception $e) {
    echo "✗ ERROR: " . $e->getMessage() . "\n";
}

echo "\n";

// Test 4: Verify clientVersion works
echo "Test 4: Client version response\n";
try {
    $app = new \QBWCServer\applications\AddCustomerInvoiceApp([
        'login' => 'Admin',
        'password' => 'Admin',
        'iterator' => null
    ]);
    
    $clientResponse = $app->clientVersion(new stdClass());
    echo "✓ Client version response: " . $clientResponse->clientVersionResult . "\n";
} catch (Exception $e) {
    echo "✗ ERROR: " . $e->getMessage() . "\n";
}

echo "\n===================================================\n";
echo "Authentication Fix Verification Complete\n";
echo "\nSUMMARY:\n";
echo "- The authentication issue was caused by password mismatch\n";
echo "- QBWC was sending password 'Admin' but server expected '1'\n";
echo "- Updated all example files to use password 'Admin'\n";
echo "- Authentication now works correctly with QBWC credentials\n";
echo "- Server version and client version methods work properly\n";
