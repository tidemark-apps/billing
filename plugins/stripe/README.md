# Stripe

Hosted Stripe Checkout for invoice payments, with webhooks and refunds. No SDK needed.

1. Enable the plugin (Plugins), then open **Settings → Payments → Stripe**.
2. Paste your **secret key** (Developers → API keys). Use `sk_test_…` keys while testing.
3. In Stripe, add a webhook endpoint pointing at `https://YOUR-DOMAIN/webhooks/gateways/stripe` and subscribe to:
   `checkout.session.completed`, `checkout.session.async_payment_succeeded`, `charge.refunded`.
4. Paste the endpoint's **signing secret** (`whsec_…`) and switch the gateway on.

Test mode: use test keys and card `4242 4242 4242 4242`. Payments are recorded both when the customer returns and when the webhook arrives; the two never double-count.

## Saved cards and autopay

Customers can save a card under **Portal → Payment methods** (Stripe Checkout in `setup` mode, one Stripe Customer per client). Saved cards are charged with off-session PaymentIntents (`off_session=true`, `confirm=true`, idempotency key per attempt). If the issuer requires authentication the customer is emailed to pay manually. Add these events to your Stripe webhook endpoint: `checkout.session.completed`, `payment_intent.succeeded`, `payment_method.detached`, `payment_method.updated`, `payment_method.automatically_updated`, `setup_intent.setup_failed`.
