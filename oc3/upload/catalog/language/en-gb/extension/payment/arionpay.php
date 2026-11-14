<?php
// Heading
$_['heading_title']        = 'Crypto (Bitcoin, USDT and more)';
$_['text_title']           = 'Crypto (Bitcoin, USDT and more)';

// Text
$_['text_instruction']     = 'Pay with cryptocurrency';
$_['text_redirect']        = 'After you confirm, you go to the secure ArionPay page to choose a coin and pay. Your order is confirmed as soon as the payment arrives.';
$_['text_test']            = 'Test mode: no real money is taken.';
$_['text_description']     = 'Order #%s at %s';

// Order notes (seen by the shop owner). {id} = ArionPay invoice, {paid} = amount received, {due} = amount due,
// {asset} = coin code, {network} = network, {reason} = ArionPay's reason, {tx} = transaction id.
$_['note_test_prefix']     = '[TEST - no real money] ';
$_['note_sent']            = 'Customer sent to ArionPay to pay invoice %s (%s %s).';
$_['note_paid']            = 'ArionPay invoice {id} is paid: {paid} {asset} received on {network}. Transaction: {tx}.';
$_['note_paid_late']       = 'Paid late: the payment arrived after the invoice expired.';
$_['note_paid_manual']     = 'You accepted this payment by hand in the ArionPay dashboard.';
$_['note_paid_over']       = 'The customer paid more than the invoice amount.';
$_['note_paid_tolerance']  = 'The customer paid slightly less, and ArionPay accepted it under your store\'s payment tolerance.';
$_['note_paid_review']     = 'ArionPay flagged this invoice for review: see your ArionPay dashboard.';
$_['note_pending']         = 'ArionPay invoice {id}: {paid} {asset} was seen on the network and is waiting for confirmations. Do not ship yet.';
$_['note_partial']         = 'ArionPay invoice {id}: partial payment of {paid} of {due} {asset}. The invoice stays open for the rest. Do not ship yet.';
$_['note_expired']         = 'ArionPay invoice {id} expired without full payment. The order is not paid. A late payment can still arrive; if ArionPay accepts it, this order is updated.';
$_['note_review']          = 'ArionPay invoice {id} needs your review in the ArionPay dashboard: {reason} The order status was not changed.';
$_['note_double']          = 'ArionPay reports invoice {id} as paid, but this order already has a paid status. If it was paid another way, this may be a double payment: check your wallet and refund the customer if needed.';
$_['note_closed']          = 'ArionPay reports invoice {id} as paid, but this order is refunded, reversed, voided or charged back. The order was not changed.';
$_['note_mismatch']        = 'ArionPay invoice %s is paid (%s %s), but that does not match this order\'s total (%s %s), so the order was NOT marked paid. Please check the order and the invoice.';

// Error
$_['error_order']          = 'Warning: Your order could not be found. Please go back to the cart.';
$_['error_payment_method'] = 'Warning: Payment method required!';
$_['error_gateway']        = 'The crypto payment could not be started right now. Please try again in a minute, or choose another payment method.';
