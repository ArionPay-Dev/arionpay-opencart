<?php
class ModelExtensionPaymentArionpay extends Model {
	/** Tables: which ArionPay invoice belongs to which order, and which webhook events were already handled. */
	public function install() {
		$this->db->query("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "arionpay_invoice` (
			`invoice_id` VARCHAR(24) NOT NULL,
			`order_id` INT(11) NOT NULL,
			`invoice_url` VARCHAR(255) NOT NULL,
			`amount` VARCHAR(32) NOT NULL,
			`currency` VARCHAR(3) NOT NULL,
			`test` TINYINT(1) NOT NULL DEFAULT 0,
			`status` VARCHAR(20) NOT NULL DEFAULT 'new',
			`order_paid` TINYINT(1) NOT NULL DEFAULT 0,
			`expires_at` DATETIME NULL,
			`date_added` DATETIME NOT NULL,
			`date_modified` DATETIME NOT NULL,
			PRIMARY KEY (`invoice_id`),
			KEY `order_id` (`order_id`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
		$this->db->query("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "arionpay_event` (
			`event_id` VARCHAR(64) NOT NULL,
			`invoice_id` VARCHAR(24) NOT NULL,
			`type` VARCHAR(32) NOT NULL,
			`date_added` DATETIME NOT NULL,
			PRIMARY KEY (`event_id`),
			KEY `invoice_id` (`invoice_id`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
	}
}
