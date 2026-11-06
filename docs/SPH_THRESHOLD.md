# Batas SPH dan Harga Kesepakatan dari BOQ TA–Mandor

Pembaruan 6 November 2026: harga satuan SPH serta Harga Kesepakatan Material dan Jasa tidak boleh melebihi harga masing-masing komponen pada BOQ TA–Mandor dalam PP terpilih.

- SPH/Kesepakatan Material dibandingkan dengan `planBudget.mandor.priceM`; SPH/Kesepakatan Jasa dengan `priceS`, melalui ID baris sumber BOQ. Batas berlaku per designator dan komponen, bukan total seluruh BOQ atau harga Material+Jasa yang saling menutup kelebihan.
- SPH dan Harga Kesepakatan pada form baru terisi dari harga BOQ Mandor tersebut. Nilai tepat pada batas diperbolehkan. Harga BOQ Mandor nol berarti komponen tersebut maksimal nol. Nilai draft lama yang berlebih tetap terlihat untuk dikoreksi; tidak dipotong diam-diam.
- Peringatan langsung **“SPH … melebihi threshold BOQ TA–Mandor”** atau **“Harga kesepakatan … melebihi threshold BOQ TA–Mandor”** muncul di bawah input; badge Validasi kesepakatan juga menandai nilai yang melampaui batas atau referensi yang hilang. Input menampilkan batas rupiah per satuan, atribut max dan aria-invalid/role alert. Nilai yang berlebih tetap terlihat agar dapat dikoreksi; server menolak penyimpanannya.
- Laravel memeriksa simpan draf, penerbitan dan pengesahan. Batas dihitung dari PP tersimpan; harga referensi/status yang disisipkan client tidak dapat melewatinya. Referensi baris yang hilang pada PP beranggaran terperinci ditolak, bukan diganti harga KHS.
- PP lanjutan memakai rincian Mandor miliknya sendiri. Volume tetap mengikuti ledger/kapasitas sebelumnya; perubahan ini tidak menambah reservasi atau mengubah pembatasan volume.
- Harga Kesepakatan pada tab Harga Kesepakatan kini juga wajib memenuhi batas Mandor Material/Jasa secara terpisah, selain batas KHS. Potongan pada satu komponen tidak boleh menutup kelebihan komponen lain. Penawaran balik Mandor tetap menjadi pembanding; aturan approval, reservasi volume dan pembayaran tidak berubah.

## Dokumen lama

Dokumen BA KH/SPH yang sudah tersimpan sebagai Terbit/Sah mempertahankan snapshot dan kebijakan harganya. Status tersebut harus berasal dari server, bukan isian client. Pengesahan dokumen Terbit tetap mengabaikan perubahan harga pada request dan hanya menerima bukti; validasi SPK berikutnya tetap dapat dilanjutkan.

PP historis tanpa rincian `planBudget` belum memiliki referensi harga BOQ Mandor per designator; perilaku lamanya dipertahankan tanpa mengarang batas. Draft dengan rincian BOQ Mandor memakai aturan baru saat diedit/disimpan. Pembacaan dokumen tidak menulis ulang harga lama.

## Implementasi dan bukti

- Server: `app/Services/PrototypeDomain.php` pada validasi negosiasi.
- React: `resources/js/area3/sph.ts`, `domain.ts`, `contracts.tsx`, serta atribut aksesibilitas pada NumberInput.
- Pengujian: `tests/Feature/AgreementThresholdTest.php`, `SphThresholdTest.php`, HTTP regression pada `UsernameApprovalV3Test.php`, dan `tests/Frontend/sph-threshold.test.ts`. Kasus meliputi dua komponen/semua aksi, tepat batas, kelebihan Rp0,01, nol, referensi hilang, kelanjutan dan snapshot terbit. HTTP membuktikan request yang ditolak tidak menulis SQL/revisi/receipt/audit.
- Cadangan sebelum perubahan: `storage/app/private/backups/its-before-sph-threshold-20261105-092002.sql`, 644.783 byte. Hash/evidence: `docs/qa/sph-threshold-20261105.json`.

Bukti awal SPH (5 November 2026): 99 tes PHP / 1.352 assertions, 67 tes frontend, build dan Pint lulus. Pengujian PHP memakai SQLite :memory: dengan bootstrap fail-closed. Tidak ada migration, perubahan .env, akun uji atau transaksi pada database its. Pengujian tampilan dilakukan melalui render komponen otomatis; browser setelah login/layout responsif belum diperiksa manual. Output dokumen dan batas prototipe lain tidak berubah.


## Pembaruan Harga Kesepakatan — 6 November 2026

Referensi BOQ dan rincian Mandor dipetakan berdasarkan ID sekali pada validasi server, termasuk kode KHS untuk pesan kesalahan. Validasi memakai state yang sudah dimuat tanpa query SQL atau request tambahan. UI memakai ulang hasil batas per baris/komponen, termasuk peringatan SPH; tidak meminta referensi ulang melalui jaringan. Tidak ada migration, seed atau perubahan data live. Dokumen Terbit/Sah tidak direvisi massal; draf yang melampaui batas harus dikoreksi sebelum simpan/generate/attest.

Backup sebelum perubahan: `storage/app/private/backups/its-before-agreement-threshold-20261106-151435.sql`, 2.032.468 byte; SHA-256 `291301806fc246fc12de83914667c5b322f4b4f0cd91471f6c2732b23b859086`. 189 tes PHP / 3.216 assertions, 133 tes frontend, build dan scoped Pint lulus. Bukti terbaru dicatat di docs/LARAVEL_QA.md. Pemeriksaan browser/cetak manual belum diulang; seluruh transaksi uji memakai SQLite :memory:.
