<?php
namespace QBWCServer\applications;

use QBWCServer\base\AbstractQBWCApplication;
use QBWCServer\response\ReceiveResponseXML;
use QBWCServer\response\SendRequestXML;

class AddCustomerInvoiceApp extends AbstractQBWCApplication
{
    private const LOG_TAG = 'AddCustomerInvoiceApp';
    private const STATE_FILENAME = 'qbwc_app_state.json';
    private const LOG_FILENAME = 'app_debug.log';

    // Static orders array for testing
    private $orders = [
        [
            'id' => 1001,
            'order_number' => 'S1001',
            'customer' => [
                'first_name' => 'John',
                'last_name' => 'Doe',
                'email' => 'john.doe@example.com',
                'phone' => '123-456-7890',
                'default_address' => [
                    'company' => 'John Co.',
                    'address1' => '123 Test Street',
                    'city' => 'Testville',
                    'province' => 'CA',
                    'zip' => '90001',
                    'country' => 'United States'
                ]
            ],
            'line_items' => [
                ['title' => 'ACCSRC', 'quantity' => 2],
                ['title' => 'NONSTOCK', 'quantity' => 1]
            ]
        ],
        [
            'id' => 1002,
            'order_number' => 'S1002',
            'customer' => [
                'first_name' => 'Jane',
                'last_name' => 'Smith',
                'email' => 'jane.smith@example.com',
                'phone' => '555-777-8888',
                'default_address' => [
                    'company' => '',
                    'address1' => '456 Sample Ave',
                    'city' => 'Sampletown',
                    'province' => 'NY',
                    'zip' => '10001',
                    'country' => 'United States'
                ]
            ],
            'line_items' => [
                ['title' => 'Service X', 'quantity' => 5]
            ]
        ]
    ];

    private $currentOrderIndex = 0;
    private $stage = 'query_customer';
    private $customerName;

    // New for item handling
    private $itemIndex = 0; // current item being checked/added in this order
    private $itemsToCheck = []; // list of item titles for current order

    // ---------------------- State Persistence ----------------------
    private function loadState() {
        $path = $this->getStatePath();
        if (is_readable($path)) {
            $raw = @file_get_contents($path);
            $state = json_decode($raw ?? '', true);
            if (is_array($state)) {
                $this->currentOrderIndex = $state['currentOrderIndex'] ?? 0;
                $this->stage = $state['stage'] ?? 'query_customer';
                $this->itemIndex = $state['itemIndex'] ?? 0;
                $this->itemsToCheck = $state['itemsToCheck'] ?? [];
            }
        }
    }

    private function saveState() {
        $state = [
            'currentOrderIndex' => $this->currentOrderIndex,
            'stage' => $this->stage,
            'itemIndex' => $this->itemIndex,
            'itemsToCheck' => $this->itemsToCheck
        ];
        $path = $this->getStatePath();
        $encoded = json_encode($state);
        if (@file_put_contents($path, $encoded) === false) {
            $this->log('Failed to write state file: ' . $path);
        }
    }

    private function resetState() {
        $this->currentOrderIndex = 0;
        $this->stage = 'query_customer';
        $this->itemIndex = 0;
        $this->itemsToCheck = [];
        @unlink($this->getStatePath());
    }

    // ---------------------- Logging ----------------------
    private function log($msg) {
        $ts = date('Y-m-d H:i:s');
        $line = "[$ts] " . self::LOG_TAG . ": $msg";
        // If env var LOG_TO_STDERR is set (e.g., on Railway), log to stderr so platform logs capture it
        $toStderr = getenv('LOG_TO_STDERR');
        if ($toStderr && $toStderr !== '0' && strtolower($toStderr) !== 'false') {
            error_log($line);
            return;
        }
        // Otherwise log to file in repo
        $path = $this->getLogPath();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        error_log($line . "\n", 3, $path);
    }

