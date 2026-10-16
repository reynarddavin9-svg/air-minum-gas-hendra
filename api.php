<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';

try {
    $path = trim((string)($_GET['path'] ?? ''), '/');
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $pdo = db();
    upgrade_schema($pdo);

    if ($method === 'GET' && $path === 'site') json_response(get_settings());

    if ($method === 'GET' && $path === 'products') {
        $rows = $pdo->query('SELECT * FROM products WHERE active=1 ORDER BY category,id')->fetchAll();
        json_response(array_map('cast_product', $rows));
    }


    // Chat langsung pembeli <-> penjual tanpa nomor telepon / nomor pesanan.
    if ($method === 'POST' && $path === 'chat/session') {
        $d=request_json();$token=clean_text($d['visitor_token']??'',100);
        if(!preg_match('/^[A-Za-z0-9_-]{16,100}$/',$token))json_response(['error'=>'Sesi chat tidak valid. Muat ulang halaman.'],400);
        $st=$pdo->prepare('SELECT id,customer_name,last_order_id FROM visitor_chat_sessions WHERE visitor_token=? LIMIT 1');$st->execute([$token]);$session=$st->fetch();
        if(!$session){
            $now=now_sql();$ins=$pdo->prepare('INSERT INTO visitor_chat_sessions(visitor_token,customer_name,last_order_id,created_at,updated_at) VALUES(?,NULL,NULL,?,?)');$ins->execute([$token,$now,$now]);
            $sid=(int)$pdo->lastInsertId();$welcome=clean_text(get_settings()['chat_welcome_message']??'',1000);
            if($welcome!==''){$wm=$pdo->prepare('INSERT INTO visitor_chat_messages(session_id,sender,message,created_at) VALUES(?,?,?,?)');$wm->execute([$sid,'seller',$welcome,$now]);}
            $session=['id'=>$sid,'customer_name'=>null,'last_order_id'=>null];
        }
        json_response(['session_id'=>(int)$session['id'],'customer_name'=>$session['customer_name'],'last_order_id'=>$session['last_order_id']?(int)$session['last_order_id']:null,'seller_name'=>get_settings()['store_name']??'Hendra']);
    }

    if ($method === 'GET' && $path === 'chat') {
        $token=clean_text($_GET['visitor_token']??'',100);
        if(!preg_match('/^[A-Za-z0-9_-]{16,100}$/',$token))json_response(['error'=>'Sesi chat tidak valid.'],400);
        $st=$pdo->prepare('SELECT id,customer_name,last_order_id FROM visitor_chat_sessions WHERE visitor_token=? LIMIT 1');$st->execute([$token]);$session=$st->fetch();
        if(!$session)json_response(['error'=>'Percakapan belum dibuat.'],404);
        $msg=$pdo->prepare('SELECT id,sender,message,created_at FROM visitor_chat_messages WHERE session_id=? ORDER BY id ASC LIMIT 300');$msg->execute([(int)$session['id']]);$messages=$msg->fetchAll();
        foreach($messages as &$x)$x['id']=(int)$x['id'];
        json_response(['session_id'=>(int)$session['id'],'customer_name'=>$session['customer_name'],'last_order_id'=>$session['last_order_id']?(int)$session['last_order_id']:null,'seller_name'=>get_settings()['store_name']??'Hendra','messages'=>$messages]);
    }

    if ($method === 'POST' && $path === 'chat') {
        $d=request_json();$token=clean_text($d['visitor_token']??'',100);$message=clean_text($d['message']??'',1000);
        if(!preg_match('/^[A-Za-z0-9_-]{16,100}$/',$token))json_response(['error'=>'Sesi chat tidak valid.'],400);
        if($message==='')json_response(['error'=>'Pesan tidak boleh kosong.'],400);
        $st=$pdo->prepare('SELECT id FROM visitor_chat_sessions WHERE visitor_token=? LIMIT 1');$st->execute([$token]);$sid=(int)($st->fetchColumn()?:0);
        if(!$sid)json_response(['error'=>'Percakapan belum dibuat.'],404);
        $now=now_sql();$ins=$pdo->prepare('INSERT INTO visitor_chat_messages(session_id,sender,message,created_at) VALUES(?,?,?,?)');$ins->execute([$sid,'buyer',$message,$now]);
        $pdo->prepare('UPDATE visitor_chat_sessions SET updated_at=? WHERE id=?')->execute([$now,$sid]);
        json_response(['success'=>true,'id'=>(int)$pdo->lastInsertId()],201);
    }

    if ($method === 'GET' && $path === 'orders/track') {
        $phone = trim((string)($_GET['phone'] ?? ''));
        if ($phone === '') json_response(['error'=>'Nomor WhatsApp/telepon wajib diisi.'],400);
        $st = $pdo->prepare('SELECT id,customer_name,phone,address,city,payment_method,payment_status,notes,subtotal,delivery_fee,total,status,eta_start,eta_end,shipped_at,delivered_at,created_at,paid_at FROM orders WHERE phone=? ORDER BY id DESC LIMIT 10');
        $st->execute([$phone]);
        $orders = $st->fetchAll();
        $it = $pdo->prepare('SELECT product_name,price,quantity FROM order_items WHERE order_id=?');
        foreach ($orders as &$o) {
            $o['id']=(int)$o['id']; $o['subtotal']=(int)$o['subtotal']; $o['delivery_fee']=(int)$o['delivery_fee']; $o['total']=(int)$o['total'];
            $o['payment_due_at']=$o['payment_method']==='COD'?null:payment_due_at((string)$o['created_at']);
            $it->execute([$o['id']]);
            $items=$it->fetchAll(); foreach($items as &$x){$x['price']=(int)$x['price'];$x['quantity']=(int)$x['quantity'];}
            $o['items']=$items;
        }
        json_response($orders);
    }

    // Chat pembeli untuk satu pesanan. Pada prototype ini pembeli cukup memakai nomor pesanan.
    if ($method === 'GET' && preg_match('#^orders/(\d+)/chat$#',$path,$m)) {
        $st=$pdo->prepare('SELECT id,customer_name,status FROM orders WHERE id=? LIMIT 1');
        $st->execute([(int)$m[1]]);$order=$st->fetch();
        if(!$order)json_response(['error'=>'Pesanan tidak ditemukan. Periksa nomor pesanan.'],404);
        $msg=$pdo->prepare('SELECT id,sender,message,created_at FROM order_messages WHERE order_id=? ORDER BY id ASC LIMIT 200');
        $msg->execute([(int)$m[1]]);$messages=$msg->fetchAll();
        foreach($messages as &$x)$x['id']=(int)$x['id'];
        $s=get_settings();
        json_response(['order_id'=>(int)$order['id'],'customer_name'=>$order['customer_name'],'status'=>$order['status'],'seller_name'=>$s['store_name']??'Hendra','messages'=>$messages]);
    }

    if ($method === 'POST' && preg_match('#^orders/(\d+)/chat$#',$path,$m)) {
        $d=request_json();$message=clean_text($d['message']??'',1000);
        if($message==='')json_response(['error'=>'Pesan tidak boleh kosong.'],400);
        $st=$pdo->prepare('SELECT id FROM orders WHERE id=? LIMIT 1');$st->execute([(int)$m[1]]);
        if(!$st->fetch())json_response(['error'=>'Pesanan tidak ditemukan.'],404);
        $ins=$pdo->prepare('INSERT INTO order_messages(order_id,sender,message,created_at) VALUES(?,?,?,?)');
        $ins->execute([(int)$m[1],'buyer',$message,now_sql()]);
        json_response(['success'=>true,'id'=>(int)$pdo->lastInsertId()],201);
    }


    if ($method === 'GET' && $path === 'admin/chats') {
        if(!admin_ok())json_response(['error'=>'Akses ditolak.'],401);
        $rows=$pdo->query("SELECT s.id,s.customer_name,s.last_order_id,s.created_at,s.updated_at,(SELECT COUNT(*) FROM visitor_chat_messages m WHERE m.session_id=s.id) AS message_count,(SELECT m2.message FROM visitor_chat_messages m2 WHERE m2.session_id=s.id ORDER BY m2.id DESC LIMIT 1) AS last_message FROM visitor_chat_sessions s ORDER BY s.updated_at DESC LIMIT 100")->fetchAll();
        foreach($rows as &$r){$r['id']=(int)$r['id'];$r['message_count']=(int)$r['message_count'];$r['last_order_id']=$r['last_order_id']?(int)$r['last_order_id']:null;}
        json_response($rows);
    }

    if ($method === 'GET' && preg_match('#^admin/chats/(\d+)$#',$path,$m)) {
        if(!admin_ok())json_response(['error'=>'Akses ditolak.'],401);
        $st=$pdo->prepare('SELECT id,customer_name,last_order_id,created_at,updated_at FROM visitor_chat_sessions WHERE id=? LIMIT 1');$st->execute([(int)$m[1]]);$session=$st->fetch();
        if(!$session)json_response(['error'=>'Percakapan tidak ditemukan.'],404);
        $msg=$pdo->prepare('SELECT id,sender,message,created_at FROM visitor_chat_messages WHERE session_id=? ORDER BY id ASC LIMIT 300');$msg->execute([(int)$m[1]]);$messages=$msg->fetchAll();
        foreach($messages as &$x)$x['id']=(int)$x['id'];
        $session['id']=(int)$session['id'];$session['last_order_id']=$session['last_order_id']?(int)$session['last_order_id']:null;$session['messages']=$messages;
        json_response($session);
    }

    if ($method === 'POST' && preg_match('#^admin/chats/(\d+)$#',$path,$m)) {
        $d=request_json();if(!admin_ok($d))json_response(['error'=>'Akses ditolak.'],401);$message=clean_text($d['message']??'',1000);
        if($message==='')json_response(['error'=>'Pesan tidak boleh kosong.'],400);
        $st=$pdo->prepare('SELECT id FROM visitor_chat_sessions WHERE id=? LIMIT 1');$st->execute([(int)$m[1]]);if(!$st->fetch())json_response(['error'=>'Percakapan tidak ditemukan.'],404);
        $now=now_sql();$ins=$pdo->prepare('INSERT INTO visitor_chat_messages(session_id,sender,message,created_at) VALUES(?,?,?,?)');$ins->execute([(int)$m[1],'seller',$message,$now]);
        $pdo->prepare('UPDATE visitor_chat_sessions SET updated_at=? WHERE id=?')->execute([$now,(int)$m[1]]);
        json_response(['success'=>true,'id'=>(int)$pdo->lastInsertId()],201);
    }

    if ($method === 'GET' && $path === 'admin/products') {
        if (!admin_ok()) json_response(['error'=>'Akses ditolak.'],401);
        $rows=$pdo->query('SELECT * FROM products WHERE active=1 ORDER BY category,id')->fetchAll();
        json_response(array_map('cast_product',$rows));
    }

    if ($method === 'GET' && $path === 'admin/orders') {
        if (!admin_ok()) json_response(['error'=>'Akses ditolak.'],401);
        $rows=$pdo->query('SELECT o.*, (SELECT COUNT(*) FROM order_messages m WHERE m.order_id=o.id) AS chat_count FROM orders o ORDER BY o.id DESC LIMIT 50')->fetchAll();
        foreach($rows as &$o){$o['id']=(int)$o['id'];$o['total']=(int)$o['total'];$o['subtotal']=(int)$o['subtotal'];$o['delivery_fee']=(int)$o['delivery_fee'];$o['chat_count']=(int)$o['chat_count'];$o['payment_due_at']=$o['payment_method']==='COD'?null:payment_due_at((string)$o['created_at']);}
        json_response($rows);
    }

    if ($method === 'GET' && preg_match('#^admin/orders/(\d+)/chat$#',$path,$m)) {
        if(!admin_ok())json_response(['error'=>'Akses ditolak.'],401);
        $st=$pdo->prepare('SELECT id,customer_name,phone,status FROM orders WHERE id=? LIMIT 1');$st->execute([(int)$m[1]]);$order=$st->fetch();
        if(!$order)json_response(['error'=>'Pesanan tidak ditemukan.'],404);
        $msg=$pdo->prepare('SELECT id,sender,message,created_at FROM order_messages WHERE order_id=? ORDER BY id ASC LIMIT 200');$msg->execute([(int)$m[1]]);$messages=$msg->fetchAll();
        foreach($messages as &$x)$x['id']=(int)$x['id'];
        json_response(['order_id'=>(int)$order['id'],'customer_name'=>$order['customer_name'],'phone'=>$order['phone'],'status'=>$order['status'],'messages'=>$messages]);
    }

    if ($method === 'POST' && preg_match('#^admin/orders/(\d+)/chat$#',$path,$m)) {
        $d=request_json();if(!admin_ok($d))json_response(['error'=>'Akses ditolak.'],401);$message=clean_text($d['message']??'',1000);
        if($message==='')json_response(['error'=>'Pesan tidak boleh kosong.'],400);
        $st=$pdo->prepare('SELECT id FROM orders WHERE id=? LIMIT 1');$st->execute([(int)$m[1]]);if(!$st->fetch())json_response(['error'=>'Pesanan tidak ditemukan.'],404);
        $ins=$pdo->prepare('INSERT INTO order_messages(order_id,sender,message,created_at) VALUES(?,?,?,?)');$ins->execute([(int)$m[1],'seller',$message,now_sql()]);
        json_response(['success'=>true,'id'=>(int)$pdo->lastInsertId()],201);
    }

    if ($method === 'GET' && $path === 'admin/export') {
        if (!admin_ok()) json_response(['error'=>'Akses ditolak.'],401);
        if (!class_exists('ZipArchive')) json_response(['error'=>'Fitur ZIP tidak tersedia pada server ini.'],501);
        $tmp=tempnam(sys_get_temp_dir(),'hendra_'); $zipPath=$tmp.'.zip'; @unlink($tmp);
        $zip=new ZipArchive(); if($zip->open($zipPath,ZipArchive::CREATE)!==true) json_response(['error'=>'Gagal membuat ZIP.'],500);
        foreach(['index.php','index.html','api.php','lib.php','.htaccess','static/styles.css','static/app.js','README_INFINITYFREE.txt'] as $f){$full=__DIR__.'/'.$f;if(is_file($full))$zip->addFile($full,$f);}
        $backup=['settings'=>get_settings(),'products'=>$pdo->query('SELECT * FROM products ORDER BY id')->fetchAll(),'orders'=>$pdo->query('SELECT * FROM orders ORDER BY id')->fetchAll(),'order_items'=>$pdo->query('SELECT * FROM order_items ORDER BY id')->fetchAll(),'order_messages'=>$pdo->query('SELECT * FROM order_messages ORDER BY id')->fetchAll(),'visitor_chat_sessions'=>$pdo->query('SELECT * FROM visitor_chat_sessions ORDER BY id')->fetchAll(),'visitor_chat_messages'=>$pdo->query('SELECT * FROM visitor_chat_messages ORDER BY id')->fetchAll()];
        $zip->addFromString('backup_data.json',json_encode($backup,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
        $zip->close();
        header('Content-Type: application/zip'); header('Content-Disposition: attachment; filename="Air_Minum_Gas_Hendra_Hasil_Edit.zip"'); header('Content-Length: '.filesize($zipPath)); readfile($zipPath); @unlink($zipPath); exit;
    }

    if ($method === 'POST' && $path === 'orders') {
        $d=request_json();
        foreach(['customer_name','phone','address','city','payment_method','items'] as $k){if(empty($d[$k]))json_response(['error'=>'Data pesanan belum lengkap.'],400);}
        if(!is_array($d['items'])||!$d['items'])json_response(['error'=>'Keranjang masih kosong.'],400);

        $s=get_settings();
        $paymentMethod=clean_text($d['payment_method']??'',50);
        $allowedMethods=['Transfer Bank'];
        $customerCity=clean_text($d['city']??'',100);
        if(cod_city_allowed($customerCity,$s))$allowedMethods[]='COD';
        if($paymentMethod==='COD' && !cod_city_allowed($customerCity,$s)){
            json_response(['error'=>'COD hanya tersedia untuk '.($s['store_city']??'Palembang').'. Untuk kota lain gunakan transfer bank.'],400);
        }
        if(!in_array($paymentMethod,$allowedMethods,true))json_response(['error'=>'Metode pembayaran tidak tersedia. Silakan muat ulang halaman.'],400);

        try {
            $pdo->beginTransaction(); $norm=[];$subtotal=0; $sel=$pdo->prepare('SELECT * FROM products WHERE id=? AND active=1 FOR UPDATE');
            foreach($d['items'] as $item){
                $pid=(int)($item['product_id']??0);$qty=(int)($item['quantity']??0);
                if($qty<=0)throw new InvalidArgumentException('Jumlah produk tidak valid.');
                $sel->execute([$pid]);$p=$sel->fetch();
                if(!$p)throw new InvalidArgumentException('Produk tidak ditemukan.');
                if((int)$p['stock']<$qty)throw new InvalidArgumentException('Stok '.$p['name'].' tidak cukup. Sisa stok: '.$p['stock'].'.');
                $subtotal+=(int)$p['price']*$qty;$norm[]=[$p,$qty];
            }
            $feeDefault=max(0,(int)($s['delivery_fee']??5000));$freeMin=max(0,(int)($s['free_delivery_minimum']??100000));
            $fee=$subtotal>=$freeMin?0:$feeDefault;$total=$subtotal+$fee;$created=now_sql();$eta=delivery_eta($created,false);
            $paymentStatus=$paymentMethod==='COD'?'Bayar di tempat':'Belum dibayar';
            $orderStatus=$paymentMethod==='COD'?'Pesanan diterima':'Menunggu pembayaran';
            $st=$pdo->prepare('INSERT INTO orders(customer_name,phone,address,city,payment_method,payment_status,notes,subtotal,delivery_fee,total,status,eta_start,eta_end,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $st->execute([clean_text($d['customer_name'],120),clean_text($d['phone'],50),clean_text($d['address'],500),$customerCity,$paymentMethod,$paymentStatus,clean_text($d['notes']??'',500),$subtotal,$fee,$total,$orderStatus,$eta['start'],$eta['end'],$created]);
            $oid=(int)$pdo->lastInsertId();
            $ins=$pdo->prepare('INSERT INTO order_items(order_id,product_id,product_name,price,quantity) VALUES(?,?,?,?,?)');
            $upd=$pdo->prepare('UPDATE products SET stock=stock-? WHERE id=?');
            foreach($norm as [$p,$qty]){$ins->execute([$oid,$p['id'],$p['name'],$p['price'],$qty]);$upd->execute([$qty,$p['id']]);}
            $welcome=clean_text($s['chat_welcome_message']??'',1000);
            if($welcome!==''){$wm=$pdo->prepare('INSERT INTO order_messages(order_id,sender,message,created_at) VALUES(?,?,?,?)');$wm->execute([$oid,'seller',$welcome,$created]);}

            // Jika browser pembeli sudah memiliki sesi chat, kaitkan otomatis dengan nama dan pesanan terbaru.
            $chatToken=clean_text($d['chat_token']??'',100);
            if(preg_match('/^[A-Za-z0-9_-]{16,100}$/',$chatToken)){
                $cs=$pdo->prepare('SELECT id FROM visitor_chat_sessions WHERE visitor_token=? LIMIT 1');$cs->execute([$chatToken]);$chatSessionId=(int)($cs->fetchColumn()?:0);
                if(!$chatSessionId){
                    $ci=$pdo->prepare('INSERT INTO visitor_chat_sessions(visitor_token,customer_name,last_order_id,created_at,updated_at) VALUES(?,?,?,?,?)');
                    $ci->execute([$chatToken,clean_text($d['customer_name'],120),$oid,$created,$created]);$chatSessionId=(int)$pdo->lastInsertId();
                    if($welcome!==''){$cm=$pdo->prepare('INSERT INTO visitor_chat_messages(session_id,sender,message,created_at) VALUES(?,?,?,?)');$cm->execute([$chatSessionId,'seller',$welcome,$created]);}
                }else{
                    $cu=$pdo->prepare('UPDATE visitor_chat_sessions SET customer_name=?,last_order_id=?,updated_at=? WHERE id=?');$cu->execute([clean_text($d['customer_name'],120),$oid,$created,$chatSessionId]);
                }
            }
            $pdo->commit();
            json_response(['success'=>true,'order_id'=>$oid,'subtotal'=>$subtotal,'delivery_fee'=>$fee,'total'=>$total,'status'=>$orderStatus,'payment_status'=>$paymentStatus,'payment_method'=>$paymentMethod,'requires_payment_proof'=>$paymentMethod!=='COD','created_at'=>$created,'payment_due_at'=>$paymentMethod==='COD'?null:payment_due_at($created),'eta_start'=>$eta['start'],'eta_end'=>$eta['end'],'city'=>$customerCity],201);
        } catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();json_response(['error'=>$e->getMessage()],400);}
    }

    if ($method === 'POST' && preg_match('#^orders/(\d+)/payment-proof$#',$path,$m)) {
        $d=request_json();
        $phone=clean_text($d['phone']??'',50);
        $proof=valid_image_data($d['image_data']??'');
        if($phone===''||$proof==='')json_response(['error'=>'Nomor telepon dan bukti pembayaran wajib diisi.'],400);
        $st=$pdo->prepare('SELECT id,payment_method,status FROM orders WHERE id=? AND phone=? LIMIT 1');
        $st->execute([(int)$m[1],$phone]);$order=$st->fetch();
        if(!$order)json_response(['error'=>'Pesanan tidak ditemukan. Pastikan nomor telepon sama dengan saat checkout.'],404);
        if($order['payment_method']==='COD')json_response(['error'=>'Pesanan COD tidak memerlukan bukti transfer.'],400);
        if($order['status']==='Dibatalkan')json_response(['error'=>'Pesanan sudah dibatalkan.'],400);
        $up=$pdo->prepare("UPDATE orders SET payment_proof=?, payment_status='Menunggu verifikasi', status='Menunggu verifikasi pembayaran' WHERE id=?");
        $up->execute([$proof,(int)$m[1]]);
        json_response(['success'=>true,'payment_status'=>'Menunggu verifikasi','status'=>'Menunggu verifikasi pembayaran']);
    }

    if ($method === 'POST' && preg_match('#^orders/(\d+)/received$#',$path,$m)) {
        $d=request_json();$phone=clean_text($d['phone']??'',50);$id=(int)$m[1];
        if($phone==='')json_response(['error'=>'Nomor telepon wajib diisi.'],400);
        $st=$pdo->prepare('SELECT * FROM orders WHERE id=? AND phone=? LIMIT 1');$st->execute([$id,$phone]);$order=$st->fetch();
        if(!$order)json_response(['error'=>'Pesanan tidak ditemukan.'],404);
        if($order['status']==='Selesai')json_response(['success'=>true,'status'=>'Selesai','payment_status'=>$order['payment_status'],'delivered_at'=>$order['delivered_at']]);
        if($order['status']!=='Dalam pengantaran')json_response(['error'=>'Pesanan hanya dapat dikonfirmasi diterima setelah status Dalam pengantaran.'],400);
        $delivered=now_sql();$pay=$order['payment_method']==='COD'?'Sudah dibayar':$order['payment_status'];
        $doneDate=substr($delivered,0,10);
        $up=$pdo->prepare("UPDATE orders SET status='Selesai',payment_status=?,delivered_at=?,eta_start=?,eta_end=? WHERE id=?");
        $up->execute([$pay,$delivered,$doneDate,$doneDate,$id]);
        json_response(['success'=>true,'status'=>'Selesai','payment_status'=>$pay,'delivered_at'=>$delivered]);
    }

    if ($method === 'POST' && $path === 'ai/recommend') {
        $d=request_json();$text=mb_strtolower(clean_text($d['text']??'',1000),'UTF-8');$household=max(1,min(20,(int)($d['household']??1)));$rows=$pdo->query('SELECT * FROM products WHERE active=1 AND stock>0 ORDER BY price')->fetchAll();$waters=[];$gases=[];foreach($rows as $p){if(str_contains(mb_strtolower($p['category'].' '.$p['name'],'UTF-8'),'air')||str_contains(mb_strtolower($p['name'],'UTF-8'),'galon'))$waters[]=$p;if(str_contains(mb_strtolower($p['category'].' '.$p['name'],'UTF-8'),'gas')||str_contains(mb_strtolower($p['name'],'UTF-8'),'lpg'))$gases[]=$p;}
        $recs=[];$exp=[];$has=function(array $words)use($text){foreach($words as $w)if(str_contains($text,$w))return true;return false;};
        if($has(['gas','masak','dapur','kompor'])&&$gases){$chosen=$household<=3?$gases[0]:$gases[min(count($gases)-1,intdiv(count($gases),2))];$recs[]=$chosen['name'];$exp[]=$chosen['name'].' dipilih dari produk gas yang tersedia untuk kebutuhan rumah sekitar '.$household.' orang.';}
        if($has(['air','minum','galon','haus'])||!$recs){if($waters){$chosen=$has(['hemat','murah'])?$waters[0]:$waters[count($waters)-1];$recs[]=$chosen['name'];$exp[]=$chosen['name'].' tersedia pada katalog dan dapat disesuaikan dengan pola konsumsi '.$household.' orang.';}}
        if(!$recs&&$rows){$recs[]=$rows[0]['name'];$exp[]='Rekomendasi awal dipilih dari produk yang sedang tersedia.';}
        json_response(['recommendations'=>$recs?:['Belum ada produk tersedia'],'explanation'=>$exp?:['Silakan perbarui stok melalui Dashboard Admin.'],'ethics_note'=>'Rekomendasi bersifat bantuan keputusan, bukan kepastian. Harga, stok, dan kebutuhan nyata tetap harus diperiksa pengguna.']);
    }

    if ($method === 'POST' && $path === 'admin/login') {$d=request_json();if(!admin_ok($d))json_response(['error'=>'PIN admin salah.'],401);json_response(['success'=>true]);}

    if ($method === 'POST' && $path === 'admin/products') {
        $d=request_json();if(!admin_ok($d))json_response(['error'=>'Akses ditolak.'],401);
        try{$name=clean_text($d['name']??'',120);$cat=clean_text($d['category']??'',80);$unit=clean_text($d['unit']??'',50);$desc=clean_text($d['description']??'',800);$emoji=clean_text($d['emoji']??'📦',10);$price=(int)($d['price']??0);$stock=(int)($d['stock']??0);$img=valid_image_data($d['image_data']??'');if($name===''||$cat===''||$unit===''||$price<0||$stock<0)throw new InvalidArgumentException('Data produk belum lengkap atau tidak valid.');$st=$pdo->prepare('INSERT INTO products(name,category,price,stock,unit,description,emoji,image_data,active) VALUES(?,?,?,?,?,?,?,?,1)');$st->execute([$name,$cat,$price,$stock,$unit,$desc,$emoji,$img]);json_response(['success'=>true,'id'=>(int)$pdo->lastInsertId()],201);}catch(Throwable $e){json_response(['error'=>$e->getMessage()],400);}
    }

    if ($method === 'PATCH' && $path === 'admin/site') {
        $d=request_json();if(!admin_ok($d))json_response(['error'=>'Akses ditolak.'],401);$allowed=array_keys(DEFAULT_SETTINGS);
        try{
            $updates=[];
            foreach($allowed as $k){
                if(!array_key_exists($k,$d))continue;
                if(in_array($k,['logo_image_data','hero_image_data'],true))$v=valid_image_data($d[$k]??'');
                elseif(in_array($k,['delivery_fee','free_delivery_minimum','delivery_eta_min_days','delivery_eta_max_days','auto_process_minutes','auto_ship_minutes'],true))$v=(string)max(0,(int)($d[$k]??0));
                elseif(in_array($k,['transfer_enabled','cod_enabled','auto_status_enabled'],true))$v=((string)($d[$k]??'0')==='1')?'1':'0';
                elseif(in_array($k,['primary_color','accent_color'],true)){$v=clean_text($d[$k]??'',20);if(!preg_match('/^#[0-9a-fA-F]{6}$/',$v))throw new InvalidArgumentException('Warna '.$k.' tidak valid.');}
                else $v=clean_text($d[$k]??'',2000);
                $updates[$k]=$v;
            }
            $prospective=array_merge(get_settings(),$updates);
            // Metode inti proyek: Transfer Bank + COD Palembang selalu aktif.
            $prospective['transfer_enabled']='1';
            $prospective['cod_enabled']='1';
            $updates['transfer_enabled']='1';
            $updates['cod_enabled']='1';
            if(($prospective['transfer_enabled']??'0')==='0' && ($prospective['cod_enabled']??'0')==='0')throw new InvalidArgumentException('Aktifkan minimal satu metode pembayaran.');
            if((int)($prospective['delivery_eta_max_days']??2)<(int)($prospective['delivery_eta_min_days']??1))throw new InvalidArgumentException('Estimasi hari maksimal tidak boleh lebih kecil dari hari minimal.');
            if((int)($prospective['delivery_eta_max_days']??2)>30)throw new InvalidArgumentException('Estimasi maksimal 30 hari.');
            $pdo->beginTransaction();$st=$pdo->prepare('INSERT INTO site_settings(`key`,`value`) VALUES(?,?) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)');
            foreach($updates as $k=>$v)$st->execute([$k,$v]);
            $pdo->commit();
            recalculate_open_order_etas($pdo);
            auto_advance_order_statuses($pdo);
            json_response(['success'=>true,'settings'=>get_settings()]);
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();json_response(['error'=>$e->getMessage()],400);}
    }

    if ($method === 'PATCH' && preg_match('#^admin/orders/(\d+)$#',$path,$m)) {
        $d=request_json();if(!admin_ok($d))json_response(['error'=>'Akses ditolak.'],401);
        $id=(int)$m[1];
        $q=$pdo->prepare('SELECT * FROM orders WHERE id=?');$q->execute([$id]);$order=$q->fetch();if(!$order)json_response(['error'=>'Pesanan tidak ditemukan.'],404);

        $action=clean_text($d['action']??'',30);
        $paymentStatus=clean_text($d['payment_status']??$order['payment_status'],50);
        $allowedPayment=['Belum dibayar','Menunggu verifikasi','Sudah dibayar','Ditolak','Bayar di tempat','Kedaluwarsa'];
        if(!in_array($paymentStatus,$allowedPayment,true))json_response(['error'=>'Status pembayaran tidak valid.'],400);

        $status=$order['status'];$paidAt=$order['paid_at'];$etaStart=$order['eta_start'];$etaEnd=$order['eta_end'];$shippedAt=$order['shipped_at'];$deliveredAt=$order['delivered_at'];

        // Verifikasi pembayaran transfer adalah kejadian nyata pertama untuk pesanan non-COD.
        if($order['payment_method']!=='COD'){
            if($paymentStatus==='Sudah dibayar' && $order['payment_status']!=='Sudah dibayar'){
                $paidAt=now_sql();$status='Pesanan diterima';$eta=delivery_eta($paidAt,false);$etaStart=$eta['start'];$etaEnd=$eta['end'];
            } elseif($paymentStatus==='Ditolak'){
                $status='Menunggu pembayaran';$paidAt=null;
            }
        } else {
            $paymentStatus=$order['payment_status']==='Sudah dibayar'?'Sudah dibayar':'Bayar di tempat';
        }

        $paidOrCod=($order['payment_method']==='COD' || $paymentStatus==='Sudah dibayar');
        if($action==='process'){
            if(!$paidOrCod)json_response(['error'=>'Pesanan transfer harus dibayar dan diverifikasi terlebih dahulu.'],400);
            if(in_array($status,['Dibatalkan','Selesai'],true))json_response(['error'=>'Status pesanan ini tidak dapat diproses lagi.'],400);
            if($status!=='Pesanan diterima')json_response(['error'=>'Pesanan hanya dapat mulai diproses dari status Pesanan diterima.'],400);
            $status='Diproses';
        } elseif($action==='handover' || $action==='ship'){
            // "ship" dipertahankan sebagai alias agar cache/frontend lama tetap kompatibel.
            if(!$paidOrCod)json_response(['error'=>'Pesanan transfer harus dibayar dan diverifikasi terlebih dahulu.'],400);
            if($status!=='Diproses')json_response(['error'=>'Pesanan harus berstatus Diproses sebelum diserahkan ke kurir.'],400);
            $status='Diserahkan ke kurir';
            $shippedAt=now_sql();
            $eta=delivery_eta($shippedAt,true);$etaStart=$eta['start'];$etaEnd=$eta['end'];
        } elseif($action==='transit'){
            if(!$paidOrCod)json_response(['error'=>'Pesanan transfer harus dibayar dan diverifikasi terlebih dahulu.'],400);
            if($status!=='Diserahkan ke kurir')json_response(['error'=>'Pesanan harus sudah diserahkan ke kurir sebelum mulai dalam pengantaran.'],400);
            $status='Dalam pengantaran';
        } elseif($action==='complete'){
            if($status!=='Dalam pengantaran')json_response(['error'=>'Pesanan belum dalam pengantaran.'],400);
            $status='Selesai';$deliveredAt=now_sql();$doneDate=substr($deliveredAt,0,10);$etaStart=$doneDate;$etaEnd=$doneDate;
            if($order['payment_method']==='COD')$paymentStatus='Sudah dibayar';
        } elseif($action==='cancel' || ((string)($d['cancel']??'0')==='1')){
            if($status!=='Dibatalkan' && $status!=='Selesai')return_order_stock($pdo,$id);
            $status='Dibatalkan';
        }

        $st=$pdo->prepare('UPDATE orders SET status=?,payment_status=?,paid_at=?,eta_start=?,eta_end=?,shipped_at=?,delivered_at=? WHERE id=?');
        $st->execute([$status,$paymentStatus,$paidAt,$etaStart,$etaEnd,$shippedAt,$deliveredAt,$id]);
        expire_unpaid_transfer_orders($pdo);
        $q->execute([$id]);$fresh=$q->fetch();
        json_response(['success'=>true,'status'=>$fresh['status'],'payment_status'=>$fresh['payment_status'],'eta_start'=>$fresh['eta_start'],'eta_end'=>$fresh['eta_end'],'shipped_at'=>$fresh['shipped_at'],'delivered_at'=>$fresh['delivered_at']]);
    }

    if ($method === 'PATCH' && preg_match('#^admin/products/(\d+)$#',$path,$m)) {
        $d=request_json();if(!admin_ok($d))json_response(['error'=>'Akses ditolak.'],401);
        try{$name=clean_text($d['name']??'',120);$cat=clean_text($d['category']??'',80);$unit=clean_text($d['unit']??'',50);$desc=clean_text($d['description']??'',800);$emoji=clean_text($d['emoji']??'📦',10);$price=(int)($d['price']??0);$stock=(int)($d['stock']??0);$img=valid_image_data($d['image_data']??'');if($name===''||$cat===''||$unit===''||$price<0||$stock<0)throw new InvalidArgumentException('Data produk belum lengkap atau tidak valid.');$st=$pdo->prepare('UPDATE products SET name=?,category=?,price=?,stock=?,unit=?,description=?,emoji=?,image_data=? WHERE id=?');$st->execute([$name,$cat,$price,$stock,$unit,$desc,$emoji,$img,(int)$m[1]]);json_response(['success'=>true]);}catch(Throwable $e){json_response(['error'=>$e->getMessage()],400);}
    }

    if ($method === 'DELETE' && preg_match('#^admin/products/(\d+)$#',$path,$m)) {
        $d=request_json();if(!admin_ok($d))json_response(['error'=>'Akses ditolak.'],401);$st=$pdo->prepare('UPDATE products SET active=0 WHERE id=?');$st->execute([(int)$m[1]]);json_response(['success'=>true]);
    }

    json_response(['error'=>'Endpoint tidak ditemukan.'],404);
} catch (Throwable $e) {
    json_response(['error'=>'Server error: '.$e->getMessage()],500);
}
