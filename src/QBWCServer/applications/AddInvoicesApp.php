<?php
namespace QBWCServer\applications;

use QBWCServer\base\AbstractQBWCApplication;
use QBWCServer\response\ReceiveResponseXML;
use QBWCServer\response\SendRequestXML;

class AddInvoicesApp extends AbstractQBWCApplication
{
    // Step tracking - use session for persistence
    private $itemsToAdd = ['Consulting Services', 'Hosting'];
    
    public function __construct($config = [])
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        parent::__construct($config);
    }
    
    public function getStep()
    {
        return $_SESSION['addInvoice_step'] ?? 'check_items';
    }
    
    public function setStep($step)
    {
        $_SESSION['addInvoice_step'] = $step;
    }
    
    public function getCurrentItemIndex()
    {
        return $_SESSION['addInvoice_currentItemIndex'] ?? 0;
    }
    
    public function setCurrentItemIndex($index)
    {
        $_SESSION['addInvoice_currentItemIndex'] = $index;
    }
    
    public function resetState()
    {
        unset($_SESSION['addInvoice_step']);
        unset($_SESSION['addInvoice_currentItemIndex']);
    }

    /**
     * Build and return QBXML to send to QuickBooks
     */
    public function sendRequestXML($object)
    {
        $qbxmlVersion = $this->_config['qbxmlVersion'] ?? '16.0';
        $step = $this->getStep();
        $this->log_this("Current Step: {$step}");

        switch ($step) {
            case 'check_items':
                return $this->buildItemQueryXML($qbxmlVersion);
            case 'add_item':
                return $this->buildItemAddXML($qbxmlVersion);
            case 'add_invoice':
                return $this->buildInvoiceAddXML($qbxmlVersion);
            default:
                $this->log_this("Unknown step reached.");
                return new SendRequestXML('');
        }
    }

    /**
     * Handle the response from QuickBooks
     */
    public function receiveResponseXML($object)
    {
        $responseXML = $object->response;
        $step = $this->getStep();
        $currentIndex = $this->getCurrentItemIndex();
        
        $this->log_this("Response received for step: " . $step);
        $this->log_this("Raw response XML:\n" . $responseXML);

        $response = simplexml_load_string($responseXML);
        if (!$response) {
            $this->log_this("ERROR: Failed to parse XML response.");
            return new ReceiveResponseXML(100); // Stop to avoid infinite loop
        }

        switch ($step) {
            case 'check_items':
                $itemFound = false;

                // Check for multiple item types
                if (isset($response->QBXMLMsgsRs->ItemQueryRs->ItemNonInventoryRet)) {
                    $itemFound = true;
                } elseif (isset($response->QBXMLMsgsRs->ItemQueryRs->ItemInventoryRet)) {
                    $itemFound = true;
                } elseif (isset($response->QBXMLMsgsRs->ItemQueryRs->ItemServiceRet)) {
                    $itemFound = true;
                }

                if (!$itemFound) {
                    $this->log_this("Item missing: " . $this->itemsToAdd[$currentIndex] . " — will add it.");
                    $this->setStep('add_item');
                    return new ReceiveResponseXML(0); // ask QBWC to call sendRequestXML again
                } else {
                    $this->log_this("Item exists: " . $this->itemsToAdd[$currentIndex]);
                    $currentIndex++;
                    $this->setCurrentItemIndex($currentIndex);
                    if ($currentIndex < count($this->itemsToAdd)) {
                        $this->setStep('check_items');
                    } else {
                        $this->setStep('add_invoice');
                    }
                    return new ReceiveResponseXML(0);
                }

            case 'add_item':
                $this->log_this("Item added: " . $this->itemsToAdd[$currentIndex]);
                $currentIndex++;
                $this->setCurrentItemIndex($currentIndex);
                if ($currentIndex < count($this->itemsToAdd)) {
                    $this->setStep('check_items');
                } else {
                    $this->setStep('add_invoice');
                }
                return new ReceiveResponseXML(0);

            case 'add_invoice':
                $this->log_this("Invoice add response complete.");
                $this->resetState(); // Clean up session state
                return new ReceiveResponseXML(100); // 100 = done

            default:
                $this->log_this("Unknown step reached: " . $step);
                $this->resetState();
                return new ReceiveResponseXML(100);
        }
    }



    /**
     * Helper: Build ItemQueryRq XML
     */
    private function buildItemQueryXML($qbxmlVersion)
    {
        $currentIndex = $this->getCurrentItemIndex();
        $currentItem = $this->itemsToAdd[$currentIndex];
        $this->log_this("Checking if item exists: {$currentItem}");

        $xml = <<<XML
<?xml version="1.0" encoding="utf-8"?>
<?qbxml version="{$qbxmlVersion}"?>
<QBXML>
  <QBXMLMsgsRq onError="stopOnError">
    <ItemQueryRq requestID="{$currentIndex}">
      <FullName>{$currentItem}</FullName>
    </ItemQueryRq>
  </QBXMLMsgsRq>
</QBXML>
XML;
        return new SendRequestXML($xml);
    }

    /**
     * Helper: Build ItemNonInventoryAddRq XML
     */
    private function buildItemAddXML($qbxmlVersion)
    {
        $currentIndex = $this->getCurrentItemIndex();
        $currentItem = $this->itemsToAdd[$currentIndex];
        $this->log_this("Adding NonInventory item: {$currentItem}");

        $xml = <<<XML
<?xml version="1.0" encoding="utf-8"?>
<?qbxml version="{$qbxmlVersion}"?>
<QBXML>
  <QBXMLMsgsRq onError="stopOnError">
    <ItemNonInventoryAddRq requestID="{$currentIndex}">
      <ItemNonInventoryAdd>
        <Name>{$currentItem}</Name>
        <SalesOrPurchase>
          <Desc>{$currentItem}</Desc>
          <Price>0.00</Price>
          <AccountRef>
            <FullName>Sales</FullName>
          </AccountRef>
        </SalesOrPurchase>
      </ItemNonInventoryAdd>
    </ItemNonInventoryAddRq>
  </QBXMLMsgsRq>
</QBXML>
XML;
        return new SendRequestXML($xml);
    }

    /**
     * Helper: Build InvoiceAddRq XML
     */
    private function buildInvoiceAddXML($qbxmlVersion)
    {
        $xml = <<<XML
<?xml version="1.0" encoding="utf-8"?>
<?qbxml version="{$qbxmlVersion}"?>
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
</QBXML>
XML;
        return new SendRequestXML($xml);
    }
}
