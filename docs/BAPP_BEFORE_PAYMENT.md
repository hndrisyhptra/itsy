# BA PP disahkan sejak BA RP tersimpan

## Otoritas terbaru — 18 November 2026

Pengguna mengoreksi urutan menjadi **BA RP → sah BA PP → payment**, lalu mengonfirmasi bahwa BA PP harus dapat disahkan **sejak BA RP disimpan, sebelum approval apa pun**. Ketentuan ini menggantikan persyaratan lama seluruh approval Final sebelum BA PP untuk dokumen yang memakai alur baru di bawah. Tidak menjadikan BA PP sebagai approval pembayaran.

Alur Final baru, untuk Konstruksi maupun Operation:

1. Admin menyimpan BA RP Final beserta volume aktual positif dan informasi rekonsiliasi.
2. Sistem membuat satu BA PP Draft yang terhubung tepat ke pembayaran Final tersebut.
3. Admin membuka BA PP dan memilih Sahkan BA PP melalui YA/TIDAK. PP, BA KH dan SPK harus Sah; tanggal/periode/nama pekerjaan BA RP harus lengkap dan valid. Tidak perlu approval pembayaran atau pembayaran lunas.
4. Admin mengisi/mengajukan kasbon dan pembayaran. Final pada alur ini ditolak bila BA PP belum sah atau tidak sesuai BA RP tersimpan.
5. Tujuh approval yang berlaku berjalan seperti sebelumnya: lima Konstruksi atau Operation, Mgr Finance & Settlement, Finance.
6. Finance mengunggah bukti transfer yang sesuai versi kasbon dan menandai lunas. APM tetap opsional; PPh/net/bruto/scan/akun/Branch/Region/unit tetap mengikuti aturan sebelumnya.

Parsial tetap memakai alurnya dan tidak dibuatkan BA PP. BA PP tidak membuat keputusan digital palsu, tidak menetapkan pembayaran lunas, tidak menambah volume accepted, dan tidak menggantikan tanda tangan fisik.

## Penyimpanan dan kesesuaian dokumen

`payment_terms.document_data.completionFlow=bapp-before-payment-v1` dan `ba_completions.document_data.completionPolicy=barp-before-approval-v1` dimiliki server. Final pada alur `kasbon-settlement-v2` mengadopsinya saat save yang sah. BARP Draft yang belum lengkap dapat disimpan, tetapi tidak bisa dipakai mengesahkan BA PP sebelum isian wajib lengkap.

Pengesahan menyimpan `source` berisi SPK, jenis Final, actuals, BARP dan money policy yang sudah divalidasi, serta `attestedBy/attestedAt` aktual. Kolom SQL `attested_by/attested_at` existing diisi dari actor nyata; bukan approver otomatis. Basis tidak memasukkan status approval, kasbon, APM atau pembayaran, sehingga pengajuan/keputusan finansial tidak mengubah BA PP yang sah. Perbandingan JSON independen dari urutan key dan tidak menyamakan string angka dengan angka.

Jika Admin mengubah isi BA RP/volume/policy yang menjadi basis, BA PP yang sah kembali Draft. Source/actor/waktu pengesahan sebelumnya dipindahkan ke `attestationHistory` beserta alasan/waktu/actor pembatalan. Metadata pengesahan aktif dan SQL attested fields dikosongkan sampai disahkan ulang. Identitas BA PP/nomor dan audit tetap. Simpan ulang tanpa perubahan tidak membatalkan pengesahan. Mengubah Final menjadi Parsial juga membatalkan BA PP alur baru tersebut; tidak mengubah jenis dokumen lama diam-diam.

Saat seluruh approval selesai, sistem menggunakan BA PP yang sama; tidak membuat BA PP kedua atau mengganti status Sah menjadi Draft. Constraint unik `ba_completions.final_term_id` tetap. Semua perubahan mengikuti lock workspace, revision, receipt UUID dan audit yang sudah ada. Client tidak dapat menetapkan flow/source/status/actor melalui saveTerm.

## Data lama dan pengajuan berjalan

Tidak ada migrasi/backfill/GET write atau perpindahan approval massal. Pembayaran/BA PP Sah lama, uang, keputusan, kasbon terenkripsi, ledger, scan, bukti transfer, APM dan arsip tetap.

Pengajuan Final v3 lama yang belum memiliki BA PP mendapat tombol **Siapkan BA PP** pada bagian BA RP. Tindakan eksplisit ini hanya memakai BA RP yang sudah tersimpan, mempertahankan status serta keputusan berjalan, lalu menambahkan BA PP Draft dan marker alur. Tidak mengharuskan mengulang approval yang sudah tercatat. Setelah BA PP disahkan, pengajuan tersebut dapat melanjutkan pembayaran sesuai tahapnya. Tindakan ini tidak disediakan untuk membuat dokumen baru pada pembayaran yang sudah lunas.

