<?php
namespace QBWCServer\applications;

use QBWCServer\base\AbstractQBWCApplication;
use QBWCServer\response\ReceiveResponseXML;
use QBWCServer\response\SendRequestXML;

class AddInvoicesApp extends AbstractQBWCApplication
{
    private $requests = [];
    private $currentStep = 0;

    public function __construct($config = [])
    {
        parent::__construct($config);

        // Define the invoice and required items
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

        // Step 1: Check if each item exists
        foreach ($this->invoiceData['items'] as $item) {
            $this->requests[] = $this->buildItemQueryXML($item['name']);
        }

        // Step 2: Add items (for missing ones)
        // Step 3: Add invoice (added dynamically in receiveResponseXML)
    }

    public function sendRequestXML($object)
    {
        if (!isset($this->requests[$this->currentStep])) {
            // No more pending requests
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

        // Detect which step we're in
        if (isset($response->QBXMLMsgsRs->ItemQueryRs)) {
            $this->handleItemQueryResponse($response);
        } elseif (isset($response->QBXMLMsgsRs->ItemNonInventoryAddRs)) {
            $this->handleItemAddResponse($response);
        } elseif (isset($response->QBXMLMsgsRs->InvoiceAddRs)) {
            $this->log_this("Invoice Added Successfully!");
        }

        $this->currentStep++;
        if ($this->currentStep < count($this->requests)) {
            return new ReceiveResponseXML(50); // 50% done
        }

        return new ReceiveResponseXML(100); // Done
    }

    private function handleItemQueryResponse($response)
    {
        $rs = $response->QBXMLMsgsRs->ItemQueryRs;
        $statusCode = (string) $rs['statusCode'];
        $itemName = (string) $rs->ItemNonInventoryRet->Name ?? '';

        if ($statusCode == "0" && $itemName !== '') {
            // Item exists
            $this->log_this("Item found: {$itemName}");
        } else {
            // Item missing — queue creation
            $missingItemName = $this->invoiceData['items'][$this->currentStep]['name'];
            $this->log_this("Item not found: {$missingItemName}, creating NonInventory item...");
            array_splice($this->requests, $this->currentStep + 1, 0, [$this->buildItemAddXML($missingItemName)]);
        }

        // After all item checks, queue invoice add (only once)
        if ($this->currentStep + 1 == count($this->invoiceData['items'])) {
            $this->requests[] = $this->buildInvoiceAddXML();
        }
    }

    private function handleItemAddResponse($response)
    {
        $rs = $response->QBXMLMsgsRs->ItemNonInventoryAddRs;
        $name = (string) $rs->ItemNonInventoryRet->Name;
        $this->log_this("NonInventory Item Added: {$name}");
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
    <ItemNonInventoryAddRq requestID="1">
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