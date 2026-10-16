# Air Minum & Gas Hendra

Prototype web Air Minum & Gas Hendra untuk project UAS.

Fitur utama mencakup katalog produk, keranjang dan checkout, pembayaran Transfer Bank/COD, pembatasan COD untuk Palembang, pelacakan pesanan, estimasi tiba, chat pembeli-penjual, admin, AI Assistant sederhana, dan pembatalan otomatis pesanan transfer yang belum dibayar setelah 24 jam ketika aplikasi kembali diakses.

## Keamanan
File `config.php`, kredensial database, backup database, dan data rahasia tidak disertakan di repository. Gunakan `config.example.php` sebagai contoh konfigurasi.


## Status Pengiriman
Alur status mengikuti kejadian nyata: **Pesanan Diterima → Sedang Diproses → Pesanan Telah Diserahkan ke Kurir → Dalam Pengantaran ke [Kota/Kabupaten Pembeli] → Selesai**. Nama kota diambil otomatis dari data checkout. Transfer yang tidak dibayar dalam 24 jam dibatalkan otomatis, sedangkan COD dibayar saat pesanan tiba.
