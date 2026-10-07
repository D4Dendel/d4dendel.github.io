<?php
declare(strict_types=1);
require __DIR__ . '/../api/config.php';
require __DIR__ . '/../api/shop_products.php';
require __DIR__ . '/../api/shop_checkout.php';
require __DIR__ . '/../api/shop_payments.php';
require __DIR__ . '/../api/shop_payment_providers.php';
require __DIR__ . '/../api/shop_stripe_checkout.php';
$checks=0;
function g3_expect(bool $condition,string $message): void { global $checks; if (!$condition) throw new RuntimeException($message); $checks++; }
function g3_reject(callable $work,string $message): void {
    try { $work(); } catch (DomainException|ShopStripeUnavailable|PDOException $error) { g3_expect(true,$message); return; }
    throw new RuntimeException($message);
}
function g3_snapshot(PDO $pdo): array {
    $state=[];
    foreach (['shop_products','shop_product_images','shop_product_badges','shop_orders','shop_order_items','shop_payments','shop_payment_events','site_settings','projects','content_entries','content_blocks','shop_shipping_zones','shop_shipping_methods','shop_shipping_zone_countries'] as $table) {
        $rows=$pdo->query("SELECT * FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC);
        if ($table==='shop_payments') foreach ($rows as &$row) { unset($row['provider_mode'],$row['provider_session_url'],$row['provider_session_expires_at']); } unset($row);
        $encoded=array_map(fn($row)=>json_encode($row,JSON_THROW_ON_ERROR),$rows); sort($encoded,SORT_STRING);
        $state[$table]=['count'=>count($rows),'hash'=>hash('sha256',implode("\n",$encoded))];
    } return $state;
}
function g3_mysql(string $database,string $sql): void {
    $process=proc_open(['C:\\xampp\\mysql\\bin\\mysql.exe','--host=127.0.0.1','--user=root','--database='.$database], [['pipe','r'],['pipe','w'],['pipe','w']],$pipes);
    fwrite($pipes[0],$sql);fclose($pipes[0]);stream_get_contents($pipes[1]);fclose($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[2]);
    g3_expect(proc_close($process)===0,'Migration failed: '.$error);
}
function g3_http(string $action,string $method='GET',array $data=[],?string $session=null): array {
    $curl=curl_init('http://127.0.0.1/dyndel-portfolio/api/index.php?action='.$action);
    curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_TIMEOUT=>10]);
    if ($method==='POST') curl_setopt($curl,CURLOPT_POSTFIELDS,http_build_query($data));
    if ($session) curl_setopt($curl,CURLOPT_COOKIE,'PHPSESSID='.$session);
    $body=curl_exec($curl);$status=curl_getinfo($curl,CURLINFO_RESPONSE_CODE);curl_close($curl);
    return [$status,json_decode($body,true,32,JSON_THROW_ON_ERROR),$body];
}
$pdo=db();$before=g3_snapshot($pdo);
$migration=file_get_contents(__DIR__.'/../migrations/20261007_shop_g3_stripe_test.sql');
$database=(string)$pdo->query('SELECT DATABASE()')->fetchColumn();
g3_mysql($database,$migration);g3_mysql($database,$migration);
g3_expect(g3_snapshot($pdo)===$before,'Migration rewrote existing business data.');
$providers=$pdo->query('SELECT * FROM shop_payment_providers ORDER BY provider')->fetchAll(PDO::FETCH_ASSOC);
$auto=$pdo->query("SELECT TABLE_NAME,AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('shop_products','shop_orders','shop_order_items','shop_payments','shop_payment_events')")->fetchAll(PDO::FETCH_KEY_PAIR);
$orders=[];$productId=null;$sessionId='g3test'.bin2hex(random_bytes(12));$sessionCreated=false;
$run=bin2hex(random_bytes(6));
// Ephemeral random signing material and an injected REST transport, never real credentials.
$credentials=['secret_key'=>'sk_test_'.bin2hex(random_bytes(24)),'webhook_secret'=>'whsec_'.bin2hex(random_bytes(24))];
$requests=[];
$transport=static function($path,$params,$idempotency,$unused) use (&$requests): array {
    $requests[]=['path'=>$path,'params'=>$params,'idempotency'=>$idempotency];
    return ['id'=>'cs_test_'.substr(hash('sha256',$idempotency),0,24),'livemode'=>false,'mode'=>'payment','status'=>'open','payment_status'=>'unpaid',
        'amount_total'=>$params['line_items'][0]['price_data']['unit_amount'],'currency'=>'usd','url'=>'https://checkout.stripe.com/c/pay/fixture',
        'expires_at'=>time()+3600,'metadata'=>array_reverse($params['metadata'],true),'client_reference_id'=>$params['client_reference_id']];
};
$adapter=new StripeTestAdapter($transport);
$loader=static fn($provider,$mode,$fields)=>$provider==='stripe' && $mode==='test' ? $credentials : [];
$registry=new ShopPaymentProviderRegistry(['paypal'=>new ShopUnconnectedProviderAdapter(),'stripe'=>$adapter],$loader);
$sign=static function(array $event,?int $time=null) use ($credentials): array {
    $payload=json_encode($event,JSON_THROW_ON_ERROR);$time??=time();
    return [$payload,['stripe-signature'=>'t='.$time.',v1='.hash_hmac('sha256',$time.'.'.$payload,$credentials['webhook_secret'])]];
};
try {
    $pdo->prepare("INSERT INTO shop_products(sku,slug,title,description,product_type,image_url,price,stock,publication_status,storefront_visible,purchase_action) VALUES (?,?,'G3 fixture','Temporary fixture','digital','img/icon.png',12.34,5,'published',1,'internal')")->execute(['G3-'.$run,'g3-'.$run]);
    $productId=(int)$pdo->lastInsertId();
    $newOrder=static function() use($pdo,&$orders,$productId): int {
        $pdo->exec("INSERT INTO shop_orders(customer_name,customer_email,currency,shipping_required,subtotal,total,status,order_origin,payment_status) VALUES ('G3 fixture','fixture@example.invalid','USD',0,12.34,12.34,'pending','checkout_v2','unpaid')");
        $id=(int)$pdo->lastInsertId();$orders[]=$id;
        $pdo->prepare("INSERT INTO shop_order_items(order_id,product_id,product_sku,product_name,product_type,currency,quantity,price,line_total) VALUES (?,?,'G3','G3 fixture','digital','USD',1,12.34,12.34)")->execute([$id,$productId]);
        return $id;
    };
    $first=$newOrder();$second=$newOrder();$failed=$newOrder();$cancelled=$newOrder();
    g3_expect(!$adapter->configuredForMode([],'test'),'Missing credentials enabled Stripe.');
    g3_expect($adapter->configuredForMode($credentials,'test') && !$adapter->configuredForMode($credentials,'live'),'Test/Live gate failed.');
    g3_expect(!$adapter->configuredForMode(['secret_key'=>'sk_live_'.bin2hex(random_bytes(12)),'webhook_secret'=>$credentials['webhook_secret']],'test'),'Live secret was accepted.');
    g3_reject(fn()=>shop_stripe_owned_order_id($first,[]),'Unrelated order initiation accepted.');
    g3_expect(shop_stripe_owned_order_id($first,['checkout_order_bindings'=>[$first=>'bound']])===$first,'Owned order rejected.');
    $pdo->exec("UPDATE shop_payment_providers SET enabled=0,mode='test' WHERE provider='stripe'");
    g3_reject(fn()=>shop_stripe_start($pdo,$registry,$first),'Disabled provider created attempt.');
    g3_expect(count($requests)===0,'Disabled provider contacted transport.');
    $pdo->exec("UPDATE shop_payment_providers SET enabled=1 WHERE provider='stripe'");
    g3_expect($registry->settings($pdo)[1]['available'],'Configured enabled Test adapter unavailable.');
    $started=shop_stripe_start($pdo,$registry,$first);
    $payment=$pdo->query('SELECT * FROM shop_payments WHERE id='.(int)$started['paymentId'])->fetch(PDO::FETCH_ASSOC);
    g3_expect($payment['provider']==='stripe' && $payment['provider_mode']==='test' && (int)$payment['order_id']===$first && $payment['amount']==='12.34' && $payment['currency']==='USD','Authoritative binding failed.');
    g3_expect($payment['provider_session_id']!==null && $payment['provider_session_url']===$started['url'],'Session assignment failed.');
    $params=$requests[0]['params'];
    g3_expect($params['line_items'][0]['price_data']['unit_amount']===1234 && $params['line_items'][0]['price_data']['currency']==='usd','Transport amount was not authoritative cents.');
    g3_expect($params['allowed_payment_method_types']===['card'] && !isset($params['payment_method_types']),'Current card filter incorrect.');
    g3_expect(str_contains($params['success_url'],'payment-return.html') && !str_contains($params['success_url'],$credentials['secret_key']),'Return URL exposed credentials.');
    g3_expect(shop_stripe_start($pdo,$registry,$first)===$started && count($requests)===1,'Retry created another session.');
    $pdo->exec("UPDATE shop_payment_providers SET enabled=0,mode='live' WHERE provider='stripe'");
    g3_expect(shop_stripe_start($pdo,$registry,$first)===$started,'Disabling invalidated existing session retry.');
    g3_expect($pdo->query('SELECT provider_mode FROM shop_payments WHERE id='.(int)$payment['id'])->fetchColumn()==='test','Admin rewrote attempt mode.');
    g3_reject(fn()=>shop_stripe_start($pdo,$registry,$second),'Disabled provider created a new attempt.');
    $event=['id'=>'evt_'.$run,'type'=>'checkout.session.completed','livemode'=>false,'data'=>['object'=>[
        'id'=>$payment['provider_session_id'],'object'=>'checkout.session','livemode'=>false,'mode'=>'payment','amount_total'=>1234,'currency'=>'usd',
        'status'=>'complete','payment_status'=>'paid','payment_intent'=>'pi_'.$run,'client_reference_id'=>(string)$first,
        'metadata'=>['dyndel_order_id'=>(string)$first,'dyndel_payment_id'=>(string)$payment['id'],'dyndel_mode'=>'test']]]];
    [$payload,$headers]=$sign($event);
    g3_reject(fn()=>$registry->processCallback($pdo,'stripe',$payload,['stripe-signature'=>'invalid']),'Invalid signature accepted.');
    [$oldPayload,$oldHeaders]=$sign($event,time()-301);
    g3_reject(fn()=>$registry->processCallback($pdo,'stripe',$oldPayload,$oldHeaders),'Expired signature accepted.');
    g3_reject(fn()=>$registry->processCallback($pdo,'stripe',$payload.' ',$headers),'Changed raw body accepted.');
    foreach (['mode','amount','currency','session','order','live','account'] as $case) {
        $bad=$event;$bad['id']='evt_bad'.$case.$run;
        if ($case==='mode') $bad['data']['object']['metadata']['dyndel_mode']='live';
        if ($case==='amount') $bad['data']['object']['amount_total']=1;
        if ($case==='currency') $bad['data']['object']['currency']='eur';
        if ($case==='session') $bad['data']['object']['id']='cs_test_wrong';
        if ($case==='order') { $bad['data']['object']['metadata']['dyndel_order_id']=(string)$second;$bad['data']['object']['client_reference_id']=(string)$second; }
        if ($case==='live') $bad['livemode']=true;
        if ($case==='account') $bad['account']='acct_fixture';
        [$badPayload,$badHeaders]=$sign($bad);
        g3_reject(fn()=>$registry->processCallback($pdo,'stripe',$badPayload,$badHeaders),'Invalid '.$case.' event accepted.');
    }
    g3_expect(shop_stripe_order_state($pdo,$first)['paymentStatus']==='unpaid','Browser-style state query marked order Paid.');
    $result=$registry->processCallback($pdo,'stripe',$payload,$headers);
    g3_expect($result['changed'] && $result['payment']['status']==='paid','Disabled-provider callback did not reach G1.');
    g3_expect($registry->processCallback($pdo,'stripe',$payload,$headers)['duplicate'],'Duplicate event was not idempotent.');
    $event['id']='evt_repeat'.$run;[$payload2,$headers2]=$sign($event);
    g3_expect(!$registry->processCallback($pdo,'stripe',$payload2,$headers2)['changed'],'Paid notification repeated transition.');
    $state=shop_stripe_order_state($pdo,$first);
    g3_expect($state['paymentStatus']==='paid' && $state['purchasedItems']===[['id'=>$productId,'quantity'=>1]],'Server-paid purchased snapshot incorrect.');
    g3_expect($pdo->query('SELECT status FROM shop_orders WHERE id='.$first)->fetchColumn()==='pending','Payment changed fulfillment.');

    $pdo->exec("UPDATE shop_payment_providers SET enabled=1,mode='test' WHERE provider='stripe'");
    foreach ([[$failed,'checkout.session.async_payment_failed','failed'],[$cancelled,'checkout.session.expired','cancelled']] as [$id,$type,$status]) {
        $session=shop_stripe_start($pdo,$registry,$id);
        $p=$pdo->query('SELECT * FROM shop_payments WHERE id='.(int)$session['paymentId'])->fetch(PDO::FETCH_ASSOC);
        $failure=$event;$failure['id']='evt_'.$status.$run;$failure['type']=$type;
        $failure['data']['object']=array_merge($failure['data']['object'],['id'=>$p['provider_session_id'],'status'=>$status==='cancelled'?'expired':'complete','payment_status'=>'unpaid','payment_intent'=>null,'client_reference_id'=>(string)$id,
            'metadata'=>['dyndel_order_id'=>(string)$id,'dyndel_payment_id'=>(string)$p['id'],'dyndel_mode'=>'test']]);
        [$pbody,$pheaders]=$sign($failure);$out=$registry->processCallback($pdo,'stripe',$pbody,$pheaders);
        g3_expect($out['payment']['status']===$status && shop_stripe_order_state($pdo,$id)['paymentStatus']==='unpaid','Terminal failure paid an order.');
    }
    // Network uncertainty preserves a pending attempt and identical provider idempotency.
    $uncertainCalls=[];
    $uncertain=new StripeTestAdapter(static function($path,$params,$key,$creds) use(&$uncertainCalls,$transport) {
        $uncertainCalls[]=$key;if(count($uncertainCalls)===1) throw new ShopStripeUnavailable('Fixture timeout.');
        return $transport($path,$params,$key,$creds);
    });
    $uncertainRegistry=new ShopPaymentProviderRegistry(['paypal'=>new ShopUnconnectedProviderAdapter(),'stripe'=>$uncertain],$loader);
    g3_reject(fn()=>shop_stripe_start($pdo,$uncertainRegistry,$second),'Uncertain transport was ignored.');
    $pdo->prepare('UPDATE shop_payments SET created_at=DATE_SUB(NOW(),INTERVAL 24 HOUR) WHERE order_id=?')->execute([$second]);
    g3_reject(fn()=>shop_stripe_start($pdo,$uncertainRegistry,$second),'Stale uncertain attempt recreated a session.');
    g3_expect(count($uncertainCalls)===1,'Stale attempt contacted Stripe after idempotency retention.');
    $pdo->prepare('UPDATE shop_payments SET created_at=NOW() WHERE order_id=?')->execute([$second]);
    shop_stripe_start($pdo,$uncertainRegistry,$second);
    g3_expect(count($uncertainCalls)===2 && $uncertainCalls[0]===$uncertainCalls[1],'Transport retry changed idempotency key.');
    $safe=json_encode($registry->settings($pdo));
    g3_expect(!str_contains($safe,$credentials['secret_key']) && !str_contains($safe,$credentials['webhook_secret']),'Registry leaked credential values.');
    g3_expect(!StripeTestAdapter::safeCheckoutUrl('https://checkout.stripe.com.evil.example/pay') && !StripeTestAdapter::safeCheckoutUrl('http://checkout.stripe.com/pay'),'Redirect allowlist failed.');
    $pdo->exec("UPDATE shop_payment_providers SET enabled=0,mode='test' WHERE provider='stripe'");
    session_id($sessionId);session_start();session_write_close();$sessionCreated=true;
    [$code,$created]=g3_http('checkout-order','POST',['attemptToken'=>bin2hex(random_bytes(16)),'items'=>json_encode([['id'=>$productId,'quantity'=>1]]),'customerName'=>'G3 fixture','customerEmail'=>'fixture@example.invalid'],$sessionId);
    g3_expect($code===201 && is_string($created['paymentCsrfToken']??null),'Checkout ownership/CSRF binding failed.');
    $owned=(int)$created['order']['id'];$orders[]=$owned;
    [$code,$ownedState]=g3_http('payment-order-state&orderId='.$owned,'GET',[],$sessionId);
    g3_expect($code===200 && $ownedState['state']['paymentStatus']==='unpaid','Owned status API failed.');
    [$code]=g3_http('payment-order-state&orderId='.$owned);
    g3_expect($code===422,'Unowned state disclosure allowed.');
    [$code]=g3_http('stripe-checkout-session','POST',['orderId'=>$owned,'csrfToken'=>'wrong'],$sessionId);
    g3_expect($code===403,'Payment initiation lacks CSRF protection.');
    [$code]=g3_http('stripe-checkout-session','POST',['orderId'=>$first,'csrfToken'=>$created['paymentCsrfToken']],$sessionId);
    g3_expect($code===422,'Unrelated order initiation allowed.');
    [$code]=g3_http('stripe-checkout-session','POST',['orderId'=>$owned,'csrfToken'=>$created['paymentCsrfToken'],'total'=>'0.01'],$sessionId);
    g3_expect($code===422,'Client amount accepted.');
    [$code,,$body]=g3_http('stripe-checkout-session','POST',['orderId'=>$owned,'csrfToken'=>$created['paymentCsrfToken']],$sessionId);
    g3_expect($code===503 && !str_contains($body,$credentials['secret_key']),'Disabled API started payment or leaked secrets.');
    g3_expect((int)$pdo->query('SELECT stock FROM shop_products WHERE id='.$productId)->fetchColumn()===5,'Payment changed fixture stock.');
    g3_expect((int)$pdo->query('SELECT COUNT(*) FROM shop_orders')->fetchColumn()===$before['shop_orders']['count']+count($orders),'Core deleted or duplicated orders.');
} finally {
    foreach($orders as $id) { $pdo->prepare('DELETE FROM shop_payment_events WHERE order_id=?')->execute([$id]);$pdo->prepare('DELETE FROM shop_payments WHERE order_id=?')->execute([$id]);$pdo->prepare('DELETE FROM shop_orders WHERE id=?')->execute([$id]); }
    if($productId) $pdo->prepare('DELETE FROM shop_products WHERE id=?')->execute([$productId]);
    foreach($providers as $setting) $pdo->prepare('UPDATE shop_payment_providers SET enabled=?,mode=?,created_at=?,updated_at=? WHERE provider=?')->execute([$setting['enabled'],$setting['mode'],$setting['created_at'],$setting['updated_at'],$setting['provider']]);
    foreach($auto as $table=>$id) if(ctype_digit((string)$id)) $pdo->exec("ALTER TABLE `{$table}` AUTO_INCREMENT=".(int)$id);
    if($sessionCreated) { session_id($sessionId);session_start();session_destroy(); }
    g3_expect(g3_snapshot($pdo)===$before,'Business state not restored.');
    g3_expect($pdo->query('SELECT * FROM shop_payment_providers ORDER BY provider')->fetchAll(PDO::FETCH_ASSOC)===$providers,'Provider settings not restored.');
}
echo 'G3 business rows before/after: '.json_encode(array_map(fn($row)=>$row['count'],$before))." (fingerprints identical).\n";
echo "Shop G3 Stripe Test tests passed: {$checks} assertions.\n";
