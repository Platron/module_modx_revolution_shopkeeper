<?php
$modx->addPackage('shopkeeper3', $modx->getOption('core_path').'components/shopkeeper3/model/');
require_once $modx->getOption('core_path') . 'components/platron/includes/PG_Signature.php';
require_once $modx->getOption('core_path') . 'components/platron/includes/classes.php';
require_once $modx->getOption('core_path') . 'components/platron/includes/ofd.php';

$actionFactory = new ActionFactory($modx, $scriptProperties);

if (isset($_REQUEST['payment']) && $_REQUEST['payment'] == 'platron') {
	if (!isset($_SESSION['shk_lastOrder'])) {
		return 'Заказ не найден';
	}

	$orderId = $modx->getOption('id', $_SESSION['shk_lastOrder'], null);
	$order = $modx->getObject('shk_order', $orderId);
	if (!$order) {
		return 'Заказ не найден';
	}

	$action = $actionFactory->createCreatePaymentAction($order);

	$result = $action->execute();
	if (!$result) {
		$hook->addError('error_message', 'Ошибка оплаты, попробуйте позже.');
	}
}

if ($action == 'callback') {
	$inputParams = $_REQUEST;

	$signature = isset($inputParams['pg_sig']) ? $inputParams['pg_sig'] : '';
	$secretKey = $modx->getOption('secretKey', $scriptProperties, '');
	$scriptName = 'index.php';

	if (!PG_Signature::check($signature, $scriptName, $inputParams, $secretKey)) {
		die('Wrong signature');
	}

	$action = $actionFactory->createProcessCallbackAction($inputParams);
	$action->execute();

}