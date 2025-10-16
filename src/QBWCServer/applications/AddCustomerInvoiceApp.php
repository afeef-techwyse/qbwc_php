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

    private function getPDO()
    {
        return new \PDO(
            "mysql:host=sql5.freesqldatabase.com;dbname=sql5802997",
            "sql5802997",
            "8jhmVbi8lN",
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
        );
    }

    public function sendRequestXML($object)
    {
        // Load the next order if not already loaded
        if (!$this->currentOrder) {
            $pdo = $this->getPDO();
            // Pick the next active order (pending or mid-stage)
            $stmt = $pdo->query("
                SELECT * FROM orders_queue 
                WHERE status IN ('pending','query_customer','add_customer','add_invoice') 
                ORDER BY id ASC 
                LIMIT 1
            ");
            $this->currentOrder = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$this->currentOrder) {
                // No orders left to process
                return new SendRequestXML('');
            }

            $order = json_decode($this->currentOrder['payload'], true);
            $this->customerName = trim($order['customer']['first_name'] . ' ' . $order['customer']['last_name']);
            $this->stage = $this->currentOrder['status']; // Resume from saved stage
        }

        $order = json_decode($this->currentOrder['payload'], true);
        $qbxmlVersion = $this->_config['qbxmlVersion'];

        // STAGE 1: Query existing customer
        if ($this->stage === 'pending' || $this->stage === 'query_customer') {
            $this->updateStatus($this->currentOrder['id'], 'query_customer');

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

        // STAGE 2: Add customer if missing
        if ($this->stage === 'add_customer') {
            $this->updateStatus($this->currentOrder['id'], 'add_customer');

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
          <Addr1>' . htmlentities($cust['default_address']['address1'] ?? '') . '</Addr1>
          <City>' . htmlentities($cust['default_address']['city'] ?? '') . '</City>
          <State>' . htmlentities($cust['default_address']['province'] ?? '') . '</State>
          <PostalCode>' . htmlentities($cust['default_address']['zip'] ?? '') . '</PostalCode>
          <Country>' . htmlentities($cust['default_address']['country'] ?? '') . '</Country>
        </BillAddress>
        <Email>' . htmlentities($cust['email'] ?? '') . '</Email>
        <Phone>' . htmlentities($cust['phone'] ?? '') . '</Phone>
      </CustomerAdd>
    </CustomerAddRq>
  </QBXMLMsgsRq>
</QBXML>';
            return new SendRequestXML($xml);
        }

        // STAGE 3: Add invoice
        if ($this->stage === 'add_invoice') {
            $this->updateStatus($this->currentOrder['id'], 'add_invoice');

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

        // Default (shouldn’t reach here)
        return new SendRequestXML('');
    }

    public function receiveResponseXML($object)
    {
        $response = simplexml_load_string($object->response);

        if (!$this->currentOrder) {
            // Safety: reload current order (in case of stateless call)
            $pdo = $this->getPDO();
            $stmt = $pdo->query("SELECT * FROM orders_queue WHERE status IN ('query_customer','add_customer','add_invoice') ORDER BY id ASC LIMIT 1");
            $this->currentOrder = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$this->currentOrder) return new ReceiveResponseXML(100);
        }

        // 1️⃣ After CustomerQuery
        if ($this->stage === 'query_customer' || $this->stage === 'pending') {
            if (isset($response->QBXMLMsgsRs->CustomerQueryRs->CustomerRet)) {
                // Customer already exists
                $this->updateStatus($this->currentOrder['id'], 'add_invoice');
                $this->stage = 'add_invoice';
            } else {
                // Need to add customer
                $this->updateStatus($this->currentOrder['id'], 'add_customer');
                $this->stage = 'add_customer';
            }
            return new ReceiveResponseXML(50);
        }

        // 2️⃣ After CustomerAdd
        if ($this->stage === 'add_customer') {
            $this->updateStatus($this->currentOrder['id'], 'add_invoice');
            $this->stage = 'add_invoice';
            return new ReceiveResponseXML(50);
        }

        // 3️⃣ After InvoiceAdd (final step)
        if ($this->stage === 'add_invoice') {
            $this->updateStatus($this->currentOrder['id'], 'invoice_done');
            $this->reset();
            return new ReceiveResponseXML(100); // Done for this order
        }

        // Fallback
        return new ReceiveResponseXML(100);
    }

    private function updateStatus($id, $status)
    {
        $pdo = $this->getPDO();
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