# Baris EVP pada penutup PP — 13 November 2026

## Pembaruan 15 November 2026 — Teks preview dipangkas

Judul penandatangan, catatan referensi EVP di bawah tabel dan footer prototipe yang diminta pengguna dihapus dari preview/Word/PDF. Identitas EVP/tanggal/cap preview dan TTD cetak kosong tetap mengikuti aturan di bawah; lima tahap keputusan akun tidak berubah. Judul pada editor tetap. [Perilaku dan bukti terbaru](PP_WORD_PRINT.md).

Pengguna meminta baris TTD PP tambahan yang selalu tampil dan diberi penanda approve otomatis. Pengguna juga mengonfirmasi tanggalnya **satu hari kalender sebelum tanggal BA KH paling awal pada PP yang sama**. Ini memperbarui ketentuan tampilan empat baris sebelumnya, bukan menambah tahap approval aplikasi.

## Form, preview dan cetak

Tabel VIII. PENUTUP pada form Risiko & Kualitas dan Preview PP menambahkan baris tetap setelah baris akun yang sudah ada (setelah Head of PMO pada template terkini):

| Keterangan | Nama | NIK | Jabatan |
|---|---|---|---|
| Disetujui Oleh | I GEDE WAHYU DANYNDRA | 730371 | EVP Area Jawa Bali |

Baris ini tidak dapat diedit/dihapus. Form menampilkan **Disetujui otomatis**. Preview menampilkan cap teks **APPROVED / Persetujuan otomatis**, berbeda dari cap **Persetujuan aplikasi** pada keputusan akun. Sesuai permintaan “selalu”, penanda otomatis tampil juga saat PP masih Draft/diajukan/dikembalikan/ditolak. Penanda itu tidak menunjukkan bahwa lima akun sudah menyetujui PP dan tidak membuka BA KH/SPK/pembayaran.

Pada cetak/Save PDF browser, cap dan seluruh status sel TTD tetap disembunyikan dengan CSS print yang sudah ada; ruang TTD basah kosong. Nama, NIK, jabatan dan tanggal referensi tetap tercetak. Tidak membuat gambar/TTD fisik atau keputusan akun EVP.

## Tanggal referensi

- Hanya BA KH tersimpan dalam state Branch-authorized dengan `deal.projectId === p.id` yang dipakai. BA KH milik PP lain, termasuk kode ID Project yang sama atau PP induk/lanjutan, tidak menjadi fallback.
- Ambil tanggal valid YYYY-MM-DD paling awal, termasuk BA KH Draft yang sudah disimpan. Kurangi satu hari kalender dengan operasi UTC agar tidak bergeser karena timezone browser, akhir bulan, tahun atau tahun kabisat.
- Contoh: BA KH paling awal 13 November 2026 → kolom tanggal EVP 12 November 2026. BA KH baru/draf belum disimpan tidak ikut perhitungan. Perubahan tanggal BA KH tersimpan akan memperbarui referensi pada render berikutnya.
- Bila belum ada tanggal BA KH valid, tanggal EVP tetap kosong/“—” dengan keterangan **Menunggu tanggal BA KH**. Jangan memakai tanggal hari ini/Header/approval sebagai pengganti. Batas tahun 0001 yang tidak mempunyai hari sebelumnya dalam rentang didukung juga tidak menghasilkan tanggal rekaan.
- Tanggal tersebut merupakan referensi berkas, bukan waktu keputusan pengguna. Tanggal empat akun, approval/history dan tanggal BA KH asli tidak diubah.

## Arsitektur, penyimpanan dan query

`pp-evp-signatory.ts` memuat identitas yang diberikan pengguna dan helper presentasi murni. `RiskQualitySection` menambahkan satu baris di luar `signatureRows` yang tersimpan. `SignatureStatus` dipakai bersama untuk menjaga struktur preview/print semua baris. Empat baris akun terkini tetap berasal dari template dan keputusan aktif; lima tahap PM → Admin Area → Project Support → Deployment → Head tetap divalidasi Laravel/React.

Tidak ada role/akun/assignee/keputusan keenam, command, endpoint, migration, backfill, field SQL atau perubahan snapshot. Membuka preview tidak menulis data. Data historis tersimpan, nomor, nominal, arsip, Finance/APM/ledger/files tidak disentuh. PP lama yang memang tidak memiliki tabel penutup itemized tetap memakai preview historisnya; tidak dibuatkan tabel baru di luar komponen ini.

Helper melakukan satu lintasan BA KH yang sudah dimuat, O(jumlah BA KH), memori tambahan konstan; tidak mengurutkan/mencari katalog per baris, tidak mengambil akun EVP atau membuka data di luar state yang diotorisasi. Tambahan SQL/API/query per akun **0**; bukan klaim benchmark produksi. Tidak mengambil backup database baru karena perubahan murni presentasi, tanpa mutasi/alur penyimpanan. Tidak menjalankan konversi nominal/migration terdahulu.

## Verifikasi dan batas

Empat regression frontend baru menguji form/preview/read-only/posisi, earliest exact-PP (termasuk draft tersimpan, tanggal invalid, urutan terbalik dan tanpa BA KH), batas tahun/bulan/kabisat dan state/routing/approval history tidak berubah pada lima status PP. Regresi lama tetap menguji cap empat akun dari keputusan aktual serta CSS print yang mengosongkan sel TTD; ekspektasi dipisahkan dari penanda otomatis EVP.

Hasil suite dan aset akhir dicatat pada [LARAVEL_QA](LARAVEL_QA.md) dan [bukti terstruktur](qa/pp-evp-signatory-20261113.json). Tes PHP tetap fail-closed SQLite memory; tidak memakai its aktif. Browser akun/responsif/visual print, cetak fisik dan deployment server belum diverifikasi. Tidak ada simulasi approval pengguna/transfer/TTD yang ditambahkan ke runtime.
