# Paket dokumen PP sampai Lampiran BA PP — 16 November 2026

## Penggunaan dan isi

Pada menu **BA PP**, baris berstatus **Sah** memiliki ikon **Unduh semua dokumen · Word / PDF** pada kolom Aksi. Ikon yang sama tersedia di detail BA PP dan bagian BA PP pada timeline. Membuka ikon menampilkan preview seluruh paket; unduh dan cetak meminta konfirmasi YA/TIDAK.

Urutannya: Project Plan lengkap → BA KH → Lampiran BA KH → SPK → Lampiran SPK → BARP + Lampiran BARP + kasbon untuk setiap pembayaran yang Disetujui, urut nomor → BA PP → Lampiran BA PP. Pengguna mengonfirmasi kasbon setiap pembayaran ikut. Hanya versi kasbon aktif tersimpan yang dibaca; versi lama/rejected tidak digabung. Pembayaran historis tanpa kasbon tidak dibuatkan berkas atau persetujuan baru dan ditandai pada informasi paket.

**Unduh Semua · Word (.docx)** menghasilkan satu Word native editable. **Cetak / Simpan PDF** membuka pencetakan browser untuk seluruh paket; pilih Simpan sebagai PDF untuk mendapatkan satu file. Surat tetap A4 portrait dan lampiran A4 landscape, menurut aturan masing-masing dokumen. Tanda tangan/cap hanya tampil pada preview sesuai keputusan sebenarnya; hasil Word/print mempunyai ruang TTD kosong. Tidak menggabungkan foto KTP/buku tabungan, eviden, scan TTD atau bukti transfer; unggahan privat tersebut tetap di endpoint berkasnya.

## Identitas dan pengamanan

Resolver mengikuti **BA PP → Final → SPK → kontrak BA KH → PP**, bukan ID Project yang mungkin digunakan beberapa PP. Pembayaran sebelumnya hanya dari SPK yang sama, nomor tidak melebihi Final, status Disetujui dan lolos gate cetak sebenarnya; maksimal3. PP/BA KH/SPK harus Sah dan Final lengkap. BA PP Draft tidak menampilkan ikon. Jika PP dibuka kembali untuk koreksi Head, pembentukan paket ditolak tanpa mengubah dokumen turunannya. Lunas/APM bukan syarat baru untuk mencetak berkas fisik; pengamanan pembayaran lama tetap.

Kasbon wajib tersedia sebelum ekspor jika pembayaran memiliki summary kasbon. UI memuat metadata privat saat modal dibuka, memeriksa UUID summary versus respons dan mengikat hasil ke fingerprint seluruh ID/UUID dalam paket. Pergantian versi langsung menonaktifkan ekspor dan mengosongkan tampilan kasbon lama. Akses gagal/UUID berubah membuat paket belum dapat diunduh/dicetak, dengan Coba Lagi. Tidak ada fallback rekening SPK terbaru atau pembuatan kasbon dari nominal bruto. Snapshot kasbon/penerima/quote/net lama dipakai apa adanya; tidak menghitung potongan PPh lagi.

GET lama `/api/finance/terms/{id}/kasbon` menerima opsi baca-saja `current_only=1`, digunakan bersama `documents_only=1`. Otorisasi role/Branch/workspace/selected approver/Head guard tetap melalui FinanceDocuments.authorize. SQL hanya memilih UUID aktif dari term SQL yang diotorisasi, maksimal1 record/3kolom; parameter UUID dari client tidak dapat memilih arsip. Tidak mengambil katalog berkas, tidak mendekripsi10versi lama dan tidak mengubah encrypted snapshot/annotation/riwayat. Respons private,no-store. Data kasbon hanya memori modal, bukan localStorage/global boot.

## Arsitektur dan performa

