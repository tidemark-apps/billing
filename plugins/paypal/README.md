# PayPal

PayPal Orders v2 (redirect checkout), refunds and webhooks. No SDK needed.

1. Enable the plugin, then open **Settings → Payments → PayPal**.
2. In the PayPal developer dashboard create a REST app and paste its **Client ID** and **Secret**. Start in **Sandbox** mode.
3. Add a webhook for the app pointing at `https://YOUR-DOMAIN/webhooks/gateways/paypal`, subscribe to
   `PAYMENT.CAPTURE.COMPLETED` and `PAYMENT.CAPTURE.REFUNDED`, and paste its **Webhook ID**.
4. Switch the gateway on. Move to **Live** with live credentials when ready.

Supported currencies are PayPal's standard list (USD, EUR, GBP, CAD, AUD, ...); other invoices will not offer PayPal.

## Saved PayPal wallets and autopay

Customers can save their PayPal wallet under **Portal → Payment methods** (Vault v3: setup token, then payment token). Saved tokens are charged with Orders v2 (`payment_source.paypal.vault_id`). Your PayPal app needs the Vault / "save payment methods" feature enabled. Add the webhook event `VAULT.PAYMENT-TOKEN.DELETED`. Saving *cards* needs PayPal's JavaScript SDK and is not offered here; only wallet tokens can be created.