Dokumen v1/v2 dan alur v3 historis tanpa adopsi eksplisit tetap mengikuti aturan tersimpannya. Jika BA PP lama sudah ada dan Sah, fitur ini tidak mengonversi pengesahan lama secara otomatis. Draf lama yang ingin memakai urutan baru perlu disimpan/diadopsi sesuai tombol dan validasi yang tersedia. `prepareBapp` berada pada command endpoint existing, memakai validasi/auth/unit/Branch/Head-reopen yang sama; result ID yang dipakai dokumen lain ditolak.

## Tampilan, dokumen, timeline dan ledger

- BA PP muncul di daftar setelah BA RP Final alur baru disimpan. Tombol Buka BA PP memakai data server terkonfirmasi; tidak membuka ID child optimistis yang mungkin berbeda dari server.
- Detail menampilkan **Rekon Kumulatif**, termasuk aktual Final saat ini yang belum approved, dan nominal rekonsiliasi. Ini presentasi pekerjaan, bukan kolom accepted ledger. Accepted SQL/domain tetap bergantung pada approval sebenarnya.
- Timeline/detail Final menampilkan BA RP → Sahkan BA PP → Kasbon Admin → 7 Approval → Bukti Transfer → Tandai Lunas. Status BA PP dan 0/7 approval dapat tampil bersamaan dengan penjelasan yang benar.
- Preview utama/lampiran BA PP yang sah sesuai source boleh dicetak dan diunduh native Word sebelum approval. Tidak diberi label menunggu approval pembayaran; ruang tanda tangan fisik tetap kosong. Draf/rejected/source stale dan PP yang belum Sah tidak mendapatkan izin cetak awal. Word review historis tetap mengikuti ketentuannya.
- Finance dan Mgr Finance & Settlement mendapat pilihan BA PP dan Lampiran BA PP dalam Review Berkas Swakelola; berkas yang belum tersedia dinonaktifkan. Relasi memakai term ID, bukan Project code atau dokumen saudara.
- Unduh semua dokumen tetap mempertahankan gate seluruh approval Final/versi kasbon yang sudah ada. BA PP sah lebih awal tidak mengizinkan paket lengkap yang persetujuan finansialnya belum lengkap; modal menjelaskan persyaratan yang belum terpenuhi.
- Pelepasan volume dan PP lanjutan **tetap** membutuhkan BA PP Sah, Final Disetujui lengkap dan otorisasi release existing. Pengesahan dini tidak membuka release pada 0/7. Kebijakan lunas/next-term/closure matriks tidak diubah. Ringkasan dashboard tidak menutup PP baru hanya karena BA PP sudah Sah sementara pembayaran belum lunas.

## Query dan batas implementasi

Resolver basis/current/ensure menggunakan state yang sudah dimuat, tanpa query account/KHS/BOQ per baris. Perbandingan source berjalan linear terhadap actuals/BARP, maksimal500item sesuai batas input existing; satu source aktif serta riwayat pengesahan bertambah sesuai perubahan eksplisit. Operasional tersimpan pada tabel existing; tidak ada migration, route baru atau runtime dependency.

Pengesahan HTTP fixture **36query** (budget80), prepare domain murni **0query**. Upload/worker bukti transfer pada Final baru menambah **satu indexed lookup** `ba_completions.final_term_id` +workspace dengan hanya `document_data`; enqueue dan worker sama-sama mengecek ulang source. Worker fixture **44query** Final vs43Parsial, bukan query per item/approver. Pelunasan/koreksi APM tetap40query; Finance list tetap4query. Pembentukan graph workspace tetap dapat bertambah dengan jumlah transaksi; bukan klaim load test atau jaminan latency. Konfirmasi UI bukan pengganti validasi server.

## Verifikasi dan penerapan

Bukti: [qa/bapp-before-approval-20261118.json](qa/bapp-before-approval-20261118.json), [LARAVEL_QA](LARAVEL_QA.md). Suite SQLite memory fail-closed, frontend dan build diuji. Regresi mencakup 0approval, blok kasbon sebelum Sah, SQL actor/time/source, volume ledger tetap, akun Operation tidak mengesahkan PP Konstruksi, perubahan BARP/riwayat/rehash, pengajuan historis eksplisit, tidak membuat duplikat BA PP saat approval7, transfer worker, lunas/APM dan pelepasan.

Browser sintetis6kombinasi375/768/1440×light/dark menggunakan React sebenarnya dan mock transaksi lokal: TIDAK0request, YA1attestation, tetap0approval, release terkunci, Word/print aktif untuk BA PP Sah dan tidak ada JS error/page overflow. Dua DOCX native dirender menjadi2halaman dan2PDF browser masing-masing1halaman (surat portrait/lampiran landscape), semua4PNG diperiksa, ZIP/CRC/XML dan blankTTD/nonblank valid. Unduhan Word dari tombol dibandingkan dengan generator native; tidak mengakses database/user server. Fixture memakai identitas contoh dan dapat menampilkan nama Head kosong bila snapshot historis tidak memiliki Head; tidak mengarang akun.

