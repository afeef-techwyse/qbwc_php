<?php
namespace QBWCServer\applications;

use QBWCServer\base\AbstractQBWCApplication;
use QBWCServer\response\ReceiveResponseXML;
use QBWCServer\response\SendRequestXML;

class AddShopifyOrdersApp extends AbstractQBWCApplication
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
                ['title' => 'Product A', 'quantity' => 2],
                ['title' => 'Product B', 'quantity' => 1]
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

    // ---------------------- State Persistence ----------------------
    private function loadState() {
        $path = '/tmp/qbwc_app_state.json';
        if (file_exists($path)) {
            $state = json_decode(file_get_contents($path), true);
            if (is_array($state)) {
                $this->currentOrderIndex = $state['index'];
                $this->stage = $state['stage'];
            }
        }
    }

    private function saveState() {
        $state = ['index' => $this->currentOrderIndex, 'stage' => $this->stage];
        file_put_contents('/tmp/qbwc_app_state.json', json_encode($state));
    }
    private function resetState() {
        $this->currentOrderIndex = 0;
        $this->stage = 'query_customer';
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
        $qbxmlVersion = $this->_config['qbxmlVersion'];

        $this->log("Stage: {$this->stage} -- Order: {$order['order_number']} (Customer: {$this->customerName})");

        if ($this->stage === 'query_customer') {
            $xml = '<?xml version="1.0" encoding="utf-8"?>
<?qbxml version="' . $qbxmlVersion . '"?>
<QBXML>
  <QBXMLMsgsRq onError="stopOnError">
    <CustomerQueryRq requestID="' . $this->generateGUID() . '">
      <FullName>' . htmlentities($this->customerName) . '</FullName>
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
        <Name>' . htmlentities($this->customerName) . '</Name>
        <CompanyName>' . htmlentities($addr['company']) . '</CompanyName>
        <FirstName>' . htmlentities($cust['first_name']) . '</FirstName>
        <LastName>' . htmlentities($cust['last_name']) . '</LastName>
        <BillAddress>
          <Addr1>' . htmlentities($addr['address1']) . '</Addr1>
          <City>' . htmlentities($addr['city']) . '</City>
          <State>' . htmlentities($addr['province']) . '</State>
          <PostalCode>' . htmlentities($addr['zip']) . '</PostalCode>
          <Country>' . htmlentities($addr['country']) . '</Country>
        </BillAddress>
        <Email>' . htmlentities($cust['email']) . '</Email>
        <Phone>' . htmlentities($cust['phone']) . '</Phone>
      </CustomerAdd>
    </CustomerAddRq>
  </QBXMLMsgsRq>
</QBXML>';
            $this->log("Sending CustomerAddRq XML:\n$xml");
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
                $this->log("Customer EXISTS in QuickBooks --> Skipping add, moving to invoice.");
                $this->stage = 'add_invoice';
            } else {
                $this->log("Customer NOT FOUND in QuickBooks --> Will add customer.");
                $this->stage = 'add_customer';
            }
            $this->saveState();
            return new ReceiveResponseXML(50);
        }

        if ($this->stage === 'add_customer') {
            $this->log("CustomerAdd completed. Moving to invoice.");
            $this->stage = 'add_invoice';
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