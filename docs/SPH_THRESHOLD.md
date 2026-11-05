# Batas SPH Telkom Akses dari BOQ TA–Mandor

Pembaruan 5 November 2026: harga satuan SPH Material dan SPH Jasa tidak boleh melebihi harga komponen yang diinput pada BOQ TA–Mandor dalam PP terpilih.

- SPH Material dibandingkan dengan `planBudget.mandor.priceM`; SPH Jasa dengan `priceS`, melalui ID baris sumber BOQ. Batas berlaku per designator dan komponen, bukan total seluruh BOQ atau harga Material+Jasa yang saling menutup kelebihan.
- SPH baru terisi dari harga BOQ Mandor tersebut. Nilai sama dengan batas diperbolehkan. Harga BOQ Mandor nol berarti SPH komponen itu maksimal nol.
- Peringatan langsung **“SPH … melebihi threshold BOQ TA–Mandor”** muncul di bawah input. Input menampilkan batas rupiah per satuan, atribut max dan aria-invalid/role alert. Nilai yang berlebih tetap terlihat agar dapat dikoreksi; server menolak penyimpanannya.
- Laravel memeriksa simpan draf, penerbitan dan pengesahan. Batas dihitung dari PP tersimpan; harga referensi/status yang disisipkan client tidak dapat melewatinya. Referensi baris yang hilang pada PP beranggaran terperinci ditolak, bukan diganti harga KHS.
- PP lanjutan memakai rincian Mandor miliknya sendiri. Volume tetap mengikuti ledger/kapasitas sebelumnya; perubahan ini tidak menambah reservasi atau mengubah pembatasan volume.
- Harga kesepakatan, penawaran balik Mandor dan aturan approval/pembayaran tetap mengikuti aturan yang sudah ada. Batas baru ini khusus kolom SPH Material/SPH Jasa. Harga kesepakatan tetap dibatasi KHS per komponen.

## Dokumen lama

Dokumen BA KH/SPH yang sudah tersimpan sebagai Terbit/Sah mempertahankan snapshot dan kebijakan harganya. Status tersebut harus berasal dari server, bukan isian client. Pengesahan dokumen Terbit tetap mengabaikan perubahan harga pada request dan hanya menerima bukti; validasi SPK berikutnya tetap dapat dilanjutkan.

PP historis tanpa rincian `planBudget` belum memiliki referensi harga BOQ Mandor per designator; perilaku lamanya dipertahankan tanpa mengarang batas. Draft dengan rincian BOQ Mandor memakai aturan baru saat diedit/disimpan. Pembacaan dokumen tidak menulis ulang harga lama.

## Implementasi dan bukti

- Server: `app/Services/PrototypeDomain.php` pada validasi negosiasi.
- React: `resources/js/area3/sph.ts`, `domain.ts`, `contracts.tsx`, serta atribut aksesibilitas pada NumberInput.
- Pengujian: `tests/Feature/SphThresholdTest.php`, HTTP regression pada `UsernameApprovalV3Test.php`, dan `tests/Frontend/sph-threshold.test.ts`.
- Cadangan sebelum perubahan: `storage/app/private/backups/its-before-sph-threshold-20261105-092002.sql`, 644.783 byte. Hash/evidence: `docs/qa/sph-threshold-20261105.json`.

99 tes PHP / 1.352 assertions, 67 tes frontend, build dan Pint lulus. Pengujian PHP memakai SQLite :memory: dengan bootstrap fail-closed. Tidak ada migration, perubahan .env, akun uji atau transaksi pada database its. Pengujian tampilan dilakukan melalui render komponen otomatis; browser setelah login/layout responsif belum diperiksa manual. Output dokumen dan batas prototipe lain tidak berubah.
