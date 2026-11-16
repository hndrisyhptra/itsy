# Pelunasan dengan bukti transfer dan APM opsional

## Otoritas pengguna 16 November 2026

Finance mengunggah bukti transfer, kemudian secara eksplisit menekan **Tandai Lunas** dengan konfirmasi YA/TIDAK. Nomor APM opsional pada langkah ini dan dapat ditambah/diedit oleh Finance setelah pembayaran lunas. Upload sendiri tidak membuat pembayaran otomatis. Aturan ini menggantikan kewajiban nomor APM sebagai pemicu pelunasan pada alur kasbon `kasbon-v1` dan `kasbon-settlement-v2`, termasuk pengajuan berjalan yang belum dibayar. Alur pra-kasbon v1/v2/partial-payment-v1 tetap mengikuti aturan historisnya; tidak ada adopsi atau pembayaran massal.

Seluruh approval yang berlaku pada dokumen tetap wajib: tujuh untuk kasbon-settlement-v2, lima ditambah review Finance tersendiri untuk kasbon-v1. Keputusan Finance dan bukti transfer harus cocok dengan kasbon aktif, tagihan belum terbayar, quote net kanonis tetap benar. Jika scan sudah tersedia harus terverifikasi; scan boleh menyusul sesuai aturan kasbon. Pending upload bukti transfer menahan pelunasan. Tetap Finance aktif/Branch, workspace lock, revision, UUID receipt, akses file privat dan audit akun nyata. Tidak membuat transfer bank otomatis.

## Perilaku layar dan command

Panel Finance menampilkan upload hanya setelah keputusan Finance. Tombol Tandai Lunas dan kolom APM opsional muncul setelah bukti transfer aktif tersimpan untuk kasbon yang sama. Setelah berhasil, status Lunas dan nominal ditampilkan; APM kosong ditulis **APM belum diisi**, bukan APM valid. Tombol pelunasan hilang dan kolom **Nomor APM → Simpan Nomor APM** tersedia hanya untuk Finance. Input yang diisi tetap dinormalisasi uppercase,5–60karakter dengan huruf/angka/._-/ dan harus unik tanpa membedakan kapital. Setelah lunas, penambahan/koreksi nomor memerlukan nomor nonkosong; tidak ada aksi menghapus pelunasan atau mengosongkan nomor yang sudah tercatat.

`settleFinancePayment(termId, apm?)` memeriksa guard yang sama dengan pelunasan kasbon/APM sebelumnya, lalu melunasi seluruh payable net secara atomik satu kali. `updatePaymentApm(termId, apm)` hanya memperbarui referensi pembayaran kasbon yang sudah benar-benar lunas. Request lama/berulang tidak mengulang pembayaran; konflik revision tidak menimpa data, uncertain write memakai UUID yang sama. `recordApm` tetap untuk kompatibilitas historis, bukan jalur UI kasbon baru.

## Penyimpanan dan audit

- `payment_terms.document_data.paid/paymentHistory`: jumlah penuh kanonis, source `TRANSFER_PROOF`, ref ID bukti transfer, actor dan tanggal bayar asli. Ini tidak ditulis ulang saat APM dikoreksi.
- `financePayment`: status Dibayar, kasbonId/transferFileId, settledAt/settledBy dan documentsAtPayment. Marker hanya dibuat server melalui command pelunasan, bukan form/saveTerm incoming.
- `apm/apmStatus`: APM kosong + Belum Terbit selama nomor belum tersedia; setelah diisi status Valid. `financePayment.apmHistory` menyimpan before/after/at/by setiap perubahan nomor.
- `apm_settlements`: tetap satu baris per pengajuan, jumlah/recorded_at/recorded_by/ID/kasbon/proof tetap. `apm_no` nullable dengan unique index lama; kosong disimpan NULL, tanpa nomor APM buatan. Pembaruan nomor hanya UPDATE kolom apm_no pada baris yang sama. Snapshot kasbon terenkripsi, keputusan, archive workspace, nominal bruto/net dan ledger volume tidak diubah.

Shared PHP/TS `settlementRecorded` mengenali APM valid historis atau pelunasan kasbon berpenanda server yang terikat bukti/kasbon dan nilai terbayar positif. Next-term tetap memeriksa lunas penuh dan semua approval; Final/3termin tetap. Matrix CLOSE, timeline, inbox Finance, picker dan List Payment mengikuti pelunasan ini tanpa mewajibkan nomor. List SQL tetap memerlukan settlement aktual dengan amount memenuhi tagihan; tanpa baris settlement/marker tidak dianggap dibayar. Scan susulan menambah verifikasi berkas tanpa membayar ulang. Report outstanding mengikuti paid/net dan kolom Status APM tetap mencerminkan nomor aktual.

## Migration dan penerapan

Migration `2026_11_16_000001_allow_optional_payment_apm` hanya membuat `apm_settlements.apm_no` nullable, mempertahankan unique/collation/FK/amount/XOR authority yang ada. CHECK tambahan mengizinkan NULL hanya pada otoritas kasbon+bukti transfer; jalur scan/partial historis tetap wajib APM. Tidak mengubah nomor, nilai, row pembayaran atau snapshot lama. SQLite memory rebuild memulihkan trigger lama; down menolak bila masih ada pembayaran tanpa APM. Migration its-only dan test-bootstrap memory-only tetap. Tidak menjalankan migration/backfill pada its aktif atau server dalam pekerjaan ini.

Sesudah backup database/storage/APP_KEY sesuai prosedur deployment, operator menjalankan migration biasa, build aset dan memuat ulang worker **Area3 saja**. Endpoint command baru memberi503 sebelum menulis bila migration belum diterapkan. Pada lokal, gunakan PHP XAMPP untuk Artisan; pada server gunakan PHP situs. Jangan memakai migrate:fresh/reset/seed, mengganti APP_KEY/env, mengimpor ulang data, atau merestart worker aplikasi lain.

## Query dan batas verifikasi

Resolver pelunasan murni0SQL/O(1), validasi referensi memakai terms aggregate yang sudah dimuat. Dua command baru menambah1query readiness migration; pelunasan memakai existing bounded pending-upload probe. Tidak menambah query per approver/baris/katalog/dekripsi. SQL settlement memakai PK/unique term existing, hanya metadata APM yang diupdate bila berubah. List Payment tetap4servicequery/20+1baris; picker menambah satu scalar projection pada batch terms existing, tanpa query tambahan. Global command aggregate/offset paging tetap batas sebelumnya, bukan klaim throughput umum. Bukti aktual, hasil suite dan batas manual: [QA JSON](qa/optional-payment-apm-20261116.json).
