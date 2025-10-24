<?php
namespace QBWCServer\applications;

use QBWCServer\base\AbstractQBWCApplication;
use QBWCServer\response\ReceiveResponseXML;
use QBWCServer\response\SendRequestXML;

class AddCustomerInvoiceApp extends AbstractQBWCApplication
{
    // Static orders array for testing
    private $orders = [
        [
            'id' => 1001,
            'order_number' => 'S1001',
            'customer' => [
                'first_name' => 'John',
                'last_name' => 'Doe',
                'email' => 'john.doe@example.com',
                'phone' => '123-456-7890',
                'default_address' => [
                    'company' => 'John Co.',
                    'address1' => '123 Test Street',
                    'city' => 'Testville',
                    'province' => 'CA',
                    'zip' => '90001',
                    'country' => 'United States'
                ]
            ],
            'line_items' => [
                ['title' => 'Product A', 'quantity' => 2, 'rate' => 25.00],
                ['title' => 'Product B', 'quantity' => 1, 'rate' => 50.00]
            ]
        ],
        [
            'id' => 1002,
            'order_number' => 'S1002',
            'customer' => [
                'first_name' => 'Jane',
                'last_name' => 'Smith',
                'email' => 'jane.smith@example.com',
                'phone' => '555-777-8888',
                'default_address' => [
                    'company' => '',
                    'address1' => '456 Sample Ave',
                    'city' => 'Sampletown',
                    'province' => 'NY',
                    'zip' => '10001',
                    'country' => 'United States'
                ]
            ],
            'line_items' => [
                ['title' => 'Service X', 'quantity' => 5, 'rate' => 15.00]
            ]
        ]
    ];

    private $currentOrderIndex = 0;
    private $stage = 'query_customer';
    private $customerName;
    private $itemsToCheck = [];
    private $currentItemIndex = 0;

    // ---------------------- State Persistence ----------------------
    private function loadState() {
        $path = $this->getStateFilePath();
        if (file_exists($path)) {
            $state = json_decode(file_get_contents($path), true);
            if (is_array($state)) {
                $this->currentOrderIndex = $state['index'] ?? 0;
                $this->stage = $state['stage'] ?? 'query_customer';
                $this->itemsToCheck = $state['itemsToCheck'] ?? [];
                $this->currentItemIndex = $state['currentItemIndex'] ?? 0;
            }
        }
    }

    private function saveState() {
        $state = [
            'index' => $this->currentOrderIndex, 
            'stage' => $this->stage,
            'itemsToCheck' => $this->itemsToCheck,
            'currentItemIndex' => $this->currentItemIndex
        ];
        $path = $this->getStateFilePath();
        file_put_contents($path, json_encode($state));
    }

    private function getStateFilePath() {
        // For Railway deployment, use a persistent directory
        // Try multiple locations for better compatibility
        $possiblePaths = [
            '/tmp/qbwc_app_state.json',
            sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'qbwc_app_state.json',
            __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'log' . DIRECTORY_SEPARATOR . 'qbwc_app_state.json'
        ];
        
        // Return the first writable path
        foreach ($possiblePaths as $path) {
            $dir = dirname($path);
            if (is_writable($dir) || @mkdir($dir, 0755, true)) {
                return $path;
            }
        }
        
        // Fallback to temp directory
        return sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'qbwc_app_state.json';
    }

    private function getDebugLogPath() {
        // For Railway deployment, use a persistent directory
        // Try multiple locations for better compatibility
        $possiblePaths = [
            '/tmp/qbwc_app_debug.log',
            sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'qbwc_app_debug.log',
            __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'log' . DIRECTORY_SEPARATOR . 'qbwc_app_debug.log'
        ];
        
        // Return the first writable path
        foreach ($possiblePaths as $path) {
            $dir = dirname($path);
            if (is_writable($dir) || @mkdir($dir, 0755, true)) {
                return $path;
            }
        }
        
        // Fallback to temp directory
        return sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'qbwc_app_debug.log';
    }
    private function resetState() {
        $this->currentOrderIndex = 0;
        $this->stage = 'query_customer';
        $this->itemsToCheck = [];
        $this->currentItemIndex = 0;
        @unlink($this->getStateFilePath());
    }

    private function getUniqueItemsFromOrder($order) {
        $uniqueItems = [];
        foreach ($order['line_items'] as $item) {
            if (!in_array($item['title'], $uniqueItems)) {
                $uniqueItems[] = $item['title'];
            }
        }
        return $uniqueItems;
    }

    // ---------------------- Logging ----------------------
    private function log($msg) {
        $ts = date('Y-m-d H:i:s');
        $logPath = $this->getDebugLogPath();
        
        // Always log to system error log for Railway visibility
        error_log("[$ts] AddCustomerInvoiceApp: $msg");
        
        // Also try to log to file if possible
        @error_log("[$ts] AddCustomerInvoiceApp: $msg\n", 3, $logPath);
    }

