<?php

namespace QBWCServer\applications;

use QBWCServer\base\AbstractQBWCApplication;
use QBWCServer\response\ReceiveResponseXML;
use QBWCServer\response\SendRequestXML;

class AddCustomerInvoiceApp extends AbstractQBWCApplication
{
    // Database configuration
    private $dsn = "mysql:host=shortline.proxy.rlwy.net;port=53111;dbname=railway";
    private $dbUser = "root";
    private $dbPass = "wTVIYIVbAlJdCqIwbHigEVotdGKGdHNA";

    // Runtime state
    private $pdo;
    private $currentOrder = null;
    private $stage = 'query_customer';
    private $customerName = '';
    private $currentItemIndex = 0;

    public function __construct($config = [])
    {
        parent::__construct($config);
        $this->pdo = $this->getPDO();
    }

    private function getPDO()
    {
        static $pdo = null;
        if ($pdo) return $pdo;
        
        $pdo = new \PDO($this->dsn, $this->dbUser, $this->dbPass, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);
        return $pdo;
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

    private function fetchNextOrder()
    {
        try {
            // Try to resume partially processed orders first
            $stmt = $this->pdo->prepare(
                "SELECT * FROM orders_queue 
                 WHERE status IN ('query_customer','add_customer','query_item','add_item','add_invoice') 
                 ORDER BY id ASC LIMIT 1"
            );
            $stmt->execute();
            $row = $stmt->fetch();
            if ($row) return $row;

            // Otherwise pick first pending order
            $stmt = $this->pdo->prepare("SELECT * FROM orders_queue WHERE status = 'pending' ORDER BY id ASC LIMIT 1");
            $stmt->execute();
            $row = $stmt->fetch();
            if ($row) return $row;

            return null;
        } catch (\Exception $e) {
            $this->log("Error fetching order: " . $e->getMessage());
            return null;
        }
    }

    private function updateOrderStatus($id, $status)
    {
        try {
            $stmt = $this->pdo->prepare("UPDATE orders_queue SET status = :status WHERE id = :id");
            $stmt->execute([':status' => $status, ':id' => $id]);
            $this->log("Updated order $id status to: $status");
            
            // Update in-memory copy if matches
            if ($this->currentOrder && (int)$this->currentOrder['id'] === (int)$id) {
                $this->currentOrder['status'] = $status;
            }
        } catch (\Exception $e) {
            $this->log("Error updating order status: " . $e->getMessage());
        }
    }

    public function sendRequestXML($object)
    {
        // If we don't have a current order, fetch the next one
        if (!$this->currentOrder) {
            $this->currentOrder = $this->fetchNextOrder();
            if (!$this->currentOrder) {
                $this->log("No orders to process. Nothing to send.");
                return new SendRequestXML('');
            }
            
            // Parse the order payload and set up initial state
            $order = json_decode($this->currentOrder['payload'], true);
            if (!$order) {
                $this->log("Invalid JSON payload for order ID {$this->currentOrder['id']}");
                return new SendRequestXML('');
            }
            
            $this->customerName = trim(($order['customer']['first_name'] ?? '') . ' ' . ($order['customer']['last_name'] ?? ''));
            $this->stage = $this->currentOrder['status'] ?? 'pending';
            
            // Normalize pending to query_customer
            if ($this->stage === 'pending') {
                $this->stage = 'query_customer';
                $this->updateOrderStatus($this->currentOrder['id'], 'query_customer');
            }
            
            $this->currentItemIndex = 0;
        }

        $order = json_decode($this->currentOrder['payload'], true);
        $qbxmlVersion = $this->_config['qbxmlVersion'] ?? '12.0';

        $this->log("Stage: {$this->stage} -- Order ID: {$this->currentOrder['id']} (Customer: {$this->customerName})");

        if ($this->stage === 'query_customer') {
            $this->updateOrderStatus($this->currentOrder['id'], 'query_customer');
            
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
            return new SendRequestXML($xml);
        }

        if ($this->stage === 'add_customer') {
            $this->updateOrderStatus($this->currentOrder['id'], 'add_customer');
            
            $cust = $order['customer'] ?? [];
            $addr = $cust['default_address'] ?? [];
            
            $xml = '<?xml version="1.0" encoding="utf-8"?>
<?qbxml version="' . $qbxmlVersion . '"?>
<QBXML>
  <QBXMLMsgsRq onError="stopOnError">
    <CustomerAddRq requestID="' . $this->generateGUID() . '">
      <CustomerAdd>
        <Name>' . htmlspecialchars($this->customerName, ENT_XML1, 'UTF-8') . '</Name>
        <CompanyName>' . htmlspecialchars($addr['company'] ?? '', ENT_XML1, 'UTF-8') . '</CompanyName>
        <FirstName>' . htmlspecialchars($cust['first_name'] ?? '', ENT_XML1, 'UTF-8') . '</FirstName>
        <LastName>' . htmlspecialchars($cust['last_name'] ?? '', ENT_XML1, 'UTF-8') . '</LastName>
        <BillAddress>
          <Addr1>' . htmlspecialchars($addr['address1'] ?? '', ENT_XML1, 'UTF-8') . '</Addr1>
          <City>' . htmlspecialchars($addr['city'] ?? '', ENT_XML1, 'UTF-8') . '</City>
          <State>' . htmlspecialchars($addr['province'] ?? '', ENT_XML1, 'UTF-8') . '</State>
          <PostalCode>' . htmlspecialchars($addr['zip'] ?? '', ENT_XML1, 'UTF-8') . '</PostalCode>
          <Country>' . htmlspecialchars($addr['country'] ?? '', ENT_XML1, 'UTF-8') . '</Country>
        </BillAddress>
        <Phone>' . htmlspecialchars($cust['phone'] ?? '', ENT_XML1, 'UTF-8') . '</Phone>
        <Email>' . htmlspecialchars($cust['email'] ?? '', ENT_XML1, 'UTF-8') . '</Email>
      </CustomerAdd>
    </CustomerAddRq>
  </QBXMLMsgsRq>
</QBXML>';
            $this->log("Sending CustomerAddRq XML");
            return new SendRequestXML($xml);
        }

        if ($this->stage === 'query_item') {
            $lineItems = $order['line_items'] ?? [];
            
            if ($this->currentItemIndex >= count($lineItems)) {
                $this->log("All items processed. Moving to add_invoice stage.");
                $this->stage = 'add_invoice';
                $this->updateOrderStatus($this->currentOrder['id'], 'add_invoice');
                $this->currentItemIndex = 0;
                return $this->sendRequestXML($object);
            }

            $item = $lineItems[$this->currentItemIndex];
            $itemName = $item['title'] ?? $item['name'] ?? 'Item';
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
            return new SendRequestXML($xml);
        }

        if ($this->stage === 'add_item') {
            $lineItems = $order['line_items'] ?? [];
            $item = $lineItems[$this->currentItemIndex];
            $itemName = $item['title'] ?? $item['name'] ?? 'Item';
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
            return new SendRequestXML($xml);
        }

        if ($this->stage === 'add_invoice') {
            $this->updateOrderStatus($this->currentOrder['id'], 'add_invoice');
            
            $this->log("Building InvoiceAdd for Order ID {$this->currentOrder['id']}");

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
        <RefNumber>' . htmlspecialchars($order['id'] ?? $order['order_number'] ?? '', ENT_XML1, 'UTF-8') . '</RefNumber>
        <BillAddress>
          <Addr1>' . htmlspecialchars($order['customer']['default_address']['address1'] ?? '', ENT_XML1, 'UTF-8') . '</Addr1>
          <City>' . htmlspecialchars($order['customer']['default_address']['city'] ?? '', ENT_XML1, 'UTF-8') . '</City>
          <State>' . htmlspecialchars($order['customer']['default_address']['province'] ?? '', ENT_XML1, 'UTF-8') . '</State>
          <PostalCode>' . htmlspecialchars($order['customer']['default_address']['zip'] ?? '', ENT_XML1, 'UTF-8') . '</PostalCode>
          <Country>' . htmlspecialchars($order['customer']['default_address']['country'] ?? '', ENT_XML1, 'UTF-8') . '</Country>
        </BillAddress>
        <Memo>Shopify Order #' . htmlspecialchars($order['order_number'] ?? $order['name'] ?? '', ENT_XML1, 'UTF-8') . '</Memo>';

            foreach ($order['line_items'] ?? [] as $item) {
                $itemName = $item['title'] ?? $item['name'] ?? 'Item';
                $quantity = (int)($item['quantity'] ?? 1);
                $rate = $item['price'] ?? '0.00';
                
                $xml .= '
        <InvoiceLineAdd>
          <ItemRef>
            <FullName>' . htmlspecialchars($itemName, ENT_XML1, 'UTF-8') . '</FullName>
          </ItemRef>
          <Desc>' . htmlspecialchars($itemName, ENT_XML1, 'UTF-8') . '</Desc>
          <Quantity>' . $quantity . '</Quantity>
          <Rate>' . number_format((float)$rate, 2, '.', '') . '</Rate>
        </InvoiceLineAdd>';
            }

            $xml .= '
      </InvoiceAdd>
    </InvoiceAddRq>
  </QBXMLMsgsRq>
</QBXML>';
            $this->log("Sending InvoiceAddRq XML");
            return new SendRequestXML($xml);
        }

        $this->log("Unexpected stage in sendRequestXML: {$this->stage}");
        return new SendRequestXML('');
    }

    public function receiveResponseXML($object)
    {
        $this->log("Received XML response");

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
                $this->updateOrderStatus($this->currentOrder['id'], 'query_item');
                $this->currentItemIndex = 0;
            } else {
                $this->log("Customer NOT FOUND in QuickBooks. Moving to add_customer.");
                $this->stage = 'add_customer';
                $this->updateOrderStatus($this->currentOrder['id'], 'add_customer');
            }
            return new ReceiveResponseXML(50);
        }

