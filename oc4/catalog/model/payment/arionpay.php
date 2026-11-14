<?php
namespace Opencart\Catalog\Model\Extension\Arionpay\Payment;

/**
 * ArionPay payment method: checkout availability, the invoice table, and the order changes a verified event makes.
 *
 * Only verified data reaches applyEvent(): a webhook whose signature was checked. Whether an invoice is paid is
 * decided by ArionPay alone; this model never compares crypto amounts.
 */
class Arionpay extends \Opencart\System\Engine\Model {
	/** Fiat currencies ArionPay can price an invoice in. */
	public const CURRENCIES = ['USD', 'EUR', 'GBP', 'CAD', 'AUD', 'CHF', 'JPY', 'TRY', 'AED', 'INR', 'BRL', 'RUB', 'CNY', 'SGD', 'HKD', 'KRW', 'MXN', 'PLN', 'SEK', 'NOK', 'ZAR'];

	/**
	 * @param array<string,mixed> $address
	 * @return array<string,mixed>
	 */
	public function getMethods(array $address = []): array {
		$this->load->language('extension/arionpay/payment/arionpay');

		$status = true;
		if ($this->cart->hasSubscription()) {
			$status = false; // crypto payments are one-off: there is no automatic renewal
		} elseif (!in_array(strtoupper((string)$this->session->data['currency']), self::CURRENCIES, true)) {
			$status = false;
		} elseif (!$this->config->get('payment_arionpay_api_key') || !$this->config->get('payment_arionpay_api_secret') || !$this->config->get('payment_arionpay_webhook_secret')) {
			$status = false;
		} elseif ($this->config->get('config_checkout_payment_address') && $this->config->get('payment_arionpay_geo_zone_id')) {
			$this->load->model('localisation/geo_zone');
			$status = (bool)$this->model_localisation_geo_zone->getGeoZone((int)$this->config->get('payment_arionpay_geo_zone_id'), (int)($address['country_id'] ?? 0), (int)($address['zone_id'] ?? 0));
		}

		if (!$status) {
			return [];
		}
		$title = trim((string)$this->config->get('payment_arionpay_title')) ?: $this->language->get('heading_title');
		return [
			'code'       => 'arionpay',
			'name'       => $title,
			'option'     => ['arionpay' => ['code' => 'arionpay.arionpay', 'name' => $title]],
			'sort_order' => $this->config->get('payment_arionpay_sort_order'),
		];
	}

	// ---------------------------------------------------------------- invoice table

	/** @return array<string,mixed> */
	public function getInvoice(string $invoice_id): array {
		$q = $this->db->query("SELECT * FROM `" . DB_PREFIX . "arionpay_invoice` WHERE `invoice_id` = '" . $this->db->escape($invoice_id) . "'");
		return $q->row;
	}

	/** @return array<string,mixed> The newest invoice made for this order, or []. */
	public function getLatestInvoice(int $order_id): array {
		$q = $this->db->query("SELECT * FROM `" . DB_PREFIX . "arionpay_invoice` WHERE `order_id` = '" . (int)$order_id . "' ORDER BY `date_added` DESC LIMIT 1");
		return $q->row;
	}

	public function countInvoices(int $order_id): int {
		$q = $this->db->query("SELECT COUNT(*) AS n FROM `" . DB_PREFIX . "arionpay_invoice` WHERE `order_id` = '" . (int)$order_id . "'");
		return (int)$q->row['n'];
	}

