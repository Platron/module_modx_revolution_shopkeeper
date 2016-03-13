<?php

$modx->addPackage('shopkeeper',$modx->getOption('core_path').'components/shopkeeper/model/');
require_once 'elements/includes/PG_Signature.php';

if (isset($_REQUEST['payment']) && $_REQUEST['payment'] == 'platron')
{
	$payment_form = $modx->getOption('PAYMENT_FORM',$scriptProperties,null);
	$modx->sendRedirect($payment_form);
}

if (isset($scriptProperties['action']))
{
	$arrStatuses = array(
		'Pending'	=> 1,
		'Fail'	=> 4,
		'Success'	=> 5,
	);
			
	switch ($scriptProperties['action'])
	{
		case 'callback':
		
			
		if(!empty($_POST))
			$arrRequest = $_POST;
		else
			$arrRequest = $_GET;
		
		$thisScriptName = PG_Signature::getOurScriptName();

		if (empty($arrRequest['pg_sig']) || !PG_Signature::check($arrRequest['pg_sig'], $thisScriptName, $arrRequest, $scriptProperties['PL_SECRET_KEY']))
			die("Wrong signature");
		
		$dbOrder = $modx->getObject('SHKorder', $_REQUEST['pg_order_id']);

		if(!isset($arrRequest['pg_result'])){
			$bCheckResult = 0;
			if(empty($dbOrder) || $dbOrder->_fields['status'] != $arrStatuses['Pending'])
				$error_desc = "Товар не доступен. Либо заказа нет, либо его статус " . array_search($dbOrder->_fields['status'], $arrStatuses);	
			elseif(sprintf('%0.2f',$arrRequest['pg_amount']) != sprintf('%0.2f', $dbOrder->_fields['price']))
				$error_desc = "Неверная сумма";
			else
				$bCheckResult = 1;

			$arrResponse['pg_salt']              = $arrRequest['pg_salt']; // в ответе необходимо указывать тот же pg_salt, что и в запросе
			$arrResponse['pg_status']            = $bCheckResult ? 'ok' : 'error';
			$arrResponse['pg_error_description'] = $bCheckResult ?  ""  : $error_desc;
			$arrResponse['pg_sig']				 = PG_Signature::make($thisScriptName, $arrResponse, $scriptProperties['PL_SECRET_KEY']);

			$objResponse = new SimpleXMLElement('<?xml version="1.0" encoding="utf-8"?><response/>');
			$objResponse->addChild('pg_salt', $arrResponse['pg_salt']);
			$objResponse->addChild('pg_status', $arrResponse['pg_status']);
			$objResponse->addChild('pg_error_description', $arrResponse['pg_error_description']);
			$objResponse->addChild('pg_sig', $arrResponse['pg_sig']);

		}
		else{
			$bResult = 0;
			if(empty($dbOrder) || 
					(($dbOrder->_fields['status'] != $arrStatuses['Pending']) &&
					!($dbOrder->_fields['status'] != $arrStatuses['Success'] && $arrRequest['pg_result'] == 1) && 
					!($dbOrder->_fields['status'] != $arrStatuses['Fail'] && $arrRequest['pg_result'] == 0)))
				
				$strResponseDescription = "Товар не доступен. Либо заказа нет, либо его статус " . array_search($dbOrder->_fields['status'], $arrStatuses);		
			elseif(sprintf('%0.2f',$arrRequest['pg_amount']) != sprintf('%0.2f',$dbOrder->_fields['price']))
				$strResponseDescription = "Неверная сумма";
			else {
				$bResult = 1;
				$strResponseStatus = 'ok';
				$strResponseDescription = "Оплата принята";
				if ($arrRequest['pg_result'] == 1) {
					// Удачная оплата
					$dbOrder->set('status', $arrStatuses['Success']);
				}
				else{
					// Не удачная оплата
					$dbOrder->set('status', $arrStatuses['Fail']);
				}
				$dbOrder->save();
			}
			if(!$bResult)
				if($arrRequest['pg_can_reject'] == 1)
					$strResponseStatus = 'rejected';
				else
					$strResponseStatus = 'error';

			$objResponse = new SimpleXMLElement('<?xml version="1.0" encoding="utf-8"?><response/>');
			$objResponse->addChild('pg_salt', $arrRequest['pg_salt']); // в ответе необходимо указывать тот же pg_salt, что и в запросе
			$objResponse->addChild('pg_status', $strResponseStatus);
			$objResponse->addChild('pg_description', $strResponseDescription);
			$objResponse->addChild('pg_sig', PG_Signature::makeXML($thisScriptName, $objResponse, $scriptProperties['PL_SECRET_KEY']));
		}

		header("Content-type: text/xml");
		echo $objResponse->asXML();
		die();

		case 'payment':
			$order_id = null;
			if (isset($_SESSION['shk_order_id'])) {
				$order_id = $_SESSION['shk_order_id'];
				$phone = $_SESSION['shk_order_phone'];
				$email = $_SESSION['shk_order_email'];
				$amount = number_format($_SESSION['shk_order_price'], 2, '.', '');
			} else {
				$order_id = $_SESSION['shk_lastOrder']['id'];
				$phone = $_SESSION['shk_lastOrder']['phone'];
				$email = $_SESSION['shk_lastOrder']['email'];
				$amount = number_format($_SESSION['shk_lastOrder']['price'], 2, '.', '');
			}

			if (!$order_id) {
				return "Заказ не найден.";
			}
			
			$order = $modx->getObject('SHKorder', $order_id);
			$strDescription = '';
			foreach(unserialize($order->_fields['content']) as $arrItem){
				$strDescription .= $arrItem['name'];
				if($arrItem['count'] > 1)
					$strDescription .= "*".$arrItem['count'];
				$strDescription .= "; ";
			}
			
			$strLang = $modx->getOption('manager_language');
			if($strLang != 'ru')
				$strLang = 'en';
		
			$arrFields = array(
				'pg_merchant_id'		=> $scriptProperties['PL_MERCHANT_ID'],
				'pg_order_id'			=> $order_id,
				'pg_currency'			=> $scriptProperties['PL_CURRENCY_CODE'],
				'pg_amount'				=> $amount,
				'pg_lifetime'			=> (int)$scriptProperties['PL_LIFETIME']*60,
				'pg_testing_mode'		=> ($scriptProperties['PL_TEST_MODE'])? 1 : 0 ,
				'pg_description'		=> $strDescription,
				'pg_user_ip'			=> $_SERVER['REMOTE_ADDR'],
				'pg_language'			=> $strLang,
				'pg_check_url'			=> $scriptProperties['PL_CALLBACK_URL'],
				'pg_result_url'			=> $scriptProperties['PL_CALLBACK_URL'],
				'pg_success_url'		=> $scriptProperties['PL_SUCCESS_URL'],
				'pg_failure_url'		=> $scriptProperties['PL_FAIL_URL'],
				'pg_request_method'		=> 'GET',
				'cms_payment_module'	=> 'MODX_REVOLUTION_SHOPKEEPER',
				'pg_salt'				=> rand(21,43433), // Параметры безопасности сообщения. Необходима генерация pg_salt и подписи сообщения.
			);
			
			if(!empty($phone)){
				preg_match_all("/\d/", $phone, $array);
				$strPhone = implode('',@$array[0]);
				$arrFields['pg_user_phone'] = $strPhone;
			}

			if(!empty($email)){
				$arrFields['pg_user_email'] = $email;
				$arrFields['pg_user_contact_email'] = $email;
			}
			
			$arrFields['pg_sig'] = PG_Signature::make('payment.php', $arrFields, $scriptProperties['PL_SECRET_KEY']);

			$order->set('status', $arrStatuses['Pending']);
			$order->save();

			$output  = "<form action='https://platron.ru/payment.php' method='POST'>";
			
			foreach($arrFields as $strName => $strKey){
				$output .= "<input type='hidden' name='".$strName."' value='".$strKey."'>";
			}
			$output .= "<input type='submit' value='Оплатить сейчас'></form>";

			return $output;

			break;
		default:
			break;
	}
}

?>