    // ---------------------- QBWC Methods ----------------------
    public function sendRequestXML($object)
    {
        $this->loadState();

        // Loop to avoid recursion and progress stages until we have a request to send or nothing to do
        while (true) {
            if ($this->currentOrderIndex >= count($this->orders)) {
                $this->log('All static orders processed. Nothing to send.');
                $this->resetState();
                return new SendRequestXML('');
            }

            $order = $this->orders[$this->currentOrderIndex];
            $this->customerName = trim(($order['customer']['first_name'] ?? '') . ' ' . ($order['customer']['last_name'] ?? ''));
            $qbxmlVersion = $object->qbXMLMajorVers . '.' . $object->qbXMLMinorVers;

            $this->log("Stage: {$this->stage} -- Order: {$order['order_number']} (Customer: {$this->customerName})");

            if ($this->stage === 'query_customer') {
                $xml = '<?xml version="1.0" encoding="utf-8"?>
<?qbxml version="' . $qbxmlVersion . '"?>
<QBXML>
  <QBXMLMsgsRq onError="stopOnError">
    <CustomerQueryRq requestID="' . $this->generateGUID() . '">
      <FullName>' . htmlspecialchars($this->customerName, ENT_XML1, 'UTF-8') . '</FullName>
    </CustomerQueryRq>
  </QBXMLMsgsRq>
</QBXML>';
                $this->log("Sending CustomerQueryRq XML:\n" . $this->safeXmlForLog($xml));
                $this->saveState();
                return new SendRequestXML($xml);
            }

        if ($this->stage === 'add_customer') {
                $cust = $order['customer'];
                $addr = $cust['default_address'] ?? [];
                $xml = '<?xml version="1.0" encoding="utf-8"?>
<?qbxml version="' . $qbxmlVersion . '"?>
<QBXML>
  <QBXMLMsgsRq onError="stopOnError">
    <CustomerAddRq requestID="' . $this->generateGUID() . '">
      <CustomerAdd>
        <Name>' . htmlspecialchars($this->customerName, ENT_XML1, 'UTF-8') . '</Name>
        <CompanyName>' . htmlspecialchars($addr['company'] ?? '', ENT_XML1, 'UTF-8') . '</CompanyName>
        <FirstName>' . htmlspecialchars($cust['first_name'] ?? '', ENT_XML1, 'UTF-8') . '</FirstName>
        <LastName>' . htmlspecialchars($cust['last_name'] ?? '', ENT_XML1, 'UTF-8') . '</LastName>
        <BillAddress>
          <Addr1>' . htmlspecialchars($addr['address1'] ?? '', ENT_XML1, 'UTF-8') . '</Addr1>
          <City>' . htmlspecialchars($addr['city'] ?? '', ENT_XML1, 'UTF-8') . '</City>
          <State>' . htmlspecialchars($addr['province'] ?? '', ENT_XML1, 'UTF-8') . '</State>
          <PostalCode>' . htmlspecialchars($addr['zip'] ?? '', ENT_XML1, 'UTF-8') . '</PostalCode>
          <Country>' . htmlspecialchars($addr['country'] ?? '', ENT_XML1, 'UTF-8') . '</Country>
        </BillAddress>
        <Phone>' . htmlspecialchars($cust['phone'] ?? '', ENT_XML1, 'UTF-8') . '</Phone>
        <Email>' . htmlspecialchars($cust['email'] ?? '', ENT_XML1, 'UTF-8') . '</Email>
     </CustomerAdd>
    </CustomerAddRq>
  </QBXMLMsgsRq>
</QBXML>';
                $this->log("Sending CustomerAddRq XML:\n" . $this->safeXmlForLog($xml));
                $this->saveState();
                return new SendRequestXML($xml);
            }

        // New stage: Prepare to check items in order
        if ($this->stage === 'prepare_item_checks') {
                $this->itemsToCheck = array_values(array_map(function ($item) {
                    return $item['title'] ?? '';
                }, $order['line_items'] ?? []));
                $this->itemIndex = 0;
                $this->stage = 'query_item';
                $this->saveState();
                $this->log('Prepared item list for item checking.');
                // continue loop to progress to query_item
                continue;
            }

        // Query each item for existence in QuickBooks
        if ($this->stage === 'query_item') {
                if ($this->itemIndex >= count($this->itemsToCheck)) {
                    $this->stage = 'add_invoice';
                    $this->saveState();
                    // continue to emit invoice
                    continue;
                }
                $itemName = $this->itemsToCheck[$this->itemIndex];
                $xml = '<?xml version="1.0" encoding="utf-8"?>
<?qbxml version="' . $qbxmlVersion . '"?>
<QBXML>
 <QBXMLMsgsRq onError="stopOnError">
  <ItemQueryRq requestID="' . $this->generateGUID() . '">
   <FullName>' . htmlspecialchars($itemName, ENT_XML1, 'UTF-8') . '</FullName>
  </ItemQueryRq>
 </QBXMLMsgsRq>
</QBXML>';
                $this->log("Sending ItemQueryRq XML for item: $itemName\n" . $this->safeXmlForLog($xml));
                $this->saveState();
                return new SendRequestXML($xml);
            }

        // Add missing item
        if ($this->stage === 'add_item') {
                $itemName = $this->itemsToCheck[$this->itemIndex];
                $xml = '<?xml version="1.0" encoding="utf-8"?>
<?qbxml version="' . $qbxmlVersion . '"?>
<QBXML>
 <QBXMLMsgsRq onError="stopOnError">
  <ItemNonInventoryAddRq requestID="' . $this->generateGUID() . '">
   <ItemNonInventoryAdd>
    <Name>' . htmlspecialchars($itemName, ENT_XML1, 'UTF-8') . '</Name>
    <SalesPrice>0.00</SalesPrice>
    <IncomeAccountRef>
     <FullName>Sales</FullName>
    </IncomeAccountRef>
   </ItemNonInventoryAdd>
  </ItemNonInventoryAddRq>
 </QBXMLMsgsRq>
</QBXML>';
                $this->log("Sending ItemNonInventoryAddRq XML for item: $itemName\n" . $this->safeXmlForLog($xml));
                $this->saveState();
                return new SendRequestXML($xml);
            }

        // Add invoice stage (same as before)
        if ($this->stage === 'add_invoice') {
                $refNumber = 'S-' . htmlspecialchars((string)($order['id'] ?? $order['order_number'] ?? uniqid('ORD-')), ENT_XML1, 'UTF-8');
                $xml = '<?xml version="1.0" encoding="utf-8"?>
<?qbxml version="' . $qbxmlVersion . '"?>
<QBXML>
  <QBXMLMsgsRq onError="stopOnError">
    <InvoiceAddRq requestID="' . $this->generateGUID() . '">
      <InvoiceAdd>
        <CustomerRef><FullName>' . htmlspecialchars($this->customerName, ENT_XML1, 'UTF-8') . '</FullName></CustomerRef>
        <RefNumber>' . $refNumber . '</RefNumber>
        <Memo>Static Test Order #' . htmlspecialchars($order['order_number'] ?? '', ENT_XML1, 'UTF-8') . '</Memo>';
                foreach (($order['line_items'] ?? []) as $item) {
                    $title = htmlspecialchars($item['title'] ?? '', ENT_XML1, 'UTF-8');
                    $qty = max(1, (int)($item['quantity'] ?? 1));
                    $xml .= '
        <InvoiceLineAdd>
          <ItemRef><FullName>' . $title . '</FullName></ItemRef>
          <Quantity>' . $qty . '</Quantity>
        </InvoiceLineAdd>';
                }
                $xml .= '
      </InvoiceAdd>
    </InvoiceAddRq>
  </QBXMLMsgsRq>
</QBXML>';
                $this->log("Sending InvoiceAddRq XML:\n" . $this->safeXmlForLog($xml));
                $this->saveState();
                return new SendRequestXML($xml);
            }

            // Fallback: unknown stage -> do nothing but log and return empty
            $this->log('Unexpected stage in sendRequestXML: ' . $this->stage);
            $this->saveState();
            return new SendRequestXML('');
        }

        // Fallback
        $this->log("Unexpected stage in sendRequestXML: {$this->stage}");
        $this->saveState();
        return new SendRequestXML('');
    }

