<?php
namespace Opencart\Admin\Controller\Extension\Arionpay\Payment;

/**
 * ArionPay settings page (Extensions > Extensions > Payments > ArionPay).
 */
class Arionpay extends \Opencart\System\Engine\Controller {
	private const ROUTE = 'extension/arionpay/payment/arionpay';

	/** Settings that are secrets: never sent back to the browser, an empty field keeps the saved value. */
	private const SECRETS = ['payment_arionpay_api_secret', 'payment_arionpay_webhook_secret'];

	public function index(): void {
		$this->load->language(self::ROUTE);
		$this->document->setTitle($this->language->get('heading_title'));
		$token = 'user_token=' . $this->session->data['user_token'];

		$data['breadcrumbs'] = [
			['text' => $this->language->get('text_home'), 'href' => $this->url->link('common/dashboard', $token)],
			['text' => $this->language->get('text_extension'), 'href' => $this->url->link('marketplace/extension', $token . '&type=payment')],
			['text' => $this->language->get('heading_title'), 'href' => $this->url->link(self::ROUTE, $token)],
		];
		$data['save'] = $this->url->link(self::ROUTE . $this->separator() . 'save', $token);
		$data['check'] = $this->url->link(self::ROUTE . $this->separator() . 'check', $token);
		$data['back'] = $this->url->link('marketplace/extension', $token . '&type=payment');

		$data['payment_arionpay_api_key'] = (string)$this->config->get('payment_arionpay_api_key');
		$data['api_secret_saved'] = (string)$this->config->get('payment_arionpay_api_secret') !== '';
		$data['webhook_secret_saved'] = (string)$this->config->get('payment_arionpay_webhook_secret') !== '';
		$data['payment_arionpay_base_url'] = (string)($this->config->get('payment_arionpay_base_url') ?: 'https://merchant.arionpay.com');
		$data['payment_arionpay_test'] = (int)$this->config->get('payment_arionpay_test');
		$data['payment_arionpay_title'] = (string)$this->config->get('payment_arionpay_title');
		$data['payment_arionpay_pending_status_id'] = (int)($this->config->get('payment_arionpay_pending_status_id') ?: 1);
		$data['payment_arionpay_paid_status_id'] = (int)($this->config->get('payment_arionpay_paid_status_id') ?: 2);
		$data['payment_arionpay_expired_status_id'] = (int)$this->config->get('payment_arionpay_expired_status_id');
		$data['payment_arionpay_geo_zone_id'] = (int)$this->config->get('payment_arionpay_geo_zone_id');
		$data['payment_arionpay_status'] = (int)$this->config->get('payment_arionpay_status');
		$data['payment_arionpay_sort_order'] = (string)$this->config->get('payment_arionpay_sort_order');
		$data['payment_arionpay_debug'] = (int)$this->config->get('payment_arionpay_debug');

		$this->load->model('localisation/order_status');
		$data['order_statuses'] = $this->model_localisation_order_status->getOrderStatuses();
		$this->load->model('localisation/geo_zone');
		$data['geo_zones'] = $this->model_localisation_geo_zone->getGeoZones();

		$data['webhook_url'] = HTTP_CATALOG . 'index.php?route=' . self::ROUTE . $this->separator() . 'callback';
		$data['webhook_not_https'] = strpos(HTTP_CATALOG, 'https://') !== 0;

		$data['header'] = $this->load->controller('common/header');
		$data['column_left'] = $this->load->controller('common/column_left');
		$data['footer'] = $this->load->controller('common/footer');
		$this->response->setOutput($this->load->view(self::ROUTE, $data));
	}

