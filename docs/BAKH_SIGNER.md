# Penandatangan BA KH otomatis — 6 November 2026

Pada BA KH baru/draf dari PP versi 3, **Penandatangan Telkom Akses adalah Head of PMO yang dipilih pada PP tersebut**. Nama dan NIK tampil otomatis pada tab Dokumen BA KH sebagai dua input read-only, lalu ditampilkan pada preview/cetak. Ini pengisian identitas, bukan persetujuan atau tanda tangan elektronik. Area TTD tetap kosong untuk dokumen fisik.

## Sumber identitas dan penyimpanan

- Gunakan approverUserIds untuk Head of PMO pada PP terpilih, termasuk PP lanjutan; tidak mengambil akun Head pertama secara global atau identitas milik PP induk.
- Nama/NIK memprioritaskan keputusan Setujui pada siklus aktif dengan role dan userId yang cocok. Fallback adalah signatureUsers PP dengan userId yang sama, kemudian akun Head terpilih yang sudah dimuat. Snapshot/keputusan orang lain tidak boleh dipakai.
- Jika NIK belum tersedia, nilainya kosong dan form meminta melengkapi data akun; tidak mengarang NIK dan tidak menambahkan kewajiban NIK baru pada validasi formal. Tidak adanya identitas Head terpilih memblokir penerbitan/pengesahan; simpan draf tetap dapat mempertahankan identitas kosong tanpa menebak orang lain.
- Laravel mengganti signer, signerUserId, signerNik, signerRole dan signerSource dari sumber server pada setiap save/generate/attest baru/draf. Nilai signer/status/identitas palsu dalam request tidak menentukan kebijakan snapshot.
- Nama terproyeksi pada contracts.signer_name; jabatan pada contracts.signer_position ketika belum ada SPK. Seluruh identitas BA KH, termasuk NIK dan ID akun, tersimpan pada contracts.document_data. Tidak perlu migration atau kolom akun baru. Proyeksi SPK yang sudah ada tetap memakai penandatangan SPK sendiri.

BA KH yang telah Terbit/Sah mempertahankan nama/NIK/ID tersimpannya, sekalipun akun atau PP berubah; pengesahan Terbit menerima bukti dan tidak mengganti identitas. Dokumen lama tanpa NIK tidak diberi NIK rekaan. PP historis versi 1/2 mempertahankan form/nama manual karena belum mempunyai pilihan Head pada rantai lamanya. Membaca atau membuka draf tidak menulis ulang data; pengguna menyimpan melalui alur biasa.

## Implementasi dan QA

PHP BakhSigner dan React bakh-signer menyelaraskan form, blankDeal, penyimpanan dan preview. Referensi PP/keputusan/akun berasal dari state yang sudah dimuat, satu pencarian per koleksi per dokumen tanpa query SQL atau panggilan jaringan baru. Uji helper mencatat nol query dan state sumber tetap utuh; HTTP menguji ketiga aksi simpan serta proyeksi nama/jabatan dan NIK pada snapshot SQL. Pilihan beberapa akun Head, snapshot yang tidak cocok, PP lanjutan, NIK/assignment hilang, field read-only dan dokumen Terbit/Sah juga dicakup.

Cadangan sebelum perubahan: storage/app/private/backups/its-before-bakh-signer-20261106-153102.sql, 2.032.468 byte; SHA-256 6e655019f5186b02969fbdcb4fca2ace345412e289c9d361f13967d04968c68a. Tidak ada migration, seed, akun uji atau perubahan transaksi pada its. Seluruh pengujian transaksi memakai SQLite :memory:. Browser/cetak fisik belum diulang manual.

Hasil: 194 tes PHP / 3.260 assertions, 137 tes frontend, build produksi dan scoped Pint lulus. Rincian bukti: docs/LARAVEL_QA.md.
