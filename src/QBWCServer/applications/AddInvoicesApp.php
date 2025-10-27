<?php
namespace QBWCServer\applications;

use QBWCServer\base\AbstractQBWCApplication;
use QBWCServer\response\ReceiveResponseXML;
use QBWCServer\response\SendRequestXML;

class AddInvoicesApp extends AbstractQBWCApplication
{
    private $requests = [];
    private $currentStep = 0;
    private $missingItems = [];

    public function __construct($config = [])
    {
        parent::__construct($config);

        $this->invoiceData = [
            'customerName' => 'John Doe',
            'refNumber'    => 'INV-1001',
            'txnDate'      => '2025-10-27',
            'dueDate'      => '2025-11-26',
            'items' => [
                ['name' => 'Consulting Services', 'desc' => 'Consulting Fee for October', 'qty' => 1, 'rate' => 500.00],
                ['name' => 'Hosting', 'desc' => 'Website Hosting (1 Month)', 'qty' => 1, 'rate' => 100.00],
            ]
        ];

        // Step 1: Query each item
        foreach ($this->invoiceData['items'] as $item) {
            $this->requests[] = $this->buildItemQueryXML($item['name']);
        }
    }

    public function sendRequestXML($object)
    {
        if (!isset($this->requests[$this->currentStep])) {
            return new SendRequestXML('');
        }

        $xml = $this->requests[$this->currentStep];
        $this->log_this("Sending XML (Step {$this->currentStep}): " . $xml);
        return new SendRequestXML($xml);
    }

    public function receiveResponseXML($object)
    {
        $response = simplexml_load_string($object->response);
        $this->log_this("Received Response: " . $object->response);

        // Detect response type
        if (isset($response->QBXMLMsgsRs->ItemQueryRs)) {
            $this->handleItemQueryResponse($response);
        } elseif (isset($response->QBXMLMsgsRs->ItemNonInventoryAddRs)) {
            $this->handleItemAddResponse($response);
        } elseif (isset($response->QBXMLMsgsRs->InvoiceAddRs)) {
            $this->log_this("✅ Invoice Added Successfully");
        }

        $this->currentStep++;

        // When done with all requests, tell QBWC we’re finished
        $done = $this->currentStep >= count($this->requests);
        return new ReceiveResponseXML($done ? 100 : 50);
    }

    private function handleItemQueryResponse($response)
    {
        $rs = $response->QBXMLMsgsRs->ItemQueryRs;
        $statusCode = (string) $rs['statusCode'];
        $itemRet = $rs->ItemNonInventoryRet ?? null;
        $itemName = $itemRet ? (string) $itemRet->Name : '';

        $currentItem = $this->invoiceData['items'][$this->currentStep]['name'];

        if ($statusCode !== "0" || $itemName === '') {
            // Item missing
            $this->log_this("❌ Item not found: {$currentItem}, queueing creation...");
            $this->missingItems[] = $currentItem;
            array_splice($this->requests, $this->currentStep + 1, 0, [$this->buildItemAddXML($currentItem)]);
        } else {
            $this->log_this("✅ Item exists: {$itemName}");
        }

        // After last item check, queue invoice if not already added
        if ($this->currentStep + 1 == count($this->invoiceData['items'])) {
            $this->requests[] = $this->buildInvoiceAddXML();
            $this->log_this("🧾 Queued InvoiceAddRq after item checks.");
        }
    }

    private function handleItemAddResponse($response)
    {
        $rs = $response->QBXMLMsgsRs->ItemNonInventoryAddRs;
        $name = (string) $rs->ItemNonInventoryRet->Name;
        $statusCode = (string) $rs['statusCode'];
        if ($statusCode === "0") {
            $this->log_this("✅ NonInventory Item Added: {$name}");
        } else {
            $this->log_this("⚠️ Failed to add NonInventory item: " . $statusCode);
        }
    }

    private function buildItemQueryXML($itemName)
    {
        return '<?xml version="1.0" encoding="utf-8"?>
<?qbxml version="16.0"?>
<QBXML>
  <QBXMLMsgsRq onError="stopOnError">
    <ItemQueryRq requestID="1">
      <FullName>' . htmlspecialchars($itemName) . '</FullName>
    </ItemQueryRq>
  </QBXMLMsgsRq>
</QBXML>';
    }

    private function buildItemAddXML($itemName)
    {
        return '<?xml version="1.0" encoding="utf-8"?>
<?qbxml version="16.0"?>
<QBXML>
  <QBXMLMsgsRq onError="stopOnError">
    <ItemNonInventoryAddRq requestID="2">
      <ItemNonInventoryAdd>
        <Name>' . htmlspecialchars($itemName) . '</Name>
        <SalesOrPurchase>
          <Desc>' . htmlspecialchars($itemName) . ' Description</Desc>
          <Price>0.00</Price>
          <AccountRef>
            <FullName>Sales</FullName>
          </AccountRef>
        </SalesOrPurchase>
      </ItemNonInventoryAdd>
    </ItemNonInventoryAddRq>
  </QBXMLMsgsRq>
</QBXML>';
    }

    private function buildInvoiceAddXML()
    {
        $data = $this->invoiceData;
        $lines = '';
        foreach ($data['items'] as $item) {
            $lines .= '
        <InvoiceLineAdd>
          <ItemRef><FullName>' . htmlspecialchars($item['name']) . '</FullName></ItemRef>
          <Desc>' . htmlspecialchars($item['desc']) . '</Desc>
          <Quantity>' . $item['qty'] . '</Quantity>
          <Rate>' . $item['rate'] . '</Rate>
        </InvoiceLineAdd>';
        }

        return '<?xml version="1.0" encoding="utf-8"?>
<?qbxml version="16.0"?>
<QBXML>
  <QBXMLMsgsRq onError="stopOnError">
    <InvoiceAddRq requestID="100">
      <InvoiceAdd>
        <CustomerRef><FullName>' . htmlspecialchars($data['customerName']) . '</FullName></CustomerRef>
        <TxnDate>' . $data['txnDate'] . '</TxnDate>
        <RefNumber>' . $data['refNumber'] . '</RefNumber>
        <DueDate>' . $data['dueDate'] . '</DueDate>
        ' . $lines . '
      </InvoiceAdd>
    </InvoiceAddRq>
  </QBXMLMsgsRq>
</QBXML>';
    }
}
