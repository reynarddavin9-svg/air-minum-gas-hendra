<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';

$done = false;
$error = '';
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $host = trim((string)($_POST['db_host'] ?? ''));
    $name = trim((string)($_POST['db_name'] ?? ''));
    $user = trim((string)($_POST['db_user'] ?? ''));
    $pass = (string)($_POST['db_pass'] ?? '');
    $pin  = trim((string)($_POST['admin_pin'] ?? ''));
    if ($host === '' || $name === '' || $user === '' || $pass === '' || strlen($pin) < 4) {
        $error = 'Semua data database wajib diisi. PIN admin minimal 4 karakter.';
    } else {
        try {
            $dsn = "mysql:host={$host};dbname={$name};charset=utf8mb4";
            $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
            create_schema($pdo);
            seed_defaults($pdo);

            $config = "<?php\nreturn " . var_export([
                'db_host' => $host,
                'db_name' => $name,
                'db_user' => $user,
                'db_pass' => $pass,
                'admin_pin_hash' => hash('sha256', $pin),
            ], true) . ";\n";
            if (file_put_contents(__DIR__ . '/config.php', $config, LOCK_EX) === false) {
                throw new RuntimeException('Tidak bisa menulis config.php. Pastikan folder htdocs dapat ditulis.');
            }
            $done = true;
            $message = 'Instalasi berhasil. Database dan data awal sudah dibuat.';
        } catch (Throwable $e) {
            $error = 'Instalasi gagal: ' . $e->getMessage();
        }
    }
}
?>
<!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Setup Hendra</title>
<style>body{font-family:system-ui;background:#f3f7fb;margin:0;color:#18324b}.box{max-width:720px;margin:40px auto;background:#fff;padding:28px;border-radius:20px;box-shadow:0 10px 40px #16324f18}.grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}label{display:grid;gap:6px;font-weight:600}input{padding:12px;border:1px solid #c9d6e2;border-radius:10px;font:inherit}.full{grid-column:1/-1}.btn{background:#1367d1;color:#fff;border:0;padding:13px 18px;border-radius:10px;font-weight:700;cursor:pointer}.ok{background:#e7f8ee;padding:14px;border-radius:10px;color:#176b37}.err{background:#fff0f0;padding:14px;border-radius:10px;color:#a52222}.note{background:#eef7ff;padding:14px;border-radius:10px;line-height:1.55}@media(max-width:650px){.grid{grid-template-columns:1fr}.full{grid-column:auto}}</style></head><body><div class="box">
<h1>Setup Air Minum & Gas Hendra</h1>
<p>Isi data MySQL dari InfinityFree. Data ini tidak ditampilkan kepada pengunjung.</p>
<?php if ($error): ?><div class="err"><?=htmlspecialchars($error)?></div><?php endif; ?>
<?php if ($done): ?><div class="ok"><b><?=htmlspecialchars($message)?></b><br><br><a href="index.php">Buka Website Hendra</a></div>
<p class="note"><b>Keamanan:</b> setelah website berhasil, hapus atau rename file <code>install.php</code> dari File Manager.</p>
<?php else: ?>
<form method="post" class="grid">
<label>MySQL Host<input name="db_host" placeholder="sqlXXX.infinityfree.com" required></label>
<label>Database Name<input name="db_name" placeholder="if0_xxxxxxxx_hendra" required></label>
<label>Database Username<input name="db_user" placeholder="if0_xxxxxxxx" required></label>
<label>Database Password<input name="db_pass" type="password" required></label>
<label class="full">PIN Admin<input name="admin_pin" type="password" minlength="4" required><small>PIN ini tidak akan ditampilkan di website.</small></label>
<button class="btn full" type="submit">Install Website</button>
</form>
<div class="note" style="margin-top:18px"><b>Belum punya database?</b><br>Di dashboard InfinityFree buka <b>MySQL Databases</b> → buat database baru → salin Host, Database Name, Username, dan Password ke form ini.</div>
<?php endif; ?>
</div></body></html>
