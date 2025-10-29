<?php
namespace QBWCServer\applications;

use QBWCServer\base\AbstractQBWCApplication;
use QBWCServer\response\ReceiveResponseXML;
use QBWCServer\response\SendRequestXML;

class AddCustomerInvoiceApp extends AbstractQBWCApplication
{
    private $dsn = "mysql:host=shortline.proxy.rlwy.net;port=53111;dbname=railway";
    private $dbUser = "root";
    private $dbPass = "wTVIYIVbAlJdCqIwbHigEVotdGKGdHNA";

    private $orders = [];
    private $currentOrderIndex = 0;
    private $stage = 'query_customer';
    private $customerName;
    private $currentItemIndex = 0;
    private $currentOrderItems = [];
    private $currentDbOrderId = null;
    // ---------------------- Database Methods ----------------------
    private function getDbConnection() {
        try {
            $pdo = new \PDO($this->dsn, $this->dbUser, $this->dbPass);
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            return $pdo;
        } catch (\PDOException $e) {
            $this->log("Database connection error: " . $e->getMessage());
            return null;
        }
    }

    private function fetchPendingOrders() {
        $pdo = $this->getDbConnection();
        if (!$pdo) {
            $this->log("Failed to connect to database");
            return;
        }

        try {
            $stmt = $pdo->prepare("SELECT id, shopify_order_id, payload FROM orders_queue WHERE status = 'pending' ORDER BY id ASC");
            $stmt->execute();
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            $this->orders = [];
            foreach ($rows as $row) {
                $payload = json_decode($row['payload'], true);
                if ($payload) {
                    $order = $this->transformShopifyOrder($payload, $row['id']);
                    if ($order) {
                        $this->orders[] = $order;
                    }
                }
            }
            $this->log("Fetched " . count($this->orders) . " pending orders from database");
            error_log("Fetched " . count($this->orders) . " pending orders from database. orders are ".json_encode($this->orders));
        } catch (\PDOException $e) {
            $this->log("Error fetching orders: " . $e->getMessage());
        }
    }

    private function transformShopifyOrder($shopifyData, $dbId) {
        $customer = $shopifyData['customer'] ?? null;
        $billingAddress = $shopifyData['billing_address'] ?? $shopifyData['shipping_address'] ?? null;
        $lineItems = $shopifyData['line_items'] ?? [];

        if (!$customer || !$billingAddress) {
            $this->log("Incomplete customer/address data for order ID: {$dbId}");
            return null;
        }

        $transformedLineItems = [];
        foreach ($lineItems as $item) {
            $transformedLineItems[] = [
                'title' => $item['sku'] ?? $item['name'] ?? 'Unknown Item',
                'quantity' => $item['quantity'] ?? 1,
                'price' => $item['price'] ?? '0.00'
            ];
        }

        return [
            'db_id' => $dbId,
            'id' => $shopifyData['id'] ?? $dbId,
            'order_number' => $shopifyData['name'] ?? $shopifyData['order_number'] ?? "ORD-{$dbId}",
            'customer' => [
                'first_name' => $customer['first_name'] ?? '',
                'last_name' => $customer['last_name'] ?? '',
                'email' => $customer['email'] ?? '',
                'phone' => $customer['phone'] ?? $billingAddress['phone'] ?? '',
                'default_address' => [
                    'company' => $billingAddress['company'] ?? '',
                    'address1' => $billingAddress['address1'] ?? '',
                    'city' => $billingAddress['city'] ?? '',
                    'province' => $billingAddress['province_code'] ?? $billingAddress['province'] ?? '',
                    'zip' => $billingAddress['zip'] ?? '',
                    'country' => $billingAddress['country'] ?? ''
                ]
            ],
            'line_items' => $transformedLineItems
        ];
    }

    private function updateOrderStatus($dbId, $status) {
        $pdo = $this->getDbConnection();
        if (!$pdo) {
            $this->log("Failed to connect to database for status update");
            return;
        }

        try {
            $stmt = $pdo->prepare("UPDATE orders_queue SET status = :status WHERE id = :id");
            $stmt->execute([':status' => $status, ':id' => $dbId]);
            $this->log("Updated order {$dbId} status to: {$status}");
        } catch (\PDOException $e) {
            $this->log("Error updating order status: " . $e->getMessage());
        }
    }

