<?php

/*
 * Title   : Conekta Card Payment Gateway for Prestashop
 * Author  : Conekta.io
 * Url     : https://www.conekta.io/es/docs/plugins/prestashop
 */

if (!defined('_PS_VERSION_'))
	exit;

class ConektaTarjeta extends PaymentModule
{
	protected $backward = false;

	public function __construct()
	{
		$this->name = 'conektatarjeta';
		$this->tab = 'payments_gateways';
		$this->version = '0.1';
		$this->author = 'Conekta.io';

		parent::__construct();

		$this->displayName = $this->l('Conekta Tarjeta');
		$this->description = $this->l('Accept payments by Credit and Debit Card with Conekta (Visa, Mastercard, Amex)');
		$this->confirmUninstall = $this->l('Warning: all the Conekta transaction details  in your database will be deleted. Are you sure you want uninstall this module?');

		$this->backward_error = $this->l('In order to work properly in PrestaShop v1.4, the Conekta module requires backward compatibility module of at least v0.4.').'<br />'.
			$this->l('You can download this module here: http://addons.prestashop.com/en/modules-prestashop/6222-backwardcompatibility.html');

		if (version_compare(_PS_VERSION_, '1.5', '<'))
		{
			require(_PS_MODULE_DIR_.$this->name.'/backward_compatibility/backward.php');
			$this->backward = true;
		}
		else
			$this->backward = true;

	}

