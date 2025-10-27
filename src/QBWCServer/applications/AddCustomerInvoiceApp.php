<?php

namespace QBWCServer\applications;

use QBWCServer\base\AbstractQBWCApplication;
use QBWCServer\response\ReceiveResponseXML;
use QBWCServer\response\SendRequestXML;

class AddCustomerInvoiceApp extends AbstractQBWCApplication
{
    private $orders = [
        [
            'id' => 1001,
            'order_number' => 'S10023',
            'customer' => [
                'first_name' => 'John1',
                'last_name' => 'Doe1',
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
                ['title' => 'ACCSRC', 'quantity' => 2, 'rate' => 100.00],
                ['title' => 'NONSTOCK', 'quantity' => 1, 'rate' => 50.00]
            ]
        ],
        [
            'id' => 1002,
            'order_number' => 'S10031',
            'customer' => [
                'first_name' => 'Jane1',
                'last_name' => 'Smith1',
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
                ['title' => 'Service X', 'quantity' => 5, 'rate' => 75.00]
            ]
        ]
    ];

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
                $this->log("Invoice successfully created for Order #{$this->orders[$this->currentOrderIndex]['order_number']}.");
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