    // ---------------------- State Persistence ----------------------
    private function loadState() {
        $path = '/tmp/qbwc_app_state.json';
        if (file_exists($path)) {
            $state = json_decode(file_get_contents($path), true);
            if (is_array($state)) {
                $this->currentOrderIndex = $state['index'];
                $this->stage = $state['stage'];
                $this->currentItemIndex = $state['itemIndex'] ?? 0;
                $this->currentOrderItems = $state['orderItems'] ?? [];
                $this->currentDbOrderId = $state['dbOrderId'] ?? null;
            }
        }

        // Ensure we have orders loaded into memory (process may be new)
        if (empty($this->orders)) {
            $this->fetchPendingOrders();
        }
    }

    private function saveState() {
        $state = [
            'index' => $this->currentOrderIndex,
            'stage' => $this->stage,
            'itemIndex' => $this->currentItemIndex,
            'orderItems' => $this->currentOrderItems,
            'dbOrderId' => $this->currentDbOrderId
        ];
        file_put_contents('/tmp/qbwc_app_state.json', json_encode($state));
    }
    private function resetState() {
        $this->currentOrderIndex = 0;
        $this->stage = 'query_customer';
        $this->currentItemIndex = 0;
        $this->currentOrderItems = [];
        $this->currentDbOrderId = null;
        @unlink('/tmp/qbwc_app_state.json');
    }

    // ---------------------- Logging ----------------------
    private function log($msg) {
        $ts = date('Y-m-d H:i:s');
    }

