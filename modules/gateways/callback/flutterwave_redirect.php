<?php
/**
 * Flutterwave Payment Gateway — Click-through redirect
 *
 * Fires when a logged-in client clicks "Pay with Flutterwave" on an invoice.
 * Verifies session ownership of the invoice, initializes a payment via
 * Flutterwave v3 Standard Checkout API, and 302s the browser to the
 * hosted checkout URL.
 *
 * Never initializes a payment just from page views — only on explicit click.
 * Never trusts the POSTed invoice id without checking session ownership.
 *
 * Author:  Decipher Media Solutions LTD
 * Version: 1.0.0
 * API:     POST https://api.flutterwave.com/v3/payments
 */

require_once __DIR__ . "/../../../init.php";

use WHMCS\Database\Capsule;

App::load_function("gateway");

$gatewayModuleName = "flutterwave";
$gatewayParams     = getGatewayVariables($gatewayModuleName);

// --- 0. Gateway enabled? --------------------------------------------
if (!$gatewayParams["type"]) {
    http_response_code(503);
    die("Module not activated");
}

// --- 1. Require logged-in client session ----------------------------
if (empty($_SESSION["uid"])) {
    $systemUrl = rtrim($gatewayParams["systemurl"], "/");
    header("Location: " . $systemUrl . "/clientarea.php");
    die();
}
$clientId = (int) $_SESSION["uid"];

// --- 2. Pull + validate invoice id ----------------------------------
$invoiceId = (int) ($_POST["invoiceid"] ?? $_GET["invoiceid"] ?? 0);
if ($invoiceId <= 0) {
    http_response_code(400);
    die("Missing invoice id");
}

$invoice = Capsule::table("tblinvoices")->where("id", $invoiceId)->first();
if (!$invoice) {
    http_response_code(404);
    die("Invoice not found");
}

// Ownership check — session client MUST match invoice.userid.
if ((int) $invoice->userid !== $clientId) {
    logTransaction($gatewayModuleName, [
        "reason"      => "session does not own invoice",
        "invoiceid"   => $invoiceId,
        "session_uid" => $clientId,
    ], "Redirect Rejected");
    http_response_code(403);
    die("Forbidden");
}

// Already paid? Back to invoice.
if (strtolower($invoice->status) === "paid") {
    $systemUrl = rtrim($gatewayParams["systemurl"], "/");
    header("Location: " . $systemUrl . "/viewinvoice.php?id=" . $invoiceId);
    die();
}

// --- 3. Gather customer details -------------------------------------
$client = Capsule::table("tblclients")->where("id", $clientId)->first();
if (!$client) {
    http_response_code(500);
    die("Client record missing");
}
$customerName  = trim($client->firstname . " " . $client->lastname) ?: "Customer";
$customerEmail = $client->email;
$customerPhone = $client->phonenumber ?: "";

// --- 3b. Currency gate — NGN only for launch ------------------------
$invoiceCurrencyId  = $client->currency ?? 0;
$invoiceCurrencyRow = Capsule::table("tblcurrencies")
    ->where("id", $invoiceCurrencyId)
    ->first();
$invoiceCurrencyCode = $invoiceCurrencyRow ? $invoiceCurrencyRow->code : "";

if ($invoiceCurrencyCode !== "NGN") {
    logTransaction($gatewayModuleName, [
        "reason"           => "non-NGN invoice blocked at redirect",
        "invoice_currency" => $invoiceCurrencyCode,
        "invoiceid"        => $invoiceId,
    ], "Redirect Rejected");
    $systemUrl = rtrim($gatewayParams["systemurl"], "/");
    header("Location: " . $systemUrl . "/viewinvoice.php?id=" . $invoiceId . "&paymenterror=currency-unsupported");
    die();
}

// --- 4. Build reference + init call ---------------------------------
$refPrefix = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($gatewayParams["referencePrefix"] ?? "")) ?: "INV-";
$txRef     = $refPrefix . $invoiceId . "-" . time() . "-" . substr(bin2hex(random_bytes(4)), 0, 8);
$systemUrl = rtrim($gatewayParams["systemurl"], "/");

$payload = [
    "tx_ref"       => $txRef,
    "amount"       => (float) $invoice->total,
    "currency"     => "NGN",
    "redirect_url" => $systemUrl . "/viewinvoice.php?id=" . $invoiceId . "&paymentsuccess=true",
    "customer"     => [
        "email" => $customerEmail,
        "name"  => $customerName,
    ],
    "customizations" => [
        "title"       => $gatewayParams["companyName"] ?: "Checkout",
        "logo"        => $gatewayParams["companyLogo"] ?: "",
        "description" => "Invoice #" . $invoiceId,
    ],
    "meta" => [
        "invoice_id" => (string) $invoiceId,
        "client_id"  => (string) $clientId,
        "source"     => "whmcs",
    ],
];

if (!empty($customerPhone)) {
    $payload["customer"]["phonenumber"] = $customerPhone;
}

$ch = curl_init("https://api.flutterwave.com/v3/payments");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER  => true,
    CURLOPT_POST            => true,
    CURLOPT_POSTFIELDS      => json_encode($payload),
    CURLOPT_HTTPHEADER      => [
        "Authorization: Bearer " . trim($gatewayParams["secretKey"]),
        "Content-Type: application/json",
        "Accept: application/json",
    ],
    CURLOPT_TIMEOUT         => 20,
    CURLOPT_CONNECTTIMEOUT  => 10,
    CURLOPT_SSL_VERIFYPEER  => true,
    CURLOPT_SSL_VERIFYHOST  => 2,
    CURLOPT_IPRESOLVE       => CURL_IPRESOLVE_V4,
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

if ($response === false) {
    logTransaction($gatewayModuleName, [
        "error"      => "cURL failed",
        "curl_error" => $curlErr,
        "ref"        => $txRef,
        "invoiceid"  => $invoiceId,
    ], "Initialize Failed");
    header("Location: " . $systemUrl . "/viewinvoice.php?id=" . $invoiceId . "&paymenterror=gateway-unreachable");
    die();
}

$decoded = json_decode($response, true);

// Flutterwave v3 returns { status: "success", data: { link: "https://..." } }
if (!is_array($decoded) || ($decoded["status"] ?? "") !== "success" || empty($decoded["data"]["link"])) {
    logTransaction($gatewayModuleName, [
        "http"      => $httpCode,
        "ref"       => $txRef,
        "invoiceid" => $invoiceId,
        "message"   => $decoded["message"] ?? "unknown",
    ], "Initialize Failed");
    header("Location: " . $systemUrl . "/viewinvoice.php?id=" . $invoiceId . "&paymenterror=initialize-failed");
    die();
}

$checkoutUrl = $decoded["data"]["link"];

logTransaction($gatewayModuleName, [
    "invoiceid" => $invoiceId,
    "ref"       => $txRef,
    "amount"    => $invoice->total,
    "result"    => "checkout initialized",
], "Redirect Issued");

// --- 5. Redirect to hosted checkout ---------------------------------
header("Location: " . $checkoutUrl, true, 302);
die();
