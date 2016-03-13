<?php

/*
 plugin keepOrderData
 System event: OnSHKsaveOrder
*/

$order = $modx->getObject('SHKorder', $modx->lastInsertId());
$contacts = unserialize($order->contacts);

$_SESSION['shk_order_id'] = $order->id;
$_SESSION['shk_payment_method'] = $contacts["payment"];
$_SESSION['shk_order_price'] = $order->price;
