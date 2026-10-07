<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__.'/../api/config.php';
require __DIR__.'/../api/shop_products.php';
require __DIR__.'/../api/shop_checkout.php';
require __DIR__.'/../api/shop_payments.php';
require __DIR__.'/../api/shop_payment_providers.php';
$pdo=db();$id=(int)($argv[1]??0);$baselineMax=(int)($argv[2]??0);$token=$argv[3]??'';
$stmt=$pdo->prepare('SELECT * FROM shop_orders WHERE id=?');$stmt->execute([$id]);$order=$stmt->fetch(PDO::FETCH_ASSOC);
if (!$order || $id<=$baselineMax || $order['customer_name']!=='Browser Guest' || $order['customer_email']!=='browser@example.com'
    || $order['order_origin']!=='checkout_v2' || $order['payment_status']!=='unpaid'
    || !hash_equals((string)$order['checkout_attempt_token'],$token)) throw new RuntimeException('Not the newly created browser fixture.');
$run=bin2hex(random_bytes(12));$sessionId='cs_test_'.$run;
$payment=shop_payment_create($pdo,$id,'stripe','browser-'.$run,$sessionId,'test');
$secret='whsec_'.bin2hex(random_bytes(24));
$event=['id'=>'evt_'.$run,'type'=>'checkout.session.completed','livemode'=>false,'data'=>['object'=>[
    'id'=>$sessionId,'object'=>'checkout.session','livemode'=>false,'mode'=>'payment','amount_total'=>shop_payment_cents($payment['amount']),
    'currency'=>'usd','status'=>'complete','payment_status'=>'paid','payment_intent'=>'pi_'.$run,'client_reference_id'=>(string)$id,
    'metadata'=>['dyndel_order_id'=>(string)$id,'dyndel_payment_id'=>(string)$payment['id'],'dyndel_mode'=>'test']]]];
$body=json_encode($event,JSON_THROW_ON_ERROR);$time=time();
$registry=new ShopPaymentProviderRegistry(null,static fn($provider,$mode,$fields)=>['webhook_secret'=>$secret]);
$registry->processCallback($pdo,'stripe',$body,['stripe-signature'=>'t='.$time.',v1='.hash_hmac('sha256',$time.'.'.$body,$secret)]);
echo "Signed fixture confirmed through G1; no gateway requests.\n";
