# Tempat penandatanganan mengikuti Branch PP

## Otoritas 19 November 2026

Pengguna meminta tempat pada baris tempat/tanggal dokumen mengikuti Branch masing-masing. Ketentuan ini menggantikan kota tetap Surabaya untuk BA KH, SPK dan BARP serta tempat historis BARP/SPK yang sebelumnya dipakai BA PP. Pengguna kemudian menegaskan **kolom VIII. PENUTUP PP tetap tidak ada perubahan**. Tidak menambahkan tanggal pada kolom tersebut atau membuat baris tanggal PP baru.

| Bagian | Sumber tempat | Sumber tanggal |
|---|---|---|
| Header PP | Branch PP yang bersangkutan, seperti sebelumnya | Saat Penggunaan tetap tersimpan |
| VIII. PENUTUP PP | Tabel/kolom/nama/jabatan/cap tetap | Sel TANGGAL tetap kosong |
| BA Negosiasi Kesepakatan Harga | `project.branch` | `deal.date` |
| SPK | `project.branch` melalui BA KH terkait | Tanggal SPK valid yang dipilih; default kalender existing tetap |
| BA RP | `project.branch` melalui pembayaran → SPK → BA KH | Tanggal rekonsiliasi BARP tersimpan |
| BA PP | Branch PP yang sama dengan pembayaran terkait | Tanggal BARP tersimpan; tidak memakai tanggal hari ini |

Contoh Branch MATARAM dan tanggal dokumen16November2026: **MATARAM, 16 November 2026**. Kapitalisasi mengikuti nama Branch PP tersimpan, hanya spasi luar dibuang. Jika Branch kosong, preview menampilkan elipsis; tidak mengganti dengan Surabaya, kota arsip, lokasi pekerjaan, Witel, Branch akun, root/sibling PP atau PP lain yang berbagi ID Project.

Berlaku Konstruksi dan Operation, preview HTML, cetak/Simpan PDF, DOCX masing-masing dan bagian terkait di Unduh Semua Dokumen. Lampiran yang memang tidak mempunyai baris tempat/tanggal mempertahankan bentuknya. Lokasi pekerjaan/Witel bukan kota penandatanganan dan tetap mengikuti isian masing-masing. Kasbon tidak termasuk lima dokumen yang diminta; form/snapshot/template kasbon tetap memakai aturan tersendiri.

## Implementasi dan penyimpanan

- `resources/js/area3/document-place.ts::projectSigningPlace` dan `app/Services/DocumentSigningPlace.php::fromProject` hanya membaca Branch dari objek PP terkait yang telah diotorisasi. Tidak mencari katalog atau melakukan query.
- Form BA KH/SPK/BARP menampilkan tempat otomatis readonly. Model `bakh-content`, `spk-content`, `barp-content`, `bapp-content` memakai helper yang sama untuk seluruh jalur output. Ekspor Word/print/package menggunakan model existing, tanpa duplikasi perhitungan atau perubahan struktur template.
- Saat Admin melakukan simpan yang diizinkan, server mengisi `deal.signingPlace`, `spk.place`, `term.barp.place` dari PP terkait dan mengabaikan kota palsu/stale dari klien. Field JSON existing dipakai; tidak ada kolom/tabel/migration baru.
- BA KH/SPK Terbit/Sah dan pembayaran terkunci tetap tidak ditulis ulang otomatis. GET/preview/unduhan tidak melakukan backfill. Output dokumen lama mengikuti Branch PP dengan tanggal/identitas/nilai/approval historis yang sama; kota arsip tetap tersimpan.
- Bila Admin secara sah menyimpan BARP editable lama dan tempatnya terselaraskan ke Branch, perubahan tersebut tetap tunduk pada pemeriksaan sumber BA PP existing: BA PP alur baru dapat memerlukan pengesahan ulang apabila sumber BARP berubah. Tidak mengecualikan perubahan tempat dari source hash atau melemahkan guard.
- Manifest paket yang sedang diajukan/keputusan/snapshot tidak dinormalisasi saat read/approval. Tahap approval, Branch/Region/unit, uang, volume, ledger, APM, kasbon, file privat dan blank TTD cetak tetap.

## Performa dan pemeriksaan

Helper bersifat scalar, trim nama Branch saja, **0 SQL** terukur. Seluruh relasi PP berasal dari authorized graph yang sudah dimuat; perubahan urutan lookup pada save SPK memakai lookup yang sudah ada, bukan query tambahan. Fixture saveTerm HTTP44query≤90, bukan benchmark beban server. Tidak ada endpoint/polling/dependency/runtime converter baru.

Verifikasi:360PHP/8755assertions pada SQLite :memory:,392frontend, TypeScript/Vite,45manifestrefs/36preloads×3profil, scopedPint/diffcheck. Tes mencakup16Branch×2unit, tanggal masa depan/invalid, penolakan kota palsu, exact PP/continuation, SQL snapshot/GET unchanged, readonly form, DOCX/HTML parity, paket gabungan dan PP signature table unchanged.

Delapan DOCX sintetis BA KH/SPK/BARP/BAPP untuk MATARAM dan FLORES dirender menjadi10halaman Word/LibreOffice; seluruh halaman diperiksa. Delapan PDF cetak browser11halaman diperiksa nonblank dan tempat/tanggal, dengan seluruh halaman diperiksa melalui contact sheet. Browser fixture12kombinasi dokumen/form/PP×2Branch, viewport375/768/1440 serta light/dark lulus tanpa JSerror/pageoverflow. Ini bukan browser akun nyata, MySQL DDL, server aaPanel, desktop Microsoft Word atau cetak fisik. Tidak mengubah its aktif, .env/APP_KEY, Dompis, server, worker, data uji nyata atau melakukan deploy/commit/push/migration.

## Penerapan

Pull kode lalu build aset dengan prosedur deployment yang sudah ada (`npm run build`, `npm run check:assets`). **Tidak memerlukan migration untuk perubahan ini.** Reload halaman agar chunk versi terbaru terpakai. Ikuti prosedur restart worker Area3 setelah perubahan kode domain; jangan restart worker Dompis/aplikasi lain. Agen belum menerapkan kode pada server.

Bukti aktual: [document-signing-place-20261119.json](qa/document-signing-place-20261119.json).
