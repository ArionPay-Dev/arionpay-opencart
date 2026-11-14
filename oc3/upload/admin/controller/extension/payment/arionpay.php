<?php
/**
 * ArionPay settings page for OpenCart 3 (Extensions > Extensions > Payments > ArionPay).
 */
class ControllerExtensionPaymentArionpay extends Controller {
	private $error = array();

	/** Settings that are secrets: never sent back to the browser, an empty field keeps the saved value. */
	private $secrets = array('payment_arionpay_api_secret', 'payment_arionpay_webhook_secret');

	public function index() {
		$this->load->language('extension/payment/arionpay');
		$this->document->setTitle($this->language->get('heading_title'));
		$this->load->model('setting/setting');
		$token = 'user_token=' . $this->session->data['user_token'];
		$saved = $this->model_setting_setting->getSetting('payment_arionpay');

		if ($this->request->server['REQUEST_METHOD'] == 'POST') {
			$post = $this->request->post;
			foreach ($this->secrets as $key) {
				$post[$key] = isset($post[$key]) ? trim((string)$post[$key]) : '';
				if ($post[$key] === '') {
					$post[$key] = isset($saved[$key]) ? (string)$saved[$key] : '';
				}
			}
			$post['payment_arionpay_api_key'] = isset($post['payment_arionpay_api_key']) ? trim((string)$post['payment_arionpay_api_key']) : '';
			$base = isset($post['payment_arionpay_base_url']) ? rtrim(trim((string)$post['payment_arionpay_base_url']), '/') : '';
			$post['payment_arionpay_base_url'] = $base !== '' ? $base : 'https://merchant.arionpay.com';
			if ($this->validate($post)) {
				$this->model_setting_setting->editSetting('payment_arionpay', $post);
				$this->session->data['success'] = $this->language->get('text_success');
				$this->response->redirect($this->url->link('extension/payment/arionpay', $token, true));
			}
			$saved = array_merge($saved, $post);
		}

		foreach (array('warning', 'api_key', 'api_secret', 'webhook_secret', 'base_url') as $field) {
			$data['error_' . $field] = isset($this->error[$field]) ? $this->error[$field] : '';
		}
		$data['success'] = '';
		if (isset($this->session->data['success'])) {
			$data['success'] = $this->session->data['success'];
			unset($this->session->data['success']);
		}

		$data['breadcrumbs'] = array(
			array('text' => $this->language->get('text_home'), 'href' => $this->url->link('common/dashboard', $token, true)),
			array('text' => $this->language->get('text_extension'), 'href' => $this->url->link('marketplace/extension', $token . '&type=payment', true)),
			array('text' => $this->language->get('heading_title'), 'href' => $this->url->link('extension/payment/arionpay', $token, true)),
		);
		$data['action'] = $this->url->link('extension/payment/arionpay', $token, true);
		$data['check'] = $this->url->link('extension/payment/arionpay/check', $token, true);
		$data['cancel'] = $this->url->link('marketplace/extension', $token . '&type=payment', true);

		$get = function ($key, $default = '') use ($saved) {
			return isset($saved[$key]) && $saved[$key] !== '' ? $saved[$key] : $default;
		};
		$data['payment_arionpay_api_key'] = (string)$get('payment_arionpay_api_key');
		$data['api_secret_saved'] = (string)$get('payment_arionpay_api_secret') !== '';
		$data['webhook_secret_saved'] = (string)$get('payment_arionpay_webhook_secret') !== '';
		$data['payment_arionpay_base_url'] = (string)$get('payment_arionpay_base_url', 'https://merchant.arionpay.com');
		$data['payment_arionpay_test'] = (int)$get('payment_arionpay_test', 0);
		$data['payment_arionpay_title'] = (string)$get('payment_arionpay_title');
		$data['payment_arionpay_pending_status_id'] = (int)$get('payment_arionpay_pending_status_id', 1);
		$data['payment_arionpay_paid_status_id'] = (int)$get('payment_arionpay_paid_status_id', 2);
		$data['payment_arionpay_expired_status_id'] = (int)$get('payment_arionpay_expired_status_id', 0);
		$data['payment_arionpay_geo_zone_id'] = (int)$get('payment_arionpay_geo_zone_id', 0);
		$data['payment_arionpay_status'] = (int)$get('payment_arionpay_status', 0);
		$data['payment_arionpay_sort_order'] = (string)$get('payment_arionpay_sort_order');
		$data['payment_arionpay_debug'] = (int)$get('payment_arionpay_debug', 0);

		$this->load->model('localisation/order_status');
		$data['order_statuses'] = $this->model_localisation_order_status->getOrderStatuses();
		$this->load->model('localisation/geo_zone');
		$data['geo_zones'] = $this->model_localisation_geo_zone->getGeoZones();

		$catalog = (defined('HTTPS_CATALOG') && strpos(HTTPS_CATALOG, 'https://') === 0) ? HTTPS_CATALOG : HTTP_CATALOG;
		$data['webhook_url'] = $catalog . 'index.php?route=extension/payment/arionpay/callback';
		$data['webhook_not_https'] = strpos($catalog, 'https://') !== 0;

		$data['header'] = $this->load->controller('common/header');
		$data['column_left'] = $this->load->controller('common/column_left');
		$data['footer'] = $this->load->controller('common/footer');
		$this->response->setOutput($this->load->view('extension/payment/arionpay', $data));
	}

