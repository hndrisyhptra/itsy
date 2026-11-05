# Dashboard analitik leader — 5 November 2026

**Arsip perubahan sebelumnya.** Atas permintaan terbaru pengguna, tampilan analitik ini sudah diganti kembali dengan ringkasan dashboard awal tanpa empat kartu operasional. Perilaku yang berlaku sekarang: [DASHBOARD_SUMMARY](DASHBOARD_SUMMARY.md). Modul analitik dan pengujian khususnya sudah diganti dengan ringkasan dashboard; angka hasil uji di bawah merupakan bukti historis.

Dashboard mengganti kartu Progress Pekerjaan per PP, Aktivitas Terbaru, Monitoring Volume dan Inbox Approval. Menu Inbox Approval serta notifikasi pada shell tetap tersedia. Branding, sembilan menu, data dan alur transaksi tidak berubah.

## Membaca angka

Seluruh ringkasan memakai state tersimpan yang diberikan Laravel setelah pembatasan Branch akun. Filter Branch dan ID Project berlaku pada semua angka, antrean dan tabel. Rentang mencakup seluruh periode; dashboard bukan laporan perubahan bulanan atau pembaruan waktu nyata. Gunakan **Muat ulang data** untuk mengambil perubahan pengguna lain.

| Indikator | Definisi |
|---|---|
| ID Project | Jumlah `projectCode` unik, setelah trim dan huruf besar, termasuk PP draft. Satu ID dapat mempunyai beberapa PP independen maupun lanjutan. |
| Total Project Plan | Seluruh PP sesuai filter; status Sah dan Diajukan diringkas pada kartu. |
| Nilai SPK Sah | Jumlah `dealTotal` dari BA KH yang terkait SPK berstatus Sah. SPK Draft/Terbit tidak menambah angka. |
| Tagihan Disetujui | Jumlah `termTotal` **termin saat ini**, hanya status Disetujui pada SPK Sah. Tidak menambahkan nilai kumulatif termin sebelumnya lagi. |
| Pembayaran Tercatat | Jumlah `paid` dari termin Disetujui yang sama. Tidak menganggap nomor APM historis sebagai bukti seluruh nilai sudah dibayar. |
| Sisa Tagihan Disetujui | Jumlah selisih nilai tagihan dan pembayaran per termin Disetujui; minimum nol per termin. Draft, Diajukan, Dikembalikan dan Ditolak tidak masuk. |
| BOQ Owner final (PP induk) | Jumlah `boqTotal` hanya PP tanpa parent dan BOQ Final, termasuk bila approval PP belum selesai. Subalokasi PP lanjutan tidak dijumlahkan ulang. Angka ini bukan COGS atau margin. |

Tidak ada pembulatan singkat ke juta/miliar yang menyembunyikan nilai sumber. Fungsi keuangan domain yang sama digunakan pada transaksi dan analitik; pembulatan agregat dua desimal. Laravel tetap menentukan validitas transaksi.

PP tanpa ID Project ditampilkan sebagai kelompok terpisah per PP, diberi keterangan, dan tidak dihitung sebagai ID unik. ID sama pada beberapa Branch dikelompokkan bersama hanya sejauh data Branch tersebut tersedia bagi akun. Dokumen tanpa relasi lengkap tidak menambah nilai SPK/termin.

## Pemantauan leader

- **Status Project Plan:** jumlah setiap status, bukan persentase progres fisik.
- **Posisi Approval:** satu pengajuan pada jabatan yang saat ini menunggu, mencakup lima approver terbaru dan Finance setelah unggah scan. Manager Konstruksi atau Finance dalam alur historis tetap ditampilkan bila memiliki antrean. Angka mencakup dokumen yang dapat dilihat akun, bukan hanya tugas approval milik akun tersebut.
- **Tahapan Pembayaran:** setiap termin masuk tepat satu posisi: Draft, Perbaikan/pengajuan ulang, Approval dokumen, Scan TTD, Koreksi scan, Finance, APM, Lunas, atau posisi historis. Kolom nilai adalah nilai penuh termin, bukan sisa tagihan. Finance v3 harus menyetujui scan yang sama sebelum posisi siap APM.
- **Portofolio per ID Project:** jumlah PP, PP Sah, SPK Sah, nilai SPK, nilai/jumlah termin Disetujui, terbayar dan sisa tagihan. Urutan sisa tagihan terbesar membantu menentukan prioritas. **Lihat PP** membuka rincian seluruh PP pada kelompok sesuai filter, posisi PP/pembayarannya dan tautan timeline.

PP/termin Ditolak v3 berada pada perbaikan/pengajuan ulang Admin, bukan antrean approver sampai diajukan kembali. Penolakan historis v1/v2 tetap terminal. Pelunasan mengikuti nilai pembayaran tersimpan; APM pada versi modern tetap dicatat melalui proses Finance yang telah ada, bukan diubah oleh dashboard.

## Implementasi dan batas pemeriksaan

- Tampilan: `resources/js/area3/dashboard.tsx`; proyeksi baca-saja: `dashboard-analytics.ts`; shell: `App.tsx`; gaya responsif memakai token bersama `styles.css`.
- Tidak ada perubahan schema, migration, endpoint, aturan approval, file unggahan atau data MySQL. Tidak ada data contoh yang dimasukkan ke database aktif. Dashboard akun memakai data MySQL melalui state Laravel; fixture sintetis hanya untuk tes terisolasi.
- Tidak membuat tren pertumbuhan, umur antrean/SLA, progres fisik, prakiraan margin atau integrasi bank yang belum mempunyai sumber data yang memadai. Nilai realisasi adalah pencatatan aplikasi, bukan konfirmasi bank.
- PHP: **127 tes / 2.282 assertions**, SQLite `:memory:` dengan pengaman fail-closed. Frontend: **96 tes**, termasuk 10 tes analitik/markup baru. Build TypeScript/Vite berhasil.
- Cakupan baru: satu ID banyak PP, normalisasi ID/kode kosong, deduplikasi BOQ lanjutan, filter relasi, tagihan vs pengajuan, pembayaran parsial historis, lima tahap approval dan Finance terikat scan, penolakan lintas versi, SPK draft/relasi yatim, keadaan kosong dan kontrol rincian.
- Pemeriksaan browser lokal terisolasi ditolak oleh izin akses. Layout 375/768/1440, dark/light dan interaksi klik belum diverifikasi secara visual untuk dashboard ini. Pengujian markup tidak menggantikan pemeriksaan browser. Pratinjau terisolasi dihentikan; tidak mencoba akses melalui jalur lain. Browser autentikasi, transaksi live dan engine MySQL terpisah tidak diuji ulang karena perubahan hanya tampilan.