    // ---------------------- QBWC Methods ----------------------
    public function sendRequestXML($object)
    {
        $this->loadState();

        if (count($this->orders) === 0) {
            $this->fetchPendingOrders();
        }

        if ($this->currentOrderIndex >= count($this->orders)) {
            $this->log("All orders processed. Nothing to send.");
            $this->resetState();
            return new SendRequestXML('');
        }

        $order = $this->orders[$this->currentOrderIndex];
        $this->currentDbOrderId = $order['db_id'];
        $this->customerName = trim($order['customer']['first_name'] . ' ' . $order['customer']['last_name']);
        
        // Get QBXML version from request parameters
        $qbxmlVersion = $object->qbXMLMajorVers . "." . $object->qbXMLMinorVers;

        $this->log("Stage: {$this->stage} -- Order: {$order['order_number']} (Customer: {$this->customerName})");

        if ($this->stage === 'query_customer') {
            $xml = '<?xml version="1.0" encoding="utf-8"?>
<?qbxml version="' . $qbxmlVersion . '"?>
<QBXML>
  <QBXMLMsgsRq onError="stopOnError">
    <CustomerQueryRq requestID="' . $this->generateGUID() . '">
      <FullName>' . htmlspecialchars($this->customerName, ENT_XML1, 'UTF-8') . '</FullName>
    </CustomerQueryRq>
  </QBXMLMsgsRq>
</QBXML>';
            $this->log("Sending CustomerQueryRq XML:\n$xml");
            $this->saveState();
            return new SendRequestXML($xml);
        }

        if ($this->stage === 'add_customer') {
            $cust = $order['customer'];
            $addr = $cust['default_address'];
            $xml = '<?xml version="1.0" encoding="utf-8"?>
<?qbxml version="' . $qbxmlVersion . '"?>
<QBXML>
  <QBXMLMsgsRq onError="stopOnError">
    <CustomerAddRq requestID="' . $this->generateGUID() . '">
      <CustomerAdd>
        <Name>' . htmlspecialchars($this->customerName, ENT_XML1, 'UTF-8') . '</Name>
        <CompanyName>' . htmlspecialchars($addr['company'] ?? '', ENT_XML1, 'UTF-8') . '</CompanyName>
        <FirstName>' . htmlspecialchars($cust['first_name'], ENT_XML1, 'UTF-8') . '</FirstName>
        <LastName>' . htmlspecialchars($cust['last_name'], ENT_XML1, 'UTF-8') . '</LastName>
        <BillAddress>
          <Addr1>' . htmlspecialchars($addr['address1'], ENT_XML1, 'UTF-8') . '</Addr1>
          <City>' . htmlspecialchars($addr['city'], ENT_XML1, 'UTF-8') . '</City>
          <State>' . htmlspecialchars($addr['province'], ENT_XML1, 'UTF-8') . '</State>
          <PostalCode>' . htmlspecialchars($addr['zip'], ENT_XML1, 'UTF-8') . '</PostalCode>
          <Country>' . htmlspecialchars($addr['country'], ENT_XML1, 'UTF-8') . '</Country>
        </BillAddress>
        <Phone>' . htmlspecialchars($cust['phone'], ENT_XML1, 'UTF-8') . '</Phone>
        <Email>' . htmlspecialchars($cust['email'], ENT_XML1, 'UTF-8') . '</Email>
      </CustomerAdd>
    </CustomerAddRq>
  </QBXMLMsgsRq>
</QBXML>';
            $this->log("Sending CustomerAddRq XML:\n$xml");
            $this->saveState();
            return new SendRequestXML($xml);
        }

        if ($this->stage === 'check_item') {
            $currentItem = $this->currentOrderItems[$this->currentItemIndex];
            $this->log("Checking if item exists: {$currentItem}");
            $xml = '<?xml version="1.0" encoding="utf-8"?>
<?qbxml version="' . $qbxmlVersion . '"?>
<QBXML>
  <QBXMLMsgsRq onError="stopOnError">
    <ItemQueryRq requestID="' . $this->generateGUID() . '">
      <FullName>' . htmlspecialchars($currentItem, ENT_XML1, 'UTF-8') . '</FullName>
    </ItemQueryRq>
  </QBXMLMsgsRq>
</QBXML>';
            $this->log("Sending ItemQueryRq XML:\n$xml");
            $this->saveState();
            return new SendRequestXML($xml);
        }

        if ($this->stage === 'add_item') {
            $currentItem = $this->currentOrderItems[$this->currentItemIndex];
            $this->log("Adding NonInventory item: {$currentItem}");
            $xml = '<?xml version="1.0" encoding="utf-8"?>
<?qbxml version="' . $qbxmlVersion . '"?>
<QBXML>
  <QBXMLMsgsRq onError="stopOnError">
    <ItemNonInventoryAddRq requestID="' . $this->generateGUID() . '">
      <ItemNonInventoryAdd>
        <Name>' . htmlspecialchars($currentItem, ENT_XML1, 'UTF-8') . '</Name>
        <SalesOrPurchase>
          <Desc>' . htmlspecialchars($currentItem, ENT_XML1, 'UTF-8') . '</Desc>
          <Price>0.00</Price>
          <AccountRef>
            <FullName>Sales</FullName>
          </AccountRef>
        </SalesOrPurchase>
      </ItemNonInventoryAdd>
    </ItemNonInventoryAddRq>
  </QBXMLMsgsRq>
</QBXML>';
            $this->log("Sending ItemNonInventoryAddRq XML:\n$xml");
            $this->saveState();
            return new SendRequestXML($xml);
        }
        error_log('invoice stage = '.$this->stage);
        if ($this->stage === 'add_invoice') {
            error_log('invoice stage = '.$this->stage);
            error_log("customer-name = ".htmlentities($this->customerName));
            $xml = '<?xml version="1.0" encoding="utf-8"?>
<?qbxml version="' . $qbxmlVersion . '"?>
<QBXML>
  <QBXMLMsgsRq onError="stopOnError">
    <InvoiceAddRq requestID="' . $this->generateGUID() . '">
      <InvoiceAdd>
        <CustomerRef><FullName>' . htmlentities($this->customerName) . '</FullName></CustomerRef>
        <RefNumber>' . htmlentities($order['id']) . '</RefNumber>
        <Memo>Static Test Order #' . htmlentities($order['order_number']) . '</Memo>';
            foreach ($order['line_items'] as $item) {
                $xml .= '
        <InvoiceLineAdd>
          <ItemRef><FullName>' . htmlentities($item['title']) . '</FullName></ItemRef>
          <Quantity>' . (int)$item['quantity'] . '</Quantity>
        </InvoiceLineAdd>';
            }
            $xml .= '
      </InvoiceAdd>
    </InvoiceAddRq>
  </QBXMLMsgsRq>
</QBXML>';
           error_log("Sending InvoiceAddRq XML:\n$xml");
            $this->saveState();
            return new SendRequestXML($xml);
        }

        $this->log("Unexpected stage in sendRequestXML: {$this->stage}");
        $this->saveState();
        return new SendRequestXML('');
    }