    // ---------------------- QBWC Methods ----------------------
    public function authenticate($object)
    {
        $this->log("=== authenticate called ===");
        $this->log("Username: " . $object->strUserName);
        $this->log("Password provided: " . (!empty($object->strPassword) ? "YES" : "NO"));
        
        return parent::authenticate($object);
    }

    public function sendRequestXML($object)
    {
        $this->loadState();
        
        // Log Railway environment info for debugging
        $this->log("=== sendRequestXML called ===");
        $this->log("Railway Environment - PHP Version: " . PHP_VERSION . ", SAPI: " . php_sapi_name());
        $this->log("State file path: " . $this->getStateFilePath());
        $this->log("Debug log path: " . $this->getDebugLogPath());
        $this->log("Current order index: " . $this->currentOrderIndex);
        $this->log("Current stage: " . $this->stage);

        if ($this->currentOrderIndex >= count($this->orders)) {
            $this->log("All static orders processed. Nothing to send.");
            $this->resetState(); // cleanup state file for next run
            return new SendRequestXML('');
        }

        $order = $this->orders[$this->currentOrderIndex];
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

        if ($this->stage === 'check_items') {
            // Check if items exist in QuickBooks
            if ($this->currentItemIndex < count($this->itemsToCheck)) {
                $itemName = $this->itemsToCheck[$this->currentItemIndex];
                $xml = '<?xml version="1.0" encoding="utf-8"?>
<?qbxml version="' . $qbxmlVersion . '"?>
<QBXML>
  <QBXMLMsgsRq onError="stopOnError">
    <ItemQueryRq requestID="' . $this->generateGUID() . '">
      <FullName>' . htmlspecialchars($itemName, ENT_XML1, 'UTF-8') . '</FullName>
    </ItemQueryRq>
  </QBXMLMsgsRq>
</QBXML>';
                $this->log("Sending ItemQueryRq XML for item: $itemName\n$xml");
                $this->saveState();
                return new SendRequestXML($xml);
            } else {
                // All items checked, move to creating missing items
                $this->stage = 'create_items';
                $this->saveState();
                return $this->sendRequestXML($object);
            }
        }

        if ($this->stage === 'create_items') {
            // Create items that don't exist
            if ($this->currentItemIndex < count($this->itemsToCheck)) {
                $itemName = $this->itemsToCheck[$this->currentItemIndex];
                $order = $this->orders[$this->currentOrderIndex];
                $itemRate = 0;
                
                // Find the rate for this item
                foreach ($order['line_items'] as $item) {
                    if ($item['title'] === $itemName) {
                        $itemRate = $item['rate'];
                        break;
                    }
                }
                
                $xml = '<?xml version="1.0" encoding="utf-8"?>
<?qbxml version="' . $qbxmlVersion . '"?>
<QBXML>
  <QBXMLMsgsRq onError="stopOnError">
    <ItemServiceAddRq requestID="' . $this->generateGUID() . '">
      <ItemServiceAdd>
        <Name>' . htmlspecialchars($itemName, ENT_XML1, 'UTF-8') . '</Name>
        <SalesOrPurchase>
          <Price>' . (float)$itemRate . '</Price>
        </SalesOrPurchase>
      </ItemServiceAdd>
    </ItemServiceAddRq>
  </QBXMLMsgsRq>
</QBXML>';
                $this->log("Sending ItemServiceAddRq XML for item: $itemName\n$xml");
                $this->saveState();
                return new SendRequestXML($xml);
            } else {
                // All items created, move to invoice creation
                $this->stage = 'add_invoice';
                $this->saveState();
                return $this->sendRequestXML($object);
            }
        }

        if ($this->stage === 'add_invoice') {
            $xml = '<?xml version="1.0" encoding="utf-8"?>
<?qbxml version="' . $qbxmlVersion . '"?>
<QBXML>
  <QBXMLMsgsRq onError="stopOnError">
    <InvoiceAddRq requestID="' . $this->generateGUID() . '">
      <InvoiceAdd>
        <CustomerRef><FullName>' . htmlentities($this->customerName) . '</FullName></CustomerRef>
        <RefNumber>' . htmlentities($order['id']) . '</RefNumber>
        <Memo>Static Test Order #' . htmlentities($order['order_number']) . '</Memo>
        <TxnDate>' . date('Y-m-d') . '</TxnDate>';
            foreach ($order['line_items'] as $item) {
                $xml .= '
        <InvoiceLineAdd>
          <ItemRef><FullName>' . htmlentities($item['title']) . '</FullName></ItemRef>
          <Quantity>' . (float)$item['quantity'] . '</Quantity>
          <Rate>' . (float)$item['rate'] . '</Rate>
        </InvoiceLineAdd>';
            }
            $xml .= '
      </InvoiceAdd>
    </InvoiceAddRq>
  </QBXMLMsgsRq>
</QBXML>';
            $this->log("Sending InvoiceAddRq XML:\n$xml");
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
        $this->log("=== receiveResponseXML called ===");
        $this->log("Received XML response length: " . strlen($object->response));
        $this->log("XML response preview: " . substr($object->response, 0, 200));

        $response = simplexml_load_string($object->response);

        $this->log("Current stage in receiveResponseXML: {$this->stage}");

        if ($this->stage === 'query_customer') {
            if (isset($response->QBXMLMsgsRs->CustomerQueryRs->CustomerRet)) {
                $this->log("Customer EXISTS in QuickBooks --> Skipping add, moving to check items.");
                // Get unique items from current order and start checking them
                $order = $this->orders[$this->currentOrderIndex];
                $this->itemsToCheck = $this->getUniqueItemsFromOrder($order);
                $this->currentItemIndex = 0;
                $this->stage = 'check_items';
            } else {
                $this->log("Customer NOT FOUND in QuickBooks --> Will add customer.");
                $this->stage = 'add_customer';
            }
            $this->saveState();
            return new ReceiveResponseXML(50);
        }

        if ($this->stage === 'add_customer') {
            $this->log("CustomerAdd completed. Moving to check items.");
            // Get unique items from current order and start checking them
            $order = $this->orders[$this->currentOrderIndex];
            $this->itemsToCheck = $this->getUniqueItemsFromOrder($order);
            $this->currentItemIndex = 0;
            $this->stage = 'check_items';
            $this->saveState();
            return new ReceiveResponseXML(50);
        }

        if ($this->stage === 'check_items') {
            $itemName = $this->itemsToCheck[$this->currentItemIndex];
            
            // Check if item exists in QuickBooks
            if (isset($response->QBXMLMsgsRs->ItemQueryRs->ItemRet)) {
                $this->log("Item '$itemName' EXISTS in QuickBooks.");
            } else {
                $this->log("Item '$itemName' NOT FOUND in QuickBooks - Will create item.");
            }
            
            $this->currentItemIndex++;
            if ($this->currentItemIndex < count($this->itemsToCheck)) {
                $this->saveState();
                return new ReceiveResponseXML(50);
            } else {
                $this->log("All items checked. Moving to create missing items.");
                $this->stage = 'create_items';
                $this->currentItemIndex = 0; // Reset for item creation
                $this->saveState();
                return new ReceiveResponseXML(50);
            }
        }

        if ($this->stage === 'create_items') {
            $itemName = $this->itemsToCheck[$this->currentItemIndex];
            
            // Check if item was created successfully
            if (isset($response->QBXMLMsgsRs->ItemServiceAddRs->ItemServiceRet)) {
                $this->log("Item '$itemName' CREATED successfully in QuickBooks.");
            } else {
                $this->log("Item '$itemName' creation FAILED - Invoice creation may fail.");
            }
            
            $this->currentItemIndex++;
            if ($this->currentItemIndex < count($this->itemsToCheck)) {
                $this->saveState();
                return new ReceiveResponseXML(50);
            } else {
                $this->log("All items created. Moving to invoice creation.");
                $this->stage = 'add_invoice';
                $this->saveState();
                return new ReceiveResponseXML(50);
            }
        }

        if ($this->stage === 'add_invoice') {
            // Check for errors in the invoice response
            if (isset($response->QBXMLMsgsRs->InvoiceAddRs->InvoiceRet)) {
                $invoiceId = (string)$response->QBXMLMsgsRs->InvoiceAddRs->InvoiceRet->TxnID;
                $this->log("InvoiceAdd SUCCESS for Order #{$this->orders[$this->currentOrderIndex]['order_number']} - Invoice ID: $invoiceId");
            } else if (isset($response->QBXMLMsgsRs->InvoiceAddRs->statusCode)) {
                $statusCode = (string)$response->QBXMLMsgsRs->InvoiceAddRs->statusCode;
                $statusMessage = (string)$response->QBXMLMsgsRs->InvoiceAddRs->statusMessage;
                $this->log("InvoiceAdd FAILED for Order #{$this->orders[$this->currentOrderIndex]['order_number']} - Status: $statusCode - Message: $statusMessage");
                
                // Log the full response for debugging
                $this->log("Full InvoiceAdd response: " . $object->response);
            } else if (!empty($object->response)) {
                $this->log("InvoiceAdd completed for Order #{$this->orders[$this->currentOrderIndex]['order_number']} - Response received but no detailed info available.");
                $this->log("Full InvoiceAdd response: " . $object->response);
            } else {
                $this->log("InvoiceAdd completed for Order #{$this->orders[$this->currentOrderIndex]['order_number']} - Empty response received.");
            }
            
            $this->currentOrderIndex++;
            $this->stage = 'query_customer';
            if ($this->currentOrderIndex < count($this->orders)) {
                $this->log("Moving to next static order (index = {$this->currentOrderIndex}).");
                $this->saveState();
                return new ReceiveResponseXML(50);
            }
            $this->log("All orders processed. Done!");
            $this->saveState();
            return new ReceiveResponseXML(100);
        }

        $this->log("Unexpected stage in receiveResponseXML: {$this->stage}");
        $this->saveState();
        return new ReceiveResponseXML(100);
    }
}