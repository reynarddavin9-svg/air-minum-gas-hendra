<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Jakarta');

const DEFAULT_SETTINGS = [
    'announcement' => 'Prototype Project UAS • Air Minum & Gas Hendra',
    'store_name' => 'Hendra',
    'store_subtitle' => 'Air Minum & Gas',
    'hero_badge' => '💧 Air Minum • 🔥 Gas • 🚚 Antar ke rumah',
    'hero_title' => 'Kebutuhan air minum dan gas jadi',
    'hero_highlight' => 'lebih mudah.',
    'hero_text' => 'Pesan galon dan gas dari UMKM Hendra melalui satu website. Cek stok, buat pesanan, dan pantau status pengantaran tanpa harus mencatat manual.',
    'hero_primary_button' => 'Pesan Sekarang',
    'hero_secondary_button' => 'Coba AI Assistant',
    'catalog_title' => 'Produk tersedia',
    'catalog_text' => 'Harga dan stok dapat diperbarui langsung melalui Dashboard Admin.',
    'ai_title' => 'Hendra Smart Assistant',
    'ai_text' => 'Fitur AI sederhana untuk membantu pelanggan memperkirakan produk yang sesuai berdasarkan kebutuhan. Sistem menggunakan aturan transparan dan tidak mengirim data ke layanan AI eksternal.',
    'tracking_title' => 'Lacak pesanan',
    'tracking_text' => 'Masukkan nomor WhatsApp/telepon yang sama seperti saat memesan untuk melihat status pesanan terakhir.',
    'about_title' => 'Digitalisasi UMKM Air Minum & Gas Hendra',
    'about_text' => 'Website ini membantu penjualan online, pencatatan stok, pemesanan pelanggan, dan pemantauan status pengantaran dalam satu sistem sederhana.',
    'footer_text' => 'Prototype UAS Program Studi Bisnis Digital.',
    'phone' => '08xxxxxxxxxx',
    'address' => 'Palembang, Sumatera Selatan',
    'store_city' => 'Palembang',
    'primary_color' => '#1367d1',
    'accent_color' => '#0ea5e9',
    'delivery_fee' => '5000',
    'free_delivery_minimum' => '100000',
    'delivery_eta_min_days' => '1',
    'delivery_eta_max_days' => '2',
    // Otomatisasi status pesanan untuk prototype UAS. Transfer mulai berjalan setelah pembayaran diverifikasi; COD mulai sejak pesanan dibuat.
    'auto_status_enabled' => '1',
    'auto_process_minutes' => '1',
    'auto_ship_minutes' => '2',
    'chat_welcome_message' => 'Halo! Terima kasih sudah berbelanja di Hendra. Jika ada pertanyaan tentang pesanan, silakan chat di sini.',
    'logo_image_data' => '',
    'hero_image_data' => '',

    // Pengaturan pembayaran. Semua dapat diubah dari Dashboard Admin > Pembayaran.
    'transfer_enabled' => '1',
    'cod_enabled' => '1',
    'qris_enabled' => '0',
    'bank_name' => 'Bank Mandiri',
    'bank_account_number' => '1130020365627',
    'payment_note' => 'Transfer sesuai total pesanan, lalu unggah bukti pembayaran. Pesanan baru diproses setelah pembayaran diverifikasi admin.',
    'qris_image_data' => '',
];

function app_config(): array {
    static $cfg = null;
    if ($cfg !== null) return $cfg;
    $file = __DIR__ . '/config.php';
    if (!file_exists($file)) {
        throw new RuntimeException('Website belum dikonfigurasi. Buka /install.php terlebih dahulu.');
    }
    $cfg = require $file;
    return $cfg;
}