	public function save(): void {
		$this->load->language(self::ROUTE);
		$json = [];
		if (!$this->user->hasPermission('modify', self::ROUTE)) {
			$json['error']['warning'] = $this->language->get('error_permission');
		}
		$post = $this->request->post;
		$saved = $this->model_setting_setting_get();
		foreach (self::SECRETS as $key) {
			$post[$key] = trim((string)($post[$key] ?? ''));
			if ($post[$key] === '') {
				$post[$key] = (string)($saved[$key] ?? '');
			}
		}
		$post['payment_arionpay_api_key'] = trim((string)($post['payment_arionpay_api_key'] ?? ''));
		$post['payment_arionpay_base_url'] = rtrim(trim((string)($post['payment_arionpay_base_url'] ?? '')), '/') ?: 'https://merchant.arionpay.com';

		if (!preg_match('/^pk_[A-Za-z0-9_-]{8,64}$/', $post['payment_arionpay_api_key'])) {
			$json['error']['api_key'] = $this->language->get('error_api_key');
		}
		if (strpos($post['payment_arionpay_api_secret'], 'sk_') !== 0) {
			$json['error']['api_secret'] = $this->language->get('error_api_secret');
		}
		if (strpos($post['payment_arionpay_webhook_secret'], 'whsec_') !== 0) {
			$json['error']['webhook_secret'] = $this->language->get('error_webhook_secret');
		}
		if ((int)($post['payment_arionpay_paid_status_id'] ?? 0) === (int)($post['payment_arionpay_pending_status_id'] ?? 0)) {
			$json['error']['warning'] = $this->language->get('error_same_status');
		}
		if (!preg_match('#^https?://[^\s/]+#i', $post['payment_arionpay_base_url'])) {
			$json['error']['base_url'] = $this->language->get('error_base_url');
		}

		if (!$json) {
			$this->load->model('setting/setting');
			$this->model_setting_setting->editSetting('payment_arionpay', $post);
			$json['success'] = $this->language->get('text_success');
		}
		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($json));
	}

	/**
	 * "Check connection": proves that ArionPay can be reached and that the keys are right, without creating anything.
	 * A signed read of an invoice that cannot exist answers 404 with good keys and 401 with wrong ones.
	 */
	public function check(): void {
		$this->load->language(self::ROUTE);
		$json = [];
		if (!$this->user->hasPermission('modify', self::ROUTE)) {
			$json['error'] = $this->language->get('error_permission');
		} else {
			$saved = $this->model_setting_setting_get();
			$key = trim((string)($this->request->post['payment_arionpay_api_key'] ?? '')) ?: (string)($saved['payment_arionpay_api_key'] ?? '');
			$secret = trim((string)($this->request->post['payment_arionpay_api_secret'] ?? '')) ?: (string)($saved['payment_arionpay_api_secret'] ?? '');
			$base = rtrim(trim((string)($this->request->post['payment_arionpay_base_url'] ?? '')), '/') ?: 'https://merchant.arionpay.com';
			require_once DIR_EXTENSION . 'arionpay/system/library/arionpay-php/autoload.php';
			try {
				$client = new \ArionPay\Client($key, $secret, ['baseUrl' => $base, 'timeout' => 15, 'maxAttempts' => 1, 'appInfo' => 'opencart/' . VERSION]);
				try {
					$client->getInvoice('000000000000000000000000');
					$json['success'] = $this->language->get('text_check_ok');
				} catch (\ArionPay\ApiException $e) {
					if ($e->getStatus() === 404) {
						$json['success'] = $this->language->get('text_check_ok');
					} elseif ($e->getStatus() === 401 || $e->getStatus() === 403) {
						$json['error'] = sprintf($this->language->get('error_check_keys'), $e->getMessage());
					} else {
						$json['error'] = sprintf($this->language->get('error_check_other'), $e->getMessage());
					}
				}
			} catch (\InvalidArgumentException $e) {
				$json['error'] = $e->getMessage();
			}
		}
		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($json));
	}

	public function install(): void {
		if ($this->user->hasPermission('modify', 'extension/payment')) {
			$this->load->model(self::ROUTE);
			$this->model_extension_arionpay_payment_arionpay->install();
			$this->load->model('setting/setting');
			$this->model_setting_setting->editSetting('payment_arionpay', [
				'payment_arionpay_base_url' => 'https://merchant.arionpay.com',
				'payment_arionpay_test' => 1,
				'payment_arionpay_pending_status_id' => 1,
				'payment_arionpay_paid_status_id' => 2,
				'payment_arionpay_expired_status_id' => 0,
				'payment_arionpay_status' => 0,
			]);
		}
	}

	public function uninstall(): void {
		// The invoice table is kept on purpose: a late payment can still arrive after a reinstall.
	}

	/** @return array<string,mixed> */
	private function model_setting_setting_get(): array {
		$this->load->model('setting/setting');
		return $this->model_setting_setting->getSetting('payment_arionpay');
	}

	/** OpenCart 4.0.2.0 and newer use "." between a route and its method; 4.0.0 and 4.0.1 used "|". */
	private function separator(): string {
		return version_compare(VERSION, '4.0.2.0', '>=') ? '.' : '|';
	}
}
