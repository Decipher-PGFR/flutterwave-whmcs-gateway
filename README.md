# Flutterwave Payment Gateway for WHMCS

WHMCS Third Party Gateway module for Flutterwave v3 Standard Checkout, built and maintained by Decipher Media Solutions LTD.

The browser redirect is never treated as proof of payment. Every payment is confirmed by a signed webhook, re-verified server to server against Flutterwave before the invoice is credited, and checked against the invoice amount and currency. A tampered callback, a replayed reference or a short payment does not credit anything.

Version: **1.0.0** — production-verified with live test transactions. MIT licensed. See [CHANGELOG.md](CHANGELOG.md).

---

## What this does

Lets a WHMCS customer pay an invoice using Flutterwave's hosted checkout. Supports card, bank transfer, USSD, and mobile money (all channels Flutterwave offers on their checkout page).

1. Customer clicks **Pay with Flutterwave** on their invoice.
2. WHMCS calls Flutterwave server-to-server to initialize a payment session.
3. Customer is redirected to Flutterwave's hosted checkout page (no card data on our side, PCI scope stays at SAQ-A).
4. Customer completes payment.
5. Flutterwave POSTs a webhook to `/modules/gateways/callback/flutterwave.php`.
6. We verify the `verif-hash` header, re-verify the transaction server-to-server via `/v3/transactions/:id/verify`, reconcile amount + currency against the WHMCS invoice, then call `addInvoicePayment`.

Authoritative confirmation is always the webhook + server-side re-verify. The browser redirect back to WHMCS is UX only.

---

## What this does NOT do (MVP scope)

- **No refunds from within WHMCS.** Refund from the Flutterwave dashboard, then update the invoice in WHMCS manually.
- **No card-on-file / tokenization / recurring billing.** Invoices are paid individually.
- **NGN only.** Gateway refuses non-NGN invoices at both the template and redirect layers.
- **No partial payments.** Exact amount enforcement: the verified payment amount must match the invoice total.

---

## Files

```
modules/gateways/flutterwave.php                       # module config + pay button
modules/gateways/callback/flutterwave_redirect.php     # on-click redirect (initializes payment, sends customer to Flutterwave checkout)
modules/gateways/callback/flutterwave.php              # webhook receiver (verifies + applies payment)
.htaccess                                              # HTTPS enforcement + sensitive file blocking (optional, deploy to WHMCS doc root)
```

Drop the three PHP files into your WHMCS install at the matching paths.

---

## Installation

1. Copy the three PHP files into your WHMCS document root:
   ```
   modules/gateways/flutterwave.php
   modules/gateways/callback/flutterwave.php
   modules/gateways/callback/flutterwave_redirect.php
   ```

2. Optionally deploy the `.htaccess` to your WHMCS document root for HTTPS enforcement. If you already have one, merge the rules instead of overwriting it.

3. In WHMCS admin: **Setup > Payments > Payment Gateways > All Payment Gateways** > click **Flutterwave (Decipher integration)** > **Activate**.

4. Fill in config fields:
   - **Public Key** — from Flutterwave dashboard (starts with `FLWPUBK_`)
   - **Secret Key** — from Flutterwave dashboard (starts with `FLWSECK_`)
   - **Webhook Secret Hash** — set in Flutterwave Dashboard > Settings > Webhooks
   - **Company Name** — shown on checkout page
   - **Logo URL** — square image URL shown on checkout page
   - **Test Mode** — tick when using test keys

5. In Flutterwave dashboard > Settings > Webhooks > set Webhook URL:
   ```
   https://your-whmcs-domain.com/modules/gateways/callback/flutterwave.php
   ```

6. Test with a small invoice using Flutterwave test keys.

---

## Security

- **Secret key** never leaves the server. Never exposed to the browser.
- **Webhook verification** uses the `verif-hash` header, matched against the webhook secret hash you set in the Flutterwave dashboard. Compared with `hash_equals` (timing-safe).
- **Server-side re-verify** after webhook verification. We call `GET /v3/transactions/:id/verify` with Bearer auth and confirm status, amount, and currency before crediting.
- **Exact amount enforcement** blocks partial-payment attacks.
- **Duplicate protection** via WHMCS `checkCbTransID` + idempotency guard (checks `tblaccounts` before calling `addInvoicePayment`).
- **Session isolation** — webhook handler closes the WHMCS session immediately to prevent session table bloat.
- **Currency gate** — dual-layer: template hides Pay button for non-NGN, redirect handler blocks checkout init for non-NGN.
- **SSL pinned** — `CURLOPT_SSL_VERIFYPEER` and `CURLOPT_SSL_VERIFYHOST` enforced on all outbound calls.
- **Log sanitization** — no raw body, customer PII, or secret values in any log entry.

---

## Known limits / future work

- Dispute/chargeback webhooks not handled. Handle manually from Flutterwave dashboard.
- Fee amount not pulled from webhook — `addInvoicePayment` fee is `0`. Reconcile in monthly close.
- Multi-currency support is gated to NGN. Expand when needed.

---

## Requirements

- WHMCS 8.x
- PHP 7.4+
- A Flutterwave merchant account with API keys
- cURL extension enabled
- SSL certificate on your WHMCS domain

---

## Related

- [korapay-whmcs](https://github.com/Decipher-PGFR/korapay-whmcs): the same approach for Korapay Checkout Redirect.

---

## Reference

- Flutterwave API docs: https://developer.flutterwave.com/docs
- Flutterwave webhook docs: https://developer.flutterwave.com/docs/webhooks
- WHMCS gateway dev docs: https://developers.whmcs.com/payment-gateways/third-party-gateway/

---

## License

MIT. See [LICENSE](LICENSE).

---

## Author

Decipher Media Solutions LTD  
https://decipher.ng

Flutterwave is the payment processor. This module is an independent integration, not affiliated with or endorsed by Flutterwave.
