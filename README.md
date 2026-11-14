# ArionPay for OpenCart

Lets your customers pay with Bitcoin, USDT, Litecoin, Ethereum, TRON and more through [ArionPay](https://arionpay.com). The money goes straight to your own wallet: ArionPay never holds it.

| Your OpenCart | File to upload |
| --- | --- |
| 4.0.0 to 4.1.x | `opencart-4/arionpay.ocmod.zip` (keep this exact file name) |
| 3.0.x | `opencart-3/arionpay-opencart-3.ocmod.zip` |

Tested on real OpenCart 4.1.0.4 and 3.0.5.1 shops with PHP 8.3: checkout, payment, late payment, payment-tolerance, wrong-total and webhook checks.

## What you need

- PHP 7.4 or newer with cURL (or `allow_url_fopen`)
- An ArionPay account with a store, and a wallet connected to it for real payments (test mode works without a wallet)
- Your shop on a public `https://` address, so ArionPay can tell it when a payment arrives

## Install

1. In OpenCart admin, go to **Extensions > Installer** and upload the zip for your version.
   - OpenCart 4: after the upload, press the green **Install** button on the ArionPay line.
2. Go to **Extensions > Extensions**, choose **Payments**, find **ArionPay (crypto)** and press **Install** (green), then **Edit** (blue).
3. Fill in the three keys (see below), leave **Test mode** on, set **Enabled**, and save.
4. Press **Check connection**. It should say "Connected: ArionPay accepted these keys."

## Where the keys come from

| Setting | Where in the ArionPay dashboard | Starts with |
| --- | --- | --- |
| API key | Open your store, **API key** | `pk_` |
| API secret | Open your store, **API secret** (asks for your authenticator code) | `sk_` |
| Webhook signing secret | **Webhooks** page, **signing secret** | `whsec_` |

You do not need to paste any address into ArionPay: each invoice tells ArionPay where to send its payment updates. The settings page shows that address. You can also paste it on the ArionPay **Webhooks** page if you want to use **Send test webhook** there.

Also check, in your ArionPay store settings, that **Old ArionPay (v1) integration compatibility** is off.

## Try it first

1. Keep **Test mode** on.
2. Buy something in your shop and choose **Crypto (Bitcoin, USDT and more)** at checkout.
3. On the ArionPay page, choose a coin and use the test payment buttons. No real money moves.
4. The order should change from **Pending** to **Processing**, with a note saying "[TEST - no real money] ArionPay invoice ... is paid".
5. Turn **Test mode** off before you take real payments.

## How orders change

- When the customer is sent to pay, the order gets the "waiting for payment" status (default **Pending**) and a note with the ArionPay invoice number.
- When ArionPay confirms the full payment, the order gets the "paid" status (default **Processing**) and the customer is notified.
- The order is marked paid only if the paid invoice still matches the order: same currency and the same total. If someone changed the order after checkout, the order is not marked paid and a note explains why.
- A payment that is not exact goes to **Payment Radar** in your ArionPay dashboard. A small difference inside your store's payment tolerance (default 1%) is accepted automatically. For anything else you decide; if you accept it, the order is marked paid here too, with a note.
- A late payment (after the invoice expired) still marks the order paid, with a note.
- If more money arrives after an order was paid, you get a note asking you to check your wallet. The order is not changed.
- Coming back from the payment page never marks an order paid.
- If the customer presses confirm again for the same order while its invoice is still open, the same invoice is reused.

## Good to know

- ArionPay shows only when the shop currency is one ArionPay accepts: USD, EUR, GBP, CAD, AUD, CHF, JPY, TRY, AED, INR, BRL, RUB, CNY, SGD, HKD, KRW, MXN, PLN, SEK, NOK or ZAR.
- It is not offered for subscription (recurring) products.
- Refunds are made from your own wallet. The extension does not send refunds.
- The extension sends ArionPay the order number, amount, currency and your shop name. It does not send the customer's name, email or address.
- It keeps two small tables, `arionpay_invoice` and `arionpay_event`. They stay after you uninstall, so a late payment can still be matched if you reinstall.
- **Debug log** writes to `system/storage/logs/arionpay.log` (OpenCart 4: **System > Maintenance > Error Logs**). Keys are never written to it.

## Removing

Uninstall it under **Extensions > Extensions > Payments**, then (OpenCart 4) uninstall it under **Extensions > Installer**. You can drop the two tables if you will not reinstall.

## Build from source

The release zip is made with `python3 build.py`. It copies the [ArionPay PHP library](https://github.com/ArionPay-Dev/arionpay-php) into the plugin first, from a folder named `arionpay-php` next to this one:

```bash
git clone https://github.com/ArionPay-Dev/arionpay-php ../arionpay-php
python3 build.py
```