    public function receiveResponseXML($object)
    {
        $this->loadState();

        // Ensure orders present for this request cycle
        if (empty($this->orders)) {
            $this->fetchPendingOrders();
        }

        $this->log("Received XML response:\n" . $object->response);

        $response = simplexml_load_string($object->response);

        $this->log("Current stage in receiveResponseXML: {$this->stage}");
        error_log("currentOrderIndex = ". $this->currentOrderIndex);
        error_log("Current stage in receiveResponseXML: {$this->stage}");
        if ($this->stage === 'query_customer') {  
            if (isset($response->QBXMLMsgsRs->CustomerQueryRs->CustomerRet)) {
                error_log("Customer EXISTS in QuickBooks --> Skipping add, moving to check items.");
                error_log("orders = ".json_encode($this->orders));
                error_log("currentOrderIndex = ". $this->currentOrderIndex);
                $order = $this->orders[$this->currentOrderIndex];
            error_log("order line ityem = ".json_encode($order));
                $this->currentOrderItems = array_column($order['line_items'], 'title');
                $this->currentItemIndex = 0;
                $this->stage = 'check_item';
            } else {
                $this->log("Customer NOT FOUND in QuickBooks --> Will add customer.");
                $this->stage = 'add_customer';
            }
            $this->saveState();
            return new ReceiveResponseXML(50);
        }

        if ($this->stage === 'add_customer') {
            $this->log("CustomerAdd completed. Moving to check items.");
            $order = $this->orders[$this->currentOrderIndex];
            error_log("order line ityem = ".json_encode($order));
            $this->currentOrderItems = array_column($order['line_items'], 'title');
            $this->currentItemIndex = 0;
            $this->stage = 'check_item';
            $this->saveState();
            return new ReceiveResponseXML(50);
        }

        if ($this->stage === 'check_item') {
            $itemFound = false;

            if (isset($response->QBXMLMsgsRs->ItemQueryRs->ItemNonInventoryRet)) {
                $itemFound = true;
            } elseif (isset($response->QBXMLMsgsRs->ItemQueryRs->ItemInventoryRet)) {
                $itemFound = true;
            } elseif (isset($response->QBXMLMsgsRs->ItemQueryRs->ItemServiceRet)) {
                $itemFound = true;
            }

            if (!$itemFound) {
                $this->log("Item missing: " . $this->currentOrderItems[$this->currentItemIndex] . " — will add it.");
                $this->stage = 'add_item';
                $this->saveState();
                return new ReceiveResponseXML(50);
            } else {
                $this->log("Item exists: " . $this->currentOrderItems[$this->currentItemIndex]);
                $this->currentItemIndex++;
                if ($this->currentItemIndex < count($this->currentOrderItems)) {
                    $this->stage = 'check_item';
                } else {
                    $this->stage = 'add_invoice';
                }
                $this->saveState();
                return new ReceiveResponseXML(50);
            }
        }

        if ($this->stage === 'add_item') {
            $this->log("Item added: " . $this->currentOrderItems[$this->currentItemIndex]);
            $this->currentItemIndex++;
            if ($this->currentItemIndex < count($this->currentOrderItems)) {
                $this->stage = 'check_item';
            } else {
                $this->stage = 'add_invoice';
            }
            $this->saveState();
            return new ReceiveResponseXML(50);
        }

        if ($this->stage === 'add_invoice') {
            $this->log("InvoiceAdd completed for Order #{$this->orders[$this->currentOrderIndex]['order_number']}.");

            if ($this->currentDbOrderId) {
                $this->updateOrderStatus($this->currentDbOrderId, 'invoice_done');
            }

            $this->currentOrderIndex++;
            $this->stage = 'query_customer';
            $this->currentItemIndex = 0;
            $this->currentOrderItems = [];
            $this->currentDbOrderId = null;

            if ($this->currentOrderIndex < count($this->orders)) {
                $this->log("Moving to next order (index = {$this->currentOrderIndex}).");
                $this->saveState();
                return new ReceiveResponseXML(50);
            }
            $this->log("All orders processed. Done!");
            $this->resetState();
            return new ReceiveResponseXML(100);
        }

        $this->log("Unexpected stage in receiveResponseXML: {$this->stage}");
        $this->saveState();
        return new ReceiveResponseXML(100);
    }
}