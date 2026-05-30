<?php
/**
 * Flutterwave Payment Gateway — Webhook / Callback
 *
 * Flutterwave POSTs a JSON payload here on every payment event.
 * We only care about "charge.completed" with status "successful".
 *
 * Trust model — we NEVER trust the webhook body on its own:
 *   1. Verify the `verif-hash` header against the webhook secret hash
 *      configured in the Flutterwave dashboard (static string match).
 *   2. Re-verify the transaction server-to-server via
 *      GET /v3/transactions/:id/verify with Bearer auth.
 *   3. Reconcile amount + currency + status against the WHMCS invoice.
 *   4. Only then call addInvoicePayment.
 *
 * Any single-step bypass is a bug.
 *
 * Author:  Decipher Media Solutions LTD
 * Version: 1.0.0
 * API:     Flutterwave v3 Standard Checkout
 */

require_once __DIR__ . "/../../../init.php";

use WHMCS\Database\Capsule;

App::load_function("gateway");
App::load_function("invoice");

$gatewayModuleName = "flutterwave";
$gatewayParams     = getGatewayVariables($gatewayModuleName);

// --- 0. Gateway enabled? --------------------------------------------
if (!$gatewayParams["type"]) {
    http_response_code(503);
    die("Module Not Activated");
}

// S-7: close the WHMCS session immediately. Webhook requests don't need
// session state, and leaving it open creates a new tblsessions row on
// every Flutterwave POST (including retries and tamper probes).
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

$secretKey   = trim($gatewayParams["secretKey"]);
$webhookHash = html_entity_decode(trim($gatewayParams["webhookHash"]), ENT_QUOTES, "UTF-8");

// --- 1. Read raw body + verif-hash header ----------------------------
$rawBody = file_get_contents("php://input");
$headers = function_exists("getallheaders") ? getallheaders() : [];
$verifHash = "";
foreach ($headers as $name => $value) {
    if (strtolower($name) === "verif-hash") {
        $verifHash = trim($value);
        break;
    }
}

if (empty($rawBody) || empty($verifHash) || empty($webhookHash)) {
    logTransaction($gatewayModuleName, ["reason" => "missing body/verif-hash/secret"], "Webhook Rejected");
    http_response_code(400);
    die("Bad request");
}

// --- 2. Verify webhook hash -----------------------------------------
// Flutterwave sends a `verif-hash` header that must match the secret
// hash configured in the Flutterwave dashboard. This is a static string
// comparison, NOT HMAC. See: https://developer.flutterwave.com/docs/webhooks
if (!hash_equals($webhookHash, $verifHash)) {
    // Posture C: reason only. No hashes, no lengths, no signatures.
    logTransaction($gatewayModuleName, ["reason" => "verif-hash mismatch"], "Webhook Rejected");
    http_response_code(401);
    die("Invalid signature");
}

// --- 3. Parse payload and filter events ------------------------------
$payload = json_decode($rawBody, true);
if (!is_array($payload) || !isset($payload["data"])) {
    logTransaction($gatewayModuleName, ["reason" => "payload not json or missing data"], "Webhook Rejected");
    http_response_code(400);
    die("Malformed");
}

$event = $payload["event"] ?? "";
if ($event !== "charge.completed") {
    // 200 so Flutterwave doesn't keep retrying — we acknowledged receipt
    logTransaction($gatewayModuleName, ["event" => $event], "Webhook Ignored (non-completed event)");
    http_response_code(200);
    die("OK");
}

$data = $payload["data"];
$transactionId = $data["id"]        ?? 0;
$txRef         = $data["tx_ref"]    ?? "";
$amountPaid    = $data["amount"]    ?? 0;
$currency      = $data["currency"]  ?? "";
$status        = $data["status"]    ?? "";
$meta          = $data["meta"]      ?? [];

// Flutterwave uses "successful" not "success" for completed charges
if ($status !== "successful") {
    logTransaction($gatewayModuleName, [
        "reason" => "webhook status not successful",
        "status" => $status,
        "ref"    => $txRef,
    ], "Webhook Ignored (non-success status)");
    http_response_code(200);
    die("OK");
}

// --- 4. Derive invoice id -------------------------------------------
// Prefer meta.invoice_id. Fall back to parsing the <prefix><id>-* tx_ref.
$invoiceIdFromMeta = $meta["invoice_id"] ?? null;
$refPrefix = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($gatewayParams["referencePrefix"] ?? "")) ?: "INV-";
$invoiceId = null;
if (!empty($invoiceIdFromMeta) && ctype_digit((string) $invoiceIdFromMeta)) {
    $invoiceId = (int) $invoiceIdFromMeta;
} elseif (preg_match('/^' . preg_quote($refPrefix, '/') . '(\d+)-/', $txRef, $m)) {
    $invoiceId = (int) $m[1];
}

if (!$invoiceId) {
    logTransaction($gatewayModuleName, ["reason" => "no invoice id", "ref" => $txRef], "Webhook Rejected");
    http_response_code(400);
    die("Missing invoice id");
}

// --- 5. Server-side re-verify ----------------------------------------
// Even though verif-hash is valid, pull the transaction directly from
// Flutterwave. Protects against replay attacks, tampered metadata, edge cases.
if (empty($transactionId)) {
    logTransaction($gatewayModuleName, [
        "reason" => "no transaction_id in payload",
        "ref"    => $txRef,
    ], "Webhook Rejected");
    http_response_code(400);
    die("Missing transaction id");
}