	/**
	 * "Check connection": a signed read of an invoice that cannot exist answers 404 with good keys and 401 with wrong ones.
	 */
	public function check() {
		$this->load->language('extension/payment/arionpay');
		$this->load->model('setting/setting');
		$json = array();
		if (!$this->user->hasPermission('modify', 'extension/payment/arionpay')) {
			$json['error'] = $this->language->get('error_permission');
		} else {
			$saved = $this->model_setting_setting->getSetting('payment_arionpay');
			$key = isset($this->request->post['payment_arionpay_api_key']) ? trim($this->request->post['payment_arionpay_api_key']) : '';
			$secret = isset($this->request->post['payment_arionpay_api_secret']) ? trim($this->request->post['payment_arionpay_api_secret']) : '';
			$base = isset($this->request->post['payment_arionpay_base_url']) ? rtrim(trim($this->request->post['payment_arionpay_base_url']), '/') : '';
			$key = $key !== '' ? $key : (isset($saved['payment_arionpay_api_key']) ? $saved['payment_arionpay_api_key'] : '');
			$secret = $secret !== '' ? $secret : (isset($saved['payment_arionpay_api_secret']) ? $saved['payment_arionpay_api_secret'] : '');
			$base = $base !== '' ? $base : 'https://merchant.arionpay.com';
			require_once DIR_SYSTEM . 'library/arionpay-php/autoload.php';
			try {
				$client = new \ArionPay\Client((string)$key, (string)$secret, array('baseUrl' => $base, 'timeout' => 15, 'maxAttempts' => 1, 'appInfo' => 'opencart/' . VERSION));
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

	public function install() {
		$this->load->model('extension/payment/arionpay');
		$this->model_extension_payment_arionpay->install();
		$this->load->model('setting/setting');
		$this->model_setting_setting->editSetting('payment_arionpay', array(
			'payment_arionpay_base_url' => 'https://merchant.arionpay.com',
			'payment_arionpay_test' => 1,
			'payment_arionpay_pending_status_id' => 1,
			'payment_arionpay_paid_status_id' => 2,
			'payment_arionpay_expired_status_id' => 0,
			'payment_arionpay_status' => 0,
		));
	}

	public function uninstall() {
		// The invoice table is kept on purpose: a late payment can still arrive after a reinstall.
	}

	private function validate(array $post) {
		if (!$this->user->hasPermission('modify', 'extension/payment/arionpay')) {
			$this->error['warning'] = $this->language->get('error_permission');
		}
		if (!preg_match('/^pk_[A-Za-z0-9_-]{8,64}$/', $post['payment_arionpay_api_key'])) {
			$this->error['api_key'] = $this->language->get('error_api_key');
		}
		if (strpos($post['payment_arionpay_api_secret'], 'sk_') !== 0) {
			$this->error['api_secret'] = $this->language->get('error_api_secret');
		}
		if (strpos($post['payment_arionpay_webhook_secret'], 'whsec_') !== 0) {
			$this->error['webhook_secret'] = $this->language->get('error_webhook_secret');
		}
		if (!preg_match('#^https?://[^\s/]+#i', $post['payment_arionpay_base_url'])) {
			$this->error['base_url'] = $this->language->get('error_base_url');
		}
		if (isset($post['payment_arionpay_paid_status_id'], $post['payment_arionpay_pending_status_id']) && (int)$post['payment_arionpay_paid_status_id'] === (int)$post['payment_arionpay_pending_status_id']) {
			$this->error['warning'] = $this->language->get('error_same_status');
		}
		return !$this->error;
	}
}
