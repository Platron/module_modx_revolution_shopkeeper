<?php

require_once 'PG_Signature.php';

class OfdReceiptRequest
{
	const SCRIPT_NAME = 'receipt.php';

	public $merchantId;
	public $operationType = 'payment';
	public $paymentId;
	public $items = array();

	private $xml;

	public function __construct($merchantId, $paymentId)
	{
		$this->merchantId = $merchantId;
		$this->paymentId = $paymentId;
	}

	public function prepare()
	{
		$this->xml = $this->makeXmlObject();
	}

	public function sign($secretKey)
	{
		$salt = md5((string) time());
		$this->xml->addChild('pg_salt', $salt);
		$this->xml->addChild('pg_sig', PG_Signature::makeXml(self::SCRIPT_NAME, $this->xml, $secretKey));
	}

	public function asXml()
	{
		return $this->xml->asXML();
	}

	public function toArray()
	{
		$result = array();

		$result['pg_merchant_id'] = $this->merchantId;
		$result['pg_operation_type'] = $this->operationType;
		$result['pg_payment_id'] = $this->paymentId;

		foreach ($this->items as $item) {
			$result['pg_items'][] = $item->toArray();
		}

		return $result;
	}

	private function makeXmlObject()
	{
		//var_dump($this->params);
		$xmlElement = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><request></request>');

		foreach ($this->toArray() as $paramName => $paramValue) {
			if ($paramName == 'pg_items') {
				//$itemsElement = $xmlElement->addChild($paramName);
				foreach ($paramValue as $itemParams) {
					$itemElement = $xmlElement->addChild($paramName);
					foreach ($itemParams as $itemParamName => $itemParamValue) {
						$itemElement->addChild($itemParamName, $itemParamValue);
					}
				}
				continue;
			}

			$xmlElement->addChild($paramName, $paramValue);
		}

		return $xmlElement;
	}
}

class OfdReceiptItem
{
	public $label;
	public $amount;
	public $price;
	public $quantity;
	public $vat;
	public $type = 'product';

	public function toArray()
	{
		return array(
			'pg_label' => $this->label,
			'pg_amount' => $this->amount,
			'pg_price' => $this->price,
			'pg_quantity' => $this->quantity,
			'pg_vat' => $this->vat,
			'pg_type' => $this->type,
		);
	}
}
