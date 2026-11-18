# Changelog

All notable changes to the Flutterwave WHMCS gateway module.

## [Unreleased]

- Genericized the payment reference prefix (#1)

- ## [1.0.0] - 2026-05-01

- First public release. Production-verified with live test transactions.

- ### Features

- - Flutterwave v3 Standard Checkout flow (PCI SAQ-A compliant)
  - - Card, bank transfer, USSD and mobile money, as offered on Flutterwave's hosted checkout
    - - verif-hash webhook signature verification, compared with hash_equals
      - - Server-side re-verification via GET /v3/transactions/:id/verify before crediting
        - - Dual-layer NGN-only currency gate (template + redirect handler)
          - - Clean separation: redirect handler vs webhook receiver
           
            - ### Security hardening
           
            - - Secret key never leaves the server and is never exposed to the browser
              - - Timing-safe signature comparison via hash_equals
                - - Exact-amount enforcement, always on, blocks partial-payment attacks
                  - - Duplicate protection via WHMCS checkCbTransID plus a tblaccounts idempotency pre-check
                    - - session_write_close() at the top of the webhook handler
                      - - CURLOPT_SSL_VERIFYPEER and CURLOPT_SSL_VERIFYHOST pinned on all outbound calls
                        - - Log sanitization: no raw body, customer PII or secret values in any log entry
                         
                          - ### Known limits
                         
                          - - Refunds handled from the Flutterwave dashboard, not from WHMCS
                            - - No card-on-file, tokenization or recurring billing
                              - - NGN only
                                - - Fee amount not pulled from the webhook; addInvoicePayment fee is 0
                                  - - Dispute and chargeback webhooks not handled
                                    - 
