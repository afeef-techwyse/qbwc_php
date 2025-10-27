<?php
namespace QBWCServer\applications;

use QBWCServer\base\AbstractQBWCApplication;
use QBWCServer\response\ReceiveResponseXML;
use QBWCServer\response\SendRequestXML;

class AddInvoicesApp extends AbstractQBWCApplication
{
    // Track step flow
    private $step = 'check_items';
    private $itemsToAdd = ['Consulting Services', 'Hosting'];
    private $currentItemIndex = 0;

    public function sendRequestXML($object)
    {
        $qbxmlVersion = $this->_config['qbxmlVersion'] ?? '16.0';
        $this->log_this("Step: " . $this->step);

        // STEP 1: Check if items exist in QuickBooks
        if ($this->step === 'check_items') {
            $currentItem = $this->itemsToAdd[$this->currentItemIndex];
            $this->log_this("Checking item: " . $currentItem);

            $xml = '<?xml version="1.0" encoding="utf-8"?>
<?qbxml version="' . $qbxmlVersion . '"?>
<QBXML>
  <QBXMLMsgsRq onError="stopOnError">
    <ItemQueryRq requestID="' . $this->currentItemIndex . '">
      <FullName>' . htmlspecialchars($currentItem) . '</FullName>
    </ItemQueryRq>
  </QBXMLMsgsRq>
</QBXML>';

            return new SendRequestXML($xml);
        }

        // STEP 2: Add NonInventory item if missing
        elseif ($this->step === 'add_item') {
            $currentItem = $this->itemsToAdd[$this->currentItemIndex];
            $this->log_this("Adding NonInventory item: " . $currentItem);

            $xml = '<?xml version="1.0" encoding="utf-8"?>
<?qbxml version="' . $qbxmlVersion . '"?>
<QBXML>
  <QBXMLMsgsRq onError="stopOnError">
    <ItemNonInventoryAddRq requestID="' . $this->currentItemIndex . '">
      <ItemNonInventoryAdd>
        <Name>' . htmlspecialchars($currentItem) . '</Name>
        <SalesOrPurchase>
          <Desc>' . htmlspecialchars($currentItem) . '</Desc>
          <Price>0.00</Price>
          <AccountRef>
            <FullName>Sales</FullName>
          </AccountRef>
        </SalesOrPurchase>
      </ItemNonInventoryAdd>
    </ItemNonInventoryAddRq>
  </QBXMLMsgsRq>
</QBXML>';

            return new SendRequestXML($xml);
        }

        // STEP 3: Add Invoice (once all items exist)
        elseif ($this->step === 'add_invoice') {
            $xml = '<?xml version="1.0" encoding="utf-8"?>
<?qbxml version="16.0"?>
<QBXML>
  <QBXMLMsgsRq onError="stopOnError">
    <InvoiceAddRq requestID="1">
      <InvoiceAdd>
        <CustomerRef>
          <FullName>John Doe</FullName>
        </CustomerRef>

        <TxnDate>2025-10-27</TxnDate>
        <RefNumber>INV-1001</RefNumber>

        <BillAddress>
          <Addr1>John Doe</Addr1>
          <Addr2>123 Main Street</Addr2>
          <City>New York</City>
          <State>NY</State>
          <PostalCode>10001</PostalCode>
          <Country>USA</Country>
        </BillAddress>

        <TermsRef>
          <FullName>Net 30</FullName>
        </TermsRef>

        <DueDate>2025-11-26</DueDate>

        <InvoiceLineAdd>
          <ItemRef>
            <FullName>Consulting Services</FullName>
          </ItemRef>
          <Desc>Consulting Fee for October</Desc>
          <Quantity>1</Quantity>
          <Rate>500.00</Rate>
        </InvoiceLineAdd>

        <InvoiceLineAdd>
          <ItemRef>
            <FullName>Hosting</FullName>
          </ItemRef>
          <Desc>Website Hosting (1 Month)</Desc>
          <Quantity>1</Quantity>
          <Rate>100.00</Rate>
        </InvoiceLineAdd>

        <SalesTaxCodeRef>
          <FullName>Tax</FullName>
        </SalesTaxCodeRef>

        <Other>Generated via QBWC</Other>
      </InvoiceAdd>
    </InvoiceAddRq>
  </QBXMLMsgsRq>
</QBXML>';

            return new SendRequestXML($xml);
        }

        // Default fallback (should not happen)
        $this->log_this("Unknown step reached.");
        return new SendRequestXML('');
    }

    public function receiveResponseXML($object)
    {
        $response = simplexml_load_string($object->response);
        $this->log_this("Response received for step: " . $this->step);
        $this->log_this($response->asXML());

        // Process responses step by step
        if ($this->step === 'check_items') {
            $itemFound = isset($response->QBXMLMsgsRs->ItemQueryRs->ItemNonInventoryRet);

            if (!$itemFound) {
                // Item doesn't exist — create it
                $this->step = 'add_item';
                return new ReceiveResponseXML(0); // Ask QBWC to call sendRequestXML again
            } else {
                // Move to next item or invoice
                $this->currentItemIndex++;
                if ($this->currentItemIndex < count($this->itemsToAdd)) {
                    $this->step = 'check_items';
                } else {
                    $this->step = 'add_invoice';
                }
                return new ReceiveResponseXML(0);
            }
        }

        elseif ($this->step === 'add_item') {
            // After item creation, check next or move on
            $this->currentItemIndex++;
            if ($this->currentItemIndex < count($this->itemsToAdd)) {
                $this->step = 'check_items';
            } else {
                $this->step = 'add_invoice';
            }
            return new ReceiveResponseXML(0);
        }

        elseif ($this->step === 'add_invoice') {
            $this->log_this("Invoice add response complete.");
            return new ReceiveResponseXML(100); // 100 means done
        }

        return new ReceiveResponseXML(100);
    }
}