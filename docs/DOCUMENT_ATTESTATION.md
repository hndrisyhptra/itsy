# Pengesahan BA KH, SPK dan BA PP — 7 November 2026

Pengguna menghapus seluruh **isian teks referensi bukti dokumen bertanda tangan**, termasuk kewajiban backend. BA KH, SPK dan BA PP dapat disahkan tanpa mengisi referensi tersebut. Ini menggantikan aturan referensi teks terdahulu; bukan persetujuan otomatis oleh approver atau pembuatan tanda tangan.

## Perilaku aktif

| Dokumen | Aksi tanpa referensi teks | Syarat yang tetap berlaku |
| --- | --- | --- |
| BA KH | Simpan draf, generate dan Sahkan | Admin Konstruksi/Superadmin, PP Sah/BOQ Final, data formal lengkap, jadwal PP, identitas Head yang sesuai, batas harga dan ketersediaan volume |
| SPK | Simpan draf, generate dan Sahkan | Admin Konstruksi/Superadmin, BA KH Sah, nomor server, data formal/periode valid dan snapshot komponen |
| BA PP | Sahkan | Admin Konstruksi/Superadmin, nomor BA PP dan Termin Final disetujui seluruh approver yang ditugaskan |

Kolom input, pesan kewajiban, status referensi pada preview dan kolom Bukti dokumen pada timeline dihapus. Tombol/modal pengesahan menjelaskan pencatatan dokumen, bukan klaim penerimaan scan. Sahkan tetap merupakan aksi Admin yang terpisah dari inbox approval PP/Pembayaran. Dokumen Sah tetap terkunci; dokumen Terbit hanya dapat disahkan menggunakan snapshot tersimpan, tanpa mengganti harga, nomor, periode atau penandatangan.

**Eviden pekerjaan PDF/foto dan scan TTD basah Pembayaran adalah berkas nyata yang berbeda.** Upload privat, preview berizin Branch, antrean, lima approval, verifikasi Finance yang terikat scan dan APM tetap mengikuti [TERM_WORK_EVIDENCE.md](TERM_WORK_EVIDENCE.md) serta [WORKFLOW_V3.md](WORKFLOW_V3.md). Referensi transfer pada pembayaran historis juga bukan isian referensi bukti dokumen ini.

## Backend dan kompatibilitas

- PrototypeDomain::saveDeal/saveSpk tidak mewajibkan evidence. Field usang dari client, termasuk tipe selain teks, diabaikan. Dokumen baru memakai nilai kosong; dokumen tersimpan mempertahankan nilai historisnya, termasuk saat draf diedit. Tidak ada referensi baru atau tanda tangan rekaan.
- attestBapp memakai ID dan nomor saja. Argumen ketiga dari client lama diabaikan. React mengirim dua argumen dan mencerminkan validasi Final/approval/nomor Laravel.
- Field evidence pada snapshot lama dan kolom SQL nullable evidence_reference dipertahankan untuk kompatibilitas/riwayat, tidak ditampilkan atau diwajibkan. BA PP baru tanpa referensi menghasilkan NULL pada ba_completions.evidence_reference. Tidak ada migration/drop kolom, pengosongan massal data lama atau penulisan ulang arsip workspace.
- Simpan memakai otorisasi akun/Branch, workspace lock, optimistic revision, request UUID, receipts/audit dan proyeksi incremental yang sama. Pelepasan volume tetap memerlukan BA PP Sah; jumlah accepted/released tidak dapat dipakai ulang.

## Optimasi dan verifikasi

Normalisasi referensi memakai aggregate yang sudah dimuat, tanpa query/endpoint baru. Rekonsiliasi BA PP sekarang membuat Map BOQ/KHS sekali per render untuk menghindari pencarian kedua katalog pada setiap komponen. Perhitungan nominal dan ledger tetap sama.

Tiga tes PHP khusus mencakup isian hilang/kosong/null/usang, simpan/generate/pengesahan, arsip draf/Terbit dan kunci Sah, serta guard BA PP. Eksekusi matriks BA KH/SPK menggunakan **nol query domain**. HTTP akun nyata pada SQLite memory memeriksa snapshot contracts/work_orders, BA PP NULL, proyeksi nomor silang, duplikat atomik, serta release/continuation setelah Final lima approval. Lima jalur HTTP terukur memakai **51–58 query** termasuk pembacaan revisi, dibatasi maksimal 60 pada fixture kecil (BA KH/SPK dua PP, BA PP satu PP). Regresi juga melarang penulisan ulang tabel PP/BOQ/approval/file/scan pada jalur itu. Ini bukan pengukuran throughput MySQL atau jaminan untuk semua ukuran workspace.

Empat tes frontend tambahan memeriksa input usang, guard Final/role/nomor, form/preview/timeline tanpa referensi, riwayat tidak berubah dan rekonsiliasi tanpa find katalog per komponen. **197 tes PHP / 3.654 assertions, 160 tes frontend, build produksi dan scoped Pint lulus.** Browser terautentikasi dan cetak fisik belum diuji manual pada perubahan ini; tidak ada transaksi uji di its.

Backup sebelum perubahan: storage/app/private/backups/its-before-remove-evidence-reference-20261107-085027.sql, 2.032.468 byte; SHA-256 428ea7446e7a3d5f3b41d09bb5558c6057c662796b14e92c6eec6b0b4feb94f0. Tidak ada perubahan .env, master, akun, schema atau data transaksi live. Kode dan build siap digunakan pada runtime MySQL its yang aktif.

Arsitektur: [ARCHITECTURE.md](ARCHITECTURE.md). Audit: [CHANGELOG.md](CHANGELOG.md), [LARAVEL_QA.md](LARAVEL_QA.md), WORKPLAN.md dan qa/document-attestation-20261107.json.