	/**
	 * Conekta's module installation
	 *
	 * @return boolean Install result
	 */
	public function install()
	{
		if (!$this->backward && _PS_VERSION_ < 1.5)
		{
			echo '<div class="error">'.Tools::safeOutput($this->backward_error).'</div>';
			return false;
		}

		/* For 1.4.3 and less compatibility */
		$updateConfig = array(
			'PS_OS_CHEQUE' => 1,
			'PS_OS_PAYMENT' => 2,
			'PS_OS_PREPARATION' => 3,
			'PS_OS_SHIPPING' => 4,
			'PS_OS_DELIVERED' => 5,
			'PS_OS_CANCELED' => 6,
			'PS_OS_REFUND' => 7,
			'PS_OS_ERROR' => 8,
			'PS_OS_OUTOFSTOCK' => 9,
			'PS_OS_BANKWIRE' => 10,
			'PS_OS_PAYPAL' => 11,
			'PS_OS_WS_PAYMENT' => 12);

		foreach ($updateConfig as $u => $v)
			if (!Configuration::get($u) || (int)Configuration::get($u) < 1)
			{
				if (defined('_'.$u.'_') && (int)constant('_'.$u.'_') > 0)
					Configuration::updateValue($u, constant('_'.$u.'_'));
				else
					Configuration::updateValue($u, $v);
			}

		$ret = parent::install() && $this->registerHook('adminOrder') && $this->registerHook('payment') && $this->registerHook('header') && $this->registerHook('backOfficeHeader') && $this->registerHook('paymentReturn') && Configuration::updateValue('CONEKTA_MODE', 0) && Configuration::updateValue('CONEKTA_PAYMENT_ORDER_STATUS', (int)Configuration::get('PS_OS_PAYMENT')) && $this->installDb();

		Configuration::updateValue('CONEKTA_TARJETA_VERSION', $this->version);

		return $ret;
	}

    
	/**
	 * Conekta's module database tables
	 *
	 * @return boolean Database tables installation result
	 */
	public function installDb()
	{
		return Db::getInstance()->Execute(
		'CREATE TABLE IF NOT EXISTS `'._DB_PREFIX_.'conekta_transaction` (
			`id_conekta_transaction` int(11) NOT NULL AUTO_INCREMENT,
			`type` enum(\'payment\',\'refund\') NOT NULL, 
			`id_conekta_customer` int(10) unsigned NOT NULL,
			`id_cart` int(10) unsigned NOT NULL,
			`id_order` int(10) unsigned NOT NULL, 
			`id_transaction` varchar(32) NOT NULL, 
			`amount` decimal(10,2) NOT NULL, 
			`status` enum(\'paid\',\'unpaid\') NOT NULL,
			`currency` varchar(3) NOT NULL, 
			`fee` decimal(10,2) NOT NULL,
			`mode` enum(\'live\',\'test\') NOT NULL,
			`date_add` datetime NOT NULL, 
			`captured` tinyint(1) NOT NULL DEFAULT \'1\',
			PRIMARY KEY (`id_conekta_transaction`),
			KEY `idx_transaction` (`type`,`id_order`,`status`))
			ENGINE='._MYSQL_ENGINE_.' DEFAULT CHARSET=utf8 AUTO_INCREMENT=1');
	}

	/**
	 * Conekta's module uninstallation
	 *
	 * @return boolean Uninstall result
	 */
	public function uninstall()
	{
		return parent::uninstall() && Configuration::deleteByName('CONEKTA_TARJETA_VERSION') && Configuration::deleteByName('CONEKTA_PUBLIC_KEY_TEST') && Configuration::deleteByName('CONEKTA_PUBLIC_KEY_LIVE')
		&& Configuration::deleteByName('CONEKTA_MODE') && Configuration::deleteByName('CONEKTA_PRIVATE_KEY_TEST') && Configuration::deleteByName('CONEKTA_PRIVATE_KEY_LIVE') && Configuration::deleteByName('CONEKTA_PAYMENT_ORDER_STATUS') && Db::getInstance()->Execute('DROP TABLE `'._DB_PREFIX_.'conekta_customer`') && Db::getInstance()->Execute('DROP TABLE `'._DB_PREFIX_.'conekta_transaction`');
	}

	/**
	 * Load Javascripts and CSS related to the CONEKTA'S module
	 * @return string Content
	 */
	public function hookHeader()
	{
		/* If 1.4 and no backward, then leave */
		if (!$this->backward)
			return;

		if (!$this->checkSettings())
			return;

		if (Tools::getValue('controller') != 'order-opc' && (!($_SERVER['PHP_SELF'] == __PS_BASE_URI__.'order.php' || $_SERVER['PHP_SELF'] == __PS_BASE_URI__.'order-opc.php' || Tools::getValue('controller') == 'order' || Tools::getValue('controller') == 'orderopc' || Tools::getValue('step') == 3)))
			return;
		$this->context->controller->addCSS($this->_path.'css/conekta-prestashop.css');

		return '
		<script type="text/javascript" src="https://conektaapi.s3.amazonaws.com/v0.3.2/js/conekta.js"></script>
		<script type="text/javascript">
			var conekta_public_key = \''.addslashes(Configuration::get('CONEKTA_MODE') ? Configuration::get('CONEKTA_PUBLIC_KEY_LIVE') : Configuration::get('CONEKTA_PUBLIC_KEY_TEST')).'\';
		</script>
		<script type="text/javascript" src="'.$this->_path.'js/tokenize.js"></script>
		';
	}

	/**
	 * Display the CONEKTA payment form on the checkout page
	 * @return string Conekta's Smarty template content
	 */
	public function hookPayment($params)
	{
		/* If 1.4 and no backward then leave */
		if (!$this->backward)
			return;

		if (!$this->checkSettings())
			return;
		
		$this->smarty->assign('conekta_ps_version', _PS_VERSION_);

		return '<script type="text/javascript"> var conekta_secure_key = \''.addslashes($this->context->customer->secure_key).'\' </script> <div class="conekta-payment-errors”> '. $_GET["message"] .' </div> '. $this->fetchTemplate('payment.tpl');
	}

	public function hookAdminOrder($params)
	{
		if (version_compare(_PS_VERSION_, '1.6', '<'))
			return;

		$id_order=(int) ($params['id_order']);

		if (Db::getInstance()->getValue('SELECT module FROM '._DB_PREFIX_.'orders WHERE id_order = '.(int)$id_order) == $this->name)
		{
			$conekta_transaction_details = Db::getInstance()->getRow('SELECT * FROM '._DB_PREFIX_.'conekta_transaction WHERE id_order = '.(int)$id_order.' AND type = \'payment\' AND status = \'paid\'');
            
			$output = '<div class="col-lg-12"><div class="panel"><h3><i class="icon-money"></i> '.$this->l('Conekta Payment Details').'</h3>';
			$output .= '
				<ul class="nav nav-tabs" id="tabConekta">
					<li class="active">
						<a href="#conekta_details">
							<i class="icon-money"></i> '.$this->l('Details').' <span class="badge">'.$conekta_transaction_details['id_transaction'].'</span>
						</a>
					</li>
				</ul>';
			$output .= '
				<div class="tab-content panel">
					<div class="tab-pane active" id="conekta_details">';

			if (isset($conekta_transaction_details['id_transaction']))
			{
				$output .= '
					<p>
					<b>'.$this->l('Status:').'</b> <span style="font-weight: bold; color: '.($conekta_transaction_details['status'] == 'paid' ? 'green;">'.$this->l('Paid') : '#CC0000;">'.$this->l('Unpaid')).'</span><br>'.
					'<b>'.$this->l('Amount:').'</b> '.Tools::displayPrice($conekta_transaction_details['amount']).'<br>'.
					'<b>'.$this->l('Processed on:').'</b> '.Tools::safeOutput($conekta_transaction_details['date_add']).'<br>'.
					'<b>'.$this->l('Mode:').'</b> <span style="font-weight: bold; color: '.($conekta_transaction_details['mode'] == 'live' ? 'green;">'.$this->l('Live') : '#CC0000;">'.$this->l('Test (No payment has been processed and you will need to enable the "Live" mode)')).'</span>
					</p>';
			}
			else
				$output .= '<b style="color: #CC0000;">'.$this->l('Warning:').'</b> '.$this->l('The customer paid using Conekta and an error occured (check details at the bottom of this page)');

			$output .= '</div>';
			$output .= '</div></div></div>';
			return $output;
		}
	}

	/**
	 * Display the info in the admin
	 *
	 * @return string Content
	 */
	public function hookBackOfficeHeader()
	{
		//do not use this function for PS v1.6+
		if (version_compare(_PS_VERSION_, 1.6, '>='))
			return;

		/* If 1.4 and no backward, then leave */
		if (!$this->backward)
			return;

		if (!Tools::getIsset('vieworder') || !Tools::getIsset('id_order'))
			return;

		$id_order=(int)Tools::getValue('id_order');

		if (Db::getInstance()->getValue('SELECT module FROM '._DB_PREFIX_.'orders WHERE id_order = '.(int)$id_order) == $this->name)
		{
			$conekta_transaction_details = Db::getInstance()->getRow('SELECT * FROM '._DB_PREFIX_.'conekta_transaction WHERE id_order = '.(int)$id_order.' AND type = \'payment\' AND status = \'paid\'');
			$output = '
			<script type="text/javascript">
				$(document).ready(function() {
					$(\'<fieldset'.(_PS_VERSION_ < 1.5 ? ' style="width: 400px;"' : '').'><legend><img src="../img/admin/money.gif" alt="" />'.$this->l('Conekta Payment Details').'</legend>';

			if (isset($conekta_transaction_details['id_transaction']))
				$output .= $this->l('Conekta Transaction ID:').' '.Tools::safeOutput($conekta_transaction_details['id_transaction']).'<br /><br />'.
				$this->l('Status:').' <span style="font-weight: bold; color: '.($conekta_transaction_details['status'] == 'paid' ? 'green;">'.$this->l('Paid') : '#CC0000;">'.$this->l('Unpaid')).'</span><br />'.
				$this->l('Captured:').' <span style="font-weight: bold; color: '.($conekta_transaction_details['captured'] == '1' ? 'green;">'.$this->l('Yes') : '#CC0000;">'.$this->l('No')).'</span><br />'.
				$this->l('Amount:').' '.Tools::displayPrice($conekta_transaction_details['amount']).'<br />'.
				$this->l('Processed on:').' '.Tools::safeOutput($conekta_transaction_details['date_add']).'<br />'.
				$this->l('Processing Fee:').' '.Tools::displayPrice($conekta_transaction_details['fee']).'<br /><br />'.
				$this->l('Mode:').' <span style="font-weight: bold; color: '.($conekta_transaction_details['mode'] == 'live' ? 'green;">'.$this->l('Live') : '#CC0000;">'.$this->l('Test (You will not receive any payment, until you enable the "Live" mode)')).'</span>';
			else
				$output .= '<b style="color: #CC0000;">'.$this->l('Warning:').'</b> '.$this->l('The customer paid using Conekta and an error occured (check details at the bottom of this page)');

			$order = new Order((int)$id_order);
			$currency = new Currency($order->id_currency);
			$symbol = $currency->getSign();

			return $output;
		}
	}
	
	/**
	 * Display a confirmation message after an order has been placed
	 * To Do: add more complete information to show to user
	 * @param array Hook parameters
	 */
	public function hookPaymentReturn($params)
	{
		if (!$this->active)
			return;

		$state = $params['objOrder']->getCurrentState();
		
		if ($params['objOrder'] && Validate::isLoadedObject($params['objOrder']) && isset($params['objOrder']->valid))
			$this->smarty->assign('conekta_order', array('reference' => isset($params['objOrder']->reference) ? $params['objOrder']->reference : '#'.sprintf('%06d', $params['objOrder']->id),
				'valid' => $params['objOrder']->valid));

		if ($state == Configuration::get('PS_OS_OUTOFSTOCK'))
			$this->smarty->assign('os_back_ordered', true);
		else
			$this->smarty->assign('os_back_ordered', false);

		$currentOrderStatus = (int)$params['objOrder']->getCurrentState();
        $this->smarty->assign('order_pending', false);

		return $this->fetchTemplate('order-confirmation.tpl');
	}

	/**
	 * Process a payment, where the magic happens
	 *
	 * @param string $token CONEKTA Transaction ID (token)
	 */
	public function processPayment($token)
	{
		/* If 1.4 and no backward, then leave */
		if (!$this->backward)
			return;

		if (!$token)
		{
			if (version_compare(_PS_VERSION_, '1.4.0.3', '>') && class_exists('Logger'))
				Logger::addLog($this->l('Conekta - Payment transaction failed.').' Message: A valid Conekta token was not provided', 3, null, 'Cart', (int)$this->context->cart->id, true);

			$controller = Configuration::get('PS_ORDER_PROCESS_TYPE') ? 'order-opc.php' : 'order.php';
			$location = $this->context->link->getPageLink($controller, true).(strpos($controller, '?') !== false ? '&' : '?').'step=3&conekta_error=1#conekta_error';
			Tools::redirectLink($location);
		}

		include(dirname(__FILE__).'/lib/Conekta.php');
		Conekta::setApiKey(Configuration::get('CONEKTA_MODE') ? Configuration::get('CONEKTA_PRIVATE_KEY_LIVE') : Configuration::get('CONEKTA_PRIVATE_KEY_TEST'));
		$conekta_customer_exists = false;

		//to do: add details and line_items
		try
		{
			$charge_details = array(
				'amount' => $this->context->cart->getOrderTotal() * 100,
                'reference_id'=>(int)$this->context->cart->id,
                'card'=> $token,
				'currency' => $this->context->currency->iso_code,
				'description' => $this->l('PrestaShop Customer ID:').' '.(int)$this->context->cookie->id_customer.' - '.$this->l('PrestaShop Cart ID:').' '.(int)$this->context->cart->id
				);

			$charge_mode=true;
			$charge_details['capture'] = $charge_mode;
			$charge_response=Conekta_Charge::create($charge_details);

			$result_json = Tools::jsonDecode($charge_response);
			$order_status = (int)Configuration::get('CONEKTA_PAYMENT_ORDER_STATUS');
			$message = $this->l('Conekta Transaction Details:')."\n\n".
			$this->l('Conekta ID:').' '.$charge_response->id."\n".
			$this->l('Amount:').' '.($charge_response->amount * 0.01)."\n".
			$this->l('Status:').' '.($charge_response->status == 'paid' ? $this->l('Paid') : $this->l('Unpaid'))."\n".
			$this->l('Processed on:').' '.strftime('%Y-%m-%d %H:%M:%S', $charge_response->created_at)."\n".
			$this->l('Currency:').' '.Tools::strtoupper($charge_response->currency)."\n".
			$this->l('Mode:').' '.($charge_response->livemode == 'true' ? $this->l('Live') : $this->l('Test'))."\n";
			$this->validateOrder((int)$this->context->cart->id, (int)$order_status, ($charge_response->amount * 0.01), $this->displayName, $message, array(), null, false, $this->context->customer->secure_key);

			if (version_compare(_PS_VERSION_, '1.5', '>='))
			{
				$new_order = new Order((int)$this->currentOrder);
				if (Validate::isLoadedObject($new_order))
				{
					$payment = $new_order->getOrderPaymentCollection();
					if (isset($payment[0]))
					{
						$payment[0]->transaction_id = pSQL($charge_response->id);
						$payment[0]->save();
					}
				}
			}

			if (isset($charge_response->id))
				Db::getInstance()->Execute('
				INSERT INTO '._DB_PREFIX_.'conekta_transaction (type, id_conekta_customer, id_cart, id_order,
				id_transaction, amount, status, currency, fee, mode, date_add, captured)
				VALUES (\'payment\', '.(isset($conekta_customer['id_conekta_customer']) ? (int)$conekta_customer['id_conekta_customer'] : 0).', '.(int)$this->context->cart->id.', '.(int)$this->currentOrder.', \''.pSQL($charge_response->id).'\',
				\''.($charge_response->amount * 0.01).'\', \''.($charge_response->status == 'paid' ? 'paid' : 'unpaid').'\', \''.pSQL($charge_response->currency).'\', \''.($fee * 0.01).'\', \''.($charge_response->livemode == 'true' ? 'live' : 'test').'\', NOW(), \''.($charge_response->captured == 'true' ? '1' : '0').'\' )');

			if (version_compare(_PS_VERSION_, '1.5', '<'))
				$redirect = 'order-confirmation.php?id_cart='.(int)$this->context->cart->id.'&id_module='.(int)$this->id.'&id_order='.(int)$this->currentOrder.'&key='.$this->context->customer->secure_key;
			else
				$redirect = $this->context->link->getPageLink('order-confirmation', true, null, array('id_order' => (int)$this->currentOrder, 'id_cart' => (int)$this->context->cart->id, 'key' => $this->context->customer->secure_key, 'id_module' => $this->id));

			Tools::redirect($redirect);
		}
		catch (Conekta_Error $e) {
			$message = $e->message_to_purchaser;
			if (version_compare(_PS_VERSION_, '1.4.0.3', '>') && class_exists('Logger'))
				Logger::addLog($this->l('Payment transaction failed').' '.$message, 2, null, 'Cart', (int)$this->context->cart->id, true);

			$controller = Configuration::get('PS_ORDER_PROCESS_TYPE') ? 'order-opc.php' : 'order.php';
			$location = $this->context->link->getPageLink($controller, true).(strpos($controller, '?') !== false ? '&' : '?').'step=3&conekta_error=1&message='. $message .' #conekta_error';
			Tools::redirectLink($location);
		}
	}

	/**
	 * Check settings requirements to make sure the CONEKTA's module will work
	 *
	 * @param string $mode This will control which settings are checked.  Valid values are 1 for 'live', 0 for 'test' or 'global' to use the global mode setting.  If a mode is not provided, then 'global' will be used by default
	 * @return boolean Check result
	 */
	public function checkSettings($mode = 'global')
	{
		if ($mode==='global')
			$mode=Configuration::get('CONEKTA_MODE');

		if ($mode)
			return Configuration::get('CONEKTA_PUBLIC_KEY_LIVE') != '' && Configuration::get('CONEKTA_PRIVATE_KEY_LIVE') != '';
		else
			return Configuration::get('CONEKTA_PUBLIC_KEY_TEST') != '' && Configuration::get('CONEKTA_PRIVATE_KEY_TEST') != '';
	}

	/**
	 * Check technical requirements to make sure the Conekta module will work properly
	 *
	 * @return array of the Requirements
	 */
	public function checkRequirements()
	{
		$tests = array('result' => true);
		$tests['curl'] = array('name' => $this->l('PHP cURL extension must be enabled on your server'), 'result' => function_exists('curl_init'));
		if (Configuration::get('CONEKTA_MODE'))
			$tests['ssl'] = array('name' => $this->l('SSL must be enabled on your store (before entering Live mode)'), 'result' => Configuration::get('PS_SSL_ENABLED') || (!empty($_SERVER['HTTPS']) && Tools::strtolower($_SERVER['HTTPS']) != 'off'));
		$tests['php52'] = array('name' => $this->l('Your server must run PHP 5.2 or greater'), 'result' => version_compare(PHP_VERSION, '5.2.0', '>='));
		$tests['configuration'] = array('name' => $this->l('You must sign-up for CONEKTA and configure your account settings in the module (publishable key, secret key...etc.)'), 'result' => $this->checkSettings());

		if (version_compare(_PS_VERSION_, '1.5', '<'))
		{
			$tests['backward'] = array('name' => $this->l('You are using the backward compatibility module'), 'result' => $this->backward, 'resolution' => $this->backward_error);
 		}

		foreach ($tests as $k => $test)
			if ($k != 'result' && !$test['result'])
				$tests['result'] = false;

		return $tests;
	}

	/**
	 * Display the admin interface of the CONEKTA module
	 *
	 * @return string HTML/JS Content
	 */
	public function getContent()
	{
		// If 1.4 and no backward, then leave
		if (!$this->backward)
			return false;

		$output = '';
		if (version_compare(_PS_VERSION_, '1.5', '>'))
			$this->context->controller->addJQueryPlugin('fancybox');
		else
			$output .= '
			<script type="text/javascript" src="'.__PS_BASE_URI__.'js/jquery/jquery.fancybox-1.3.4.js"></script>
		  	<link type="text/css" rel="stylesheet" href="'.__PS_BASE_URI__.'css/jquery.fancybox-1.3.4.css" />';

		if (Tools::isSubmit('SubmitConekta'))
		{
			$configuration_values = array(
				'CONEKTA_MODE' => Tools::getValue('conekta_mode'),
				'CONEKTA_PUBLIC_KEY_TEST' => rtrim(Tools::getValue('conekta_public_key_test')),
				'CONEKTA_PUBLIC_KEY_LIVE' => rtrim(Tools::getValue('conekta_public_key_live')),
				'CONEKTA_PRIVATE_KEY_TEST' => rtrim(Tools::getValue('conekta_private_key_test')),
				'CONEKTA_PRIVATE_KEY_LIVE' => rtrim(Tools::getValue('conekta_private_key_live')),
				'CONEKTA_PAYMENT_ORDER_STATUS' => (int)Tools::getValue('conekta_payment_status'),
			);

			foreach ($configuration_values as $configuration_key => $configuration_value)
				Configuration::updateValue($configuration_key, $configuration_value);

			$output .= '
				<fieldset>
					<legend>'.$this->l('Confirmation').'</legend>
					<div class="form-group">
						<div class="col-lg-9">
							<div class="conf confirmation">'.$this->l('Settings successfully saved').'</div>
						</div>
					</div>
				</fieldset>
			<br />';

		}

		$requirements = $this->checkRequirements();

		$output .= '
		<link href="'.$this->_path.'css/conekta-prestashop-admin.css" rel="stylesheet" type="text/css" media="all" />
		<div class="conekta-module-wrapper">
			<fieldset>
				<legend>'.$this->l('Technical Checks').'</legend>
				<div class="'.($requirements['result'] ? 'conf">'.$this->l('All the checks were successfully performed. You can now configure and start using your module.') :
				'warn">'.$this->l('Unfortunately, at least one issue is preventing you from using this module. Please fix the issue and reload this page.')).'</div>
				<table cellspacing="0" cellpadding="0" class="conekta-technical">';
				foreach ($requirements as $k => $requirement)
					if ($k != 'result')
						$output .= '
						<tr>
							<td><img src="../img/admin/'.($requirement['result'] ? 'ok' : 'forbbiden').'.gif" alt="" /></td>
							<td>'.$requirement['name'].(!$requirement['result'] && isset($requirement['resolution']) ? '<br />'.Tools::safeOutput($requirement['resolution'], true) : '').'</td>
						</tr>';
				$output .= '
				</table>
			</fieldset>
		<br />';

		/* If 1.4 and no backward, then leave */
		if (!$this->backward)
			return $output;

		$statuses = OrderState::getOrderStates((int)$this->context->cookie->id_lang);
		$output .= '
		<form action="'.Tools::safeOutput($_SERVER['REQUEST_URI']).'" method="post">
			<fieldset class="conekta-settings">
				<legend>'.$this->l('Settings').'</legend>
				<label>'.$this->l('Mode').'</label>
				<input type="radio" name="conekta_mode" value="0"'.(!Configuration::get('CONEKTA_MODE') ? ' checked="checked"' : '').' /> Test
				<input type="radio" name="conekta_mode" value="1"'.(Configuration::get('CONEKTA_MODE') ? ' checked="checked"' : '').' /> Live
				<br /><br />
				<table cellspacing="0" cellpadding="0" class="conekta-settings">
					<tr>
						<td align="center" valign="middle" colspan="2">
							<table cellspacing="0" cellpadding="0" class="innerTable">
								<tr>
									<td align="right" valign="middle">'.$this->l('Test Public Key').'</td>
									<td align="left" valign="middle"><input type="text" name="conekta_public_key_test" value="'.Tools::safeOutput(Configuration::get('CONEKTA_PUBLIC_KEY_TEST')).'" /></td>
									<td width="15"></td>
									<td width="15" class="vertBorder"></td>
									<td align="left" valign="middle">'.$this->l('Live Public Key').'</td>
									<td align="left" valign="middle"><input type="text" name="conekta_public_key_live" value="'.Tools::safeOutput(Configuration::get('CONEKTA_PUBLIC_KEY_LIVE')).'" /></td>
								</tr>
								<tr>
									<td align="right" valign="middle">'.$this->l('Test Private Key').'</td>
									<td align="left" valign="middle"><input type="password" name="conekta_private_key_test" value="'.Tools::safeOutput(Configuration::get('CONEKTA_PRIVATE_KEY_TEST')).'" /></td>
									<td width="15"></td>
									<td width="15" class="vertBorder"></td>
									<td align="left" valign="middle">'.$this->l('Live Private Key').'</td>
									<td align="left" valign="middle"><input type="password" name="conekta_private_key_live" value="'.Tools::safeOutput(Configuration::get('CONEKTA_PRIVATE_KEY_LIVE')).'" /></td>
								</tr>
							</table>
						</td>
					</tr>';

					$statuses_options = array(array('name' => 'conekta_payment_status', 'label' => $this->l('Order status for sucessfull payment:'), 'current_value' => Configuration::get('CONEKTA_PAYMENT_ORDER_STATUS')));
					foreach ($statuses_options as $status_options)
					{
						$output .= '
						<tr>
							<td align="right" valign="middle"><label>'.$status_options['label'].'</label></td>
							<td align="left" valign="middle" class="td-right">
								<select name="'.$status_options['name'].'">';
									foreach ($statuses as $status)
										$output .= '<option value="'.(int)$status['id_order_state'].'"'.($status['id_order_state'] == $status_options['current_value'] ? ' selected="selected"' : '').'>'.Tools::safeOutput($status['name']).'</option>';
						$output .= '
								</select>
							</td>
						</tr>';
					}
            
					$output .= '
					<tr>
						<td colspan="2" class="td-noborder save"><input type="submit" class="button" name="SubmitConekta" value="'.$this->l('Save Settings').'" /></td>
					</tr>
				</table>
			</fieldset>
			<div class="clear"></div>
			<br />

		</div>
		</form>
		<script type="text/javascript">
			function updateConektaSettings()
			{
				if ($(\'input:radio[name=conekta_mode]:checked\').val() == 1)
					$(\'fieldset.conekta-cc-numbers\').hide();
				else
					$(\'fieldset.conekta-cc-numbers\').show(1000);

				if ($(\'input:radio[name=conekta_save_tokens]:checked\').val() == 1)
					$(\'tr.conekta_save_token_tr\').show(1000);
				else
					$(\'tr.conekta_save_token_tr\').hide();
			}

			$(\'input:radio[name=conekta_mode]\').click(function() { updateConektaSettings(); });
			$(\'input:radio[name=conekta_save_tokens]\').click(function() { updateConektaSettings(); });
			$(document).ready(function() { updateConektaSettings(); });
		</script>';

		return $output;
	}

	public function fetchTemplate($name)
	{
		if (version_compare(_PS_VERSION_, '1.4', '<'))
			$this->context->smarty->currentTemplate = $name;
		elseif (version_compare(_PS_VERSION_, '1.5', '<'))
		{
			$views = 'views/templates/';
			if (@filemtime(dirname(__FILE__).'/'.$name))
				return $this->display(__FILE__, $name);
			elseif (@filemtime(dirname(__FILE__).'/'.$views.'hook/'.$name))
				return $this->display(__FILE__, $views.'hook/'.$name);
			elseif (@filemtime(dirname(__FILE__).'/'.$views.'front/'.$name))
				return $this->display(__FILE__, $views.'front/'.$name);
			elseif (@filemtime(dirname(__FILE__).'/'.$views.'admin/'.$name))
				return $this->display(__FILE__, $views.'admin/'.$name);
		}

		return $this->display(__FILE__, $name);
	}

	public function pre($data) 
	{
		print '<pre>'.print_r($data, true).'</pre>';
	}

}
