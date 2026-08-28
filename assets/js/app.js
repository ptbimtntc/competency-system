/*
|--------------------------------------------------------------------------
| Sticky stack
|--------------------------------------------------------------------------
|
| Beberapa halaman (index.php, employee.php) punya bagian yang dibuat
| freeze (header, identitas employee, judul section/tab). Dulu tiap bagian
| dibuat sticky sendiri-sendiri dan "top"-nya dihitung di sini supaya
| bertumpuk rapi -- tapi pendekatan itu bikin tiap bagian nempel di titik
| scroll yang beda sehingga sempat muncul celah di bawah header.
|
| Sekarang semua bagian itu dibungkus satu elemen ".sticky-stack" yang
| sticky sebagai SATU unit (lihat assets/css/style.css), jadi tidak perlu
| lagi hitung "top" per bagian di JS. Blok ini sengaja dibiarkan kosong
| supaya file tetap ada untuk <script> yang sudah terpasang di halaman.
|
*/
