# Matriks dashboard Region → Branch — 9 November 2026

Permintaan pengguna menambahkan tabel pada dashboard awal, bukan mengganti tiga kartu/enam ringkasan yang ada. Tabel **Matriks Project per Region** mengelompokkan Branch berdasarkan relasi master `branches.region_id → regions.id_region`. Klik tombol Region untuk menutup/membuka Branch; subtotal tetap terlihat. Kondisi awal terbuka, tombol mendukung fokus/Enter/Space melalui native button, `aria-expanded` dan `aria-controls`. Tabel bergaris, angka rata kanan dan gulir horizontal berada di panel. Warna mengikuti tema #9FA1FF. Dropdown Branch dashboard juga menyaring matriks.

## Definisi angka

| Kolom | Sumber dan aturan |
| --- | --- |
| Branch | Master Branch dalam akses akun; Branch tanpa PP tetap tampil nol. Nama Branch historis pada PP tetap dapat ditampilkan dengan Region belum ditetapkan bila tidak ditemukan pada master. |
| ID Project · JMLH | Jumlah `projectCode` unik (trim dan huruf besar) pada PP tersimpan. Bukan jumlah PP. |
| ID Project · CLOSE | Seluruh PP pada ID Project yang boleh dilihat akun telah selesai pembayaran final. PP harus Sah, memiliki BA KH Sah dan SPK Sah, semua SPK mencapai termin terakhir Final; seluruh termin terkait Disetujui, ber-APM Valid dan `paid` menutup tagihan aktual masing-masing. |
| ID Project · OPEN | JMLH − CLOSE. Draf/pengajuan, belum memiliki SPK, Final baru disetujui tetapi belum lunas, dan pembayaran historis parsial tetap OPEN. |
| Jumlah PP | Semua PP tersimpan pada Branch/filter, termasuk draf dan lanjutan. |
| Nilai Realisasi | Jumlah `paid` tersimpan dari termin Disetujui pada SPK Sah, termasuk pembayaran parsial historis yang benar-benar tercatat. Pengguna mengonfirmasi pembayaran terealisasi/APM, bukan seluruh tagihan yang baru disetujui. Tidak menganggap APM historis otomatis melunasi sisa tagihan. |
| Total Nilai | BOQ Plan tersimpan PP induk: kuantitas × (harga Material + Jasa) dengan pembulatan domain per baris. Termasuk BOQ tersimpan pada draf/review; PP lanjutan tidak menambah ulang subalokasi induk. Beberapa PP induk independen pada satu ID Project tetap dijumlahkan. |

Contoh fixture: 10 ID unik, 11 PP (salah satu ID memiliki dua PP), satu ID selesai pembayaran final → **10 | 9 | 1**. Ini fixture pengujian terisolasi, bukan data yang ditanam pada its.

JMLH/OPEN/CLOSE dihitung unik di masing-masing Branch, subtotal Region dan total keseluruhan. Jika satu ID berada pada dua Branch, masing-masing Branch menghitung satu, tetapi subtotal Region/total menghitung sekali. Karena itu penjumlahan angka ID antarbaris dapat berbeda dari subtotal. PP tanpa ID tetap masuk jumlah PP/nilai dengan pemberitahuan, tanpa membuat kode ID baru.

Klasifikasi CLOSE melihat seluruh PP dalam akses akun **sebelum** filter Branch. Memilih Branch yang telah lunas tidak membuat ID menjadi CLOSE bila PP lain pada ID yang sama di Branch lain yang juga dapat diakses belum selesai. PP di luar akses akun tidak diambil/diterka; angka tetap terbatas pada cakupan akun. Nilai uang dan jumlah PP mengikuti Branch terpilih. BOQ induk dibukukan pada Branch PP induk sendiri; subalokasi di Branch lanjutan tidak menggandakan nilai.

Kondisi CLOSE tidak mensyaratkan seluruh volume awal habis: Final lebih awal tetap sah menurut workflow. Tidak menambahkan syarat BA PP pada definisi pembayaran final pengguna dan tidak mengubah approval, Finance/APM, ledger, pelepasan volume atau pembuatan PP lanjutan. Seluruh syarat transaksi tetap dimiliki Laravel.

## Sumber, arsitektur dan query

- `AuthenticatedWorkspace.bind` menambah `regionId/regionName` pada metadata Branch melalui satu left join pada query Branch yang sudah ada, dengan proyeksi kolom eksplisit. Region null/terhapus tetap mempertahankan Branch dan menjadi **Region belum ditetapkan**. Kolom teks lama `branches.region` tidak ditebak sebagai identitas Region.
- Scope SQL/`visible()` tetap per Branch **PP**, bukan Branch pada master Project bersama. Region/Branch yang tidak diotorisasi tidak dikirim ke dashboard. Metadata Region baru tidak ditulis ke snapshot dokumen/arsip.
- `dashboard-matrix.ts` adalah read model murni atas state berizin yang sudah dimuat dari Laravel/RelationalWorkflowStore. Map PP/BA KH/SPK/termin dan Set ID menghindari pencarian relasi berulang per baris. `termTotalForDeal` membagi rumus pembulatan lama dengan `termTotal`; tidak mengubah nilai transaksi. Model dimemoisasi pada state/filter sehingga toggle accordion tidak menghitung ulang seluruh nilai.
- Tidak ada API, dependency, migration, backfill, reservasi volume atau perubahan data/workflow. Tidak mengaktifkan data contoh/fallback arsip. Pembacaan account/reference/aggregate yang sudah ada tetap berlaku; fitur ini belum mengganti boot dengan agregasi SQL paginated.
- Fixture direct `bind` tetap **5 query**, termasuk tepat **1 query Branch/Region**, pada katalog awal dan setelah tambahan **200 Branch/200 Region** di SQLite memory. Tidak ada query Region terpisah atau per-Branch/per-PP. Fixture frontend **1000 PP/SPK/termin** melarang `.find` pada koleksi relasi dan memeriksa total utuh. Bukti ini bukan benchmark throughput, waktu respons produksi atau uji beban browser.

## Bukti dan batas

**222 tes PHP / 4332 assertions**, **218 frontend**, TypeScript/Vite build, scoped Pint dan diff check lulus. Tiga tes PHP baru memeriksa query/proyeksi metadata, null/deleted Region, serta state Admin/Head/Finance dengan sibling Project ID di Branch tersembunyi dan arsip/revisi/receipts/audit tetap. Sepuluh frontend baru memeriksa contoh 10/9/1, multi-PP/mandor/SPK, final belum lunas, pembayaran historis, root/continuation, shared ID lintas Region/filter, missing ID/Region, cents, 1000 PP, markup/escaping/disclosure. Rincian: [qa/dashboard-matrix-20261109.json](qa/dashboard-matrix-20261109.json).

Pengujian database hanya SQLite `:memory:` fail-closed. Tidak ada query/mutasi live its, perubahan .env, deployment schema, seed atau sentuhan Dompis Prov pada fitur ini. Backup baru tidak diperlukan karena tidak ada perubahan workflow/data tersimpan. Tampilan memakai data transaksi tersimpan, bukan simulasi. APM tetap pencatatan manual aplikasi, bukan integrasi bank.

Visual browser, klik accordion/filter dengan akun, responsif/dark, keyboard dan load test belum diverifikasi langsung; akses browser sebelumnya ditolak dan tidak dicoba ulang. SSR/perhitungan tidak menggantikan QA visual. Setelah pembaruan, refresh halaman untuk aset terbaru dan **Muat ulang data** untuk mengambil transaksi akun lain.
