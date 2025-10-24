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
                ['title' => 'ACCSRC', 'quantity' => 2],
                ['title' => 'NONSTOCK', 'quantity' => 1]
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
                ['title' => 'Service X', 'quantity' => 5]
            ]
        ]
    ];

    private $currentOrderIndex = 0;
    private $stage = 'query_customer';
    private $customerName;
    private $itemsToCheck = [];
    private $itemsToCreate = [];
    private $currentItemIndex = 0;

    // ---------------------- State Persistence ----------------------
    private function loadState() {
        $path = '/tmp/qbwc_app_state.json';
        if (file_exists($path)) {
            $state = json_decode(file_get_contents($path), true);
            if (is_array($state)) {
                $this->currentOrderIndex = $state['index'];
                $this->stage = $state['stage'];
                $this->itemsToCheck = $state['itemsToCheck'] ?? [];
                $this->itemsToCreate = $state['itemsToCreate'] ?? [];
                $this->currentItemIndex = $state['currentItemIndex'] ?? 0;
            }
        }
    }

    private function saveState() {
        $state = [
            'index' => $this->currentOrderIndex, 
            'stage' => $this->stage,
            'itemsToCheck' => $this->itemsToCheck,
            'itemsToCreate' => $this->itemsToCreate,
            'currentItemIndex' => $this->currentItemIndex
        ];
        file_put_contents('/tmp/qbwc_app_state.json', json_encode($state));
    }
    private function resetState() {
        $this->currentOrderIndex = 0;
        $this->stage = 'query_customer';
        $this->itemsToCheck = [];
        $this->itemsToCreate = [];
        $this->currentItemIndex = 0;
        @unlink('/tmp/qbwc_app_state.json');
    }

    // ---------------------- Logging ----------------------
    private function log($msg) {
        $ts = date('Y-m-d H:i:s');
        error_log("[$ts] AddShopifyOrdersApp: $msg\n", 3, '/tmp/qbwc_app_debug.log');
    }

    // ---------------------- QBWC Methods ----------------------
    public function sendRequestXML($object)
    {
        $this->loadState();

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

        if ($this->stage === 'query_items') {
            // Extract unique items from line_items
            $this->itemsToCheck = array_unique(array_column($order['line_items'], 'title'));
            $this->itemsToCreate = [];
            $this->currentItemIndex = 0;
            
            if (empty($this->itemsToCheck)) {
                $this->log("No items to check, moving to invoice creation.");
                $this->stage = 'add_invoice';
                $this->saveState();
                return new SendRequestXML('');
            }
            
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
        }

        if ($this->stage === 'create_items') {
            if ($this->currentItemIndex >= count($this->itemsToCreate)) {
                $this->log("All items created, moving to invoice creation.");
                $this->stage = 'add_invoice';
                $this->saveState();
                return new SendRequestXML('');
            }
            
            $itemName = $this->itemsToCreate[$this->currentItemIndex];
            $xml = '<?xml version="1.0" encoding="utf-8"?>
<?qbxml version="' . $qbxmlVersion . '"?>
<QBXML>
  <QBXMLMsgsRq onError="stopOnError">
    <ItemServiceAddRq requestID="' . $this->generateGUID() . '">
      <ItemServiceAdd>
        <Name>' . htmlspecialchars($itemName, ENT_XML1, 'UTF-8') . '</Name>
        <SalesOrPurchase>
          <Price>25.00</Price>
        </SalesOrPurchase>
      </ItemServiceAdd>
    </ItemServiceAddRq>
  </QBXMLMsgsRq>
</QBXML>';
            $this->log("Sending ItemServiceAddRq XML for item: $itemName\n$xml");
            $this->saveState();
            return new SendRequestXML($xml);
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
        $this->log("Received XML response:\n" . $object->response);

        $response = simplexml_load_string($object->response);

        $this->log("Current stage in receiveResponseXML: {$this->stage}");

        if ($this->stage === 'query_customer') {
            if (isset($response->QBXMLMsgsRs->CustomerQueryRs->CustomerRet)) {
                $this->log("Customer EXISTS in QuickBooks --> Moving to item check.");
                $this->stage = 'query_items';
            } else {
                $this->log("Customer NOT FOUND in QuickBooks --> Will add customer.");
                $this->stage = 'add_customer';
            }
            $this->saveState();
            return new ReceiveResponseXML(50);
        }

        if ($this->stage === 'add_customer') {
            $this->log("CustomerAdd completed. Moving to item check.");
            $this->stage = 'query_items';
            $this->saveState();
            return new ReceiveResponseXML(50);
        }

        if ($this->stage === 'query_items') {
            $itemName = $this->itemsToCheck[$this->currentItemIndex];
            
            if (isset($response->QBXMLMsgsRs->ItemQueryRs->ItemRet)) {
                $this->log("Item '$itemName' EXISTS in QuickBooks --> Skipping creation.");
            } else {
                $this->log("Item '$itemName' NOT FOUND in QuickBooks --> Will create item.");
                $this->itemsToCreate[] = $itemName;
            }
            
            $this->currentItemIndex++;
            
            if ($this->currentItemIndex >= count($this->itemsToCheck)) {
                // All items checked
                if (empty($this->itemsToCreate)) {
                    $this->log("All items exist, moving to invoice creation.");
                    $this->stage = 'add_invoice';
                } else {
                    $this->log("Need to create " . count($this->itemsToCreate) . " items: " . implode(', ', $this->itemsToCreate));
                    $this->stage = 'create_items';
                    $this->currentItemIndex = 0;
                }
            }
            
            $this->saveState();
            return new ReceiveResponseXML(50);
        }

        if ($this->stage === 'create_items') {
            $itemName = $this->itemsToCreate[$this->currentItemIndex];
            
            if (isset($response->QBXMLMsgsRs->ItemServiceAddRs->ItemServiceRet)) {
                $this->log("Item '$itemName' CREATED successfully in QuickBooks.");
            } else {
                $this->log("Item '$itemName' creation FAILED. Response: " . $object->response);
            }
            
            $this->currentItemIndex++;
            
            if ($this->currentItemIndex >= count($this->itemsToCreate)) {
                $this->log("All items processed, moving to invoice creation.");
                $this->stage = 'add_invoice';
            }
            
            $this->saveState();
            return new ReceiveResponseXML(50);
        }

        if ($this->stage === 'add_invoice') {
            $this->log("InvoiceAdd completed for Order #{$this->orders[$this->currentOrderIndex]['order_number']}.");
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