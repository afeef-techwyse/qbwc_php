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

    // New for item handling
    private $itemIndex = 0; // current item being checked/added in this order
    private $itemsToCheck = []; // list of item titles for current order

    // ---------------------- State Persistence ----------------------
    private function loadState() {
        $path = '/tmp/qbwc_app_state.json';
        if (file_exists($path)) {
            $state = json_decode(file_get_contents($path), true);
            if (is_array($state)) {
                $this->currentOrderIndex = $state['currentOrderIndex'];
                $this->stage = $state['stage'];
                $this->itemIndex = $state['itemIndex'];
                $this->itemsToCheck = $state['itemsToCheck'];
            }
        }
    }

    private function saveState() {
        $state = [
            'currentOrderIndex' => $this->currentOrderIndex,
            'stage' => $this->stage,
            'itemIndex' => $this->itemIndex,
            'itemsToCheck' => $this->itemsToCheck
        ];
        file_put_contents('/tmp/qbwc_app_state.json', json_encode($state));
    }

    private function resetState() {
        $this->currentOrderIndex = 0;
        $this->stage = 'query_customer';
        $this->itemIndex = 0;
        $this->itemsToCheck = [];
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

        // New stage: Prepare to check items in order
        if ($this->stage === 'prepare_item_checks') {
            $this->itemsToCheck = array_map(function ($item) {
                return $item['title'];
            }, $order['line_items']);
            $this->itemIndex = 0;
            $this->stage = 'query_item';
            $this->saveState();
            $this->log("Prepared item list for item checking.");
            return $this->sendRequestXML($object); // recurse to continue with item querying
        }

        // Query each item for existence in QuickBooks
        if ($this->stage === 'query_item') {
            if ($this->itemIndex >= count($this->itemsToCheck)) {
                // All items checked, move to add invoice
                $this->stage = 'add_invoice';
                $this->saveState();
                return $this->sendRequestXML($object);
            }
            $itemName = $this->itemsToCheck[$this->itemIndex];
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

        // Add missing item
        if ($this->stage === 'add_item') {
            $itemName = $this->itemsToCheck[$this->itemIndex];
            // Simplified example as non-inventory item with default sales price and income account (adjust as needed)
            $xml = '<?xml version="1.0" encoding="utf-8"?>
<?qbxml version="' . $qbxmlVersion . '"?>
<QBXML>
 <QBXMLMsgsRq onError="stopOnError">
  <ItemNonInventoryAddRq requestID="' . $this->generateGUID() . '">
   <ItemNonInventoryAdd>
    <Name>' . htmlspecialchars($itemName, ENT_XML1, 'UTF-8') . '</Name>
    <SalesPrice>0.00</SalesPrice>
    <IncomeAccountRef>
     <FullName>Sales</FullName>
    </IncomeAccountRef>
   </ItemNonInventoryAdd>
  </ItemNonInventoryAddRq>
 </QBXMLMsgsRq>
</QBXML>';
            $this->log("Sending ItemNonInventoryAddRq XML for item: $itemName\n$xml");
            $this->saveState();
            return new SendRequestXML($xml);
        }

        // Add invoice stage (same as before)
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

        // Fallback
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
                $this->log("Customer EXISTS in QuickBooks --> Skipping add, preparing items.");
                $this->stage = 'prepare_item_checks';
            } else {
                $this->log("Customer NOT FOUND in QuickBooks --> Will add customer.");
                $this->stage = 'add_customer';
            }
            $this->saveState();
            return new ReceiveResponseXML(50);
        }

        if ($this->stage === 'add_customer') {
            $this->log("CustomerAdd completed. Preparing to check items.");
            $this->stage = 'prepare_item_checks';
            $this->saveState();
            return new ReceiveResponseXML(50);
        }

        if ($this->stage === 'query_item') {
            // Check if item found in the response
            if (isset($response->QBXMLMsgsRs->ItemQueryRs->ItemInventoryRet) ||
                isset($response->QBXMLMsgsRs->ItemQueryRs->ItemNonInventoryRet) ||
                isset($response->QBXMLMsgsRs->ItemQueryRs->ItemServiceRet)) {
                $this->log("Item '{$this->itemsToCheck[$this->itemIndex]}' EXISTS in QuickBooks.");
                // Item exists, move to next item
                $this->itemIndex++;
                $this->stage = 'query_item';
            } else {
                // Item not found, add it now
                $this->log("Item '{$this->itemsToCheck[$this->itemIndex]}' NOT FOUND in QuickBooks. Adding item.");
                $this->stage = 'add_item';
            }
            $this->saveState();
            return new ReceiveResponseXML(50);
        }

        if ($this->stage === 'add_item') {
            $this->log("ItemAdd completed for '{$this->itemsToCheck[$this->itemIndex]}'. Moving to next item.");
            $this->itemIndex++;
            $this->stage = 'query_item';
            $this->saveState();
            return new ReceiveResponseXML(50);
        }

        if ($this->stage === 'add_invoice') {
            $this->log("InvoiceAdd completed for Order #{$this->orders[$this->currentOrderIndex]['order_number']}.");
            $this->currentOrderIndex++;
            $this->stage = 'query_customer';
            $this->itemIndex = 0;
            $this->itemsToCheck = [];
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