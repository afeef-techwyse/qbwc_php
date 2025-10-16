<?php
namespace QBWCServer\applications;

use QBWCServer\base\AbstractQBWCApplication;
use QBWCServer\response\ReceiveResponseXML;
use QBWCServer\response\SendRequestXML;

class AddCustomerInvoiceApp extends AbstractQBWCApplication
{
    private $dsn = "mysql:host=shortline.proxy.rlwy.net;port=53111;dbname=railway";
    private $dbUser = "root"; 
    private $dbPass = "wTVIYIVbAlJdCqIwbHigEVotdGKGdHNA";
    
    private $pdo;
    private $currentOrder = null;
    private $stage = 'query_customer';
    private $customerName = '';

    public function __construct() 
    {
        parent::__construct();
        $this->pdo = $this->getPDO();
    }

    private function getPDO()
    {
        static $p = null;
        if ($p) return $p;
        
        $p = new \PDO($this->dsn, $this->dbUser, $this->dbPass, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC
        ]);
        return $p;
    }

    public function sendRequestXML($object)
    {
        // Get QBXML version from request parameters
        $qbxmlVersion = $object->qbXMLMajorVers . "." . $object->qbXMLMinorVers;

        // If no current order, try to fetch next from DB
        if (!$this->currentOrder) {
            $this->currentOrder = $this->fetchNextOrder();
            if (!$this->currentOrder) {
                return new SendRequestXML('');
            }
            $order = json_decode($this->currentOrder['payload'], true);
            $this->customerName = trim($order['customer']['first_name'] . ' ' . $order['customer']['last_name']);
            $this->stage = $this->currentOrder['status'];
            if ($this->stage === 'pending') {
                $this->stage = 'query_customer';
            }
        } else {
            $order = json_decode($this->currentOrder['payload'], true);
        }

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
            return new SendRequestXML($xml);
        }

        if ($this->stage === 'add_customer') {
            $this->updateOrderStatus($this->currentOrder['id'], 'add_customer');
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
            return new SendRequestXML($xml);
        }

        if ($this->stage === 'add_invoice') {
            $this->updateOrderStatus($this->currentOrder['id'], 'add_invoice');
            $xml = '<?xml version="1.0" encoding="utf-8"?>
<?qbxml version="' . $qbxmlVersion . '"?>
<QBXML>
  <QBXMLMsgsRq onError="stopOnError">
    <InvoiceAddRq requestID="' . $this->generateGUID() . '">
      <InvoiceAdd>
        <CustomerRef>
          <FullName>' . htmlspecialchars($this->customerName, ENT_XML1, 'UTF-8') . '</FullName>
        </CustomerRef>
        <RefNumber>' . htmlspecialchars($order['id'], ENT_XML1, 'UTF-8') . '</RefNumber>
        <Memo>Order #' . htmlspecialchars($order['order_number'], ENT_XML1, 'UTF-8') . '</Memo>';

            foreach ($order['line_items'] as $item) {
                $xml .= '
        <InvoiceLineAdd>
          <ItemRef>
            <FullName>' . htmlspecialchars($item['title'], ENT_XML1, 'UTF-8') . '</FullName>
          </ItemRef>
          <Quantity>' . (int)$item['quantity'] . '</Quantity>
          <Rate>' . htmlspecialchars($item['price'] ?? '0.00', ENT_XML1, 'UTF-8') . '</Rate>
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
        if (!$object->response) {
            return new ReceiveResponseXML(100);
        }

        $response = simplexml_load_string($object->response);

        if ($this->stage === 'query_customer') {
            if (isset($response->QBXMLMsgsRs->CustomerQueryRs->CustomerRet)) {
                $this->stage = 'add_invoice';
                $this->updateOrderStatus($this->currentOrder['id'], 'add_invoice');
            } else {
                $this->stage = 'add_customer';
                $this->updateOrderStatus($this->currentOrder['id'], 'add_customer');
            }
            return new ReceiveResponseXML(50);
        }

        if ($this->stage === 'add_customer') {
            $this->stage = 'add_invoice';
            $this->updateOrderStatus($this->currentOrder['id'], 'add_invoice');
            return new ReceiveResponseXML(50);
        }

        if ($this->stage === 'add_invoice') {
            $this->updateOrderStatus($this->currentOrder['id'], 'completed');
            $this->reset();
            return new ReceiveResponseXML(100);
        }

        return new ReceiveResponseXML(100);
    }

    private function fetchNextOrder()
    {
        // First try to get an order that's in progress
        $stmt = $this->pdo->prepare(
            "SELECT * FROM orders_queue 
             WHERE status IN ('query_customer','add_customer','add_invoice') 
             ORDER BY id ASC LIMIT 1"
        );
        $stmt->execute();
        $row = $stmt->fetch();
        if ($row) return $row;

        // If no in-progress orders, get next pending order
        $stmt = $this->pdo->prepare(
            "SELECT * FROM orders_queue WHERE status = 'pending' ORDER BY id ASC LIMIT 1"
        );
        $stmt->execute();
        return $stmt->fetch();
    }

    private function updateOrderStatus($id, $status)
    {
        $stmt = $this->pdo->prepare(
            "UPDATE orders_queue SET status = :status WHERE id = :id"
        );
        $stmt->execute([':status' => $status, ':id' => $id]);
    }

    private function reset()
    {
        $this->currentOrder = null;
        $this->stage = 'query_customer';
        $this->customerName = '';
    }

    // Change from private to public
    public function generateGUID()
    {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }

    /**
     * Required SOAP method - returns server version
     */
    public function serverVersion($object)
    {
        return "PHP QtWebConnector 1.0";
    }

    /**
     * Required SOAP method - handles client version verification
     */
    public function clientVersion($object)
    {
        return "";  // Return empty string to accept any client version
    }

    /**
     * Required SOAP method - handles authentication
     */
    public function authenticate($object)
    {
        $username = $object->strUserName ?? '';
        $password = $object->strPassword ?? '';

        if ($username === 'Admin' && $password === '1') {
            $ticket = $this->generateGUID();
            return array(
                $ticket,  // Ticket
                '',       // Empty company file path
                null,     // Optional wait time
                null      // Optional min run time
            );
        }
        
        return array('', 'nvu', null, null);
    }

    /**
     * Required SOAP method - handles connection closing
     */
    public function closeConnection($object)
    {
        return "Connection closed";
    }
}