        if ($this->stage === 'add_customer') {
            $statusCode = (string)($response->QBXMLMsgsRs->CustomerAddRs->attributes()->statusCode ?? '');

            if ($statusCode === '0') {
                $this->log("CustomerAdd completed. Moving to query_item.");
                $this->stage = 'query_item';
                $this->updateOrderStatus($this->currentOrder['id'], 'query_item');
                $this->currentItemIndex = 0;
                return new ReceiveResponseXML(50);
            } else {
                $errorMsg = (string)($response->QBXMLMsgsRs->CustomerAddRs->attributes()->statusMessage ?? '');
                $this->log("Error adding customer. Halting. Error: $errorMsg");
                $this->updateOrderStatus($this->currentOrder['id'], 'error');
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

            return new ReceiveResponseXML(50);
        }

        if ($this->stage === 'add_item') {
            $itemAddRs = $response->QBXMLMsgsRs->ItemNonInventoryAddRs;
            $statusCode = (string)($itemAddRs->attributes()->statusCode ?? '');

            if ($statusCode === '0') {
                $this->log("ItemAdd completed successfully. Moving to next item.");
                $this->currentItemIndex++;
                $this->stage = 'query_item';
                return new ReceiveResponseXML(50);
            } else {
                $errorMsg = (string)($itemAddRs->attributes()->statusMessage ?? '');
                $this->log("Error adding item. Halting. Error: $errorMsg");
                $this->updateOrderStatus($this->currentOrder['id'], 'error');
                return new ReceiveResponseXML(100);
            }
        }

        if ($this->stage === 'add_invoice') {
            $invoiceAddRs = $response->QBXMLMsgsRs->InvoiceAddRs;
            $statusCode = (string)($invoiceAddRs->attributes()->statusCode ?? '');
            $statusMessage = (string)($invoiceAddRs->attributes()->statusMessage ?? '');

            if ($statusCode === '0') {
                $this->log("Invoice successfully created for Order ID {$this->currentOrder['id']}.");
                $this->updateOrderStatus($this->currentOrder['id'], 'completed');
                
                // Reset for next order
                $this->currentOrder = null;
                $this->stage = 'query_customer';
                $this->currentItemIndex = 0;
                
                return new ReceiveResponseXML(100);
            } else {
                $this->log("ERROR creating invoice. Status: {$statusCode}, Message: {$statusMessage}");
                $this->updateOrderStatus($this->currentOrder['id'], 'error');
                
                // Reset for next attempt or move on
                $this->currentOrder = null;
                $this->stage = 'query_customer';
                $this->currentItemIndex = 0;
                
                return new ReceiveResponseXML(100);
            }
        }

        $this->log("Unexpected stage in receiveResponseXML: {$this->stage}");
        return new ReceiveResponseXML(100);
    }
}
