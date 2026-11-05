# Nomor BA KH dan SPK otomatis — 5 November 2026

Nomor dokumen mengikuti **No PP tersimpan yang dipilih**, dengan kode jenis tetap disisipkan sebelum garis miring pertama:

| Dokumen | Contoh nomor |
|---|---|
| PP | `0002/LG.000/TA-03R546/11-2026` |
| BA KH · kode 01 | `0002-01/LG.000/TA-03R546/11-2026` |
| SPK · kode 02 | `0002-02/LG.000/TA-03R546/11-2026` |

Kode 01/02 bukan nomor urut mandor atau penghitung baru. Padding nomor awal, kode Branch, bulan, tahun dan bagian lain dari No PP dipertahankan. Tanggal dokumen/tanggal saat ini dan perubahan konfigurasi format PP berikutnya tidak membentuk ulang nomor dari awal. Bila format PP khusus tidak memakai `/`, kode ditambahkan pada akhir nomor. PP lanjutan menggunakan No PP miliknya sendiri, bukan nomor root. Beberapa PP pada ID Project yang sama tetap menghasilkan nomor masing-masing.

## Form, validasi dan penyimpanan

- Form akun aktif memakai state schema 3. BA KH/SPK baru serta draft yang disimpan kembali mendapat nomor otomatis saat simpan, generate atau pengesahan langsung. Nomor pada form baca saja, dapat disalin, dan digunakan pada preview. Cache draft dengan nomor manual/usang tidak menentukan nomor final.
- Laravel mengambil No PP dari record PP terkait dan mengabaikan nomor yang dikirim browser. SPK diturunkan langsung dari PP melalui BA KH, sehingga kode tidak menumpuk menjadi `-01-02`.
- BA KH/SPK yang sudah **Terbit atau Sah** mempertahankan nomor tersimpan, termasuk nomor historis. Pengesahan dokumen Terbit hanya menambahkan bukti sesuai aturan yang ada. Dokumen Sah tetap terkunci. Membaca atau membuka form tidak menulis ulang nomor database.
- Nomor tetap unik sesuai validasi dan unique key yang ada. Membuat dokumen lain pada PP dengan nomor turunan sama ditolak; mengganti nomor client tidak dapat melewati pemeriksaan. Tidak mengarang kode lanjutan `03` atau urutan mandor tambahan. Dokumen historis yang sudah tersimpan tidak digabung atau dihapus.
- Panjang nomor turunan maksimal 150 karakter sesuai kolom SQL. No PP wajib tersedia. Tidak ada perubahan counter/format No PP, approval, harga, periode otomatis, ledger volume atau pembayaran.
- Nomor BA KH tersimpan pada `contracts.bakh_no`; nomor SPK pada `contracts.spk_no` dan `work_orders.spk_no`, konsisten dengan `document_data` dan audit dalam transaksi yang sama. Tidak memerlukan migration atau perintah aktivasi ulang.
- Skenario offline historis schema 1/2 mempertahankan validasi lamanya; akun aktif selalu memakai schema 3 dari Laravel. Form baru tetap mempunyai default nomor turunan.

## Implementasi dan bukti uji

Server: `app/Services/ContractNumber.php`, dipanggil `PrototypeDomain.php` sebelum validasi simpan dokumen draft. React: `resources/js/area3/contract-numbering.ts`, default `blankDeal`/`blankSpk`, canonical save pada domain dan field baca saja di `contracts.tsx`.

Vector bersama PHP/TypeScript pada `tests/Fixtures/contract-numbering.json` mencakup contoh pengguna, padding lima digit, periode lama, format khusus dengan/tanpa slash, serta format PP historis. Tes memeriksa semua aksi simpan, draft usang, readonly/preview, sumber PP lanjutan, penolakan duplikat, nomor Terbit/Sah tetap, batas panjang dan integritas sumber PP.

**137 tes PHP / 2.486 assertions**, **103 tes frontend**, TypeScript/Vite build dan Pint pada empat file PHP yang diubah berhasil. HTTP test memeriksa kolom SQL dan snapshot saat BA KH/SPK disimpan/diterbitkan/disahkan, rollback duplikat, No PP dan counter yang tidak berubah. Tes PHP memakai SQLite `:memory:` dengan bootstrap fail-closed; tidak menulis data sintetis ke its.

Cadangan sebelum perubahan: `storage/app/private/backups/its-before-contract-numbering-20261105-222558.sql`, 1.047.622 byte, SHA-256 `03a077b6ba245dc96e44cf2ef8ff5be340b4751df5e3c2e8fb76bab7587921ea`. Percobaan backup sandbox yang gagal diberi akhiran `.failed`. Bukti build/pengujian: `docs/qa/contract-numbering-20261105.json`.

Tidak ada migration, penggantian data massal atau perubahan Dompis Prov. Ini penomoran nyata yang disimpan Laravel pada transaksi aplikasi, bukan simulasi. Pemeriksaan browser autentikasi/visual dan engine MySQL terpisah tidak diulang; akses pratinjau sebelumnya ditolak. Pengujian markup tidak diklaim sebagai pemeriksaan visual. Output dokumen tetap preview HTML/cetak browser.
