<?php
namespace QBWCServer\applications;

use QBWCServer\base\AbstractQBWCApplication;
use QBWCServer\response\ReceiveResponseXML;
use QBWCServer\response\SendRequestXML;
use PDO;

/**
 * Shopify → QuickBooks integration
 * - Fetch pending order from DB
 * - Check if customer exists → add if missing
 * - Add invoice
 * - Mark order complete
 */
class AddCustomerInvoiceApp extends AbstractQBWCApplication
{
    private $pdo;
    private $currentOrder;
    private $stage;
    private $customerName;

    public function __construct()
    {
        $this->pdo = new PDO(
            "mysql:host=sql5.freesqldatabase.com;dbname=sql5802997",
            "sql5802997",
            "8jhmVbi8lN"
        );
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    public function sendRequestXML($ticket, $strHCPResponse, $strCompanyFileName, $qbXMLCountry, $qbXMLMajorVers, $qbXMLMinorVers)
    {
        // Get next order
        $stmt = $this->pdo->query("SELECT * FROM orders_queue WHERE status NOT IN ('invoice_done') LIMIT 1");
        $this->currentOrder = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$this->currentOrder) {
            return new SendRequestXML(''); // no orders
        }

        $order = json_decode($this->currentOrder['payload'], true);
        $this->customerName = trim($order['customer']['first_name'] . ' ' . $order['customer']['last_name']);
        $this->stage = $this->currentOrder['status'];

        if ($this->stage == 'pending' || !$this->stage) {
            $this->updateStatus('query_customer');
            return new SendRequestXML($this->buildCustomerQueryXML());
        } elseif ($this->stage == 'add_customer') {
            $this->updateStatus('add_invoice');
            return new SendRequestXML($this->buildCustomerAddXML($order));
        } elseif ($this->stage == 'add_invoice') {
            $this->updateStatus('invoice_done');
            return new SendRequestXML($this->buildInvoiceAddXML($order));
        }

        return new SendRequestXML('');
    }

    public function receiveResponseXML($ticket, $response, $hresult, $message)
    {
        $responseXml = simplexml_load_string($response);
        $responseName = $responseXml->QBXMLMsgsRs->children()->getName();

        if ($responseName == 'CustomerQueryRs') {
            if (isset($responseXml->QBXMLMsgsRs->CustomerQueryRs->CustomerRet)) {
                // customer exists
                $this->updateStatus('add_invoice');
                return new ReceiveResponseXML(50);
            } else {
                // customer missing
                $this->updateStatus('add_customer');
                return new ReceiveResponseXML(50);
            }
        }

        if ($responseName == 'CustomerAddRs') {
            $this->updateStatus('add_invoice');
            return new ReceiveResponseXML(50);
        }

        if ($responseName == 'InvoiceAddRs') {
            $this->updateStatus('invoice_done');
            return new ReceiveResponseXML(100);
        }

        return new ReceiveResponseXML(100);
    }

    private function updateStatus($status)
    {
        $stmt = $this->pdo->prepare("UPDATE orders_queue SET status = ? WHERE id = ?");
        $stmt->execute([$status, $this->currentOrder['id']]);
    }

    // 🧾 Build CustomerQueryRq
    private function buildCustomerQueryXML()
    {
        return '<?xml version="1.0" encoding="utf-8"?>
        <?qbxml version="12.0"?>
        <QBXML>
          <QBXMLMsgsRq onError="stopOnError">
            <CustomerQueryRq>
              <FullName>' . htmlspecialchars($this->customerName) . '</FullName>
            </CustomerQueryRq>
          </QBXMLMsgsRq>
        </QBXML>';
    }

    // 🧾 Build CustomerAddRq
    private function buildCustomerAddXML($order)
    {
        $c = $order['customer'];
        return '<?xml version="1.0" encoding="utf-8"?>
        <?qbxml version="12.0"?>
        <QBXML>
          <QBXMLMsgsRq onError="stopOnError">
            <CustomerAddRq>
              <CustomerAdd>
                <Name>' . htmlspecialchars($this->customerName) . '</Name>
                <CompanyName>' . htmlspecialchars($c['default_address']['company'] ?? '') . '</CompanyName>
                <FirstName>' . htmlspecialchars($c['first_name']) . '</FirstName>
                <LastName>' . htmlspecialchars($c['last_name']) . '</LastName>
                <Email>' . htmlspecialchars($c['email']) . '</Email>
              </CustomerAdd>
            </CustomerAddRq>
          </QBXMLMsgsRq>
        </QBXML>';
    }

    // 🧾 Build InvoiceAddRq
    private function buildInvoiceAddXML($order)
    {
        $c = $order['customer'];
        $itemsXML = '';
        foreach ($order['line_items'] as $item) {
            $itemsXML .= '
                <InvoiceLineAdd>
                    <ItemRef>
                        <FullName>' . htmlspecialchars($item['name']) . '</FullName>
                    </ItemRef>
                    <Quantity>' . htmlspecialchars($item['quantity']) . '</Quantity>
                    <Amount>' . htmlspecialchars($item['price']) . '</Amount>
                </InvoiceLineAdd>';
        }

        return '<?xml version="1.0" encoding="utf-8"?>
        <?qbxml version="12.0"?>
        <QBXML>
          <QBXMLMsgsRq onError="stopOnError">
            <InvoiceAddRq>
              <InvoiceAdd>
                <CustomerRef>
                  <FullName>' . htmlspecialchars($this->customerName) . '</FullName>
                </CustomerRef>
                <TxnDate>' . date('Y-m-d') . '</TxnDate>
                <RefNumber>' . htmlspecialchars($order['name']) . '</RefNumber>
                <InvoiceLineAdd>
                    ' . $itemsXML . '
                </InvoiceLineAdd>
              </InvoiceAdd>
            </InvoiceAddRq>
          </QBXMLMsgsRq>
        </QBXML>';
    }
}