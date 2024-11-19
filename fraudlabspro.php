<?php

/**
 * @copyright 2024 FraudLabsPro.com
 * @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
 */
if (!defined('_PS_VERSION_')) {
	exit;
}

class fraudlabspro extends Module
{
	protected $_html = '';
	protected $_postErrors = [];

	public function __construct()
	{
		$this->name = 'fraudlabspro';
		$this->tab = 'payment_security';
		$this->version = '1.1.2';
		$this->author = 'FraudLabs Pro';
		$this->controllers = ['payment', 'validation'];
		$this->module_key = '3122a09eb6886205eaef0857a9d9d077';

		$this->bootstrap = true;
		parent::__construct();

		$this->displayName = $this->l('FraudLabs Pro Fraud Prevention');
		$this->description = $this->l('FraudLabs Pro screens transaction for online frauds to protect your store from fraud attempts.');
	}

	public function install()
	{
		if (!parent::install() || !$this->registerHook('newOrder') || !$this->registerHook('adminOrder') || !$this->registerHook('cart') || !$this->registerHook('footer')) {
			return false;
		}

		Configuration::updateValue('FLP_ENABLED', '1');
		Configuration::updateValue('FLP_LICENSE_KEY', '');

		Db::getInstance()->Execute('
		CREATE TABLE `' . _DB_PREFIX_ . 'orders_fraudlabspro` (
			`id_order` INT(10) UNSIGNED NOT NULL,
			`api_response` TEXT NOT NULL COLLATE \'utf8_bin\',
			`status` VARCHAR(10) NOT NULL COLLATE \'utf8_bin\',
			`is_blacklisted` CHAR(1) NOT NULL DEFAULT \'0\' COLLATE \'utf8_bin\',
			INDEX `id_order` (`id_order`) USING BTREE
		) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8;');

		Db::getInstance()->Execute('CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'flp_order_ip` (
			`id_cart` INT NOT NULL,
			`ip` VARCHAR(39) NOT NULL,
			PRIMARY KEY (`id_cart`)
		) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8;');

		return true;
	}

	public function getContent()
	{
		if (Tools::isSubmit('btnSubmit')) {
			$this->_postValidation();
			if (!count($this->_postErrors)) {
				$this->_postProcess();
			} else {
				foreach ($this->_postErrors as $err) {
					$this->_html .= $this->displayError($err);
				}
			}
		} else {
			$this->_html .= '<br />';
		}

		$this->_html .= $this->renderForm();

		return $this->_html;
	}

	public function hookCart($params)
	{
		if (!Validate::isLoadedObject($params['cart'])) {
			return;
		}

		$ip = $this->getIP();

		// Validate IP address
		if (!filter_var($ip, FILTER_VALIDATE_IP)) {
			return; // Exit if IP is invalid
		}

		// Securely insert into the database
		Db::getInstance()->execute(
			'INSERT IGNORE INTO `' . _DB_PREFIX_ . 'flp_order_ip` (`id_cart`, `ip`) VALUES (' . (int) $params['cart']->id . ', "' . pSQL($ip) . '")'
		);
	}

	public function hookNewOrder($params)
	{
		if (!Configuration::get('PS_SHOP_ENABLE') || !Configuration::get('FLP_LICENSE_KEY') || !Configuration::get('FLP_ENABLED')) {
			return;
		}

		$customer = new Customer((int) $params['order']->id_customer);
		$address_delivery = new Address((int) $params['order']->id_address_delivery);
		$address_invoice = new Address((int) $params['order']->id_address_invoice);
		$default_currency = new Currency((int) Configuration::get('PS_CURRENCY_DEFAULT'));
		$cart = new Cart((int) $params['order']->id_cart);
		$cart_rules = $cart->getCartRules();

		if ($address_delivery->id_state !== null || $address_delivery->id_state != '') {
			$delivery_state = new State((int) $address_delivery->id_state);
		}

		$product_list = $params['order']->getProductsDetail();
		$items = [];
		$quantity = 0;
		foreach ($product_list as $product) {
			$quantity += $product['product_quantity'];
			$items[] = $product['product_reference'] . ':' . $product['price'] . ':' . ((empty($product['download_hash'])) ? 'physical' : 'downloadable');
		}

		$coupon_code = '';

		foreach ($cart_rules as $rule) {
			if (!empty($rule['code'])) {
				$coupon_code = $rule['code'];
				break;
			}
		}

		$ip = Db::getInstance()->getValue('SELECT `ip` FROM  `' . _DB_PREFIX_ . 'flp_order_ip` WHERE `id_cart` = "' . ((int) $params['cart']->id) . '"');
		$ip = (!$ip) ? $this->getIP() : $ip;

		$bill_state = '';

		if ($address_invoice->id_state !== null or $address_invoice->id_state != '') {
			$State = new State((int) $address_invoice->id_state);
			$bill_state = $State->iso_code;
		}

		$guzzle = new GuzzleHttp\Client([
			'timeout' => 60,
			'verify'  => Configuration::getSslTrustStore(),
		]);

		$attempts = 0;
		do {
			try {
				$body = $guzzle->post('https://api.fraudlabspro.com/v2/order/screen', [
					'form_params' => [
						'key'             => Configuration::get('FLP_LICENSE_KEY'),
						'ip'              => $ip,
						'first_name'      => $address_invoice->firstname,
						'last_name'       => $address_invoice->lastname,
						'bill_city'       => $address_invoice->city,
						'bill_state'      => $bill_state,
						'bill_country'    => Country::getIsoById((int) $address_invoice->id_country),
						'bill_zip_code'   => $address_invoice->postcode,
						'email_domain'    => substr($customer->email, strpos($customer->email, '@') + 1),
						'email_hash'      => $this->hastIt($customer->email),
						'email'           => $customer->email,
						'user_phone'      => $address_invoice->phone,
						'ship_addr'       => trim($address_delivery->address1 . ' ' . $address_delivery->address2),
						'ship_city'       => $address_delivery->city,
						'ship_state'      => (Tools::getIsset($delivery_state->iso_code)) ? $delivery_state->iso_code : '',
						'ship_zip_code'   => $address_delivery->postcode,
						'ship_country'    => Country::getIsoById((int) $address_delivery->id_country),
						'amount'          => $params['order']->total_paid,
						'quantity'        => $quantity,
						'currency'        => $default_currency->iso_code,
						'user_order_id'   => $params['order']->id,
						'items'           => implode(',', $items),
						'coupon_code'     => $coupon_code,
						'payment_gateway' => $params['order']->payment,
						'flp_checksum'    => Context::getContext()->cookie->flp_checksum,
						'format'          => 'json',
						'source'          => 'thirtybees',
						'source_version'  => $this->version,
					],
				])->getBody();
			} catch (GuzzleException $e) {
				++$attempts;

				// Wait for 3 seconds for next attempt
				sleep(3);
				continue;
			}

			// End the loop
			break;
		} while ($attempts < 3);

		if (($json = json_decode($body)) === null) {
			return true;
		}

		Db::getInstance()->execute('INSERT INTO `' . _DB_PREFIX_ . 'orders_fraudlabspro` (`id_order`, `api_response`, `status`, `is_blacklisted`) VALUES (' . (int) $params['order']->id . ', \'' . $body . '\', "' . $json->fraudlabspro_status . '", "0")');

		if (Configuration::get('FLP_APPROVE_STATUS_ID') && $json->fraudlabspro_status == 'APPROVE') {
			$history = new OrderHistory();
			$history->id_order = $params['order']->id;
			$history->changeIdOrderState((int) Configuration::get('FLP_APPROVE_STATUS_ID'), $params['order'], true);
			$history->add();
		}

		if (Configuration::get('FLP_REVIEW_STATUS_ID') && $json->fraudlabspro_status == 'REVIEW') {
			$history = new OrderHistory();
			$history->id_order = $params['order']->id;
			$history->changeIdOrderState((int) Configuration::get('FLP_REVIEW_STATUS_ID'), $params['order'], true);
			$history->add();
		}

		if (Configuration::get('FLP_REJECT_STATUS_ID') && $json->fraudlabspro_status == 'REJECT') {
			$history = new OrderHistory();
			$history->id_order = $params['order']->id;
			$history->changeIdOrderState((int) Configuration::get('FLP_REJECT_STATUS_ID'), $params['order'], true);
			$history->add();
		}

		return true;
	}

	public function hookFooter()
	{
		return $this->display(__FILE__, 'footer.tpl');
	}

	public function hookAdminOrder($params)
	{
		if (Tools::getValue('transactionId')) {
			if (Tools::getValue('approve')) {
				if ($this->feedback('APPROVE', Tools::getValue('transactionId'))) {
					Db::getInstance()->execute('UPDATE `' . _DB_PREFIX_ . 'orders_fraudlabspro` SET `status` = \'APPROVE\' WHERE id_order = ' . (int) $params['id_order'] . ' LIMIT 1');
				}
			} elseif (Tools::getValue('reject')) {
				if ($this->feedback('REJECT', Tools::getValue('transactionId'))) {
					Db::getInstance()->execute('UPDATE `' . _DB_PREFIX_ . 'orders_fraudlabspro` SET `status` = \'REJECT\' WHERE id_order = ' . (int) $params['id_order'] . ' LIMIT 1');
				}
			} elseif (Tools::getValue('blacklist')) {
				if ($this->feedback('REJECT_BLACKLIST', Tools::getValue('transactionId'), Tools::getValue('reason'))) {
					Db::getInstance()->execute('UPDATE `' . _DB_PREFIX_ . 'orders_fraudlabspro` SET `status` = \'REJECT\', `is_blacklisted` = "1" WHERE id_order = ' . (int) $params['id_order'] . ' LIMIT 1');
				}
			}
		}

		$row = Db::getInstance()->getRow('SELECT * FROM `' . _DB_PREFIX_ . 'orders_fraudlabspro` WHERE `id_order` = ' . (int) $params['id_order']);

		if ($row) {
			$json = json_decode($row['api_response']);

			$location = [
				$json->ip_geolocation->continent, $json->ip_geolocation->country_name, $json->ip_geolocation->region, $json->ip_geolocation->city,
			];

			$location = implode(', ', array_unique(array_diff($location, [''])));

			$this->smarty->assign([
				'no_result'                  => false,
				'fraud_score'                => $json->fraudlabspro_score,
				'fraud_status'               => '<span style="font-size:1.5em;font-weight:bold;color:#' . (($row['status'] == 'APPROVE') ? '339933' : (($row['status'] == 'REVIEW') ? 'ff7f27' : 'f00')) . '">' . (($row['status'] == 'APPROVE') ? 'APPROVED' : (($row['status'] == 'REJECT') ? 'REJECTED' : $row['status'])) . '</span>',
				'remaining_credits'          => number_format($json->remaining_credits, 0, '', ','),
				'client_ip'                  => $json->ip_geolocation->ip,
				'location'                   => $location,
				'coordinates'                => ($json->ip_geolocation->latitude !== null) ? ($json->ip_geolocation->latitude . ', ' . $json->ip_geolocation->longitude) : 'N/A',
				'isp'                        => ($json->ip_geolocation->isp_name !== null) ? $json->ip_geolocation->isp_name : 'N/A',
				'domain'                     => ($json->ip_geolocation->domain !== null) ? $json->ip_geolocation->domain : 'N/A',
				'net_speed'                  => ($json->ip_geolocation->netspeed !== null) ? $json->ip_geolocation->netspeed : 'N/A',
				'is_proxy'                   => ($json->ip_geolocation->is_proxy !== null) ? (($json->ip_geolocation->is_proxy) ? 'Yes' : 'No') : 'N/A',
				'usage_type'                 => implode(', ', $json->ip_geolocation->usage_type),
				'time_zone'                  => ($json->ip_geolocation->timezone !== null) ? ('UTC ' . $json->ip_geolocation->timezone) : 'N/A',
				'distance'                   => ($json->billing_address->ip_distance_in_mile !== null) ? ($json->billing_address->ip_distance_in_mile . ' Miles') : 'N/A',
				'is_free_email'              => ($json->email_address->is_free !== null) ? (($json->email_address->is_free) ? 'Yes' : 'No') : 'N/A',
				'is_ship_forward'            => ($json->shipping_address->is_address_ship_forward !== null) ? (($json->shipping_address->is_address_ship_forward) ? 'Yes' : 'No') : 'N/A',
				'is_email_blacklist'         => ($json->email_address->is_in_blacklist !== null) ? (($json->email_address->is_in_blacklist) ? 'Yes' : 'No') : 'N/A',
				'is_card_blacklist'          => ($json->credit_card->is_in_blacklist !== null) ? (($json->credit_card->is_in_blacklist) ? 'Yes' : 'No') : 'N/A',
				'is_bin_found'               => ($json->credit_card->is_bin_exist !== null) ? (($json->credit_card->is_bin_exist) ? 'Yes' : 'No') : 'N/A',
				'is_ip_blacklist'            => ($json->ip_geolocation->is_in_blacklist !== null) ? (($json->ip_geolocation->is_in_blacklist) ? 'Yes' : 'No') : 'N/A',
				'is_device_blacklist'        => ($json->device->is_in_blacklist !== null) ? (($json->device->is_in_blacklist) ? 'Yes' : 'No') : 'N/A',
				'triggered_rules'            => implode(', ', $json->fraudlabspro_rules),
				'transaction_id'             => $json->fraudlabspro_id,
				'error_message'              => (isset($json->error->error_message)) ? $json->error->error_message : '',
				'show_approve_reject_button' => ($row['status'] == 'REVIEW') ? true : false,
				'show_blacklist_button'      => ($row['is_blacklisted']) ? false : true,
			]);
		} else {
			$this->smarty->assign([
				'no_result' => true,
			]);
		}

		return $this->display(__FILE__, 'admin_order.tpl');
	}

	public function renderForm()
	{
		$orderStates = OrderState::getOrderStates((int) $this->context->language->id);

		$orderStatuses = [
			[
				'id_order_state' => 0,
				'name'           => '',
			],
		];

		foreach ($orderStates as $orderState) {
			$orderStatuses[] = [
				'id_order_state' => (int) $orderState['id_order_state'],
				'name'           => $orderState['name'],
			];
		}

		$fields_form = [
			'form' => [
				'legend' => [
					'title' => $this->l('Settings'),
					'icon'  => 'icon-cog',
				],
				'input' => [
					[
						'type'   => 'checkbox',
						'name'   => 'FLP_ENABLED',
						'values' => [
							'query' => [
								[
									'id'   => 'on',
									'name' => $this->l('Enable'),
									'val'  => '1',
								],
							],
							'id'   => 'id',
							'name' => 'name',
						],
					],
					[
						'type'     => 'text',
						'label'    => $this->l('API Key'),
						'name'     => 'FLP_LICENSE_KEY',
						'desc'     => $this->l('Enter your FraudLabs Pro API key. You can register a free license key at https://www.fraudlabspro.com/sign-up if you do not have one.'),
						'required' => true,
					],
					[
						'type'     => 'select',
						'label'    => $this->l('Approve Status'),
						'name'     => 'FLP_APPROVE_STATUS_ID',
						'required' => false,
						'options'  => [
							'query' => $orderStatuses,
							'id'    => 'id_order_state',
							'name'  => 'name',
						],
						'desc' => $this->l('Change order to this state if marked as Approve by FraudLabs Pro.'),
					],
					[
						'type'     => 'select',
						'label'    => $this->l('Review Status'),
						'name'     => 'FLP_REVIEW_STATUS_ID',
						'required' => false,
						'options'  => [
							'query' => $orderStatuses,
							'id'    => 'id_order_state',
							'name'  => 'name',
						],
						'desc' => $this->l('Change order to this state if marked as Review by FraudLabs Pro.'),
					],
					[
						'type'     => 'select',
						'label'    => $this->l('Reject Status'),
						'name'     => 'FLP_REJECT_STATUS_ID',
						'required' => false,
						'options'  => [
							'query' => $orderStatuses,
							'id'    => 'id_order_state',
							'name'  => 'name',
						],
						'desc' => $this->l('Change order to this state if marked as Reject by FraudLabs Pro.'),
					],
					[
						'type'   => 'checkbox',
						'name'   => 'FLP_GET_FORWARDED_IP',
						'values' => [
							'query' => [
								[
									'id'   => 'on',
									'name' => $this->l('Get forwarded IP address.'),
									'val'  => '1',
								],
							],
							'id'   => 'id',
							'name' => 'name',
						],
						'desc' => $this->l('Enable this option if FraudLabs Pro cannot detect correct IP address in your order.'),
					],
					[
						'type'   => 'checkbox',
						'name'   => 'FLP_PURGE',
						'values' => [
							'query' => [
								[
									'id'   => 'on',
									'name' => $this->l('Enable this option only if you want to erase all FraudLabs Pro data. The data will be permanently erased upon clicking the SAVE button.'),
									'val'  => '1',
								],
							],
							'id'   => 'id',
							'name' => 'name',
						],
					],
				],
				'submit' => [
					'title' => $this->l('Save'),
				],
			],
		];

		$helper = new HelperForm();
		$helper->show_toolbar = false;
		$helper->table = $this->table;
		$lang = new Language((int) Configuration::get('PS_LANG_DEFAULT'));
		$helper->default_form_language = $lang->id;
		$helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') ? Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') : 0;
		$this->fields_form = [];
		$helper->id = (int) Tools::getValue('id_carrier');
		$helper->identifier = $this->identifier;
		$helper->submit_action = 'btnSubmit';
		$helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false) . '&configure=' . $this->name . '&tab_module=' . $this->tab . '&module_name=' . $this->name;
		$helper->token = Tools::getAdminTokenLite('AdminModules');
		$helper->tpl_vars = [
			'fields_value' => $this->getConfigFieldsValues(),
			'languages'    => $this->context->controller->getLanguages(),
			'id_language'  => $this->context->language->id,
		];

		return $helper->generateForm([$fields_form]);
	}

	public function getConfigFieldsValues()
	{
		return [
			'FLP_ENABLED_on'          => Tools::getValue('FLP_ENABLED_on', Configuration::get('FLP_ENABLED')),
			'FLP_LICENSE_KEY'         => Tools::getValue('FLP_LICENSE_KEY', Configuration::get('FLP_LICENSE_KEY')),
			'FLP_APPROVE_STATUS_ID'   => Tools::getValue('FLP_APPROVE_STATUS_ID', Configuration::get('FLP_APPROVE_STATUS_ID')),
			'FLP_REVIEW_STATUS_ID'    => Tools::getValue('FLP_REVIEW_STATUS_ID', Configuration::get('FLP_REVIEW_STATUS_ID')),
			'FLP_REJECT_STATUS_ID'    => Tools::getValue('FLP_REJECT_STATUS_ID', Configuration::get('FLP_REJECT_STATUS_ID')),
			'FLP_GET_FORWARDED_IP_on' => Tools::getValue('FLP_GET_FORWARDED_IP_on', Configuration::get('FLP_GET_FORWARDED_IP')),
			'FLP_PURGE_on'            => Tools::getValue('FLP_PURGE_on', Configuration::get('FLP_PURGE')),
		];
	}

	protected function _postProcess()
	{
		if (Tools::isSubmit('btnSubmit')) {
			Configuration::updateValue('FLP_ENABLED', Tools::getValue('FLP_ENABLED_on'));
			Configuration::updateValue('FLP_LICENSE_KEY', Tools::getValue('FLP_LICENSE_KEY'));
			Configuration::updateValue('FLP_APPROVE_STATUS_ID', Tools::getValue('FLP_APPROVE_STATUS_ID'));
			Configuration::updateValue('FLP_REVIEW_STATUS_ID', Tools::getValue('FLP_REVIEW_STATUS_ID'));
			Configuration::updateValue('FLP_REJECT_STATUS_ID', Tools::getValue('FLP_REJECT_STATUS_ID'));
			Configuration::updateValue('FLP_GET_FORWARDED_IP', Tools::getValue('FLP_GET_FORWARDED_IP_on'));

			if (Tools::getValue('FLP_PURGE_on') == '1') {
				Db::getInstance()->Execute('TRUNCATE TABLE `' . _DB_PREFIX_ . 'orders_fraudlabspro`');
				$this->_html .= $this->displayConfirmation($this->l('FraudLabs Pro records cleared.'));
			}
		}

		$this->_html .= $this->displayConfirmation($this->l('Settings updated'));
	}

	protected function _postValidation()
	{
		if (Tools::isSubmit('btnSubmit')) {
			if (!Tools::getValue('FLP_LICENSE_KEY')) {
				$this->_postErrors[] = $this->l('FraudLabs Pro API key is required.');
			}
		}
	}

	private function feedback($action, $id, $note = '')
	{
		$guzzle = new GuzzleHttp\Client([
			'timeout' => 60,
			'verify'  => Configuration::getSslTrustStore(),
		]);

		$attempts = 0;

		do {
			try {
				$guzzle->post(
					'https://api.fraudlabspro.com/v2/order/feedback',
					[
						'form_params' => [
							'key'    => Configuration::get('FLP_LICENSE_KEY'),
							'action' => $action,
							'id'     => $id,
							'note'   => $note,
							'format' => 'json',
						],
					]
				);
			} catch (GuzzleException $e) {
				++$attempts;
				sleep(1);
				continue;
			}

			// End the loop
			break;
		} while ($attempts < 3);

		return true;
	}

	private function getIP()
	{
		// For development usage
		if (isset($_SERVER['DEV_MODE'])) {
			do {
				$ip = mt_rand(0, 255) . '.' . mt_rand(0, 255) . '.' . mt_rand(0, 255) . '.' . mt_rand(0, 255);
			} while (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE));

			return $ip;
		}

		if (Configuration::get('FLP_GET_FORWARDED_IP')) {
			$headers = [
				'HTTP_CF_CONNECTING_IP', 'X-Real-IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_INCAP_CLIENT_IP', 'HTTP_X_SUCURI_CLIENTIP',
			];

			foreach ($headers as $header) {
				if (!isset($_SERVER[$header])) {
					continue;
				}

				if (!filter_var($_SERVER[$header], FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
					continue;
				}

				return $_SERVER[$header];
			}
		}

		return $_SERVER['REMOTE_ADDR'];
	}

	private function hastIt($s, $prefix = 'fraudlabspro_')
	{
		$hash = $prefix . $s;
		for ($i = 0; $i < 65536; ++$i) {
			$hash = sha1($prefix . $hash);
		}

		return $hash;
	}
}
