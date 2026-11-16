# Pengajuan lama dari Finance ke Mgr Finance & Settlement — 16 November 2026

Pengguna meminta pengajuan payment lama yang masih di inbox Finance dan belum disetujui dialihkan lebih dahulu ke Mgr Finance. Perubahan ini merupakan pemindahan **eksplisit per pilihan**, bukan backfill otomatis saat pull/migration. Pengajuan baru tetap menggunakan tujuh approval kasbon-settlement-v2.

## Pemakaian

1. Finance atau Superadmin membuka **Inbox Approval → Alihkan Payment Lama ke Mgr Finance**. Tombol muncul bila ada calon yang memenuhi syarat pada Branch akun.
2. Cari ID Project/LOP/PP/Branch, pilih pengajuan (20 baris per halaman, maksimum50 per aksi), lalu pilih akun Mgr Finance & Settlement aktif yang memiliki semua Branch terpilih. Bila akun berbeda untuk masing-masing Branch, lakukan per kelompok Branch.
3. Konfirmasikan YA. Seluruh pilihan diperiksa kembali; satu konflik/status/versi/Branch yang tidak valid menggagalkan seluruh batch. Pengajuan keluar dari tugas Finance dan masuk hanya ke akun Mgr Finance yang ditetapkan.
4. Mgr Finance membuka pengajuan, mereview PP sampai kasbon melalui modal dokumen, lalu **Setujui & Teruskan ke Finance**. Sesudah keputusan nyata ini, pengajuan kembali ke Finance untuk review/approval pembayaran, transfer, unggah bukti dan Tandai Lunas. APM tetap opsional.
5. Mgr Finance dapat **Kembalikan Kasbon** dengan catatan wajib. Admin memperbaiki dengan kasbon versi baru; review Mgr Finance harus dilakukan lagi untuk versi baru itu. Lima approval pekerjaan terdahulu tetap, mengikuti pola pengembalian kasbon historis. Ini bukan penolakan pengajuan tujuh-approval baru yang mengulang dari PM.

## Batas pengajuan

Hanya workflowVersion3/paymentFlow kasbon-v1, status pekerjaan Disetujui dengan lima keputusan pengguna terpilih yang lengkap, kasbon aktif sudah terbit, dan Finance belum menyetujui. Tidak boleh sudah dialihkan, memiliki keputusan Finance pada versi aktif, riwayat Finance Setujui, bukti transfer, APM, paymentHistory atau nominal paid. Kasbon yang belum diajukan Admin, alur partial-payment-v1/pre-kasbon/v1/v2, serta pengajuan tujuh approval tidak dikonversi melalui fitur ini. Bukti transfer queued/processing dan PP yang sedang koreksi Head juga memblokir pemindahan.

Tidak ada otomatisasi berdasarkan nama atau pemilihan akun pertama. Manager nonaktif, salah role atau tidak memiliki seluruh Branch ditolak. Approve hanya oleh akun Manager yang ditetapkan dan masih aktif/berhak pada Branch, untuk UUID kasbon yang sedang ditampilkan. Finance tidak dapat melewati review ini melalui endpoint approval, upload transfer, APM atau Tandai Lunas. Konflik revisi dan respons jaringan tidak memicu auto-retry finansial.

## Data dan audit

`LegacySettlementReview` (Laravel) dan legacy-settlement-review.ts menyimpan marker server-owned `payment_terms.document_data.legacySettlementReview` (versi1, userId/nama Manager, UUID kasbon, pelaku/waktu penugasan, keputusan aktual) serta append-only `legacySettlementReviewHistory`. Penugasan dan keputusan menggunakan workspace lock/revision, UUID receipt dan prototype_audits/actor SQL yang sudah ada. Reissue kasbon mempertahankan penugasan Manager dan keputusan sebelumnya dalam riwayat, mengganti binding UUID aktif dan menghapus keputusan aktif agar wajib direview kembali.

Lima approval SQL asli (`approval_runs/steps/decisions`), assignee, waktu dan history tidak diubah/digandakan/diberi kasbonId fiktif. Karena itu ini adalah review tambahan kasbon historis, bukan konversi retrospektif menjadi run tujuh tahap. Snapshot terenkripsi finance_kasbons, rekening/penerima, BOQ/BARP, nominal bruto/net, ledger, arsip workspace dan pembayaran tetap. Tidak ada migration/schema baru.

Inbox, badge penghitung, status List Payment dan timeline menampilkan review Manager tambahan beserta nama, waktu dan riwayat. Endpoint kasbon privat hanya membuka versi kepada Manager yang ditetapkan (Branch tetap wajib). Pembacaan memberi overlay nama/jabatan Manager di slot fisik kasbon aktif; keputusan asli Manager pada UUID yang sama menghasilkan cap preview. Cetak dan native Word tetap kosong pada area TTD. Versi arsip/snapshot terenkripsi tidak ditulis ulang. Pilihan penandatangan fisik bebas dari fitur sebelumnya dikunci setelah dialihkan agar tidak berbeda dari Manager yang ditugaskan.

## Arsitektur, query dan deployment

Batch dibatasi50, daftar20 dan lookup relasi/akun dari state yang sudah terotorisasi diindeks sekali untuk validasi. Satu pemeriksaan antrean SQL untuk seluruh ID, tanpa query/dekripsi private_data per pengajuan. SQL writes mengikuti jumlah termin/event yang berubah; proyeksi approval/actual/ledger tidak ditulis ulang. List Payment tetap4query dan hanya menambah proyeksi JSON status review/Finance yang diperlukan (bukan nama/rekening/isi kasbon).

Tidak ada migration baru untuk pemindahan ini; role/queue/kasbon migrations terdahulu harus sudah tersedia. Setelah deploy kode lengkap, build/check aset, clear cache dan restart hanya worker Area3 sesuai panduan server, operator masih harus membuka tombol tersebut dan memilih pengajuan/Manager. Tidak ada pemindahan its/server oleh agen dan tidak ada SQL manual untuk mengganti status secara sebagian. Jangan migrate:fresh/reset/seed atau mengganti APP_KEY.

Bukti pengujian dan batas verifikasi dicatat pada [LARAVEL_QA](LARAVEL_QA.md) dan [bukti JSON](qa/legacy-finance-routing-20261116.json). Query fixture kecil bukan benchmark MySQL/multiuser. Interaksi browser login/server aaPanel, Word desktop dan cetak fisik tetap membutuhkan verifikasi operator.


Verifikasi akhir alih inbox:310PHP/7137assertions,318frontend,TypeScript/Vite,Pint dan assets36manifest/28preload×3profil lulus; satu DOCX/1halaman dirender/diperiksa, cap hanya preview. Query single36/batch8=51, batas50/120; list tetap4query. Tidak ada private_data query per target atau perubahan proyeksi lima approval/ledger/nominal lama. Browser akun/server/MySQL load/Word desktop/cetak fisik belum diverifikasi. [Bukti](qa/legacy-finance-routing-20261116.json), [catatan QA](LARAVEL_QA.md).
