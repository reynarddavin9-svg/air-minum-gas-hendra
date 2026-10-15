<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';

try {
    $path = trim((string)($_GET['path'] ?? ''), '/');
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $pdo = db();

    if ($method === 'GET' && $path === 'site') json_response(get_settings());

    if ($method === 'GET' && $path === 'products') {
        $rows = $pdo->query('SELECT * FROM products WHERE active=1 ORDER BY category,id')->fetchAll();
        json_response(array_map('cast_product', $rows));
    }

    if ($method === 'GET' && $path === 'orders/track') {
        $phone = trim((string)($_GET['phone'] ?? ''));
        if ($phone === '') json_response(['error'=>'Nomor WhatsApp/telepon wajib diisi.'],400);
        $st = $pdo->prepare('SELECT * FROM orders WHERE phone=? ORDER BY id DESC LIMIT 10');
        $st->execute([$phone]);
        $orders = $st->fetchAll();
        $it = $pdo->prepare('SELECT product_name,price,quantity FROM order_items WHERE order_id=?');
        foreach ($orders as &$o) {
            $o['id']=(int)$o['id']; $o['subtotal']=(int)$o['subtotal']; $o['delivery_fee']=(int)$o['delivery_fee']; $o['total']=(int)$o['total'];
            $it->execute([$o['id']]);
            $items=$it->fetchAll(); foreach($items as &$x){$x['price']=(int)$x['price'];$x['quantity']=(int)$x['quantity'];}
            $o['items']=$items;
        }
        json_response($orders);
    }

    if ($method === 'GET' && $path === 'admin/products') {
        if (!admin_ok()) json_response(['error'=>'Akses ditolak.'],401);
        $rows=$pdo->query('SELECT * FROM products WHERE active=1 ORDER BY category,id')->fetchAll();
        json_response(array_map('cast_product',$rows));
    }

    if ($method === 'GET' && $path === 'admin/orders') {
        if (!admin_ok()) json_response(['error'=>'Akses ditolak.'],401);
        $rows=$pdo->query('SELECT * FROM orders ORDER BY id DESC LIMIT 100')->fetchAll();
        foreach($rows as &$o){$o['id']=(int)$o['id'];$o['total']=(int)$o['total'];$o['subtotal']=(int)$o['subtotal'];$o['delivery_fee']=(int)$o['delivery_fee'];}
        json_response($rows);
    }

    if ($method === 'GET' && $path === 'admin/export') {
        if (!admin_ok()) json_response(['error'=>'Akses ditolak.'],401);
        if (!class_exists('ZipArchive')) json_response(['error'=>'Fitur ZIP tidak tersedia pada server ini.'],501);
        $tmp=tempnam(sys_get_temp_dir(),'hendra_'); $zipPath=$tmp.'.zip'; @unlink($tmp);
        $zip=new ZipArchive(); if($zip->open($zipPath,ZipArchive::CREATE)!==true) json_response(['error'=>'Gagal membuat ZIP.'],500);
        foreach(['index.php','index.html','api.php','lib.php','.htaccess','static/styles.css','static/app.js','README_INFINITYFREE.txt'] as $f){$full=__DIR__.'/'.$f;if(is_file($full))$zip->addFile($full,$f);}
        $backup=['settings'=>get_settings(),'products'=>$pdo->query('SELECT * FROM products ORDER BY id')->fetchAll(),'orders'=>$pdo->query('SELECT * FROM orders ORDER BY id')->fetchAll(),'order_items'=>$pdo->query('SELECT * FROM order_items ORDER BY id')->fetchAll()];
        $zip->addFromString('backup_data.json',json_encode($backup,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
        $zip->close();
        header('Content-Type: application/zip'); header('Content-Disposition: attachment; filename="Air_Minum_Gas_Hendra_Hasil_Edit.zip"'); header('Content-Length: '.filesize($zipPath)); readfile($zipPath); @unlink($zipPath); exit;
    }

    if ($method === 'POST' && $path === 'orders') {
        $d=request_json(); foreach(['customer_name','phone','address','payment_method','items'] as $k){if(empty($d[$k]))json_response(['error'=>'Data pesanan belum lengkap.'],400);} if(!is_array($d['items'])||!$d['items'])json_response(['error'=>'Keranjang masih kosong.'],400);
        try {
            $pdo->beginTransaction(); $norm=[];$subtotal=0; $sel=$pdo->prepare('SELECT * FROM products WHERE id=? AND active=1 FOR UPDATE');
            foreach($d['items'] as $item){$pid=(int)($item['product_id']??0);$qty=(int)($item['quantity']??0);if($qty<=0)throw new InvalidArgumentException('Jumlah produk tidak valid.');$sel->execute([$pid]);$p=$sel->fetch();if(!$p)throw new InvalidArgumentException('Produk tidak ditemukan.');if((int)$p['stock']<$qty)throw new InvalidArgumentException('Stok '.$p['name'].' tidak cukup. Sisa stok: '.$p['stock'].'.');$subtotal+=(int)$p['price']*$qty;$norm[]=[$p,$qty];}
            $s=get_settings();$feeDefault=max(0,(int)($s['delivery_fee']??5000));$freeMin=max(0,(int)($s['free_delivery_minimum']??100000));$fee=$subtotal>=$freeMin?0:$feeDefault;$total=$subtotal+$fee;$created=date('Y-m-d H:i:s');
            $st=$pdo->prepare('INSERT INTO orders(customer_name,phone,address,payment_method,notes,subtotal,delivery_fee,total,status,created_at) VALUES(?,?,?,?,?,?,?,?,?,?)');$st->execute([clean_text($d['customer_name'],120),clean_text($d['phone'],50),clean_text($d['address'],500),clean_text($d['payment_method'],50),clean_text($d['notes']??'',500),$subtotal,$fee,$total,'Pesanan diterima',$created]);$oid=(int)$pdo->lastInsertId();
            $ins=$pdo->prepare('INSERT INTO order_items(order_id,product_id,product_name,price,quantity) VALUES(?,?,?,?,?)');$upd=$pdo->prepare('UPDATE products SET stock=stock-? WHERE id=?');foreach($norm as [$p,$qty]){$ins->execute([$oid,$p['id'],$p['name'],$p['price'],$qty]);$upd->execute([$qty,$p['id']]);}
            $pdo->commit(); json_response(['success'=>true,'order_id'=>$oid,'subtotal'=>$subtotal,'delivery_fee'=>$fee,'total'=>$total,'status'=>'Pesanan diterima','created_at'=>$created],201);
        } catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();json_response(['error'=>$e->getMessage()],400);}
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
        $d=request_json();if(!admin_ok($d))json_response(['error'=>'Akses ditolak.'],401);$allowed=array_keys(DEFAULT_SETTINGS);$st=$pdo->prepare('INSERT INTO site_settings(`key`,`value`) VALUES(?,?) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)');
        try{foreach($allowed as $k){if(!array_key_exists($k,$d))continue;if(in_array($k,['logo_image_data','hero_image_data'],true))$v=valid_image_data($d[$k]??'');elseif(in_array($k,['delivery_fee','free_delivery_minimum'],true))$v=(string)max(0,(int)($d[$k]??0));elseif(in_array($k,['primary_color','accent_color'],true)){$v=clean_text($d[$k]??'',20);if(!preg_match('/^#[0-9a-fA-F]{6}$/',$v))throw new InvalidArgumentException('Warna '.$k.' tidak valid.');}else $v=clean_text($d[$k]??'',2000);$st->execute([$k,$v]);}json_response(['success'=>true,'settings'=>get_settings()]);}catch(Throwable $e){json_response(['error'=>$e->getMessage()],400);}
    }

    if ($method === 'PATCH' && preg_match('#^admin/orders/(\d+)$#',$path,$m)) {
        $d=request_json();if(!admin_ok($d))json_response(['error'=>'Akses ditolak.'],401);$status=clean_text($d['status']??'',50);$allowed=['Pesanan diterima','Diproses','Dalam pengantaran','Selesai','Dibatalkan'];if(!in_array($status,$allowed,true))json_response(['error'=>'Status tidak valid.'],400);$st=$pdo->prepare('UPDATE orders SET status=? WHERE id=?');$st->execute([$status,(int)$m[1]]);json_response(['success'=>true]);
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