    public function receiveResponseXML($object)
    {
        $this->loadState();
        $this->log("Received XML response:\n" . $this->safeXmlForLog((string)($object->response ?? '')));

        $response = @simplexml_load_string((string)($object->response ?? ''));
        if ($response === false) {
            $this->log('Failed to parse response XML.');
            // Return 0 to indicate we processed nothing; could implement retry/backoff here.
            return new ReceiveResponseXML(0);
        }
        $this->log("Current stage in receiveResponseXML: {$this->stage}");

        if ($this->stage === 'query_customer') {
            $rs = $response->QBXMLMsgsRs->CustomerQueryRs ?? null;
            $statusCode = (string)($rs['statusCode'] ?? '');
            $statusSeverity = (string)($rs['statusSeverity'] ?? '');
            $statusMessage = (string)($rs['statusMessage'] ?? '');
            if ($statusCode !== '' && $statusCode !== '0') {
                $this->log("CustomerQueryRs error: code=$statusCode severity=$statusSeverity message=$statusMessage");
            }
            if (isset($rs->CustomerRet)) {
                $this->log('Customer EXISTS in QuickBooks --> Skipping add, preparing items.');
                $this->stage = 'prepare_item_checks';
            } else {
                $this->log('Customer NOT FOUND in QuickBooks --> Will add customer.');
                $this->stage = 'add_customer';
            }
            $this->saveState();
            return new ReceiveResponseXML(50);
        }

        if ($this->stage === 'add_customer') {
            $rs = $response->QBXMLMsgsRs->CustomerAddRs ?? null;
            $statusCode = (string)($rs['statusCode'] ?? '');
            $statusSeverity = (string)($rs['statusSeverity'] ?? '');
            $statusMessage = (string)($rs['statusMessage'] ?? '');
            if ($statusCode !== '' && $statusCode !== '0') {
                $this->log("CustomerAddRs status: code=$statusCode severity=$statusSeverity message=$statusMessage");
            }
            $this->log('CustomerAdd completed. Preparing to check items.');
            $this->stage = 'prepare_item_checks';
            $this->saveState();
            return new ReceiveResponseXML(50);
        }

        if ($this->stage === 'query_item') {
            $rs = $response->QBXMLMsgsRs->ItemQueryRs ?? null;
            $statusCode = (string)($rs['statusCode'] ?? '');
            $statusSeverity = (string)($rs['statusSeverity'] ?? '');
            $statusMessage = (string)($rs['statusMessage'] ?? '');
            if ($statusCode !== '' && $statusCode !== '0') {
                $this->log("ItemQueryRs status: code=$statusCode severity=$statusSeverity message=$statusMessage");
            }
            $found = isset($rs->ItemInventoryRet) || isset($rs->ItemNonInventoryRet) || isset($rs->ItemServiceRet) || isset($rs->ItemOtherChargeRet) || isset($rs->ItemSubtotalRet) || isset($rs->ItemDiscountRet);
            if ($found) {
                $this->log("Item '" . ($this->itemsToCheck[$this->itemIndex] ?? '') . "' EXISTS in QuickBooks.");
                $this->itemIndex++;
                $this->stage = 'query_item';
            } else {
                $this->log("Item '" . ($this->itemsToCheck[$this->itemIndex] ?? '') . "' NOT FOUND in QuickBooks. Adding item.");
                $this->stage = 'add_item';
            }
            $this->saveState();
            return new ReceiveResponseXML(50);
        }

        if ($this->stage === 'add_item') {
            $rs = $response->QBXMLMsgsRs->ItemNonInventoryAddRs ?? $response->QBXMLMsgsRs->ItemAddRs ?? null;
            $statusCode = (string)($rs['statusCode'] ?? '');
            $statusSeverity = (string)($rs['statusSeverity'] ?? '');
            $statusMessage = (string)($rs['statusMessage'] ?? '');
            if ($statusCode !== '' && $statusCode !== '0') {
                $this->log("ItemAddRs status: code=$statusCode severity=$statusSeverity message=$statusMessage");
            }
            $this->log("ItemAdd completed for '" . ($this->itemsToCheck[$this->itemIndex] ?? '') . "'. Moving to next item.");
            $this->itemIndex++;
            $this->stage = 'query_item';
            $this->saveState();
            return new ReceiveResponseXML(50);
        }

        if ($this->stage === 'add_invoice') {
            $rs = $response->QBXMLMsgsRs->InvoiceAddRs ?? null;
            $statusCode = (string)($rs['statusCode'] ?? '');
            $statusSeverity = (string)($rs['statusSeverity'] ?? '');
            $statusMessage = (string)($rs['statusMessage'] ?? '');
            if ($statusCode !== '' && $statusCode !== '0') {
                $this->log("InvoiceAddRs status: code=$statusCode severity=$statusSeverity message=$statusMessage");
            }
            $orderNum = $this->orders[$this->currentOrderIndex]['order_number'] ?? '';
            $this->log("InvoiceAdd completed for Order #{$orderNum}.");
            $this->currentOrderIndex++;
            $this->stage = 'query_customer';
            $this->itemIndex = 0;
            $this->itemsToCheck = [];
            if ($this->currentOrderIndex < count($this->orders)) {
                $this->log("Moving to next static order (index = {$this->currentOrderIndex}).");
                $this->saveState();
                return new ReceiveResponseXML(50);
            }
            $this->log('All orders processed. Done!');
            $this->saveState();
            return new ReceiveResponseXML(100);
        }
        $this->log("Unexpected stage in receiveResponseXML: {$this->stage}");
        $this->saveState();
        return new ReceiveResponseXML(100);
    }

    private function getStatePath(): string
    {
        return rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . self::STATE_FILENAME;
    }

    private function getLogPath(): string
    {
        // Fixed log path inside repository for easier access
        // c:\Afeef\PTL\GIT\qbwc_php\QBWCServer\log\app_debug.log
        return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'log' . DIRECTORY_SEPARATOR . self::LOG_FILENAME;
    }

    private function safeXmlForLog(string $xml): string
    {
        // Mask potentially sensitive fields in logs (basic masking for Email and Phone)
        $masked = preg_replace('/(<Email>)([^<]*)(<\/Email>)/i', '$1***$3', $xml);
        $masked = preg_replace('/(<Phone>)([^<]*)(<\/Phone>)/i', '$1***$3', $masked ?? $xml);
        return (string)$masked;
    }
}