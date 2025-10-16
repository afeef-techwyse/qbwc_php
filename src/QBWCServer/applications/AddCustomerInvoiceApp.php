<?php
namespace QBWCServer\applications;

use QBWCServer\base\AbstractQBWCApplication;

/**
 * AddCustomerInvoiceApp
 * - Implements correct method signatures expected by QBWC
 * - Persists stage in orders_queue.status
 *
 * Table:
 * CREATE TABLE orders_queue (
 *   id INT AUTO_INCREMENT PRIMARY KEY,
 *   payload TEXT NOT NULL,
 *   status VARCHAR(50) NOT NULL DEFAULT 'pending',
 *   updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
 * );
 */
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
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);
        return $p;
    }

    // Authenticate
    public function authenticate($object)
    {
        $UserName = $object->UserName ?? $object->userName ?? '';
        $Password = $object->Password ?? $object->password ?? '';

        $validUser = 'Admin';
        $validPass = '1';

        if ($UserName === $validUser && $Password === $validPass) {
            $ticket = uniqid('qbwc_', true);
            return [$ticket, ''];
        }

        return ['none', 'nvu'];
    }

    // Send QBXML request to QuickBooks
    public function sendRequestXML($object)
    {
        $qbxmlVersion = $this->_config['qbxmlVersion'] ?? '13.0';

        if (!$this->currentOrder) {
            $this->currentOrder = $this->fetchNextOrder();
            if (!$this->currentOrder) {
                return '';
            }

            $order = json_decode($this->currentOrder['payload'], true);
            $this->customerName = trim(($order['customer']['first_name'] ?? '') . ' ' . ($order['customer']['last_name'] ?? ''));
            $this->stage = $this->currentOrder['status'] ?? 'pending';
            if ($this->stage === 'pending') $this->stage = 'query_customer';
        } else {
            $order = json_decode($this->currentOrder['payload'], true);
        }

        if ($this->stage === 'query_customer') {
            $this->updateOrderStatus($this->currentOrder['id'], 'query_customer');
            $xml = $this->wrapQBXML(
                $qbxmlVersion,
                '<CustomerQueryRq requestID="' . $this->generateGUID() . '">
                    <FullName>' . $this->xmlEscape($this->customerName) . '</FullName>
                </CustomerQueryRq>'
            );
            return $xml;
        }

        if ($this->stage === 'add_customer') {
            $this->updateOrderStatus($this->currentOrder['id'], 'add_customer');
            $cust = $order['customer'] ?? [];
            $addr = $cust['default_address'] ?? [];

            $inner = '<CustomerAddRq requestID="' . $this->generateGUID() . '">
                <CustomerAdd>
                    <Name>' . $this->xmlEscape($this->customerName) . '</Name>
                    <CompanyName>' . $this->xmlEscape($addr['company'] ?? '') . '</CompanyName>
                    <FirstName>' . $this->xmlEscape($cust['first_name'] ?? '') . '</FirstName>
                    <LastName>' . $this->xmlEscape($cust['last_name'] ?? '') . '</LastName>
                    <BillAddress>
                        <Addr1>' . $this->xmlEscape($addr['address1'] ?? '') . '</Addr1>
                        <City>' . $this->xmlEscape($addr['city'] ?? '') . '</City>
                        <State>' . $this->xmlEscape($addr['province'] ?? '') . '</State>
                        <PostalCode>' . $this->xmlEscape($addr['zip'] ?? '') . '</PostalCode>
                        <Country>' . $this->xmlEscape($addr['country'] ?? '') . '</Country>
                    </BillAddress>
                    <Email>' . $this->xmlEscape($cust['email'] ?? '') . '</Email>
                    <Phone>' . $this->xmlEscape($cust['phone'] ?? '') . '</Phone>
                </CustomerAdd>
            </CustomerAddRq>';

            return $this->wrapQBXML($qbxmlVersion, $inner);
        }

        if ($this->stage === 'add_invoice') {
            $this->updateOrderStatus($this->currentOrder['id'], 'add_invoice');
            $itemsXml = '';

            foreach ($order['line_items'] ?? [] as $item) {
                $itemsXml .= '
                    <InvoiceLineAdd>
                        <ItemRef><FullName>' . $this->xmlEscape($item['title'] ?? $item['name'] ?? 'Item') . '</FullName></ItemRef>
                        <Desc>' . $this->xmlEscape($item['name'] ?? '') . '</Desc>
                        <Quantity>' . (int)($item['quantity'] ?? 1) . '</Quantity>
                        <Rate>' . $this->xmlEscape($item['price'] ?? '0.00') . '</Rate>
                    </InvoiceLineAdd>';
            }

            $inner = '<InvoiceAddRq requestID="' . $this->generateGUID() . '">
                <InvoiceAdd>
                    <CustomerRef><FullName>' . $this->xmlEscape($this->customerName) . '</FullName></CustomerRef>
                    <RefNumber>' . $this->xmlEscape($order['id'] ?? '') . '</RefNumber>
                    <Memo>Shopify Order #' . $this->xmlEscape($order['order_number'] ?? $order['name'] ?? '') . '</Memo>
                    ' . $itemsXml . '
                </InvoiceAdd>
            </InvoiceAddRq>';

            return $this->wrapQBXML($qbxmlVersion, $inner);
        }

        return '';
    }

    // Receive response from QBWC
    public function receiveResponseXML($object)
    {
        $responseXmlStr = $object->response ?? '';
        if (empty(trim($responseXmlStr))) return 100;

        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($responseXmlStr);
        if ($xml === false) {
            error_log("QBWC: malformed XML in receiveResponseXML");
            return 100;
        }

        $msg = $xml->QBXMLMsgsRs->children();
        $childName = $msg->getName();

        if ($childName === 'CustomerQueryRs') {
            if (isset($xml->QBXMLMsgsRs->CustomerQueryRs->CustomerRet)) {
                $this->updateOrderStatus($this->currentOrder['id'], 'add_invoice');
                $this->stage = 'add_invoice';
            } else {
                $this->updateOrderStatus($this->currentOrder['id'], 'add_customer');
                $this->stage = 'add_customer';
            }
            return 50;
        }

        if ($childName === 'CustomerAddRs') {
            $this->updateOrderStatus($this->currentOrder['id'], 'add_invoice');
            $this->stage = 'add_invoice';
            return 50;
        }

        if ($childName === 'InvoiceAddRs') {
            $this->updateOrderStatus($this->currentOrder['id'], 'invoice_done');
            $this->reset();
            return 100;
        }

        return 100;
    }

    private function fetchNextOrder()
    {
        $stmt = $this->getPDO()->prepare(
            "SELECT * FROM orders_queue
             WHERE status IN ('query_customer','add_customer','add_invoice')
             ORDER BY id ASC LIMIT 1"
        );
        $stmt->execute();
        $row = $stmt->fetch();
        if ($row) return $row;

        $stmt = $this->getPDO()->prepare("SELECT * FROM orders_queue WHERE status = 'pending' ORDER BY id ASC LIMIT 1");
        $stmt->execute();
        return $stmt->fetch() ?: null;
    }

    private function updateOrderStatus($id, $status)
    {
        $stmt = $this->getPDO()->prepare("UPDATE orders_queue SET status = :status WHERE id = :id");
        $stmt->execute([':status' => $status, ':id' => $id]);

        if ($this->currentOrder && (int)$this->currentOrder['id'] === (int)$id) {
            $this->currentOrder['status'] = $status;
        }
    }

    private function reset()
    {
        $this->currentOrder = null;
        $this->stage = 'query_customer';
        $this->customerName = '';
    }

    // FIXED visibility
    public function generateGUID()
    {
        if (function_exists('com_create_guid')) {
            return trim(com_create_guid(), '{}');
        }
        return strtoupper(md5(uniqid((string)rand(), true)));
    }

    private function wrapQBXML($qbxmlVersion, $innerXml)
    {
        return '<?xml version="1.0" encoding="utf-8"?>' . "\n" .
               '<?qbxml version="' . $this->xmlEscape($qbxmlVersion) . '"?>' . "\n" .
               '<QBXML>' . "\n" .
               '  <QBXMLMsgsRq onError="stopOnError">' . "\n" .
               $innerXml . "\n" .
               '  </QBXMLMsgsRq>' . "\n" .
               '</QBXML>';
    }

    private function xmlEscape($s)
    {
        return htmlspecialchars((string)$s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
