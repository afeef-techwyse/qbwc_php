<?php
namespace QBWCServer\applications;

use QBWCServer\base\AbstractQBWCApplication;
use QBWCServer\response\ReceiveResponseXML;
use QBWCServer\response\SendRequestXML;

/**
 * Unified application to process Shopify orders:
 * 1. Get next pending order from DB
 * 2. CustomerQuery -> if missing -> CustomerAdd
 * 3. InvoiceAdd
 */
class AddCustomerInvoiceApp extends AbstractQBWCApplication
{
    private $currentOrder;
    private $stage = 'query_customer';
    private $customerName;
    private $customerExists = false;

    public function sendRequestXML($object)
    {
        // Load the next order if not already loaded
        if (!$this->currentOrder) {
            $pdo = new \PDO("mysql:host=localhost;dbname=mseet_40172767_qb_integration", "mseet_40172767", "Tech@142536");
            $stmt = $pdo->query("SELECT * FROM orders_queue WHERE status='pending' LIMIT 1");
            $this->currentOrder = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$this->currentOrder) {
                // Nothing to do
                return new SendRequestXML('');
            }

            $order = json_decode($this->currentOrder['payload'], true);
            $this->customerName =
                trim($order['customer']['first_name'] . ' ' . $order['customer']['last_name']);
        }

        $order = json_decode($this->currentOrder['payload'], true);

        $qbxmlVersion = $this->_config['qbxmlVersion'];

        if ($this->stage === 'query_customer') {
            // Step 1: Query Customer
            $xml = '<?xml version="1.0" encoding="utf-8"?>
<?qbxml version="' . $qbxmlVersion . '"?>
<QBXML>
  <QBXMLMsgsRq onError="stopOnError">
    <CustomerQueryRq requestID="' . $this->generateGUID() . '">
      <FullName>' . htmlentities($this->customerName) . '</FullName>
    </CustomerQueryRq>
  </QBXMLMsgsRq>
</QBXML>';

            return new SendRequestXML($xml);
        }

        if ($this->stage === 'add_customer') {
            // Step 2: Add Customer
            $cust = $order['customer'];

            $xml = '<?xml version="1.0" encoding="utf-8"?>
<?qbxml version="' . $qbxmlVersion . '"?>
<QBXML>
  <QBXMLMsgsRq onError="stopOnError">
    <CustomerAddRq requestID="' . $this->generateGUID() . '">
      <CustomerAdd>
        <Name>' . htmlentities($this->customerName) . '</Name>
        <CompanyName>' . htmlentities($cust['default_address']['company'] ?? '') . '</CompanyName>
        <FirstName>' . htmlentities($cust['first_name']) . '</FirstName>
        <LastName>' . htmlentities($cust['last_name']) . '</LastName>
        <BillAddress>
          <Addr1>' . htmlentities($cust['default_address']['address1']) . '</Addr1>
          <City>' . htmlentities($cust['default_address']['city']) . '</City>
          <State>' . htmlentities($cust['default_address']['province']) . '</State>
          <PostalCode>' . htmlentities($cust['default_address']['zip']) . '</PostalCode>
          <Country>' . htmlentities($cust['default_address']['country']) . '</Country>
        </BillAddress>
        <Email>' . htmlentities($cust['email']) . '</Email>
        <Phone>' . htmlentities($cust['phone']) . '</Phone>
      </CustomerAdd>
    </CustomerAddRq>
  </QBXMLMsgsRq>
</QBXML>';

            return new SendRequestXML($xml);
        }

        if ($this->stage === 'add_invoice') {
            // Step 3: Add Invoice
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
        </InvoiceLineAdd>';
            }

            $xml .= '
      </InvoiceAdd>
    </InvoiceAddRq>
  </QBXMLMsgsRq>
</QBXML>';

            return new SendRequestXML($xml);
        }

        return new SendRequestXML('');
    }

    public function receiveResponseXML($object)
    {
        $response = simplexml_load_string($object->response);

        if ($this->stage === 'query_customer') {
            // Check QB response for existing customer
            if (isset($response->QBXMLMsgsRs->CustomerQueryRs->CustomerRet)) {
                $this->customerExists = true;
                $this->stage = 'add_invoice'; // Skip customer add
            } else {
                $this->customerExists = false;
                $this->stage = 'add_customer';
            }
            return new ReceiveResponseXML(50); // Ask QBWC for next sendRequestXML in same cycle
        }

        if ($this->stage === 'add_customer') {
            // After adding customer, next step is invoice
            $this->stage = 'add_invoice';
            return new ReceiveResponseXML(50);
        }

        if ($this->stage === 'add_invoice') {
            // After invoice, mark order as processed
            $this->updateStatus($this->currentOrder['id'], 'invoice_done');
            $this->reset();
            return new ReceiveResponseXML(100); // Done for this cycle
        }

        return new ReceiveResponseXML(100);
    }

    private function updateStatus($id, $status)
    {
        $pdo = new \PDO("mysql:host=localhost;dbname=mseet_40172767_qb_integration", "mseet_40172767", "Tech@142536");
        $stmt = $pdo->prepare("UPDATE orders_queue SET status=:status WHERE id=:id");
        $stmt->execute([':status' => $status, ':id' => $id]);
    }

    private function reset()
    {
        $this->currentOrder = null;
        $this->stage = 'query_customer';
        $this->customerExists = false;
    }
}