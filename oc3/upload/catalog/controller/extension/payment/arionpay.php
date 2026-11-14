<?php
/**
 * OpenCart 3: checkout (confirm button -> ArionPay payment page) and the webhook endpoint.
 */
class ControllerExtensionPaymentArionpay extends Controller {
	const MAX_BODY_BYTES = 262144;

	public function index() {
		$this->load->language('extension/payment/arionpay');
		$data['test'] = (bool)$this->config->get('payment_arionpay_test');
		return $this->load->view('extension/payment/arionpay', $data);
	}

	/** Make (or reuse) the ArionPay invoice for the session's order and send the customer there. */
	public function confirm() {
		$this->load->language('extension/payment/arionpay');
		$json = array();

		if (!isset($this->session->data['order_id'])) {
			$json['error'] = $this->language->get('error_order');
		} elseif (!isset($this->session->data['payment_method']['code']) || $this->session->data['payment_method']['code'] !== 'arionpay') {
			$json['error'] = $this->language->get('error_payment_method');
		}

		if (!$json) {
			$this->load->model('checkout/order');
			$this->load->model('extension/payment/arionpay');
			$order = $this->model_checkout_order->getOrder((int)$this->session->data['order_id']);
			if (!$order) {
				$json['redirect'] = $this->url->link('checkout/cart', '', true);
			} else {
				try {
					$json['redirect'] = $this->invoiceUrl($order);
				} catch (\Throwable $e) {
					$this->log('Could not create an ArionPay invoice for order ' . (int)$order['order_id'] . ': ' . $e->getMessage());
					$json['error'] = $this->language->get('error_gateway');
				}
			}
		}

		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($json));
	}

	/**
	 * Webhook endpoint: index.php?route=extension/payment/arionpay/callback
	 * ArionPay retries anything that is not 2xx for about 3 days.
	 */
	public function callback() {
		$this->load->language('extension/payment/arionpay');
		if (!isset($this->request->server['REQUEST_METHOD']) || $this->request->server['REQUEST_METHOD'] !== 'POST') {
			$this->respond(405, array('error' => 'method_not_allowed'));
			return;
		}
		$secret = (string)$this->config->get('payment_arionpay_webhook_secret');
		if ($secret === '') {
			$this->log('Webhook refused: the webhook signing secret is not set in the ArionPay settings.');
			$this->respond(503, array('error' => 'not_configured'));
			return;
		}
		$raw = file_get_contents('php://input', false, null, 0, self::MAX_BODY_BYTES + 1);
		if ($raw === false || strlen($raw) > self::MAX_BODY_BYTES) {
			$this->respond(413, array('error' => 'payload_too_large'));
			return;
		}
		$this->sdk();
		$header = isset($_SERVER['HTTP_ARIONPAY_SIGNATURE']) ? (string)$_SERVER['HTTP_ARIONPAY_SIGNATURE'] : '';
		try {
			// The body is signed byte for byte, so it is read raw, never through OpenCart's cleaned request.
			$event = \ArionPay\Webhook::verify($raw, $header, $secret);
		} catch (\ArionPay\SignatureException $e) {
			$this->log($header === '' && isset($_SERVER['HTTP_X_SIGNATURE'])
				? 'Webhook refused: it has the old format. Turn off "old API (v1) compatibility" for this store in ArionPay.'
				: 'Webhook refused: ' . $e->getMessage());
			$this->respond(401, array('error' => 'invalid_signature'));
			return;
		}

		if (\ArionPay\Webhook::isTestEvent($event)) {
			$this->log('Verified ArionPay test webhook received (' . $event['type'] . ').');
			$this->respond(200, array('received' => true, 'test_event' => true));
			return;
		}
		if (!in_array($event['type'], \ArionPay\Webhook::EVENT_TYPES, true)) {
			$this->respond(200, array('received' => true, 'ignored' => 'unknown_event'));
			return;
		}
		$invoice_id = isset($event['data']['invoice']['id']) ? (string)$event['data']['invoice']['id'] : '';
		if (!preg_match('/^[0-9a-f]{24}$/i', $invoice_id)) {
			$this->respond(400, array('error' => 'malformed_event'));
			return;
		}

		$this->load->model('extension/payment/arionpay');
		$result = $this->model_extension_payment_arionpay->applyEvent($event);
		$this->log('Webhook ' . $event['type'] . ' for invoice ' . $invoice_id . ': ' . $result . '.');
		switch ($result) {
			case 'busy':
				header('Retry-After: 30');
				$this->respond(503, array('error' => 'busy'));
				break;
			case 'mismatch':
				$this->respond(409, array('error' => 'invoice_order_mismatch'));
				break;
			case 'not_found':
				$this->respond(404, array('error' => 'order_not_found'));
				break;
			case 'unknown_invoice':
				$this->respond(200, array('received' => true, 'ignored' => 'not_an_opencart_invoice'));
				break;
			case 'duplicate':
				$this->respond(200, array('received' => true, 'duplicate' => true));
				break;
			default:
				$this->respond(200, array('received' => true));
		}
	}

	private function invoiceUrl(array $order) {
		$model = $this->model_extension_payment_arionpay;
		$order_id = (int)$order['order_id'];
		$amount = $model->orderAmount($order);
		$currency = strtoupper((string)$order['currency_code']);
		$test = (bool)$this->config->get('payment_arionpay_test');

		$latest = $model->getLatestInvoice($order_id);
		if ($latest && $latest['status'] === 'new' && $latest['amount'] === $amount && $latest['currency'] === $currency && (bool)$latest['test'] === $test
			&& $latest['expires_at'] && strtotime($latest['expires_at'] . ' UTC') > time() + 300) {
			return (string)$latest['invoice_url'];
		}

		$attempt = $model->countInvoices($order_id) + 1;
		$site = substr(hash('sha256', HTTP_SERVER . '|' . DB_DATABASE . '|' . DB_PREFIX), 0, 12);
		$invoice = $this->client()->createInvoice(array(
			'amount'         => $amount,
			'currency'       => $currency,
			'orderId'        => (string)$order_id,
			'description'    => mb_substr(sprintf($this->language->get('text_description'), $order_id, html_entity_decode((string)$order['store_name'], ENT_QUOTES, 'UTF-8')), 0, 500),
			'successUrl'     => str_replace('&amp;', '&', $this->url->link('checkout/success', '', true)),
			'cancelUrl'      => str_replace('&amp;', '&', $this->url->link('checkout/checkout', '', true)),
			'callbackUrl'    => $this->webhookUrl(),
			'metadata'       => array('opencart_order_id' => $order_id, 'opencart_store_id' => (int)$order['store_id'], 'platform' => 'opencart'),
			'idempotencyKey' => 'opencart-' . $site . '-' . $order_id . '-' . $attempt,
			'test'           => $test,
		));
		$model->addInvoice($order_id, $invoice, $amount, $currency, $test);

		$comment = ($test ? $this->language->get('note_test_prefix') : '') . sprintf($this->language->get('note_sent'), $invoice['id'], $amount, $currency);
		$status_id = (int)$order['order_status_id'] ? (int)$order['order_status_id'] : (int)$this->config->get('payment_arionpay_pending_status_id');
		$this->model_checkout_order->addOrderHistory($order_id, $status_id, $comment, false);
		$this->log('Invoice ' . $invoice['id'] . ' created for order ' . $order_id . ' (' . $amount . ' ' . $currency . ($test ? ', test' : '') . ').');
		return (string)$invoice['invoiceUrl'];
	}

	private function webhookUrl() {
		$base = (defined('HTTPS_SERVER') && strpos(HTTPS_SERVER, 'https://') === 0) ? HTTPS_SERVER : HTTP_SERVER;
		return rtrim($base, '/') . '/index.php?route=extension/payment/arionpay/callback';
	}

	private function client() {
		$this->sdk();
		$base = (string)$this->config->get('payment_arionpay_base_url');
		return new \ArionPay\Client((string)$this->config->get('payment_arionpay_api_key'), (string)$this->config->get('payment_arionpay_api_secret'), array(
			'baseUrl' => $base !== '' ? $base : 'https://merchant.arionpay.com',
			'appInfo' => 'opencart/' . VERSION . ' arionpay-opencart/1.0.0',
		));
	}

	private function sdk() {
		require_once DIR_SYSTEM . 'library/arionpay-php/autoload.php';
	}

	private function respond($status, array $body) {
		http_response_code($status);
		$this->response->addHeader('Content-Type: application/json; charset=utf-8');
		$this->response->addHeader('Cache-Control: no-store');
		$this->response->setOutput(json_encode($body));
	}

	private function log($message) {
		if ($this->config->get('payment_arionpay_debug')) {
			$log = new Log('arionpay.log');
			$log->write($message);
		}
	}
}
