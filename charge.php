<?php

include(dirname(__FILE__).'/../../config/config.inc.php');
include(dirname(__FILE__).'/../../init.php');
include(dirname(__FILE__).'/conektatarjeta.php');

if (!defined('_PS_VERSION_'))
	exit;

/* Todo: extend for subscriptions and meses sin intereses */
    
/* Check token */
$conekta = new ConektaTarjeta();
$conektaToken=Tools::getValue('conektaToken');

/* Check that module is active */
if ($conekta->active)
	$conekta->processPayment($conektaToken);
else
	Tools::dieOrLog('Token required, please check for any Javascript errors on the payment page.', true);
    
    
