<?php
declare(strict_types=1);

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
    'address' => 'Alamat toko dapat diubah dari Dashboard Admin.',
    'primary_color' => '#1367d1',
    'accent_color' => '#0ea5e9',
    'delivery_fee' => '5000',
    'free_delivery_minimum' => '100000',
    'logo_image_data' => '',
    'hero_image_data' => '',
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

function get_settings(): array {
    $pdo = db();
    $rows = $pdo->query('SELECT `key`, `value` FROM site_settings')->fetchAll();
    $out = DEFAULT_SETTINGS;
    foreach ($rows as $r) $out[$r['key']] = $r['value'];
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
        payment_method VARCHAR(50) NOT NULL,
        notes VARCHAR(500) NULL,
        subtotal INT NOT NULL,
        delivery_fee INT NOT NULL,
        total INT NOT NULL,
        status VARCHAR(50) NOT NULL DEFAULT 'Pesanan diterima',
        created_at DATETIME NOT NULL,
        INDEX idx_orders_phone (phone),
        INDEX idx_orders_created (created_at)
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

    $pdo->exec("CREATE TABLE IF NOT EXISTS site_settings (
        `key` VARCHAR(100) NOT NULL PRIMARY KEY,
        `value` MEDIUMTEXT NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
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