function db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    $c = app_config();
    $dsn = 'mysql:host=' . $c['db_host'] . ';dbname=' . $c['db_name'] . ';charset=utf8mb4';
    $pdo = new PDO($dsn, $c['db_user'], $c['db_pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    return $pdo;
}

function json_response(mixed $data, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function request_json(): array {
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') return [];
    if (strlen($raw) > 8 * 1024 * 1024) json_response(['error' => 'Data terlalu besar.'], 413);
    $d = json_decode($raw, true);
    return is_array($d) ? $d : [];
}

function clean_text(mixed $v, int $limit = 1000): string {
    $s = trim((string)($v ?? ''));
    return mb_substr($s, 0, $limit, 'UTF-8');
}

function admin_ok(?array $data = null): bool {
    $cfg = app_config();
    $pin = '';
    if (is_array($data) && array_key_exists('pin', $data)) $pin = (string)$data['pin'];
    if ($pin === '') $pin = (string)($_SERVER['HTTP_X_ADMIN_PIN'] ?? '');
    return hash_equals((string)$cfg['admin_pin_hash'], hash('sha256', $pin));
}

function valid_image_data(mixed $v): string {
    $s = (string)($v ?? '');
    if ($s === '') return '';
    if (strlen($s) > 5_500_000) throw new InvalidArgumentException('Ukuran gambar terlalu besar. Gunakan gambar maksimal sekitar 4 MB.');
    if (!preg_match('#^data:image/(png|jpeg|jpg|webp|gif);base64,#i', $s)) throw new InvalidArgumentException('Format gambar tidak didukung.');
    return $s;
}

function normalize_city_name(mixed $value): string {
    $s = mb_strtolower(clean_text($value, 120), 'UTF-8');
    $s = preg_replace('/^(kota|kabupaten|kab\.)\s+/u', '', $s) ?? $s;
    $s = preg_replace('/\s+/u', ' ', trim($s)) ?? trim($s);
    return $s;
}

function cod_city_allowed(mixed $customerCity, array $settings): bool {
    // COD proyek ini khusus Palembang. Toleran terhadap penulisan seperti
    // "Kota Palembang" atau "Palembang, Sumatera Selatan".
    $city = normalize_city_name($customerCity);
    return $city !== '' && (str_contains($city, 'palembang'));
}

function get_settings(): array {
    $pdo = db();
    $rows = $pdo->query('SELECT `key`, `value` FROM site_settings')->fetchAll();
    $out = DEFAULT_SETTINGS;
    foreach ($rows as $r) $out[$r['key']] = $r['value'];
    unset($out['bank_account_name'], $out['cod_city_rule_v1'], $out['payment_fix_v2'], $out['payment_methods_v3']);
    return $out;
}

function cast_product(array $p): array {
    $p['id'] = (int)$p['id'];
    $p['price'] = (int)$p['price'];
    $p['stock'] = (int)$p['stock'];
    $p['active'] = (int)$p['active'];
    return $p;
}

function create_schema(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS products (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(120) NOT NULL,
        category VARCHAR(80) NOT NULL,
        price INT NOT NULL,
        stock INT NOT NULL DEFAULT 0,
        unit VARCHAR(50) NOT NULL,
        description TEXT NULL,
        emoji VARCHAR(20) NOT NULL DEFAULT '📦',
        image_data MEDIUMTEXT NULL,
        active TINYINT(1) NOT NULL DEFAULT 1,
        INDEX idx_products_active (active),
        INDEX idx_products_category (category)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS orders (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        customer_name VARCHAR(120) NOT NULL,
        phone VARCHAR(50) NOT NULL,
        address VARCHAR(500) NOT NULL,
        city VARCHAR(100) NOT NULL DEFAULT '',
        payment_method VARCHAR(50) NOT NULL,
        payment_status VARCHAR(50) NOT NULL DEFAULT 'Belum dibayar',
        payment_proof MEDIUMTEXT NULL,
        paid_at DATETIME NULL,
        notes VARCHAR(500) NULL,
        subtotal INT NOT NULL,
        delivery_fee INT NOT NULL,
        total INT NOT NULL,
        status VARCHAR(50) NOT NULL DEFAULT 'Menunggu pembayaran',
        eta_start DATE NULL,
        eta_end DATE NULL,
        shipped_at DATETIME NULL,
        delivered_at DATETIME NULL,
        stock_returned TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL,
        INDEX idx_orders_phone (phone),
        INDEX idx_orders_created (created_at),
        INDEX idx_orders_payment_status (payment_status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS order_items (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        order_id INT UNSIGNED NOT NULL,
        product_id INT UNSIGNED NOT NULL,
        product_name VARCHAR(120) NOT NULL,
        price INT NOT NULL,
        quantity INT NOT NULL,
        INDEX idx_items_order (order_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS order_messages (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        order_id INT UNSIGNED NOT NULL,
        sender VARCHAR(20) NOT NULL,
        message VARCHAR(1000) NOT NULL,
        created_at DATETIME NOT NULL,
        INDEX idx_messages_order (order_id),
        INDEX idx_messages_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");


    $pdo->exec("CREATE TABLE IF NOT EXISTS visitor_chat_sessions (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        visitor_token VARCHAR(100) NOT NULL UNIQUE,
        customer_name VARCHAR(120) NULL,
        last_order_id INT UNSIGNED NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        INDEX idx_chat_sessions_updated (updated_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS visitor_chat_messages (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        session_id INT UNSIGNED NOT NULL,
        sender VARCHAR(20) NOT NULL,
        message VARCHAR(1000) NOT NULL,
        created_at DATETIME NOT NULL,
        INDEX idx_visitor_messages_session (session_id),
        INDEX idx_visitor_messages_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS site_settings (
        `key` VARCHAR(100) NOT NULL PRIMARY KEY,
        `value` MEDIUMTEXT NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function column_exists(PDO $pdo, string $table, string $column): bool {
    $allowed = ['orders'];
    if (!in_array($table, $allowed, true)) return false;
    $q = $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE " . $pdo->quote($column));
    return (bool)$q->fetch();
}

/**
 * Migrasi ringan agar ZIP baru bisa langsung menggantikan file website lama
 * tanpa menghapus data produk/pesanan yang sudah ada.
 */
function upgrade_schema(PDO $pdo): void {
    create_schema($pdo);

    if (!column_exists($pdo, 'orders', 'city')) {
        $pdo->exec("ALTER TABLE orders ADD city VARCHAR(100) NOT NULL DEFAULT '' AFTER address");
    }
    if (!column_exists($pdo, 'orders', 'payment_status')) {
        $pdo->exec("ALTER TABLE orders ADD payment_status VARCHAR(50) NOT NULL DEFAULT 'Belum dibayar' AFTER payment_method");
    }
    if (!column_exists($pdo, 'orders', 'payment_proof')) {
        $pdo->exec("ALTER TABLE orders ADD payment_proof MEDIUMTEXT NULL AFTER payment_status");
    }
    if (!column_exists($pdo, 'orders', 'paid_at')) {
        $pdo->exec("ALTER TABLE orders ADD paid_at DATETIME NULL AFTER payment_proof");
    }
    if (!column_exists($pdo, 'orders', 'eta_start')) {
        $pdo->exec("ALTER TABLE orders ADD eta_start DATE NULL AFTER status");
    }
    if (!column_exists($pdo, 'orders', 'eta_end')) {
        $pdo->exec("ALTER TABLE orders ADD eta_end DATE NULL AFTER eta_start");
    }
    if (!column_exists($pdo, 'orders', 'shipped_at')) {
        $pdo->exec("ALTER TABLE orders ADD shipped_at DATETIME NULL AFTER eta_end");
    }
    if (!column_exists($pdo, 'orders', 'delivered_at')) {
        $pdo->exec("ALTER TABLE orders ADD delivered_at DATETIME NULL AFTER shipped_at");
    }
    if (!column_exists($pdo, 'orders', 'stock_returned')) {
        $pdo->exec("ALTER TABLE orders ADD stock_returned TINYINT(1) NOT NULL DEFAULT 0 AFTER delivered_at");
    }

    // Hapus pengaturan nama pemilik rekening versi lama agar tidak tampil/terekspos lagi.
    $pdo->exec("DELETE FROM site_settings WHERE `key`='bank_account_name'");

    // Pastikan instalasi lama tidak menghasilkan dropdown pembayaran kosong.
    // Transfer Bank dan COD merupakan metode inti proyek ini.
    $forcePay = $pdo->prepare('INSERT INTO site_settings(`key`,`value`) VALUES(?,?) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)');
    $forcePay->execute(['transfer_enabled','1']);
    $forcePay->execute(['cod_enabled','1']);
    $forcePay->execute(['qris_enabled','0']);
    $forcePay->execute(['bank_name','Bank Mandiri']);
    $forcePay->execute(['bank_account_number','1130020365627']);

    // Pesanan lama tetap dianggap valid agar tidak tiba-tiba terkunci setelah update.
    $pdo->exec("UPDATE orders SET payment_status='Sudah dibayar' WHERE payment_status='Belum dibayar' AND status IN ('Pesanan diterima','Diproses','Dalam pengantaran','Selesai')");

    // Perbaikan satu kali untuk instalasi lama: pastikan Transfer Bank Mandiri tersedia
    // sehingga pilihan pembayaran tidak kosong. Setelah marker dibuat, admin tetap dapat mengubahnya.
    $paymentFix = $pdo->query("SELECT `value` FROM site_settings WHERE `key`='payment_fix_v2' LIMIT 1")->fetchColumn();
    if ($paymentFix === false) {
        $up = $pdo->prepare('INSERT INTO site_settings(`key`,`value`) VALUES(?,?) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)');
        $up->execute(['transfer_enabled','1']);
        $up->execute(['bank_name','Bank Mandiri']);
        $up->execute(['bank_account_number','1130020365627']);
        $up->execute(['payment_fix_v2','1']);
    }

    // Setup satu kali: metode pembayaran publik hanya Transfer Bank Mandiri dan COD.
    $paymentMethodsMarker = $pdo->query("SELECT `value` FROM site_settings WHERE `key`='payment_methods_v3' LIMIT 1")->fetchColumn();
    if ($paymentMethodsMarker === false) {
        $up = $pdo->prepare('INSERT INTO site_settings(`key`,`value`) VALUES(?,?) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)');
        $up->execute(['transfer_enabled','1']);
        $up->execute(['cod_enabled','1']);
        $up->execute(['qris_enabled','0']);
        $up->execute(['bank_name','Bank Mandiri']);
        $up->execute(['bank_account_number','1130020365627']);
        $up->execute(['payment_methods_v3','1']);
    }

    // Setup satu kali: lokasi toko Palembang dan COD hanya untuk kota yang sama dengan lokasi toko.
    $marker = $pdo->query("SELECT `value` FROM site_settings WHERE `key`='cod_city_rule_v1' LIMIT 1")->fetchColumn();
    if ($marker === false) {
        $up = $pdo->prepare('INSERT INTO site_settings(`key`,`value`) VALUES(?,?) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)');
        $up->execute(['store_city','Palembang']);
        $up->execute(['cod_enabled','1']);
        $pdo->exec("UPDATE site_settings SET `value`='Palembang, Sumatera Selatan' WHERE `key`='address' AND (`value`='' OR `value`='Alamat toko dapat diubah dari Dashboard Admin.')");
        $up->execute(['cod_city_rule_v1','1']);
    }

    $st = $pdo->prepare('INSERT IGNORE INTO site_settings(`key`,`value`) VALUES(?,?)');
    foreach (DEFAULT_SETTINGS as $k => $v) $st->execute([$k, $v]);

    seed_order_eta_if_missing($pdo);
    auto_advance_order_statuses($pdo);
}


function now_sql(): string {
    return date('Y-m-d H:i:s');
}

function delivery_eta(?string $base = null, bool $alreadyShipped = false): array {
    $s = get_settings();
    $minDays = max(0, min(30, (int)($s['delivery_eta_min_days'] ?? 1)));
    $maxDays = max($minDays, min(30, (int)($s['delivery_eta_max_days'] ?? 2)));
    $dt = $base ? new DateTimeImmutable($base) : new DateTimeImmutable('now');

    // Rentang estimasi selalu mengikuti pengaturan Admin. Jadi 2-3, 3-5, dst.
    // Saat pesanan mulai diantar, tanggal dihitung ulang dari waktu pengantaran,
    // tetapi rentang harinya tidak dipaksa menjadi 0-1 lagi.
    return [
        'start' => $dt->modify('+' . $minDays . ' day')->format('Y-m-d'),
        'end' => $dt->modify('+' . $maxDays . ' day')->format('Y-m-d'),
    ];
}

function return_order_stock(PDO $pdo, int $orderId): void {
    $q = $pdo->prepare('SELECT stock_returned FROM orders WHERE id=? LIMIT 1');
    $q->execute([$orderId]);
    $returned = $q->fetchColumn();
    if ($returned === false || (int)$returned === 1) return;

    $items = $pdo->prepare('SELECT product_id, quantity FROM order_items WHERE order_id=?');
    $items->execute([$orderId]);
    $upd = $pdo->prepare('UPDATE products SET stock=stock+? WHERE id=?');
    foreach ($items->fetchAll() as $item) {
        $upd->execute([(int)$item['quantity'], (int)$item['product_id']]);
    }
    $pdo->prepare('UPDATE orders SET stock_returned=1 WHERE id=?')->execute([$orderId]);
}

function payment_due_at(string $createdAt): string {
    try {
        return (new DateTimeImmutable($createdAt))->modify('+24 hours')->format('Y-m-d H:i:s');
    } catch (Throwable $e) {
        return $createdAt;
    }
}

function expire_unpaid_transfer_orders(PDO $pdo): void {
    // Seperti marketplace: transfer yang belum dibayar selama 24 jam dibatalkan otomatis.
    // COD tidak pernah dibatalkan oleh aturan pembayaran 24 jam karena dibayar saat barang tiba.
    $deadline = (new DateTimeImmutable('now'))->modify('-24 hours')->format('Y-m-d H:i:s');
    $st = $pdo->prepare("SELECT id FROM orders WHERE payment_method<>'COD' AND status NOT IN ('Selesai','Dibatalkan') AND payment_status IN ('Belum dibayar','Ditolak') AND created_at<=? ORDER BY id ASC LIMIT 300");
    $st->execute([$deadline]);
    $ids = array_map('intval', array_column($st->fetchAll(), 'id'));
    if (!$ids) return;

    $pdo->beginTransaction();
    try {
        $cancel = $pdo->prepare("UPDATE orders SET status='Dibatalkan', payment_status='Kedaluwarsa' WHERE id=? AND status<>'Dibatalkan'");
        foreach ($ids as $id) {
            return_order_stock($pdo, $id);
            $cancel->execute([$id]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

// Nama fungsi lama dipertahankan agar instalasi lama tetap kompatibel.
// Sekarang fungsi ini TIDAK lagi memajukan status berdasarkan menit/tanggal estimasi.
function auto_advance_order_statuses(PDO $pdo): void {
    expire_unpaid_transfer_orders($pdo);
}

function recalculate_open_order_etas(PDO $pdo): void {
    $rows = $pdo->query("SELECT id,payment_method,payment_status,status,created_at,paid_at,shipped_at FROM orders WHERE status NOT IN ('Selesai','Dibatalkan') ORDER BY id DESC LIMIT 300")->fetchAll();
    if (!$rows) return;
    $st = $pdo->prepare('UPDATE orders SET eta_start=?, eta_end=? WHERE id=?');
    foreach ($rows as $o) {
        $base = (string)($o['created_at'] ?? now_sql());
        $alreadyShipped = false;
        if (!empty($o['shipped_at'])) {
            $base = (string)$o['shipped_at'];
            $alreadyShipped = true;
        } elseif ($o['payment_method'] !== 'COD' && $o['payment_status'] === 'Sudah dibayar' && !empty($o['paid_at'])) {
            $base = (string)$o['paid_at'];
        }
        $eta = delivery_eta($base, $alreadyShipped);
        $st->execute([$eta['start'],$eta['end'],(int)$o['id']]);
    }
}

function seed_order_eta_if_missing(PDO $pdo): void {
    $rows = $pdo->query("SELECT id, created_at, status, eta_start, eta_end FROM orders WHERE eta_start IS NULL OR eta_end IS NULL")->fetchAll();
    if (!$rows) return;
    $st = $pdo->prepare('UPDATE orders SET eta_start=?, eta_end=? WHERE id=?');
    foreach ($rows as $o) {
        $eta = delivery_eta((string)$o['created_at'], $o['status'] === 'Dalam pengantaran');
        $st->execute([$eta['start'], $eta['end'], (int)$o['id']]);
    }
}

function seed_defaults(PDO $pdo): void {
    $count = (int)$pdo->query('SELECT COUNT(*) FROM products')->fetchColumn();
    if ($count === 0) {
        $products = [
            ['Isi Ulang Galon','Air Minum',7000,40,'galon','Isi ulang air minum untuk kebutuhan harian.','💧'],
            ['Air Mineral Galon 19L','Air Minum',22000,25,'galon','Galon air mineral siap antar.','🚰'],
            ['LPG 3 Kg','Gas',22000,30,'tabung','Gas LPG 3 kg untuk kebutuhan rumah tangga.','🔥'],
            ['Bright Gas 5,5 Kg','Gas',100000,12,'tabung','Tabung gas praktis untuk kebutuhan memasak.','🟣'],
            ['LPG 12 Kg','Gas',210000,8,'tabung','Tabung gas 12 kg untuk pemakaian lebih lama.','🧯'],
        ];
        $st = $pdo->prepare('INSERT INTO products(name,category,price,stock,unit,description,emoji,image_data,active) VALUES(?,?,?,?,?,?,?,\'\',1)');
        foreach ($products as $p) $st->execute($p);
    }
    $st = $pdo->prepare('INSERT IGNORE INTO site_settings(`key`,`value`) VALUES(?,?)');
    foreach (DEFAULT_SETTINGS as $k => $v) $st->execute([$k, $v]);
}