- `document-package.ts`: resolver read-only graph spesifik dan filename aman. `document-package-ui.tsx`: modal gabungan dari renderer existing, private read maksimum3request (satu per pembayaran), confirmation/busy/duplicate/abort/error guards. Pembacaan paket menggunakan state Branch-scoped yang telah dimuat.
- `document-package-docx.ts`: lazy generators existing dan PP dari capture DOM yang sama. `docx-merge.ts`: hanya ZIP stored dari generator internal, bukan parser upload pengguna; memisahkan style/numbering/bookmark/relationship ID, media/header dan section asli. Header kosong eksplisit mencegah logo SPK diwariskan ke seluruh lampiran. Pada PDF gabungan, margin negatif letterhead SPK dinetralkan secara scoped agar gambar tidak melintas ke halaman landscape sebelumnya atau terpotong di kanan; cetak individual tetap. Metadata docProps asal tidak digabung; notes kosong tanpa referensi boleh dihilangkan, notes/comments aktif dan relasi eksternal ditolak. Word native, bukan altChunk/HTML/gambar dokumen.
- Maksimal20source packages (alur aktual maksimal16), total source48MiB, outputZIP64MiB. Tidak memangkas item; bila terlalu besar, minta unduh terpisah. Memori/CPU bertambah mengikuti isi dokumen; tidak diklaim sebagai benchmark ribuan baris atau server serentak.
- List BA PP membangun indeks PP/kontrak/SPK/term dan total sekali,20baris per halaman; ikon tidak melakukan join/GET privat per baris. Timeline mengelompokkan relasi per PP sekali dan aktivitas20per halaman. Pembuatan ulang model dokumen existing tetap mengikuti kompleksitas masing-masing.
- Lampiran SPK Word memperlebar TotalVol6%→8%, Uraian23%→21% tanpa perubahan isi, untuk mencegah digit total Rupiah turun sendiri. Perubahan berlaku juga pada unduhan individual karena renderer dibagi.
- Tidak ada endpoint baru, migration, dependency runtime, command mutasi, query saat list ikon dirender, perubahan workflow/uang/volume/akun/arsip atau perubahan database its aktif. Current-only lazy read fixture3query layanan/6HTTP; angka HTTP memakai konteks fixture autentikasi yang sama, bukan cold-session/load benchmark.

## Timeline dan detail terbaru

Ringkasan paket menunjukkan Draf PP → BA KH & SPK → Ajukan Paket →5approval. Pembayaran baru menunjukkan kasbon Admin sebelum7approval, lalu bukti transfer dan Tandai Lunas. Ketujuh role/nama/keputusan/tanggal ditampilkan dari assignment/keputusan yang tersimpan dan UUID kasbon aktif; bukan persetujuan palsu. Ditolak/dikembalikan tetap mengikuti resubmission server. Dokumen historis dan review tambahan Mgr Finance memakai marker aktualnya.

Scan fisik/NOK/OK tetap terpisah dari pembayaran. Timeline membedakan bruto, nilai kasbon/net dan terbayar; APM kosong pada alur kasbon dijelaskan opsional. Detail PP, pembayaran, inbox dan BA PP berbagi ringkasan read-only, tanpa mengajukan atau menyetujui otomatis. Kartu List Payment putih/surface solid, aksen ungu dan tanpa gradient; keterangan pelunasan tidak lagi menyatakan APM wajib.

## Pengujian dan deployment

Lihat [bukti QA](qa/document-package-20261116.json) dan [LARAVEL_QA](LARAVEL_QA.md). Pengujian memakai SQLite memory, fixture sintetis dan browser sementara dengan semua request diarahkan ke fixture lokal. Dua Word gabungan (13/17halaman) dirender dengan bundled LibreOffice; PDF browser14/18halaman, orientasi campuran/nonblank/ruang TTD kosong diperiksa. Paging Word dan browser memang dapat berbeda. Teks/tabel panjang dapat menambah halaman.

Belum diuji pada akun server aaPanel, Microsoft Word desktop, cetak fisik atau MySQL concurrent load. Tidak ada deploy/pull/migration/restart worker oleh agen. Setelah operator menerapkan kode, build aset (`npm run build`, `npm run check:assets`) dan refresh halaman. Fitur ini tidak memerlukan migration baru atau reset data; migration fitur sebelumnya tetap mengikuti panduannya sendiri.


## 17 November 2026 — Ikon Unduh Semua Dokumen

Tombol pembuka paket bersama memakai Lucide `Download` ukuran16 pada Aksi BA PP/detail/timeline. Ikon `Files` pada label isi modal tetap. Tooltip, akses/status Sah, isi paket, konfirmasi unduh dan cetak tetap. TypeScript/Vite serta pemeriksaan aset lulus; tanpa SQL/API/migration baru atau pengujian browser/server ulang.