$verifyUrl = "https://api.flutterwave.com/v3/transactions/" . urlencode($transactionId) . "/verify";
$ch = curl_init($verifyUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER  => true,
    CURLOPT_HTTPHEADER      => [
        "Authorization: Bearer " . $secretKey,
        "Accept: application/json",
    ],
    CURLOPT_TIMEOUT         => 15,
    CURLOPT_CONNECTTIMEOUT  => 10,
    CURLOPT_SSL_VERIFYPEER  => true,
    CURLOPT_SSL_VERIFYHOST  => 2,
    CURLOPT_IPRESOLVE       => CURL_IPRESOLVE_V4,
]);
$verifyResp = curl_exec($ch);
$verifyHttp = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$verify = json_decode($verifyResp, true);
if (!is_array($verify) || ($verify["status"] ?? "") !== "success" || empty($verify["data"])) {
    logTransaction($gatewayModuleName, [
        "reason" => "verify call failed",
        "http"   => $verifyHttp,
        "ref"    => $txRef,
    ], "Webhook Rejected");
    http_response_code(500);
    die("Verify failed");
}

$vStatus   = $verify["data"]["status"]   ?? "";
$vAmount   = $verify["data"]["amount"]   ?? 0;
$vCurrency = $verify["data"]["currency"] ?? "";
$vTxRef    = $verify["data"]["tx_ref"]   ?? "";

// Flutterwave verify returns status "successful" for completed payments
if ($vStatus !== "successful") {
    logTransaction($gatewayModuleName, [
        "reason" => "server-verify not successful",
        "status" => $vStatus,
        "ref"    => $txRef,
    ], "Webhook Rejected");
    http_response_code(200);
    die("Not a successful charge");
}

// Cross-check: tx_ref from verify must match what the webhook sent
if ($vTxRef !== $txRef) {
    logTransaction($gatewayModuleName, [
        "reason"       => "tx_ref mismatch between webhook and verify",
        "webhook_ref"  => $txRef,
        "verify_ref"   => $vTxRef,
    ], "Webhook Rejected");
    http_response_code(409);
    die("Reference mismatch");
}

// --- 6. WHMCS helpers: invoice id + duplicate protection ------------
$invoiceId = checkCbInvoiceID($invoiceId, $gatewayParams["name"]);
checkCbTransID($txRef);

// --- 7. Amount + currency reconciliation ----------------------------
$invoice = Capsule::table("tblinvoices")->where("id", $invoiceId)->first();
if (!$invoice) {
    logTransaction($gatewayModuleName, [
        "reason"    => "invoice not found",
        "invoiceid" => $invoiceId,
    ], "Webhook Rejected");
    http_response_code(404);
    die("Invoice not found");
}

$invoiceTotal = (float) $invoice->total;

// WHMCS tblinvoices has no currency column.
// Currency lives on the client record (tblclients.currency -> tblcurrencies.id).
$client = Capsule::table("tblclients")->where("id", $invoice->userid)->first();
$invoiceCurrencyId = $client ? $client->currency : 0;
$invoiceCurrencyRow = Capsule::table("tblcurrencies")
    ->where("id", $invoiceCurrencyId)
    ->first();
$invoiceCurrencyCode = $invoiceCurrencyRow ? $invoiceCurrencyRow->code : "";

// Currency must match across all three sources: invoice, webhook, server-verify
if ($invoiceCurrencyCode !== $vCurrency || $vCurrency !== "NGN") {
    logTransaction($gatewayModuleName, [
        "reason"           => "currency mismatch",
        "invoice_currency" => $invoiceCurrencyCode,
        "verify_currency"  => $vCurrency,
        "ref"              => $txRef,
    ], "Webhook Rejected");
    http_response_code(409);
    die("Currency mismatch");
}

// Exact-amount enforcement. No partial payments for this gateway.
if (abs($vAmount - $invoiceTotal) > 0.01) {
    logTransaction($gatewayModuleName, [
        "reason" => "amount mismatch",
        "paid"   => $vAmount,
        "total"  => $invoiceTotal,
        "ref"    => $txRef,
    ], "Webhook Rejected");
    http_response_code(409);
    die("Amount mismatch");
}

// --- 8. Apply payment ------------------------------------------------
// S-8: idempotency guard — belt-and-braces on top of checkCbTransID.
$alreadyApplied = Capsule::table("tblaccounts")
    ->where("transid", $txRef)
    ->where("invoiceid", $invoiceId)
    ->exists();
if ($alreadyApplied) {
    logTransaction($gatewayModuleName, [
        "reason"    => "duplicate payment (idempotency guard)",
        "ref"       => $txRef,
        "invoiceid" => $invoiceId,
    ], "Webhook Ignored (already applied)");
    http_response_code(200);
    die("OK");
}

addInvoicePayment(
    $invoiceId,
    $txRef,       // transaction id
    $vAmount,     // amount
    0,            // fees (manual reconciliation)
    $gatewayModuleName
);

logTransaction($gatewayModuleName, [
    "invoiceid" => $invoiceId,
    "amount"    => $vAmount,
    "currency"  => $vCurrency,
    "ref"       => $txRef,
    "flw_id"    => $transactionId,
    "result"    => "payment applied",
], "Successful");

http_response_code(200);
die("OK");