Tidak ada migration baru **untuk fitur BA PP ini**. Operator tetap harus menerapkan migration fitur terdahulu yang belum terpasang. Setelah backup, pull kode lengkap, build aset dan periksa manifest; restart hanya worker upload Area 3 agar handler memakai kode baru, lalu muat ulang halaman. Jangan reset/migrate:fresh/seed database berdata. Agen tidak deploy/restart/migrate/.env atau mengubah its aktif/Dompis. Backup SQLite demo233472byte/0600 dibuat baca-saja sebelum edit.

Belum diuji: aaPanel/MySQL aktual, akun live, concurrency/load besar, Microsoft Word desktop, cetak/TTD fisik dan transfer bank nyata. Transaksi browser hanya fixture sintetis; keputusan/payment produksi tidak disimulasikan sebagai kejadian nyata.

## 19 November 2026 — BA RP dan BA PP setiap pengajuan sebelum pembayaran

Pengguna meminta alur sederhana SPK→BA RP+lampiran→Sahkan BA PP+lampiran→Kasbon/Pembayaran, dan mengonfirmasi Parsial juga wajib BA PP. Sidebar BA RP&BA PP dipindahkan sebelum Pembayaran dengan dua submenu/tab, daftar20/search, langkah bernomor dan satu tombol utama per tahap. Pengesahan/lanjut menunggu server, YA/TIDAK tetap, input aktual dipusatkan di BA RP, empat preview disediakan di pembayaran dan fitur release hanya Final/collapsible.

Marker server bapp-each-payment-v2/barp-each-payment-v2 pada transaksi baru memakai satu BA PP perterm existingFKfinal_term_id (tanpa typeCHECK), nomorBAPP/SPK/Tn unik, source-current invalidasi/riwayat pengesahan. v1/historical/paid tidak dibackfill; SiapkanBA PP adopsi lama eksplisit mempertahankan actualapproval/UUID. Kasbon/transfer/pelunasan menahan sumber belumSah, Partial tidak menutup PP atau membuka release/continuation. Paket Final mencakup BA PP Partial aktual/lampiran, max20parts; uang/PPh/7approval/Branch/unit/private gates tetap. Map-based loadedreadmodel/20DOM, no perrow SQL/API, safe listpoller existing; Partial transfer menambah satuindexedBAPPlookup sepertiFinal. Tidak ada migration/dependency/livewrite/its/env/server/Dompis change. [Aturan dan bukti QA](WORK_DOCUMENTS_FLOW.md).


## 19 November 2026 — Pengesahan BA RP dan tanggal BA PP tersendiri

Menambah tombol Langkah Berikutnya, Sahkan BA RP pada card dokumen, lalu Buat BA PP eksplisit dan Sahkan BA PP. New server marker barpFlow=attest-before-bapp-v1 menghindari pembuatan BA PP dari simpan draf; attestation source/actor/time, revision/receipt/Branch/unit/Head/upload guards dan invalidasi dua dokumen menjaga sumber. Tanggal BA RP dan BA PP dipilih terpisah, tempat otomatis Branch, kalender valid, legacy read memakai tanggal tersimpan tanpa backfill. Word/cetak BARP Sah tersedia dengan parent Sah, BAPP Draft tetap dikunci terpisah. Revisi setelah penolakan tetap melalui kasbon versi baru/all-seven restart. Existing JSON/tabel,0migration/APIendpoint/dependency/poller/live write. Detail serta batas verifikasi: [WORK_DOCUMENTS_FLOW](WORK_DOCUMENTS_FLOW.md), [QA](qa/barp-attestation-dates-20261119.json).


## 19 November 2026 — Jalur BA PP Termin 2 dari BA RP lama tersimpan

Memperbaiki tombol lanjut yang terhenti pada BA RP legacy terkunci. Admin kini dapat memilih Buat BA PP dari BA RP Tersimpan untuk exact unpaid term yang memenuhi prepareBapp existing, termasuk Diajukan/Disetujui. Termin1 lunas dan approval/history/isi BARP Termin2 tetap; tidak membuat term lain/markSah palsu/reset status. Form pending/approved tetap perlu pengembalian/penolakan nyata untuk koreksi. New BARP gate, scope dan settlement guards tetap. UI memakai loaded Map/20DOM/amount existing,0newSQL/API/schema/migration/live write. Lihat [WORK_DOCUMENTS_FLOW](WORK_DOCUMENTS_FLOW.md) dan [QA](qa/legacy-barp-bapp-20261119.json).
