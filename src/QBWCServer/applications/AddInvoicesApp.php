<?php
/**
 * Created by PhpStorm.
 * User: Alex
 * Date: 27.10.2015
 * Time: 17:22
 */

namespace QBWCServer\applications;

use QBWCServer\base\AbstractQBWCApplication;
use QBWCServer\response\ReceiveResponseXML,
    QBWCServer\response\SendRequestXML;

class AddInvoicesApp extends AbstractQBWCApplication
{

    public function sendRequestXML($object)
    {
//        $requestId = $this->generateGUID();
//		$this->log_this('at last AddInvoicesApp');
        $qbxmlVersion = $this->_config['qbxmlVersion'];

        $xml = '<?xml version="1.0" encoding="utf-8"?>
		<?qbxml version="' . $qbxmlVersion . '"?>
		<QBXML>
			<QBXMLMsgsRq onError="stopOnError">
				<InvoiceAddRq requestID="1">
				<InvoiceAdd>
					<!-- Customer -->
					<CustomerRef>
					<FullName>John Doe</FullName>
					</CustomerRef>

					<!-- Invoice Header -->
					<TxnDate>2025-10-27</TxnDate>
					<RefNumber>INV-1001</RefNumber>
					<BillAddress>
					<Addr1>John Doe</Addr1>
					<Addr2>123 Main Street</Addr2>
					<City>Los Angeles</City>
					<State>CA</State>
					<PostalCode>90001</PostalCode>
					<Country>USA</Country>
					</BillAddress>

					<!-- Terms, Due Date, etc. -->
					<TermsRef>
					<FullName>Net 30</FullName>
					</TermsRef>
					<DueDate>2025-11-26</DueDate>

					<!-- Line Items -->
					<InvoiceLineAdd>
					<ItemRef>
						<FullName>Consulting Services</FullName>
					</ItemRef>
					<Desc>Consulting work for project A</Desc>
					<Quantity>10</Quantity>
					<Rate>150.00</Rate>
					</InvoiceLineAdd>

					<InvoiceLineAdd>
					<ItemRef>
						<FullName>Software License</FullName>
					</ItemRef>
					<Desc>1-year subscription license</Desc>
					<Quantity>1</Quantity>
					<Rate>300.00</Rate>
					</InvoiceLineAdd>

					<!-- Optional: Sales Tax, Customer Message, etc. -->
					<CustomerMsgRef>
					<FullName>Thank you for your business!</FullName>
					</CustomerMsgRef>
				</InvoiceAdd>
				</InvoiceAddRq>
			</QBXMLMsgsRq>
		</QBXML>';

        return new SendRequestXML($xml);
    }

    public function receiveResponseXML($object)
    {
       $response = simplexml_load_string($object->response);
       $this->log_this($response);

        return new ReceiveResponseXML(100);
    }
}