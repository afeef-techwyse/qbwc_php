<?php
namespace QBWCServer\applications;

use QBWCServer\base\AbstractQBWCApplication;

/**
 * AddCustomerInvoiceApp
 * ---------------------
 * Handles Shopify order sync to QuickBooks:
 * - Fetch pending order
 * - Check/add customer
 * - Add invoice
 * - Mark as processed
 */
class AddCustomerInvoiceApp extends AbstractQBWCApplication
{
    private $currentOrder = null;
    private $stage = 'query_customer';
    private $customerName = '';
    private $customerExists = false;

    // === DATABASE CONFIG ===
    private $dsn = "mysql:host=shortline.proxy.rlwy.net;port=53111;dbname=railway";
    private $user = "root";
    private $pass = "wTVIYIVbAlJdCqIwbHigEVotdGKGdHNA";

    /**
     * Step 1: Called by QBWC to send QBXML.
     */
    public function sendRequestXML($object)
    {
        $qbxmlVersion = $this->_config['qbxmlVersion'] ?? '13.0';

        // Load the next pending order
        if (!$this->currentOrder) {
            $pdo = new \PDO($this->dsn, $this->user, $this->pass);
            $stmt = $pdo->query("SELECT * FROM orders_queue WHERE status='pending' LIMIT 1");
            $this->currentOrder = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$this->currentOrder) {
                // No pending orders
                return '';
            }

            $order = json_decode($this->currentOrder['payload'], true);
            $this->customerName = trim($order['customer']['first_name'] . ' ' . $order['customer']['last_name']);
        }

        $order = json_decode($this->currentOrder['payload'], true);

        // === Step 1: Customer Query ===
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
            return $xml;
        }

        // === Step 2: Add Customer ===
        if ($this->stage === 'add_customer') {
            $cust = $order['customer'];
            $addr = $cust['default_address'] ?? [];

            $xml = '<?xml version="1.0" encoding="utf-8"?>
<?qbxml version="' . $qbxmlVersion . '"?>
<QBXML>
  <QBXMLMsgsRq onError="stopOnError">
    <CustomerAddRq requestID="' . $this->generateGUID() . '">
      <CustomerAdd>
        <Name>' . htmlentities($this->customerName) . '</Name>
        <CompanyName>' . htmlentities($addr['company'] ?? '') . '</CompanyName>
        <FirstName>' . htmlentities($cust['first_name']) . '</FirstName>
        <LastName>' . htmlentities($cust['last_name']) . '</LastName>
        <BillAddress>
          <Addr1>' . htmlentities($addr['address1'] ?? '') . '</Addr1>
          <City>' . htmlentities($addr['city'] ?? '') . '</City>
          <State>' . htmlentities($addr['province'] ?? '') . '</State>
          <PostalCode>' . htmlentities($addr['zip'] ?? '') . '</PostalCode>
          <Country>' . htmlentities($addr['country'] ?? '') . '</Country>
        </BillAddress>
        <Email>' . htmlentities($cust['email'] ?? '') . '</Email>
        <Phone>' . htmlentities($cust['phone'] ?? '') . '</Phone>
      </CustomerAdd>
    </CustomerAddRq>
  </QBXMLMsgsRq>
</QBXML>';
            return $xml;
        }

        // === Step 3: Add Invoice ===
        if ($this->stage === 'add_invoice') {
            $xml = '<?xml version="1.0" encoding="utf-8"?>
<?qbxml version="' . $qbxmlVersion . '"?>
<QBXML>
  <QBXMLMsgsRq onError="stopOnError">
    <InvoiceAddRq requestID="' . $this->generateGUID() . '">
      <InvoiceAdd>
        <CustomerRef>
          <FullName>' . htmlentities($this->customerName) . '</FullName>
        </CustomerRef>
        <RefNumber>' . htmlentities($order['id']) . '</RefNumber>
        <Memo>Shopify Order #' . htmlentities($order['order_number']) . '</Memo>';

            foreach ($order['line_items'] as $item) {
                $xml .= '
        <InvoiceLineAdd>
          <ItemRef><FullName>' . htmlentities($item['title']) . '</FullName></ItemRef>
          <Quantity>' . (int)$item['quantity'] . '</Quantity>
          <Desc>' . htmlentities($item['name']) . '</Desc>
          <Rate>' . htmlentities($item['price']) . '</Rate>
        </InvoiceLineAdd>';
            }

            $xml .= '
      </InvoiceAdd>
    </InvoiceAddRq>
  </QBXMLMsgsRq>
</QBXML>';
            return $xml;
        }

        return '';
    }

    /**
     * Step 2: Called after QBWC processes XML.
     */
    public function receiveResponseXML($object)
    {
        $response = simplexml_load_string($object->response);

        // === Handle Customer Query ===
        if ($this->stage === 'query_customer') {
            if (isset($response->QBXMLMsgsRs->CustomerQueryRs->CustomerRet)) {
                $this->customerExists = true;
                $this->stage = 'add_invoice';
            } else {
                $this->customerExists = false;
                $this->stage = 'add_customer';
            }
            return 50; // ask QBWC to call sendRequestXML again
        }

        // === Handle Customer Add ===
        if ($this->stage === 'add_customer') {
            $this->stage = 'add_invoice';
            return 50;
        }

        // === Handle Invoice Add ===
        if ($this->stage === 'add_invoice') {
            $this->updateStatus($this->currentOrder['id'], 'invoice_done');
            $this->reset();
            return 100; // done for this order
        }

        return 100;
    }

    /**
     * Update order status in DB
     */
    private function updateStatus($id, $status)
    {
        $pdo = new \PDO($this->dsn, $this->user, $this->pass);
        $stmt = $pdo->prepare("UPDATE orders_queue SET status=:status WHERE id=:id");
        $stmt->execute([':status' => $status, ':id' => $id]);
    }

    /**
     * Reset app state between orders
     */
    private function reset()
    {
        $this->currentOrder = null;
        $this->stage = 'query_customer';
        $this->customerExists = false;
        $this->customerName = '';
    }

    /**
     * Required for login
     */
    public function authenticate($strUserName, $strPassword)
    {
        // your QBWC credentials
        if ($username === 'Admin' && $password === '1') {
            return ['SESSION123', ''];
        } else {
            return ['none', 'nvu'];
        }
    }

    /**
     * Helper to generate requestID
     */
    private function generateGUID()
    {
        return strtoupper(md5(uniqid(rand(), true)));
    }
}
