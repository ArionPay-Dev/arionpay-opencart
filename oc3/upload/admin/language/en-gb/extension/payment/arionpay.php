<?php
// Heading
$_['heading_title']            = 'ArionPay (crypto)';

// Text
$_['text_extension']           = 'Extensions';
$_['text_success']             = 'Success: ArionPay settings saved.';
$_['text_edit']                = 'ArionPay settings';
$_['text_arionpay']            = '<a href="https://arionpay.com" target="_blank" rel="noopener"><b>ArionPay</b></a>';
$_['text_intro']               = 'Your customers pay with Bitcoin, USDT, Litecoin, Ethereum, TRON and more. The money goes straight to your own wallet: ArionPay never holds it.';
$_['text_keys_help']           = 'In your ArionPay dashboard, open your store: copy the <b>API key</b> (pk_...) and the <b>API secret</b> (sk_...). On the <b>Webhooks</b> page, copy the <b>signing secret</b> (whsec_...).';
$_['text_saved_secret']        = 'Saved. Leave empty to keep it.';
$_['text_all_zones']           = 'All zones';
$_['text_no_change']           = 'Do not change the status (add a note only)';
$_['text_check']               = 'Check connection';
$_['text_check_ok']            = 'Connected: ArionPay accepted these keys.';
$_['text_webhook']             = 'Payment updates';
$_['text_webhook_help']        = 'Nothing to paste: every invoice tells ArionPay to send its updates to this address. To use "Send test webhook" in the ArionPay dashboard, you can also paste it on the Webhooks page there.';
$_['text_webhook_not_https']   = 'Your shop address does not start with https://. ArionPay only sends payment updates to https addresses, so orders will not be marked paid until your shop uses https.';
$_['text_test_help']           = 'Test mode creates test invoices: no real money moves, and you can finish the payment with the test buttons on the ArionPay payment page. Turn it off before you take real payments.';
$_['text_rules']               = 'How orders change: when the customer is sent to pay, the order gets the "waiting" status. Only when ArionPay confirms the full payment does it get the "paid" status. If the paid amount or currency does not match the order total, the order is not marked paid and a note explains why. Coming back from the payment page never marks an order paid.';

// Entry
$_['entry_api_key']            = 'API key';
$_['entry_api_secret']         = 'API secret';
$_['entry_webhook_secret']     = 'Webhook signing secret';
$_['entry_base_url']           = 'ArionPay address';
$_['entry_test']               = 'Test mode';
$_['entry_title']              = 'Name at checkout';
$_['entry_pending_status']     = 'Status while waiting for payment';
$_['entry_paid_status']        = 'Status when paid';
$_['entry_expired_status']     = 'Status when the invoice expires';
$_['entry_geo_zone']           = 'Geo zone';
$_['entry_status']             = 'Enabled';
$_['entry_sort_order']         = 'Sort order';
$_['entry_debug']              = 'Debug log';

// Help
$_['help_base_url']            = 'Leave it as https://merchant.arionpay.com unless ArionPay tells you otherwise.';
$_['help_title']               = 'Empty: "Crypto (Bitcoin, USDT and more)".';
$_['help_expired']             = 'A late payment can still arrive after the invoice expires. If ArionPay accepts it, the order is marked paid.';
$_['help_debug']               = 'Writes what the extension does to System > Maintenance > Error Logs (file arionpay.log). Never writes your keys.';

// Error
$_['error_permission']         = 'Warning: You do not have permission to modify the ArionPay settings!';
$_['error_api_key']            = 'The API key starts with pk_.';
$_['error_api_secret']         = 'The API secret starts with sk_.';
$_['error_webhook_secret']     = 'The webhook signing secret starts with whsec_.';
$_['error_base_url']           = 'Enter an address that starts with https://.';
$_['error_same_status']        = 'The "paid" status must be different from the "waiting for payment" status.';
$_['error_check_keys']         = 'ArionPay refused these keys: %s';
$_['error_check_other']        = 'Could not check the connection: %s';
