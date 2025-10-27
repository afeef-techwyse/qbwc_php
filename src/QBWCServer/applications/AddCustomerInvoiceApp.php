<?php

namespace QBWCServer\applications;

use QBWCServer\base\AbstractQBWCApplication;
use QBWCServer\response\ReceiveResponseXML;
use QBWCServer\response\SendRequestXML;

class AddCustomerInvoiceApp extends AbstractQBWCApplication
{
    // orders will be populated from orders_queue (status = 'pending')
    private $orders = [];

    // DB connection config (matches shopify dynamic.php)
    private $dsn = "mysql:host=shortline.proxy.rlwy.net;port=53111;dbname=railway";
    private $dbUser = "root";
    private $dbPass = "wTVIYIVbAlJdCqIwbHigEVotdGKGdHNA";
    /** @var \PDO|null */
    private $pdo = null;

    public function __construct()
    {
        // keep parent's initialization if any
        parent::__construct();
        $this->initPDO();
    }

    private function initPDO()
    {
        if ($this->pdo) return;
        try {
            $this->pdo = new \PDO($this->dsn, $this->dbUser, $this->dbPass, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            ]);
        } catch (\Throwable $e) {
            $this->log("DB connection failed: " . $e->getMessage());
            $this->pdo = null;
        }
    }

    private function loadOrdersFromDb()
    {
        if (!$this->pdo) {
            $this->initPDO();
            if (!$this->pdo) return;
        }

        try {
            $stmt = $this->pdo->prepare("SELECT * FROM orders_queue WHERE status = 'pending' ORDER BY id ASC");
            $stmt->execute();
            $rows = $stmt->fetchAll();
            $orders = [];
            foreach ($rows as $r) {
                $payload = json_decode($r['payload'], true);
                if (!is_array($payload)) continue;

                $orderNumber = $payload['order_number'] ?? $payload['name'] ?? ($payload['number'] ?? '');
                $customer = $payload['customer'] ?? [];
                $defaultAddress = $customer['default_address'] ?? $payload['billing_address'] ?? $payload['shipping_address'] ?? [];

                $lineItems = [];
                foreach ($payload['line_items'] ?? [] as $li) {
                    $lineItems[] = [
                        'title' => $li['title'] ?? ($li['name'] ?? ($li['sku'] ?? 'Item')),
                        'quantity' => (int)($li['quantity'] ?? 1),
                        'rate' => (float)($li['price'] ?? ($li['price_set']['shop_money']['amount'] ?? 0.00))
                    ];
                }

                $orders[] = [
                    'queue_id' => (int)$r['id'],
                    'id' => $payload['id'] ?? null,
                    'order_number' => (string)$orderNumber,
                    'customer' => [
                        'first_name' => $customer['first_name'] ?? ($defaultAddress['first_name'] ?? ''),
                        'last_name' => $customer['last_name'] ?? ($defaultAddress['last_name'] ?? ''),
                        'email' => $customer['email'] ?? $payload['email'] ?? '',
                        'phone' => $customer['phone'] ?? $defaultAddress['phone'] ?? '',
                        'default_address' => [
                            'company' => $defaultAddress['company'] ?? '',
                            'address1' => $defaultAddress['address1'] ?? '',
                            'city' => $defaultAddress['city'] ?? '',
                            'province' => $defaultAddress['province'] ?? ($defaultAddress['province_code'] ?? ''),
                            'zip' => $defaultAddress['zip'] ?? '',
                            'country' => $defaultAddress['country'] ?? ($defaultAddress['country_name'] ?? '')
                        ]
                    ],
                    'line_items' => $lineItems
                ];
            }
            $this->orders = $orders;
        } catch (\Throwable $e) {
            $this->log("Failed to load orders from DB: " . $e->getMessage());
        }
    }

    private function updateOrderStatusInDb($queueId, $status)
    {
        if (!$this->pdo) {
            $this->initPDO();
            if (!$this->pdo) return;
        }
        try {
            $stmt = $this->pdo->prepare("UPDATE orders_queue SET status = :status WHERE id = :id");
            $stmt->execute([':status' => $status, ':id' => $queueId]);
        } catch (\Throwable $e) {
            $this->log("Failed to update order status (id={$queueId}): " . $e->getMessage());
        }
    }

    private $currentOrderIndex = 0;
    private $stage = 'query_customer';
    private $customerName;
    private $currentItemIndex = 0;

    private function loadState()
    {
        $path = '/tmp/qbwc_app_state.json';
        if (file_exists($path)) {
            $state = json_decode(file_get_contents($path), true);
            if (is_array($state)) {
                $this->currentOrderIndex = $state['index'] ?? 0;
                $this->stage = $state['stage'] ?? 'query_customer';
                $this->currentItemIndex = $state['item_index'] ?? 0;
            }
        }
    }

    private function saveState()
    {
        $state = [
            'index' => $this->currentOrderIndex,
            'stage' => $this->stage,
            'item_index' => $this->currentItemIndex
        ];
        file_put_contents('/tmp/qbwc_app_state.json', json_encode($state));
    }

    private function resetState()
    {
        $this->currentOrderIndex = 0;
        $this->stage = 'query_customer';
        $this->currentItemIndex = 0;
        @unlink('/tmp/qbwc_app_state.json');
    }

    private function log($msg)
    {
        $ts = date('Y-m-d H:i:s');
        error_log("[$ts] AddCustomerInvoiceApp: $msg\n", 3, '/tmp/qbwc_app_debug.log');
    }

    public function generateGUID()
    {
        return sprintf('%04X%04X-%04X-%04X-%04X-%04X%04X%04X',
            mt_rand(0, 65535), mt_rand(0, 65535),
            mt_rand(0, 65535),
            mt_rand(16384, 20479),
            mt_rand(32768, 49151),
            mt_rand(0, 65535), mt_rand(0, 65535), mt_rand(0, 65535)
        );
    }

    public function sendRequestXML($object)
    {
        // Ensure orders are loaded from DB (if not already)
        if (empty($this->orders)) {
            $this->loadOrdersFromDb();
        }

        $this->loadState();

        if ($this->currentOrderIndex >= count($this->orders)) {
            $this->log("All orders processed. Nothing to send.");
            $this->resetState();
            return new SendRequestXML('');
        }

        $order = $this->orders[$this->currentOrderIndex];
        $this->customerName = trim($order['customer']['first_name'] . ' ' . $order['customer']['last_name']);

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
            $this->log("Sending CustomerQueryRq XML");
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
        </BillAddress>
        <Phone>' . htmlspecialchars($cust['phone'], ENT_XML1, 'UTF-8') . '</Phone>
        <Email>' . htmlspecialchars($cust['email'], ENT_XML1, 'UTF-8') . '</Email>
      </CustomerAdd>
    </CustomerAddRq>
  </QBXMLMsgsRq>
</QBXML>';
            $this->log("Sending CustomerAddRq XML");
            $this->saveState();
            return new SendRequestXML($xml);
        }

        if ($this->stage === 'query_item') {
            if ($this->currentItemIndex >= count($order['line_items'])) {
                $this->log("All items processed for Order #{$order['order_number']}. Moving to add_invoice stage.");
                $this->stage = 'add_invoice';
                $this->currentItemIndex = 0;
                $this->saveState();
                return $this->sendRequestXML($object);
            }

            $item = $order['line_items'][$this->currentItemIndex];
            $itemName = $item['title'];
            $this->log("Querying item: {$itemName} (Index: {$this->currentItemIndex})");

            $xml = '<?xml version="1.0" encoding="utf-8"?>
<?qbxml version="' . $qbxmlVersion . '"?>
<QBXML>
  <QBXMLMsgsRq onError="stopOnError">
    <ItemQueryRq requestID="' . $this->generateGUID() . '">
      <FullName>' . htmlspecialchars($itemName, ENT_XML1, 'UTF-8') . '</FullName>
    </ItemQueryRq>
  </QBXMLMsgsRq>
</QBXML>';
            $this->log("Sending ItemQueryRq XML");
            $this->saveState();
            return new SendRequestXML($xml);
        }

        if ($this->stage === 'add_item') {
            $item = $order['line_items'][$this->currentItemIndex];
            $itemName = $item['title'];
            $this->log("Adding item: {$itemName} as NonInventory");

            $xml = '<?xml version="1.0" encoding="utf-8"?>
<?qbxml version="' . $qbxmlVersion . '"?>
<QBXML>
  <QBXMLMsgsRq onError="stopOnError">
    <ItemNonInventoryAddRq requestID="' . $this->generateGUID() . '">
      <ItemNonInventoryAdd>
        <Name>' . htmlspecialchars($itemName, ENT_XML1, 'UTF-8') . '</Name>
        <SalesOrPurchase>
          <Desc>' . htmlspecialchars($itemName, ENT_XML1, 'UTF-8') . '</Desc>
          <Price>0.00</Price>
          <AccountRef>
            <FullName>Sales</FullName>
          </AccountRef>
        </SalesOrPurchase>
      </ItemNonInventoryAdd>
    </ItemNonInventoryAddRq>
  </QBXMLMsgsRq>
</QBXML>';
            $this->log("Sending ItemNonInventoryAddRq XML");
            $this->saveState();
            return new SendRequestXML($xml);
        }

        if ($this->stage === 'add_invoice') {
            $this->log("Building InvoiceAdd for Order #{$order['order_number']}");

            $xml = '<?xml version="1.0" encoding="utf-8"?>
<?qbxml version="' . $qbxmlVersion . '"?>
<QBXML>
  <QBXMLMsgsRq onError="stopOnError">
    <InvoiceAddRq requestID="' . $this->generateGUID() . '">
      <InvoiceAdd>
        <CustomerRef>
          <FullName>' . htmlspecialchars($this->customerName, ENT_XML1, 'UTF-8') . '</FullName>
        </CustomerRef>
        <TxnDate>' . date('Y-m-d') . '</TxnDate>
        <RefNumber>' . htmlspecialchars($order['order_number'], ENT_XML1, 'UTF-8') . '</RefNumber>
        <BillAddress>
          <Addr1>' . htmlspecialchars($order['customer']['default_address']['address1'], ENT_XML1, 'UTF-8') . '</Addr1>
          <City>' . htmlspecialchars($order['customer']['default_address']['city'], ENT_XML1, 'UTF-8') . '</City>
          <State>' . htmlspecialchars($order['customer']['default_address']['province'], ENT_XML1, 'UTF-8') . '</State>
          <PostalCode>' . htmlspecialchars($order['customer']['default_address']['zip'], ENT_XML1, 'UTF-8') . '</PostalCode>
        </BillAddress>
        <Memo>Order #' . htmlspecialchars($order['order_number'], ENT_XML1, 'UTF-8') . '</Memo>';

            foreach ($order['line_items'] as $item) {
                $xml .= '
        <InvoiceLineAdd>
          <ItemRef>
            <FullName>' . htmlspecialchars($item['title'], ENT_XML1, 'UTF-8') . '</FullName>
          </ItemRef>
          <Desc>' . htmlspecialchars($item['title'], ENT_XML1, 'UTF-8') . '</Desc>
          <Quantity>' . (int)$item['quantity'] . '</Quantity>
          <Rate>' . number_format($item['rate'], 2, '.', '') . '</Rate>
        </InvoiceLineAdd>';
            }

            $xml .= '
      </InvoiceAdd>
    </InvoiceAddRq>
  </QBXMLMsgsRq>
</QBXML>';
            $this->log("Sending InvoiceAddRq XML");
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
        $this->log("Received XML response (truncated for logging)");

        if (empty(trim($object->response))) {
            $this->log("Received an EMPTY response from QuickBooks. Halting.");
            return new ReceiveResponseXML(100);
        }

        libxml_use_internal_errors(true);
        $response = simplexml_load_string($object->response);
        if ($response === false) {
            $errors = libxml_get_errors();
            $this->log("Failed to parse XML response. Errors: " . print_r($errors, true));
            libxml_clear_errors();
            return new ReceiveResponseXML(100);
        }

        $this->log("Current stage in receiveResponseXML: {$this->stage}");

        if ($this->stage === 'query_customer') {
            $statusCode = (string)($response->QBXMLMsgsRs->CustomerQueryRs->attributes()->statusCode ?? '');

            if ($statusCode === '0' && isset($response->QBXMLMsgsRs->CustomerQueryRs->CustomerRet)) {
                $this->log("Customer EXISTS in QuickBooks. Moving to query_item.");
                $this->stage = 'query_item';
                $this->currentItemIndex = 0;
            } else {
                $this->log("Customer NOT FOUND in QuickBooks. Moving to add_customer.");
                $this->stage = 'add_customer';
            }
            $this->saveState();
            return new ReceiveResponseXML(50);
        }

        if ($this->stage === 'add_customer') {
            $statusCode = (string)($response->QBXMLMsgsRs->CustomerAddRs->attributes()->statusCode ?? '');

            if ($statusCode === '0') {
                $this->log("CustomerAdd completed. Moving to query_item.");
                $this->stage = 'query_item';
                $this->currentItemIndex = 0;
                $this->saveState();
                return new ReceiveResponseXML(50);
            } else {
                $errorMsg = (string)($response->QBXMLMsgsRs->CustomerAddRs->attributes()->statusMessage ?? '');
                $this->log("Error adding customer. Halting. Error: $errorMsg");
                $this->saveState();
                return new ReceiveResponseXML(100);
            }
        }

        if ($this->stage === 'query_item') {
            $itemQueryRs = $response->QBXMLMsgsRs->ItemQueryRs;
            $statusCode = (string)($itemQueryRs->attributes()->statusCode ?? '');

            if ($statusCode === '0') {
                $itemFound = false;
                if (isset($itemQueryRs->ItemNonInventoryRet) ||
                    isset($itemQueryRs->ItemInventoryRet) ||
                    isset($itemQueryRs->ItemServiceRet)) {
                    $itemFound = true;
                }

                if ($itemFound) {
                    $this->log("Item EXISTS in QuickBooks. Moving to next item.");
                    $this->currentItemIndex++;
                    $this->stage = 'query_item';
                } else {
                    $this->log("Item query returned success but no item found. Will add.");
                    $this->stage = 'add_item';
                }
            } else {
                $this->log("Item NOT FOUND in QuickBooks (status: {$statusCode}). Moving to add_item.");
                $this->stage = 'add_item';
            }

            $this->saveState();
            return new ReceiveResponseXML(50);
        }

        if ($this->stage === 'add_item') {
            $itemAddRs = $response->QBXMLMsgsRs->ItemNonInventoryAddRs;
            $statusCode = (string)($itemAddRs->attributes()->statusCode ?? '');

            if ($statusCode === '0') {
                $this->log("ItemAdd completed successfully. Moving to next item.");
                $this->currentItemIndex++;
                $this->stage = 'query_item';
                $this->saveState();
                return new ReceiveResponseXML(50);
            } else {
                $errorMsg = (string)($itemAddRs->attributes()->statusMessage ?? '');
                $this->log("Error adding item. Halting. Error: $errorMsg");
                $this->saveState();
                return new ReceiveResponseXML(100);
            }
        }

        if ($this->stage === 'add_invoice') {
            $invoiceAddRs = $response->QBXMLMsgsRs->InvoiceAddRs;
            $statusCode = (string)($invoiceAddRs->attributes()->statusCode ?? '');
            $statusMessage = (string)($invoiceAddRs->attributes()->statusMessage ?? '');

            if ($statusCode === '0') {
                $queueId = $this->orders[$this->currentOrderIndex]['queue_id'] ?? null;
                $this->log("Invoice successfully created for Order #{$this->orders[$this->currentOrderIndex]['order_number']} (queue_id={$queueId}).");
                // mark in DB
                if ($queueId !== null) {
                    $this->updateOrderStatusInDb($queueId, 'invoice_done');
                }

                $this->currentOrderIndex++;
                $this->stage = 'query_customer';
                $this->currentItemIndex = 0;

                if ($this->currentOrderIndex < count($this->orders)) {
                    $this->log("Moving to next order (index = {$this->currentOrderIndex}).");
                    $this->saveState();
                    return new ReceiveResponseXML(50);
                }

                $this->log("All orders processed. Done!");
                $this->resetState();
                return new ReceiveResponseXML(100);
            } else {
                $this->log("ERROR creating invoice. Status: {$statusCode}, Message: {$statusMessage}");
                $this->resetState();
                return new ReceiveResponseXML(100);
            }
        }

        $this->log("Unexpected stage in receiveResponseXML: {$this->stage}");
        $this->saveState();
        return new ReceiveResponseXML(100);
    }
}