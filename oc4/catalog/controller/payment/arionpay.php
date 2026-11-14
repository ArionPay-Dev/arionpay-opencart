<?php
namespace Opencart\Catalog\Controller\Extension\Arionpay\Payment;

/**
 * Checkout (confirm button -> ArionPay payment page) and the webhook endpoint.
 */
class Arionpay extends \Opencart\System\Engine\Controller {
	private const ROUTE = 'extension/arionpay/payment/arionpay';
	private const MAX_BODY_BYTES = 262144;

	public function index(): string {
		$this->load->language(self::ROUTE);
		$data['language'] = $this->config->get('config_language');
		$data['test'] = (bool)$this->config->get('payment_arionpay_test');
		$data['confirm'] = $this->url->link(self::ROUTE . $this->separator() . 'confirm', 'language=' . $this->config->get('config_language'));
		return $this->load->view(self::ROUTE, $data);
	}

	/** Make (or reuse) the ArionPay invoice for the session's order and send the customer there. */
	public function confirm(): void {
		$this->load->language(self::ROUTE);
		$json = [];

		if (!isset($this->session->data['order_id'])) {
			$json['error'] = $this->language->get('error_order');
		} elseif (!isset($this->session->data['payment_method']['code']) || $this->session->data['payment_method']['code'] !== 'arionpay.arionpay') {
			$json['error'] = $this->language->get('error_payment_method');
		}

		if (!$json) {
			$order_id = (int)$this->session->data['order_id'];
			$this->load->model('checkout/order');
			$this->load->model(self::ROUTE);
			$model = $this->model_extension_arionpay_payment_arionpay;
			$order = $this->model_checkout_order->getOrder($order_id);
			if (!$order) {
				$json['redirect'] = $this->url->link('checkout/failure', 'language=' . $this->config->get('config_language'), true);
				unset($this->session->data['order_id']);
			} else {
				try {
					$json['redirect'] = $this->invoiceUrl($order);
				} catch (\Throwable $e) {
					$this->log('Could not create an ArionPay invoice for order ' . $order_id . ': ' . $e->getMessage());
					$json['error'] = $this->language->get('error_gateway');
				}
			}
		}

		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($json));
	}

	/**
	 * Webhook endpoint: index.php?route=extension/arionpay/payment/arionpay.callback
	 * ArionPay retries anything that is not 2xx for about 3 days.
	 */
	public function callback(): void {
		$this->load->language(self::ROUTE);
		if (($this->request->server['REQUEST_METHOD'] ?? '') !== 'POST') {
			$this->respond(405, ['error' => 'method_not_allowed']);
			return;
		}
		$secret = (string)$this->config->get('payment_arionpay_webhook_secret');
		if ($secret === '') {
			$this->log('Webhook refused: the webhook signing secret is not set in the ArionPay settings.');
			$this->respond(503, ['error' => 'not_configured']);
			return;
		}
		$raw = file_get_contents('php://input', false, null, 0, self::MAX_BODY_BYTES + 1);
		if ($raw === false || strlen($raw) > self::MAX_BODY_BYTES) {
			$this->respond(413, ['error' => 'payload_too_large']);
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
			$this->respond(401, ['error' => 'invalid_signature']);
			return;
		}

		if (\ArionPay\Webhook::isTestEvent($event)) {
			$this->log('Verified ArionPay test webhook received (' . $event['type'] . ').');
			$this->respond(200, ['received' => true, 'test_event' => true]);
			return;
		}
		if (!in_array($event['type'], \ArionPay\Webhook::EVENT_TYPES, true)) {
			$this->respond(200, ['received' => true, 'ignored' => 'unknown_event']);
			return;
		}
		$invoice_id = (string)($event['data']['invoice']['id'] ?? '');
		if (!preg_match('/^[0-9a-f]{24}$/i', $invoice_id)) {
			$this->respond(400, ['error' => 'malformed_event']);
			return;
		}

		$this->load->model(self::ROUTE);
		$result = $this->model_extension_arionpay_payment_arionpay->applyEvent($event);
		$this->log('Webhook ' . $event['type'] . ' for invoice ' . $invoice_id . ': ' . $result . '.');
		switch ($result) {
			case 'busy':
				header('Retry-After: 30');
				$this->respond(503, ['error' => 'busy']);
				break;
			case 'mismatch':
				$this->respond(409, ['error' => 'invoice_order_mismatch']);
				break;
			case 'not_found':
				$this->respond(404, ['error' => 'order_not_found']);
				break;
			case 'unknown_invoice':
				// An invoice from the ArionPay dashboard, a payment link, or another shop on the same store: not ours.
				$this->respond(200, ['received' => true, 'ignored' => 'not_an_opencart_invoice']);
				break;
			case 'duplicate':
				$this->respond(200, ['received' => true, 'duplicate' => true]);
				break;
			default:
				$this->respond(200, ['received' => true]);
		}
	}

	/**
	 * The payment page address for this order: the open invoice when it still fits, otherwise a new one.
	 *
	 * @param array<string,mixed> $order
	 */
	private function invoiceUrl(array $order): string {
		$model = $this->model_extension_arionpay_payment_arionpay;
		$order_id = (int)$order['order_id'];
		$amount = $model->orderAmount($order);
		$currency = strtoupper((string)$order['currency_code']);
		$test = (bool)$this->config->get('payment_arionpay_test');

		$latest = $model->getLatestInvoice($order_id);
		if ($latest && $latest['status'] === 'new' && $latest['amount'] === $amount && $latest['currency'] === $currency && (bool)$latest['test'] === $test
			&& $latest['expires_at'] && strtotime($latest['expires_at'] . ' UTC') > time() + 300) {
			return (string)$latest['invoice_url'];
		}

		$lang = 'language=' . $this->config->get('config_language');
		$attempt = $model->countInvoices($order_id) + 1;
		$site = substr(hash('sha256', HTTP_SERVER . '|' . DB_DATABASE . '|' . DB_PREFIX), 0, 12);
		$client = $this->client();
		$invoice = $client->createInvoice([
			'amount'         => $amount,
			'currency'       => $currency,
			'orderId'        => (string)$order_id,
			'description'    => mb_substr(sprintf($this->language->get('text_description'), $order_id, html_entity_decode((string)$order['store_name'], ENT_QUOTES, 'UTF-8')), 0, 500),
			'successUrl'     => str_replace('&amp;', '&', $this->url->link('checkout/success', $lang, true)),
			'cancelUrl'      => str_replace('&amp;', '&', $this->url->link('checkout/checkout', $lang, true)),
			'callbackUrl'    => $this->webhookUrl(),
			'metadata'       => ['opencart_order_id' => $order_id, 'opencart_store_id' => (int)$order['store_id'], 'platform' => 'opencart'],
			'idempotencyKey' => 'opencart-' . $site . '-' . $order_id . '-' . $attempt,
			'test'           => $test,
		]);
		$model->addInvoice($order_id, $invoice, $amount, $currency, $test);

		$comment = ($test ? $this->language->get('note_test_prefix') : '') . sprintf($this->language->get('note_sent'), $invoice['id'], $amount, $currency);
		$this->model_checkout_order->addHistory($order_id, (int)$order['order_status_id'] ?: (int)$this->config->get('payment_arionpay_pending_status_id'), $comment, false);
		$this->log('Invoice ' . $invoice['id'] . ' created for order ' . $order_id . ' (' . $amount . ' ' . $currency . ($test ? ', test' : '') . ').');
		return (string)$invoice['invoiceUrl'];
	}

	private function webhookUrl(): string {
		// A webhook address must not depend on the visitor's language or session.
		$base = $this->config->get('config_url') ?: HTTP_SERVER;
		return rtrim((string)$base, '/') . '/index.php?route=' . self::ROUTE . $this->separator() . 'callback';
	}

	private function client(): \ArionPay\Client {
		$this->sdk();
		return new \ArionPay\Client((string)$this->config->get('payment_arionpay_api_key'), (string)$this->config->get('payment_arionpay_api_secret'), [
			'baseUrl' => (string)($this->config->get('payment_arionpay_base_url') ?: 'https://merchant.arionpay.com'),
			'appInfo' => 'opencart/' . VERSION . ' arionpay-opencart/1.0.0',
		]);
	}

	private function sdk(): void {
		require_once DIR_EXTENSION . 'arionpay/system/library/arionpay-php/autoload.php';
	}

	/** @param array<string,mixed> $body */
	private function respond(int $status, array $body): void {
		http_response_code($status);
		$this->response->addHeader('Content-Type: application/json; charset=utf-8');
		$this->response->addHeader('Cache-Control: no-store');
		$this->response->setOutput(json_encode($body));
	}

	private function log(string $message): void {
		if ($this->config->get('payment_arionpay_debug')) {
			$log = new \Opencart\System\Library\Log('arionpay.log');
			$log->write($message);
		}
	}

	private function separator(): string {
		return version_compare(VERSION, '4.0.2.0', '>=') ? '.' : '|';
	}
}
