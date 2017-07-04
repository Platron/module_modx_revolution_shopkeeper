<?php

class ActionFactory
{
	protected $modx;
	protected $properties;

	public function __construct($modx, $properties)
	{
		$this->modx = $modx;
		$this->properties = $properties;
	}

	public function createPaymentPageRedirectAction()
	{
		return new PaymentPageRedirectAction($this->modx, $this->properties);
	}

	public function createCreatePaymentAction($order)
	{
		$action = new CreatePaymentAction($this->modx, $this->properties);
		$action->setOrder($order);

		return $action;
	}

	public function createProcessCallbackAction($inputParams)
	{
		$action = new ProcessCallbackAction($this->modx, $this->properties);
		$action->setInputParams($inputParams);

		return $action;
	}
}

abstract class Action
{
	protected $modx;
	protected $properties;

	protected $statuses = array(
		'pending' => 2,
		'fail' => 5,
		'success' => 6,
	);


	public function __construct($modx, $properties)
	{
		$this->modx = $modx;
		$this->properties = $properties;
	}

	abstract public function execute();
}

class PaymentPageRedirectAction extends Action
{
	public function execute()
	{
		$paymentPageId = $this->modx->getOption('paymentPageId', $this->properties, null);
		$paymentPageUrl = $this->modx->makeUrl($paymentPageId);
		$this->modx->sendRedirect($paymentPageUrl);

		return true;
	}
}

class CreatePaymentAction extends Action
{
	const PLATRON_URL = 'https://www.platron.ru/';

	private $client;
	private $order;

	public function __construct($modx, $properties)
	{
		parent::__construct($modx, $properties);
		$this->client = $modx->getService('rest.modRestCurlClient');
	}

	public function setOrder($order)
	{
		$this->order = $order;
	}

	public function execute()
	{
		$initPaymentParams = $this->prepareParams($this->modx, $this->order, $this->properties);

		$initPaymentResponse = $this->doRequest('init_payment.php', $initPaymentParams);
		if (!$initPaymentResponse) {
			return false;
		}

		if ($this->modx->getOption('ofdSendReceipt', $this->properties, false)) {
			$receipt = $this->createReceipt($this->order, (string) $initPaymentResponse->pg_payment_id);
			$receiptResponse = $this->doRequest('receipt.php', array('pg_xml'=>$receipt->asXml()));
			if (!$receiptResponse) {
				return false;
			}
		}

		$this->order->set('status', $this->statuses['pending']);
		$this->order->save();

		$this->modx->sendRedirect((string) $initPaymentResponse->pg_redirect_url);
		return true;
	}

	public function prepareParams($modx, $order, $scriptProperties)
	{
		$languageCode = $modx->getOption('manager_language');
		if ($languageCode != 'ru') {
			$languageCode = 'en';
		}

		$description = '';
		$purchases = $this->getOrderPurchases($order);
					
		foreach ($purchases as $purchase) {			
			$purchaseData = $purchase->toArray();
			
			$description .= $purchaseData['name'];
			if ($purchaseData['count'] > 1) {
				$description .= ' * ' . $purchaseData['count'];
			}

			$description .= '; ';
		}		

		$requestFields = array(
			'pg_merchant_id' => $modx->getOption('merchantId', $scriptProperties, null),
			'pg_order_id' => $order->get('id'),
			'pg_currency' => $modx->getOption('currencyCode', $scriptProperties, 'RUB'),
			'pg_amount' => $order->get('price'),
			'pg_lifetime' => $modx->getOption('lifetime', $scriptProperties, 0),
			'pg_testing_mode' => $modx->getOption('testingMode', $scriptProperties, 0),
			'pg_description' => $description,
			//'pg_user_ip' => $_SERVER['REMOTE_ADDR'],
			'pg_language' => $languageCode,
			'pg_check_url' => $this->makeUrlById($modx->getOption('callbackPageId', $scriptProperties, null)),
			'pg_result_url' => $this->makeUrlById($modx->getOption('callbackPageId', $scriptProperties, null)),
			'pg_success_url' => $this->makeUrlById($modx->getOption('successPageId', $scriptProperties, null)),
			'pg_failure_url' => $this->makeUrlById($modx->getOption('failPageId', $scriptProperties, null)),
			'pg_request_method'	=> 'GET',
			'cms_payment_module' => 'MODX_REVOLUTION_SHOPKEEPER',
			'pg_salt' => rand(21,43433),
		);

		$contacts = json_decode($order->get('contacts'));
		foreach ($contacts as $contact) {
			if ($contact->name == 'email' && $contact->value) {
				$requestFields['pg_user_email'] = $contact->value;
				$requestFields['pg_user_contact_email'] = $contact->value;
			}

			if ($contact->name == 'phone' && $contact->value) {
				$phone = preg_replace('/[^\d]/', '', $contact->value);
				if ($phone) {
					$requestFields['pg_user_phone'] = $phone;
				}
			}
		}

		$requestFields['pg_sig'] = PG_Signature::make('init_payment.php', $requestFields, $modx->getOption('secretKey', $scriptProperties, ''));

		return $requestFields;
	}