	public function addInvoice(int $order_id, array $invoice, string $amount, string $currency, bool $test): void {
		$expires = !empty($invoice['expiresAt']) ? date('Y-m-d H:i:s', (int)strtotime((string)$invoice['expiresAt'])) : null;
		$this->db->query("INSERT IGNORE INTO `" . DB_PREFIX . "arionpay_invoice` SET
			`invoice_id` = '" . $this->db->escape((string)$invoice['id']) . "',
			`order_id` = '" . (int)$order_id . "',
			`invoice_url` = '" . $this->db->escape((string)$invoice['invoiceUrl']) . "',
			`amount` = '" . $this->db->escape($amount) . "',
			`currency` = '" . $this->db->escape($currency) . "',
			`test` = '" . ($test ? 1 : 0) . "',
			`status` = 'new',
			`expires_at` = " . ($expires ? "'" . $this->db->escape($expires) . "'" : 'NULL') . ",
			`date_added` = UTC_TIMESTAMP(),
			`date_modified` = UTC_TIMESTAMP()");
	}

	// ---------------------------------------------------------------- events

	/**
	 * Apply one verified event under a per-order lock.
	 *
	 * @param array<string,mixed> $event
	 * @return string processed, duplicate, busy, unknown_invoice, mismatch or not_found
	 */
	public function applyEvent(array $event): string {
		$invoice = $event['data']['invoice'];
		$invoice_id = (string)($invoice['id'] ?? '');
		$row = $this->getInvoice($invoice_id);
		if (!$row) {
			return 'unknown_invoice'; // made by another shop on the same ArionPay store, or from the dashboard
		}
		$order_id = (int)$row['order_id'];
		$metadata = isset($invoice['metadata']) && is_array($invoice['metadata']) ? $invoice['metadata'] : [];
		if ((int)($metadata['opencart_order_id'] ?? 0) !== $order_id || (string)($invoice['orderId'] ?? '') !== (string)$order_id || (bool)($invoice['test'] ?? false) !== (bool)$row['test']) {
			return 'mismatch';
		}

		$lock = $this->db->escape('arionpay_order_' . DB_DATABASE . '_' . $order_id);
		$got = $this->db->query("SELECT GET_LOCK('" . $lock . "', 10) AS l");
		if ((int)($got->row['l'] ?? 0) !== 1) {
			return 'busy';
		}
		try {
			$seen = $this->db->query("SELECT 1 FROM `" . DB_PREFIX . "arionpay_event` WHERE `event_id` = '" . $this->db->escape((string)$event['id']) . "'");
			if ($seen->num_rows) {
				return 'duplicate';
			}
			$this->load->model('checkout/order');
			$order = $this->model_checkout_order->getOrder($order_id);
			if (!$order) {
				return 'not_found';
			}

			$this->db->query("UPDATE `" . DB_PREFIX . "arionpay_invoice` SET `status` = '" . $this->db->escape(substr((string)($invoice['status'] ?? ''), 0, 20)) . "', `date_modified` = UTC_TIMESTAMP() WHERE `invoice_id` = '" . $this->db->escape($invoice_id) . "'");
			$latest = $this->getLatestInvoice($order_id);
			$is_current = $latest && $latest['invoice_id'] === $invoice_id;

			switch ((string)$event['type']) {
				case 'invoice.paid':
					$this->onPaid($order, $invoice, $row);
					break;
				case 'invoice.pending':
					$this->note($order, $is_current && !$this->isPaid($order) ? $this->text('note_pending', $invoice) : '');
					break;
				case 'invoice.partial':
					$this->note($order, $is_current && !$this->isPaid($order) ? $this->text('note_partial', $invoice) : '');
					break;
				case 'invoice.expired':
					if ($is_current && !$this->isPaid($order) && !$this->isClosed($order)) {
						$status_id = (int)$this->config->get('payment_arionpay_expired_status_id');
						$this->model_checkout_order->addHistory($order_id, $status_id ?: (int)$order['order_status_id'], $this->text('note_expired', $invoice), false);
					}
					break;
				case 'invoice.needs_review':
					$this->note($order, $this->text('note_review', $invoice));
					break;
			}

			$this->db->query("INSERT IGNORE INTO `" . DB_PREFIX . "arionpay_event` SET `event_id` = '" . $this->db->escape(substr((string)$event['id'], 0, 64)) . "', `invoice_id` = '" . $this->db->escape($invoice_id) . "', `type` = '" . $this->db->escape(substr((string)$event['type'], 0, 32)) . "', `date_added` = UTC_TIMESTAMP()");
			return 'processed';
		} finally {
			$this->db->query("SELECT RELEASE_LOCK('" . $lock . "')");
		}
	}

	/**
	 * @param array<string,mixed> $order
	 * @param array<string,mixed> $invoice
	 * @param array<string,mixed> $row
	 */
	private function onPaid(array $order, array $invoice, array $row): void {
		$order_id = (int)$order['order_id'];
		if ($this->isPaid($order)) {
			$this->note($order, $this->text('note_double', $invoice));
			return;
		}
		if ($this->isClosed($order)) {
			$this->note($order, $this->text('note_closed', $invoice));
			return;
		}

		// The invoice must still match the order: same currency, same total to the cent (not a payment tolerance:
		// it catches an order whose total was edited after the customer was sent to pay).
		$expected = $this->orderAmount($order);
		$decimals = $this->decimals((string)$order['currency_code']);
		$paid_amount = is_numeric($invoice['amountFiat'] ?? null) ? number_format((float)$invoice['amountFiat'], $decimals, '.', '') : '';
		if (strtoupper((string)($invoice['currency'] ?? '')) !== strtoupper((string)$order['currency_code']) || $paid_amount !== $expected || $row['amount'] !== $expected) {
			$this->note($order, sprintf($this->language->get('note_mismatch'), $invoice['id'], $paid_amount !== '' ? $paid_amount : '?', (string)($invoice['currency'] ?? ''), $expected, $order['currency_code']));
			return;
		}

		$this->model_checkout_order->addHistory($order_id, (int)$this->config->get('payment_arionpay_paid_status_id'), $this->paidNote($invoice), true);
		$this->db->query("UPDATE `" . DB_PREFIX . "arionpay_invoice` SET `order_paid` = '1' WHERE `invoice_id` = '" . $this->db->escape((string)$invoice['id']) . "'");
	}

	/** Order total in the order's own currency, as a fixed-decimal string (what the invoice was made for). */
	public function orderAmount(array $order): string {
		$code = (string)$order['currency_code'];
		$value = $this->currency->format((float)$order['total'], $code, (float)$order['currency_value'], false);
		return number_format((float)$value, $this->decimals($code), '.', '');
	}

	private function decimals(string $code): int {
		$this->load->model('localisation/currency');
		$c = $this->model_localisation_currency->getCurrencyByCode($code);
		return $c ? (int)$c['decimal_place'] : 2;
	}

	/**
	 * Is this order already paid? OpenCart's "processing" statuses cannot tell: by default they include Pending (they
	 * decide when stock is taken). So: this extension marked it paid, or it has a complete status or the "paid" status.
	 *
	 * @param array<string,mixed> $order
	 */
	private function isPaid(array $order): bool {
		$applied = $this->db->query("SELECT 1 FROM `" . DB_PREFIX . "arionpay_invoice` WHERE `order_id` = '" . (int)$order['order_id'] . "' AND `order_paid` = '1' LIMIT 1");
		if ($applied->num_rows) {
			return true;
		}
		$paid = array_merge((array)$this->config->get('config_complete_status'), [(int)$this->config->get('payment_arionpay_paid_status_id')]);
		return in_array((int)$order['order_status_id'], array_map('intval', $paid), true);
	}

	/**
	 * Refunded (11), Chargeback (13), Reversed (12) and Voided (16) orders (OpenCart's default ids): a payment must not
	 * reopen them by itself. Canceled orders are reopened, because the customer did pay.
	 */
	private function isClosed(array $order): bool {
		return in_array((int)$order['order_status_id'], [11, 12, 13, 16], true);
	}

	/** @param array<string,mixed> $order */
	private function note(array $order, string $comment): void {
		if ($comment !== '') {
			$this->model_checkout_order->addHistory((int)$order['order_id'], (int)$order['order_status_id'], $comment, false);
		}
	}

	/** @param array<string,mixed> $invoice */
	private function paidNote(array $invoice): string {
		$parts = [$this->text('note_paid', $invoice)];
		if (!empty($invoice['late'])) {
			$parts[] = $this->language->get('note_paid_late');
		}
		if (!empty($invoice['manual'])) {
			$parts[] = $this->language->get('note_paid_manual');
		}
		if (!empty($invoice['overpaid'])) {
			$parts[] = $this->language->get('note_paid_over');
		}
		if (!empty($invoice['underpaidWithinTolerance'])) {
			$parts[] = $this->language->get('note_paid_tolerance');
		}
		if (!empty($invoice['needsReview'])) {
			$parts[] = $this->language->get('note_paid_review');
		}
		return implode(' ', $parts);
	}

	/**
	 * A language string filled with invoice values: id, amount seen, amount due, coin, network, review reason.
	 *
	 * @param array<string,mixed> $invoice
	 */
	private function text(string $key, array $invoice): string {
		$this->load->language('extension/arionpay/payment/arionpay');
		$s = static function ($v): string {
			return is_scalar($v) ? mb_substr(strip_tags((string)$v), 0, 300) : '';
		};
		$tx = '';
		if (!empty($invoice['transactions']) && is_array($invoice['transactions'])) {
			$last = end($invoice['transactions']);
			$tx = is_array($last) ? $s($last['txid'] ?? '') : '';
		}
		$text = strtr($this->language->get($key), [
			'{id}'      => $s($invoice['id'] ?? ''),
			'{paid}'    => $s($invoice['paidCrypto'] ?? ''),
			'{due}'     => $s($invoice['amountCrypto'] ?? ''),
			'{asset}'   => $s($invoice['asset'] ?? ''),
			'{network}' => $s($invoice['network'] ?? ''),
			'{reason}'  => $s($invoice['reviewReason'] ?? '') ?: '-',
			'{tx}'      => $tx ?: '-',
		]);
		return (!empty($invoice['test']) ? $this->language->get('note_test_prefix') : '') . $text;
	}
}
