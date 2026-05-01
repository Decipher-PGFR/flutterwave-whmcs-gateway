<?php
/**
 * WHMCS Payment Gateway integration for Flutterwave
 *
 * Flutterwave is the payment processor. This file is the WHMCS integration
 * built and maintained by Decipher Media Solutions LTD for Decipher Hosting.
 *
 * Flow: Standard Checkout (hosted). Customer clicks Pay, we initialize a
 * Flutterwave payment server-to-server (on click, not on page render), then
 * redirect them to Flutterwave's hosted checkout. Authoritative confirmation
 * happens via webhook (callback/flutterwave.php) with server-side re-verify.
 *
 * Integration author:  Decipher Media Solutions LTD
 * License:             MIT
 * Version:             1.0.0
 * API:                 Flutterwave v3 Standard Checkout
 *
 * PCI Scope: SAQ-A. No card data ever touches our servers.
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

/**
 * Define module metadata.
 */
function flutterwave_MetaData()
{
    return [
        "DisplayName"                 => "Flutterwave (Decipher integration)",
        "APIVersion"                  => "1.1",
        "DisableLocalCreditCardInput" => true,
        "TokenisedStorage"            => false,
        "Description"                 => "WHMCS integration for Flutterwave v3 Standard Checkout. Supports card, bank transfer, USSD, and mobile money. Built and maintained by Decipher Media Solutions LTD. Webhook-verified with server-side re-verify and exact-amount reconciliation.",
        "IntegrationDeveloper"        => "Decipher Media Solutions LTD",
        "IntegrationDeveloperURL"     => "https://decipher.ng",
        "Category"                    => "Payments",
        "SupportURL"                  => "https://decipher.ng/start/",
        "Author"                      => "Decipher Media Solutions LTD",
    ];
}

/**
 * Define gateway configuration fields rendered under
 * Setup > Payments > Payment Gateways > Flutterwave.
 */
function flutterwave_config()
{
    return [
        "FriendlyName" => [
            "Type"  => "System",
            "Value" => "Flutterwave",
        ],
        "publicKey" => [
            "FriendlyName" => "Public Key",
            "Type"         => "text",
            "Size"         => "64",
            "Default"      => "",
            "Description"  => "Your Flutterwave public key (starts with FLWPUBK_).",
        ],
        "secretKey" => [
            "FriendlyName" => "Secret Key",
            "Type"         => "password",
            "Size"         => "64",
            "Default"      => "",
            "Description"  => "Your Flutterwave secret key (starts with FLWSECK_). Used for server-to-server calls.",
        ],
        "webhookHash" => [
            "FriendlyName" => "Webhook Secret Hash",
            "Type"         => "password",
            "Size"         => "64",
            "Default"      => "",
            "Description"  => 'Your webhook secret hash from <a href="https://dashboard.flutterwave.com/dashboard/settings/webhooks" target="_blank">Flutterwave Dashboard > Settings > Webhooks</a>. Used to verify webhook authenticity.',
        ],
        "companyName" => [
            "FriendlyName" => "Company Name",
            "Type"         => "text",
            "Size"         => "50",
            "Default"      => "Decipher Hosting",
            "Description"  => "Shown on the Flutterwave checkout page.",
        ],
        "companyLogo" => [
            "FriendlyName" => "Logo URL",
            "Type"         => "text",
            "Size"         => "100",
            "Default"      => "",
            "Description"  => "Full URL to your logo (square, https). Shown on checkout page.",
        ],
        "testMode" => [
            "FriendlyName" => "Test Mode",
            "Type"         => "yesno",
            "Description"  => "Tick when using FLWPUBK_TEST / FLWSECK_TEST keys.",
        ],
    ];
}

/**
 * Render the payment button shown on the invoice page.
 *
 * No network calls here. We render a form that POSTs to our redirect
 * endpoint on click, which does the actual payment initialization.
 */
function flutterwave_link($params)
{
    $invoiceId    = (int) $params["invoiceid"];
    $currencyCode = $params["currency"];
    $systemUrl    = rtrim($params["systemurl"], "/");
    $testMode     = !empty($params["testMode"]);

    // Currency gate: NGN only for launch. Flutterwave supports more, but
    // we gate here to match our storefront pricing and avoid reconciliation
    // issues. Expand when multi-currency invoicing is needed.
    if ($currencyCode !== "NGN") {
        return '<div style="color:#b91c1c;font-size:0.9rem">Flutterwave payments are currently NGN-only. Please change invoice currency to NGN.</div>';
    }
    if (empty($params["secretKey"])) {
        return '<div style="color:#b91c1c;font-size:0.9rem">Flutterwave is not configured. Contact support.</div>';
    }

    $action = htmlspecialchars($systemUrl . "/modules/gateways/callback/flutterwave_redirect.php", ENT_QUOTES, "UTF-8");
    $label  = $testMode ? "Pay with Flutterwave (TEST)" : "Pay with Flutterwave";

    return <<<HTML
<form action="{$action}" method="post" style="margin:0;">
  <input type="hidden" name="invoiceid" value="{$invoiceId}">
  <button type="submit" style="
      display:inline-flex;align-items:center;justify-content:center;
      padding:12px 24px;border:0;border-radius:8px;
      font-family:inherit;font-weight:600;font-size:0.95rem;
      background:#f5a623;color:#fff;cursor:pointer;">
    {$label}
  </button>
</form>
HTML;
}
