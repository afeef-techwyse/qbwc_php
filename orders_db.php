<?php

$rawData = file_get_contents('php://input');
$orderData = json_decode($rawData, true);

if (!$orderData) {
    http_response_code(400);
    exit('Invalid data');
}

$pdo = new PDO("mysql:host=mysql.railway.internal1;dbname=railway","root","wTVIYIVbAlJdCqIwbHigEVotdGKGdHNA");
$stmt = $pdo->prepare("
    INSERT INTO orders_queue (shopify_order_id, payload, status)
    VALUES (:oid, :payload, 'pending')
");
$stmt->execute([
    ':oid' => $orderData['id'],
    ':payload' => json_encode($orderData)
]);

http_response_code(200);
echo "OK";
?>