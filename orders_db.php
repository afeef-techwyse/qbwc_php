<?php

// Define log file path
$logFile = 'order_log.txt';

// Simple log function
function logMessage($message) {
    global $logFile;
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[$timestamp] $message\n", FILE_APPEND);
}

try {
    // Read and decode input
    $rawData = file_get_contents('php://input');
    logMessage("Raw input: " . $rawData);

    $orderData = json_decode($rawData, true);
    if (!$orderData) {
        http_response_code(400);
        logMessage("❌ Invalid JSON input");
        exit('Invalid data');
    }

    // Database connection
    $dsn = "mysql:host=mysql.railway.internal;dbname=railway;charset=utf8mb4";
    $username = "root";
    $password = "wTVIYIVbAlJdCqIwbHigEVotdGKGdHNA";

    $pdo = new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, // Throw exceptions on error
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    // Insert query
    $stmt = $pdo->prepare("
        INSERT INTO orders_queue (shopify_order_id, payload, status)
        VALUES (:oid, :payload, 'pending')
    ");
    $stmt->execute([
        ':oid' => $orderData['id'],
        ':payload' => json_encode($orderData)
    ]);

    logMessage("✅ Insert successful for order ID: " . $orderData['id']);

    http_response_code(200);
    echo "OK";

} catch (PDOException $e) {
    logMessage("❌ PDO Error: " . $e->getMessage());
    http_response_code(500);
    echo "Database error";
} catch (Exception $e) {
    logMessage("❌ General Error: " . $e->getMessage());
    http_response_code(500);
    echo "Error";
}
?>