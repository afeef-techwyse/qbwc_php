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
                ['title' => 'Service X', 'quantity' => 5] // This item will be added
            ]
        ]
    ];

    private $currentOrderIndex = 0;
    private $stage = 'query_customer';
    private $customerName;
    private $currentItemIndex = 0; // -- NEW -- Tracks which line item we are processing

    // ---------------------- State Persistence ----------------------
    private function loadState()
    {
        $path = '/tmp/qbwc_app_state.json';
        if (file_exists($path)) {
            $state = json_decode(file_get_contents($path), true);
            if (is_array($state)) {
                $this->currentOrderIndex = $state['index'] ?? 0;
                $this->stage = $state['stage'] ?? 'query_customer';
                $this->currentItemIndex = $state['item_index'] ?? 0; // -- NEW --
            }
        }
    }

    private function saveState()
    {
        // -- MODIFIED --
        $state = [
            'index' => $this->currentOrderIndex,
            'stage' => $this->stage,
            'item_index' => $this->currentItemIndex
        ];
        file_put_contents('/tmp/qbwc_app_state.json', json_encode($state));
    }

    private function resetState()
    {
        // -- MODIFIED --
        $this->currentOrderIndex = 0;
        $this->stage = 'query_customer';
        $this->currentItemIndex = 0;
        @unlink('/tmp/qbwc_app_state.json');
    }

    // ---------------------- Logging ----------------------
    private function log($msg)
    {
        $ts = date('Y-m-d H:i:s');
        error_log("[$ts] AddCustomerInvoiceApp: $msg\n", 3, '/tmp/qbwc_app_debug.log');
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

        // -- NEW STAGE --
        if ($this->stage === 'query_item') {
            // Check if we are done with items for this order
            if ($this->currentItemIndex >= count($order['line_items'])) {
                $this->log("All items processed for Order #{$order['order_number']}. Moving to add_invoice stage.");
                $this->stage = 'add_invoice';
                // Fall through to the 'add_invoice' block below
            } else {
                // We still have items to query
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
                $this->log("Sending ItemQueryRq XML:\n$xml");
                $this->saveState();
                return new SendRequestXML($xml);
            }
        }

        // -- NEW STAGE --
        if ($this->stage === 'add_item') {
            $item = $order['line_items'][$this->currentItemIndex];
            $itemName = $item['title'];
            $this->log("Adding item: {$itemName}");

            // --- ATTENTION ---
            // Adding as 'ItemService'.
            // You MUST change 'Services' to a valid 'Income' account in your QB file.
            // This is the most common point of failure.
            $xml = '<?xml version="1.0" encoding="utf-8"?>
<?qbxml version="' . $qbxmlVersion . '"?>
<QBXML>
  <QBXMLMsgsRq onError="stopOnError">
    <ItemInventoryAddRq requestID="' . $this->generateGUID() . '">
      <ItemInventoryAdd>
        <Name>' . htmlspecialchars($itemName, ENT_XML1, 'UTF-8') . '</Name>
        <SalesOrPurchase>
          <Desc>' . htmlspecialchars($itemName, ENT_XML1, 'UTF-8') . '</Desc>
          <Price>0.00</Price>
          <AccountRef>
            <FullName>Services</FullName> </AccountRef>
        </SalesOrPurchase>
      </ItemInventoryAdd>
    </ItemInventoryAddRq>
  </QBXMLMsgsRq>
</QBXML>';
            $this->log("Sending ItemInventoryAddRq XML:\n$xml");
            $this->saveState();
            return new SendRequestXML($xml);
        }


        if ($this->stage === 'add_invoice') {
            // -- MODIFIED -- Corrected htmlentities to htmlspecialchars
            $xml = '<?xml version="1.0" encoding="utf-8"?>
<?qbxml version="' . $qbxmlVersion . '"?>
<QBXML>
  <QBXMLMsgsRq onError="stopOnError">
    <InvoiceAddRq requestID="' . $this->generateGUID() . '">
      <InvoiceAdd>
        <CustomerRef><FullName>' . htmlspecialchars($this->customerName, ENT_XML1, 'UTF-8') . '</FullName></CustomerRef>
        <RefNumber>' . htmlspecialchars($order['id'], ENT_XML1, 'UTF-8') . '</RefNumber>
        <Memo>Static Test Order #' . htmlspecialchars($order['order_number'], ENT_XML1, 'UTF-8') . '</Memo>';
            foreach ($order['line_items'] as $item) {
                $xml .= '
        <InvoiceLineAdd>
          <ItemRef><FullName>' . htmlspecialchars($item['title'], ENT_XML1, 'UTF-8') . '</FullName></ItemRef>
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
            // -- MODIFIED --
            if (isset($response->QBXMLMsgsRs->CustomerQueryRs->CustomerRet)) {
                $this->log("Customer EXISTS in QuickBooks --> Skipping add, moving to items.");
                $this->stage = 'query_item'; // Start checking items
            } else {
                $this->log("Customer NOT FOUND in QuickBooks --> Will add customer.");
                $this->stage = 'add_customer';
            }
            $this->saveState();
            return new ReceiveResponseXML(50);
        }

        if ($this->stage === 'add_customer') {
            // -- MODIFIED --
            $this->log("CustomerAdd completed. Moving to items.");
            $this->stage = 'query_item'; // Start checking items
            $this->saveState();
            return new ReceiveResponseXML(50);
        }

        // -- NEW STAGE HANDLER --
        if ($this->stage === 'query_item') {
            $itemQueryRs = $response->QBXMLMsgsRs->ItemQueryRs;
            $statusCode = (string)$itemQueryRs->attributes()->statusCode;

            if ($statusCode === '0') {
                $this->log("Item EXISTS in QuickBooks --> Skipping add.");
                $this->currentItemIndex++; // Move to next item
                $this->stage = 'query_item'; // Stay in item loop
            } else if ($statusCode === '3140') { // 3140 = "There is no item... in the list."
                $this->log("Item NOT FOUND in QuickBooks --> Will add item.");
                $this->stage = 'add_item'; // Move to add item
            } else {
                // An actual error occurred
                $errorMsg = (string)$itemQueryRs->attributes()->statusMessage;
                $this->log("Error querying item. Halting. Error: $errorMsg");
                $this->saveState(); // Save state but don't progress
                return new ReceiveResponseXML(100); // Stop processing
            }

            $this->saveState();
            return new ReceiveResponseXML(50); // Continue processing
        }

        // -- NEW STAGE HANDLER --
        if ($this->stage === 'add_item') {
            $itemAddRs = $response->QBXMLMsgsRs->ItemInventoryAddRs; // Assuming ItemInventoryAddRq
            $statusCode = (string)$itemAddRs->attributes()->statusCode;

            if ($statusCode === '0') {
                $this->log("ItemAdd completed successfully.");
                $this->currentItemIndex++; // Move to next item
                $this->stage = 'query_item'; // Go back to item loop
            } else {
                // An error occurred adding the item
                $errorMsg = (string)$itemAddRs->attributes()->statusMessage;
                $this->log("Error adding item. Halting. Error: $errorMsg");
                $this->saveState();
                return new ReceiveResponseXML(100); // Stop processing
            }
            $this->saveState();
            return new ReceiveResponseXML(50);
        }

        if ($this->stage === 'add_invoice') {
            $this->log("InvoiceAdd completed for Order #{$this->orders[$this->currentOrderIndex]['order_number']}.");
            $this->currentOrderIndex++;
            $this->stage = 'query_customer';
            $this->currentItemIndex = 0; // -- NEW -- Reset item index for the next order

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