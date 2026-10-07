<?php
declare(strict_types=1);
// Dedicated raw-body endpoint; no browser session and no provider payload logging.
ini_set('display_errors','0');
require __DIR__ . '/config.php';
require __DIR__ . '/shop_products.php';
require __DIR__ . '/shop_checkout.php';
require __DIR__ . '/shop_payments.php';
require __DIR__ . '/shop_payment_providers.php';
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(['error'=>'Method not allowed.'],405);
if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 1048576) json_response(['error'=>'Webhook is too large.'],413);
$payload = file_get_contents('php://input',false,null,0,1048577);
if (!is_string($payload) || strlen($payload)>1048576) json_response(['error'=>'Webhook is too large.'],413);
try {
    (new ShopPaymentProviderRegistry())->processCallback(db(),'stripe',$payload,['stripe-signature'=>$_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '']);
    json_response(['received'=>true]);
} catch (ShopIgnoredStripeEvent) {
    json_response(['received'=>true,'ignored'=>true]);
} catch (ShopStripeUnavailable) {
    json_response(['error'=>'Webhook configuration or session binding is pending. Retry delivery.'],503);
} catch (DomainException) {
    json_response(['error'=>'Webhook verification or payment binding failed.'],400);
} catch (Throwable) {
    json_response(['error'=>'Payment confirmation could not be completed. Retry delivery.'],503);
}