	private function createReceipt($order, $paymentId)
	{
		$receipt = new OfdReceiptRequest($this->modx->getOption('merchantId', $this->properties, null), $paymentId);
		foreach ($this->getOrderPurchases($order) as $purchase) {
			$ofdItem = new OfdReceiptItem();
			$ofdItem->label = substr($purchase->get('name'), 0, 128);
			$ofdItem->price = round($purchase->get('price'), 2);
			$ofdItem->quantity = $purchase->get('count');
			$ofdItem->amount = round($ofdItem->price * $ofdItem->quantity, 2);
			$ofdItem->vat = $this->modx->getOption('ofdVatType', $this->properties, null);
			$receipt->items[] = $ofdItem;
		}
		if ($order->get('delivery_price') > 0) {
			$ofdItem = new OfdReceiptItem();
			$ofdItem->label = 'Доставка';
			$ofdItem->price = round($order->get('delivery_price'), 2);
			$ofdItem->quantity = 1;
			$ofdItem->amount = $ofdItem->price;
			$ofdItem->vat = '18';
			$receipt->items[] = $ofdItem;
		}
		$receipt->prepare();
		$receipt->sign($this->modx->getOption('secretKey', $this->properties, ''));

		return $receipt;
	}

	private function getOrderPurchases($order)
	{
		$query = $this->modx->newQuery('shk_purchases');
		$query->where(array('order_id' => $order->get('id')));
		$query->sortby('id', 'asc');
		return $this->modx->getIterator('shk_purchases', $query);
	}

	private function makeUrlById($id)
	{
		if (!$id) {
			return '';
		}
		return $this->modx->makeUrl($id, '', '', 'full', array('friendly_urls' => 0));
	}

	private function doRequest($scriptName, $params)
	{
		$response = $this->client->request(self::PLATRON_URL, $scriptName, 'POST', $params, array('contentType'=>'string'));
		try {
			$responseXml = new SimpleXMLElement($response);
		} catch (Exception $e) {
			$this->modx->log(modX::LOG_LEVEL_ERROR, "[Platron] {$scriptName} request error: " . $e->getMessage(), true);
			return null;
		}
		if (!PG_Signature::checkXML($scriptName, $responseXml, $this->modx->getOption('secretKey', $this->properties, ''))) {
			$this->modx->log(modX::LOG_LEVEL_ERROR, "[Platron] {$scriptName} response signature is invalid", true);
			return null;
		}
		if ($responseXml->pg_status == 'error') {
			$this->modx->log(modX::LOG_LEVEL_ERROR, "[Platron] {$scriptName} response error: " . $responseXml->pg_error_description, true);
			return null;
		}

		return $responseXml;
	}
}

class ProcessCallbackAction extends Action
{
	private $inputParams;
	private $outputParams = array();

	public function setInputParams($inputParams)
	{
		$this->inputParams = $inputParams;
	}

	public function execute()
	{
		$this->prepareOutputParams();
		$this->sendResponse();
	}

	private function prepareOutputParams()
	{
		$this->outputParams['pg_salt'] = $this->inputParams['pg_salt'];
		$this->outputParams['pg_status'] = 'ok';
		try {
			$order = $this->fetchOrder();
			$this->checkOrderPrice($order);
			$this->checkOrderStatus($order);

			if ($this->needChangeStatus($order)) {
				$this->changeOrderStatus($order);
			}
		} catch (ActionError $e) {
			if ($this->canReject()) {
				$this->outputParams['pg_status'] = 'rejected';
			} else {
				$this->outputParams['pg_status'] = 'error';
			}
			$this->outputParams['pg_description'] = $e->getMessage();
			$this->outputParams['pg_error_description'] = $e->getMessage();
		}

		$this->outputParams['pg_sig'] = PG_Signature::make('index.php', $this->outputParams, $this->modx->getOption('secretKey', $this->properties, ''));
	}

	private function sendResponse()
	{
		$xml = new SimpleXMLElement('<?xml version="1.0" encoding="utf-8"?><response/>');
		foreach ($this->outputParams as $name => $value) {
			$xml->addChild($name, $value);
		}
		
		header("Content-type: text/xml");
		echo $xml->asXML();
		die();
	}

	private function fetchOrder()
	{
		$orderId = $this->modx->getOption('pg_order_id', $this->inputParams, null);
		$order = $this->modx->getObject('shk_order', $orderId);

		if (!$order) {
			throw new ActionError('Заказ не найден');
		}

		return $order;
	}

	private function checkOrderStatus($order)
	{
		/** Если повторно обращаемся с результатом платежа, должны вернуть тот же результат */
		if ($this->hasResult()) {
			if ($order->get('status') == $this->statuses['fail']) {
				throw new ActionError('Неправильный статус заказа');
			}
		} else {
			if ($order->get('status') != $this->statuses['pending']) {
				throw new ActionError('Неправильный статус заказа');
			}
		}
	}

	private function checkOrderPrice($order)
	{
		if (sprintf('%0.2f', $this->inputParams['pg_amount']) != sprintf('%0.2f', $order->get('price'))) {
			throw new ActionError('Неправильная сумма заказа');
		}
	}

	private function needChangeStatus($order)
	{
		return $this->hasResult() && $order->get('status') == $this->statuses['pending'];
	}

	private function hasResult()
	{
		return isset($this->inputParams['pg_result']);
	}

	private function changeOrderStatus($order)
	{
		if ($order->get('status') == $this->statuses['pending']) {
			if ($this->inputParams['pg_result'] == 1) {
				$order->set('status', $this->statuses['success']);
			} else {
				$order->set('status', $this->statuses['fail']);
			}

			$order->save();
		}
	}

	private function canReject()
	{
		return isset($this->outputParams['pg_can_reject']) && $this->outputParams['pg_can_reject'] == '1';
	}
}

class ActionError extends Exception
{

}