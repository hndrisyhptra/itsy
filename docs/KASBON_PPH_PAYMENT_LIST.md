# Kasbon bersih dan List Payment — 16 November 2026

## 24 November 2026 — Revisi paling baru: ROUND hanya nilai PPh

Pengguna kemudian merevisi secara eksplisit: **“rumus round digunakan ke PPH, hanya khusus nilai PPH; nilai lainnya normal seperti sebelumnya.”** Bagian ini menggantikan aturan Jasa bersih/total akhir sebelumnya untuk kasbon baru dan penerbitan ulang yang sah.

```text
PPh Jasa        = ROUND(total Jasa Mandor × 2,5%, 0)
Jasa bersih     = total Jasa Mandor − PPh Jasa
Kasbon/transfer = Material + Jasa bersih
```

ROUND half-up satu kali terhadap PPh atas **total Jasa** actuals pembayaran saat ini × snapshot harga Mandor. Material tidak dikenai PPh; sumber Material/Jasa/bruto, harga per baris, kuantitas dan moneyPolicy existing tidak diubah. Jasa bersih dan total akhir tidak di-ROUND lagi oleh langkah pajak ini. Contoh Material62.900/Jasa42.500: PPh1.062,50→**1.063**, Jasa bersih41.437, kasbon**104.337**; angka104.338 dari service-net ROUND adalah aturan versi sebelumnya.

Policy default sekarang `mandor-service-pph25-tax-round-v4`. PHP BCMath menghitung Jasa×0.025 dengan presisi sumber lalu menambah0.5/div0 untuk PPh integer; TS BigInt memakai serviceCents/4000 dan half2000. Bruto dikurangi nominal PPh tersebut tanpa pembulatan baru. Quote tetap satu lintasan sumber tersimpan/0SQL. `withheld` v4 sama dengan PPh bulat; UI kembali label **Potongan PPh 2,5% · Jasa Mandor** dan menjelaskan ROUND khusus PPh. Berkas kasbon tetap net-only tanpa baris pajak. Private snapshot/rows/summary/amount/HTML/nativeWord/terbilang/proof/paymentDue/transfer/settlement memakai satu net canonical yang sama.

V1 truncation, v2 final-total-round dan v3 service-net-round tetap didukung per saved policy. Kasbon sudah terbit/pending/approved/paid tetap nominal tersimpan; tidak mengubah policy/UUID/ciphertext/approval/bukti/paid/APM saat membaca atau memproses Finance. Penerbitan ulang hanya lewat pengembalian/perbaikan sah existing dan keputusan baru sesuai flow. Tidak mengalihkan semua data lama ke rumus terbaru. Label v3 hanya tampil pada quote v3 yang memang tersimpan, bukan perkiraan baru. Proposal tax-round-v3 sebelumnya tidak diterapkan; tax-round-v4 sekarang merupakan revisi eksplisit terbaru.

Tidak ada schema/migration/API/dependency/poller/query baru; semua current actor/Branch/unit/Region/UUID/revision/source/Head/BAPP/private/proof/APMopsional/paid/volume/PID guards tetap. Pure helper juga menguji sumber historis Material0.75/Jasa20→PPh1/net19.75 untuk membuktikan langkah pajak tidak membulatkan nilai lain; itu bukan izin input pecahan SQL BIGINT atau perubahan kebijakan harga. Dua Jasa20 menghasilkan totalPPh1, bukan dua potongan bulat1. Actual fixture issue45/settle41HTTP tetap, bukan benchmark aaPanel.

Penerapan setelah source dipush/pull: `npm run build`, `npm run check:assets`, clear cache PHP sesuai prosedur, lalu restart **hanya worker upload Area3** (`supervisorctl restart area3-uploads`, gunakan nama program aktual). Ini diperlukan agar worker lama mengenali policyv4 saat memeriksa bukti transfer. Tanpa migration baru, tanpa reset data/ENV/APP_KEY/queue. Agen belum deploy atau menyentuh activeits/server/worker/Dompis. [QA](LARAVEL_QA.md), [audit v4](qa/pph-tax-round-v4-20261124.json). Bagian-bagian di bawah merupakan riwayat kebijakan sebelumnya.

## 24 November 2026 — Koreksi terakhir: ROUND hanya hasil Jasa setelah PPh

Pengguna menegaskan **“Hanya nilai setelah potongan PPH saja”**, dengan sumber potongan hanya Jasa Mandor. Aturan terkini menggantikan bagian ROUND total sebelumnya:

```text
PPh acuan       = total Jasa Mandor × 2,5%          (tidak dibulatkan dahulu)
Jasa bersih     = ROUND(total Jasa Mandor − PPh acuan, 0)
Kasbon/transfer = Material + Jasa bersih
```

Pembulatan half-up satu kali pada hasil bersih **total Jasa** dari actuals pembayaran saat ini × snapshot kesepakatan Mandor. Bukan pembulatan tiap baris, pajak terlebih dahulu, Material atau gabungan total akhir. Contoh Material62.900 dan Jasa42.500: PPh acuan1.062,50, Jasa bersih41.437,50→41.438, kasbon104.338. Membulatkan pajak menjadi1.063 dahulu akan memberi104.337 dan tidak mengikuti aturan ini. Untuk Material rupiah bulat, hasil bisa sama dengan kebijakanv2 final-total-round; tahap perhitungannya tetap dipisahkan secara eksplisit, tidak memaksa angka yang sama menjadi berbeda.

`KasbonSettlement::POLICY`/`KASBON_SETTLEMENT_POLICY` sekarang `mandor-service-pph25-service-round-v3`. PHP BCMath menghitung Jasa×0.975, menambah0.5 lalu mengambil integer Jasa bersih; TS BigInt mengerjakan pembilang97,5% yang sama. Baru kemudian Material canonical ditambahkan, tanpa pembulatan akhir. Aturan pembentukan Material/Jasa dari harga×actuals dan moneyPolicy dokumen tetap; tidak mengubah per-row input/harga/quantity/BARP/BOQ/bruto. Pure helper turut menguji Material historis0.75 tetap0.75, bukan bulat1/0; itu bukan izin memasukkan nominal pecahan baru ke kolom SQL BIGINT atau migrasi data historis.

`withheld` tetap selisih efektif `gross − net`, sehingga memperhitungkan penyesuaian pembulatan Jasa bersih. UI v3 menamainya **Selisih PPh & pembulatan Jasa**, disertai penjelasan bahwa yang dibulatkan adalah nilai Jasa setelah PPh. Ini bukan pembulatan nominal PPh terlebih dahulu. Dokumen kasbon HTML/Word/cetak tetap hanya net dan terbilangnya, tanpa menambahkan baris potongan pajak. Satu net yang dibekukan dipakai pada rows/summary/private_data/amount, Finance transfer, paymentDue, proof/settlement dan next-term; tidak ada selisih nominal antartampilan.

Policyv1 `mandor-service-pph25-v1` dan policyv2 `mandor-service-pph25-round-v2` tetap dikenali secara terpisah. Kasbon terbit/pending/approved/paid tidak diubah atau dihitung ulang saat GET, approval, upload bukti atau pelunasan. V1 tetap truncation semula, v2 tetap final-total round semula, no-policy gross tetap historis. Penerbitan ulang hanya lewat pengembalian/perbaikan sah existing, snapshot/UUID baru dan approval sesuai flow; tidak menandai approval fiktif atau memindahkan nominal paid. Proposal pembulatan pajak di tengah klarifikasi belum diterapkan ke database/deploy dan tidak menjadi policy tersimpan yang didukung.

Perubahan hanya kalkulator dan informasi UI;0SQL kalkulasi/onepass, tanpa endpoint/schema/migration/dependency/poller baru. Same Branch/unit/Region/selectedactor/currentkasbon/revision/UUID/private/source/BAPP/proof/APMopsional/ledger/paid guards tetap. Issue45HTTP dan settle41HTTP pada fixture existing; bukan benchmark server. Pengujian menutup12vectorbaru,16vectorv1/v2, total-Jasa vs per-row, half-tax trap, Material utuh, private issue/net/APM SQL, dan pembayaran quote lama melalui worker/bukti/Finance tanpa rewriteciphertext. [QA aktual](LARAVEL_QA.md), [audit koreksi](qa/pph-service-net-round-20261124.json).

**Penerapan:** setelah push/pull source, `npm run build`, `npm run check:assets`, clear cache PHP sesuai prosedur, lalu restart **hanya worker upload Area3** agar proses lama mengenali policyv3 (`supervisorctl restart area3-uploads`, sesuaikan nama program bila berbeda). Tidak ada migration baru. Agen tidak menjalankan migration/import/its aktif/ENV/APP_KEY/server/worker/Dompis atau deployment. Server/transfer bank/Word desktop/cetakfisik belum diverifikasi; generator Word dan layout tidak diubah.

## 24 November 2026 — ROUND pada hasil akhir setelah PPh

Pengguna mengubah khusus pembulatan hasil bersih kasbon/transfer: **ROUND(Material + Jasa Mandor × 97,5%, 0)**. Pembulatan satu kali di hasil akhir ke rupiah terdekat, pecahan tepat0,5 naik; bukan ROUND potongan pajak dahulu, bukan pembulatan tiap item. Material tidak dikenai2,5%, bruto BOQ/BARP/approved_amount tidak berubah. Contoh31450Material+21250Jasa menghasilkan52168,75→**52169**; contoh17Material+20Jasa menghasilkan36,5→37 (membulatkan pajak dahulu akan keliru menjadi36).

Kasbon baru/penerbitan ulang yang diizinkan memakai server-owned `mandor-service-pph25-round-v2`; PHP BCMath menambahkan0,5 pada nilai akhir sebelum konversi bulat, TS memakai pembilang BigInt+setengah pembagi untuk hasil identik tanpa drift perkalian floating-point. Kalkulasi tetap satu lintasan saved agreement×actuals dan0SQL. Rincian form, snapshot terenkripsi, summary.amount, preview/Word/terbilang, `paymentDue`, proof dan settlement memakai net baru yang sama. Ringkasan UI menjelaskan ROUND; berkas kasbon tetap net-only tanpa baris potongan.

`mandor-service-pph25-v1` tetap didukung untuk kasbon yang sudah terbit: due/matches/notice memakai policy dan nominal tersimpan, termasuk yang pending/approved/paid. Tidak ada GET repricing, SQL backfill, perubahan uang/riwayat/bukti/approval maupun selisih pelunasan otomatis. Kasbon tanpa policy tetap aturan historis. Policy tidak dikenal/quote salah ditolak saat validasi; data quote dari klien tidak menjadi sumber. Ingin menerapkan ROUND pada dokumen terbit harus melalui pengembalian/perbaikan/penerbitan ulang normal, dengan UUID/approval baru sesuai workflow, bukan edit nominal langsung.

Perubahan ini **tidak mengganti pemotongan pecahan untuk harga/input rupiah lain**. Scope/Branch/unit/Region, sumber BARP/BAPP, revisi/receipt, kasbon aktif,7approval, net/proof/APMopsional/paid, volume dan ledger tetap. Tidak ada schema/migration/API/dependency/poller/query tambahan. Uji pure PHP/TS mencakup10vector baru dan6v1 historis; HTTP membuktikan kasbon terenkripsi dan Finance TandaiLunas52169 tanpaAPM sambil gross52700 tetap, next term tetap dapat dibuat setelah settlement.

Deploy: build aset (`npm run build`, `npm run check:assets`), **restart hanya worker upload Area3** agar worker lama mengenali policyv2 pada bukti transfer, misalnya `supervisorctl restart area3-uploads` sesuai nama program server. Di lokal hentikan worker Area3 lama dan mulai ulang dengan perintah proyek yang sudah digunakan. Tidak ada migration khusus perubahan ROUND; migration Budgeting PID terpisah tetap mengikuti tahap1. Agen tidak mengubah worker/server/ENV/APP_KEY/its aktif. [Bukti dan batas aktual](qa/pph-round-20261124.json), [QA](LARAVEL_QA.md).

## 17 November 2026 — Keterangan PPh dan label pembayaran Final satu kali

Pengguna meminta keterangan selisih bruto dan transfer pada **Kasbon Admin & Review Pembayaran Finance** serta penghapusan label Termin untuk pembayaran pertama langsung Final. Ketentuan ini menggantikan larangan keterangan potongan **pada UI pengajuan/review saja**; isi formulir kasbon yang menjadi berkas preview/Word/cetak tetap nilai bersih tanpa baris potongan seperti sebelumnya.

Panel dan modal pengajuan menampilkan penjelasan PPh2,5% hanya untuk Jasa Mandor, Material utuh, serta nilai pengajuan bruto → potongan Jasa Mandor → **Nilai setelah potongan PPh**. Form baru memakai quote kanonis existing dan menandai nilai sebelum pengajuan sebagai perkiraan. Panel kasbon terbit memakai `t.kasbon.settlement` dan `t.kasbon.amount` yang sudah tersimpan; tidak menghitung ulang dokumen lama. Bila belum ada kasbon, angka summary disebut Tagihan bruto, bukan Nilai transfer. Kasbon historis tanpa policy menjelaskan nominal tersimpan tanpa menerapkan potongan baru. Finance tetap mentransfer nominal versi yang disetujui; status, approval, nominal, PII, rekening dan riwayat tidak diubah.

`payment-labels.ts` membagi aturan `number===1 && type==='Final'`: **Pembayaran Final/Final**, **Total Pembayaran**, tanpa kolom Nomor Termin dan tanpa tiga kolom historis Termin pada editor Volume Aktual. Kolom input aktual, harga, nilai, saldo dan ledger tetap dari model yang sama. Bila memilih Parsial atau Final2/3, nomor Termin dan kolom historis tetap muncul. Sebelum jenis dipilih, picker menyebut Pembayaran pertama; tahap2/3 tetap Termin. Card pembuatan Termin Berikutnya tidak ditampilkan pada Final1 karena tidak tersedia; panel Finance historis tetap tersedia.

Aturan diterapkan pada editor, list pembayaran, inbox/task/konfirmasi, pengajuan kasbon Admin, review Finance, list Finance, legacy routing, rincian dashboard, laporan layar, generic preview dan detail BA PP. Nama dokumen BARP pada tombol review tidak memuat akhiran Termin untuk Final1; key internal jenis dokumen/route tetap. BARP/BAPP/kasbon paper tetap memakai aturan single-Final yang sudah ada. Nama file Word kasbon Final1 sekarang `Kasbon_<PP>_Final.docx`; pembayaran bertahap tetap `_Termin-N`. Nomor tersimpan, jenis, cycle, actuals, filename/path unggahan privat dan teks riwayat audit/note asli tidak ditulis ulang.

Tidak ada endpoint, query SQL, migration, dependency atau mutasi workflow/data. Notice memakai quote/saved summary berizin yang sudah tersedia, helper label O(1), daftar tetap20baris dan akses Branch/private unchanged. Panel Finance menghitung gross existing satu kali untuk summary/notice/konfirmasi, menggantikan pemanggilan berulang. Boot/read aggregate existing tetap, bukan klaim benchmark throughput.

Verifikasi: **311PHP/7241assertions** pada SQLite`:memory:`, **345frontend**, TypeScript/Vite dan assets41refs/32preloads×3profil lulus. Empat tes baru mencakup editor/list/BA PP Final1 dan Parsial1/Final2/3, kolom8/11, panel Finance historis tidak hilang, bruto/potongan/net/historical unchanged, serta nama file. Regresi HTML/native Word kasbon net-only dan BARP/BAPP single-Final existing tetap lulus. Tidak merender ulang Word/PDF atau mencetak fisik karena tata letak berkas tidak berubah.

Browser sintetis Chrome memeriksa Final→Parsial→Final, form Admin, panel Finance dengan frozen300.000/5.000/295.000, kasbon historis tanpa repricing, dokumen preview net-only tanpa PPh/Termin1, dan viewport375/768/1440 light/dark tanpa page overflow. Seluruh request diintersep fixture lokal dan hanyaGET,0JSerror; screenshot diperiksa. Peluncuran pertama terkena sandbox lalu retry berizin; locator teks total diperbaiki karena total dan nominal ada dalam satu elemen. Tidak memakai akun/server/transfer bank nyata, tidak deploy/migrate/restart worker dan tidak mengubah its/.env/archive/Dompis. [Bukti](qa/payment-presentation-20261117.json).


## 16 November 2026 — Tandai Lunas dengan APM opsional

Finance dapat menekan Tandai Lunas setelah bukti transfer aktif selesai diunggah dan approval/review Finance lengkap, tanpa nomor APM. Upload sendiri tidak membayar otomatis. APM dapat diisi saat pelunasan atau ditambah/diedit sesudah lunas; format/keunikan dan konfirmasi YA/TIDAK tetap. Koreksi nomor menyimpan audit before/after/actor/waktu dan tidak mengubah nilai/tanggal/penerima/bukti/keputusan maupun menambah pembayaran kedua. Berlaku pada alur kasbon-v1/kasbon-settlement-v2; pra-kasbon historis tetap.

Nomor APM dipisahkan dari pelunasan; next-term/CLOSE/inbox/List Payment memakai settlement nyata. Migration nullable APM disiapkan tanpa rewrite data lama dan belum dijalankan pada its aktif. [Aturan, penyimpanan, query dan hasil verifikasi](PAYMENT_SETTLEMENT_OPTIONAL_APM.md).

## Nominal pengajuan dan transfer

Pengguna menetapkan potongan **2,5% khusus Jasa Mandor**, Material tidak dipotong, dan mengonfirmasi kasbon serta transfer/APM menggunakan nilai bersih sementara tagihan bruto tetap tercatat. Angka ini adalah parameter yang diminta pengguna, bukan hasil penetapan tarif pajak oleh aplikasi.

Sumbernya hanya volume aktual pembayaran saat ini dan harga kesepakatan SPK Mandor yang tersimpan. Bukan harga BOQ Owner, KHS saat ini, seluruh nilai SPK atau termin sebelumnya. Material/Jasa yang dikecualikan tetap tidak ikut perhitungan. Pembagian komponen mengikuti kebijakan uang dokumen/rounding baris kanonis, termasuk sisa cent Jasa historis.

**Kasbon/transfer = Material + (Jasa Mandor × 97,5%)**, pecahan Rupiah pada hasil akhir dipotong, tidak dibulatkan naik. Contoh: Material100.000 + Jasa200.000 = bruto300.000, kasbon/transfer295.000. Jika hanya Material100.000, kasbon tetap100.000. Net harus positif; rincian1–20baris wajib tepat sama dengan net. Nilai, total, terbilang, preview, Word dan cetak kasbon memakai net, tanpa baris/label PPh atau persentase potongan. BARP/BOQ/kontrak dan approved_amount tetap bruto.

PHP `KasbonSettlement` dan TS `kasbon-settlement` menghitung quote yang sama. Saat penerbitan baru/ulang, server membekukan `settlement {policy,gross,material,service,withheld,net}` dalam snapshot terenkripsi finance_kasbons.private_data dan ringkasan kasbon pembayaran. finance_kasbons.amount adalah net. Nilai incoming quote/total tidak dipercaya; server mengikat quote ke agreement/actuals, versi payee/kasbon, revision/UUID dan seluruh keputusan yang sudah ada. Snapshot ringkasan berisi angka, bukan PII bank.

APM tetap memerlukan approval berurutan lengkap, keputusan Finance, bukti transfer nyata untuk kasbon yang sama, scan terverifikasi bila tersedia, dan nomor unik. payment_terms.document_data.paid, paymentHistory dan apm_settlements.amount kini menyimpan transfer net untuk policy baru. `paymentDue` menjaga next-term, status Lunas/timeline/CLOSE dashboard, outstanding layar/CSV dan batas manual payment memakai net, sehingga potongan tidak menjadi tagihan tersisa. Realisasi dashboard memakai uang net yang benar-benar tercatat, sementara tagihan bruto tetap terpisah. Tiga termin/Final/volume/release/Branch dan bukti privat tetap.

Kasbon lama tanpa policy baru memakai nominal bruto/alur tersimpannya. Tidak ada GET backfill, perubahan paid/APM atau perhitungan ulang otomatis atas kasbon terbit/keputusan lama. Penerbitan ulang yang diizinkan workflow membuat versi baru dan menyimpan versi/keputusan/bukti sebelumnya; dokumen baru tujuh approval tetap mengulang dari PM bila dikembalikan. Form lama yang jumlah rinciannya berbeda dari quote baru meminta rincian net baru; metadata referensi tetap dapat dipakai. Preflight konversi uang juga memeriksa quote versioned agar tidak salah menganggap selisih pajak sebagai ketidakcocokan bruto, dan tetap menolak bila konversi mengubah quote aktif.

## Halaman List Payment

Buka **Pembayaran → List Payment** (`/termin/list-payment`). Hanya akun **Mgr Finance & Settlement** dan **Finance** dapat membaca endpoint; role lain termasuk Superadmin mendapat403. Tetap sembilan menu utama. Daftar adalah per pengajuan pembayaran/LOP, sehingga LOP/ID sama boleh muncul untuk PP/termin berbeda tanpa digabung dan tanpa menggandakan satu versi kasbon.

Daftar memuat pengajuan dengan kasbon aktif, request Payment historis, atau settlement APM SQL. Draf tanpa pengajuan kasbon/request tidak dihitung. Ringkasan: jumlah pengajuan, ID Project unik, jumlah/nilai belum terbayar dan jumlah/nilai sudah terbayar. Pencarian literal LOP/ID/PP/SPK/Mandor/APM dan filter Branch memengaruhi ringkasan sebelum tab status; filter sudah/belum menentukan baris yang ditampilkan. Nilai pengajuan mengikuti kasbon aktif (net baru, bruto historis), request lama atau approved_amount. Sudah terbayar memerlukan APM SQL positif, status APM Valid dan amount yang melunasi pengajuan; tidak menebak transfer dari approval. Status berkas NOK/OK hanya keberadaan scan; keterangan verifikasi terpisah. Kolom juga memuat tahap approval, nomor/tanggal APM dan tombol Detail ke dokumen asli untuk review/proses existing.

Semua join workspace dan Branch PP dilakukan sebelum search/summary/pagination. Tidak memakai projects.branch_id/name sebagai sumber akses/nama LOP, sehingga sibling dengan ID Project sama tetap aman. Maksimal20baris+1sentinel, urutan submitted_at+UUID stabil, page tervalidasi. Hanya proyeksi metadata, tanpa private_data/katalog/master/actuals/archive/PII/file paths. Hash idempotensi/approval/mutasi tidak dibuka lewat daftar.

Pencarian debounce300ms, abort request lama, loading/empty/error/retry jelas, kartu dapat memfilter, tabel horizontal terkurung, viewport375/768/1440 dan dark mode. Probe revisi setiap15detik saat terlihat/aman; daftar dibaca ulang hanya setelah revisi bertambah, tanpa refresh aggregate global, tanpa duplicate initial fetch atau mutation retry. Dialog menghentikan refresh otomatis. Perbarui manual tersedia.

## Query, data dan deploy

Kalkulasi quote0SQL/1pass pada saved agreement. List4servicequery: workspace activation/revision, readiness migration kasbon, SQL summary dan page. Tidak ada per-row SQL, dekripsi private snapshot atau full aggregate pada jalur daftar. Indeks PK/FK/Branch/workspace existing dipakai; summary masih agregasi pengajuan dalam cakupan akun dan offset paging, bukan janji waktu konstan atau uji beban. Fixture100pending memastikan4query dan20+1; HTTP issue/APM masing-masing44query; tujuh keputusan54/56/57/58/59/60/66query fixture. Query accounts/middleware terpisah dari4servicequery. Boot/editor/command aggregate lama masih batas aplikasi.

Tidak ada migration baru untuk perubahan ini: memakai kolom amount/snapshot JSON existing. Migration kasbon dan role/order sebelumnya tetap prasyarat deployment. Tidak menjalankan migrasi, seeding, .env, live its atau server; APP_KEY/storage privat harus dipertahankan seperti panduan kasbon sebelumnya. BackupdemoSQLite233472byte/0600 dibuat sebelum edit. Transfer bank dan penyetoran pajak dilakukan di luar aplikasi, tidak dibuat otomatis.

Verifikasi:300PHP/6581assertions,304frontend,TypeScript/Vite,assets36references/28preloads×3profil dan scopedPint lulus. Enam vector PHP/TS mencakup material saja/jasa saja/campuran/net pecahan/nol, quote forged, historical due, SQL net APM dan bruto tetap, no double pay dan next-term, penolakan tujuh tahap/reissue dan akses/query. Dua nativeWord generator aplikasi dirender1+1halaman, ZIP/CRC/XML dan seluruhPNG diperiksa; amount/terbilang net tanpa rincian potongan. Browser Chrome pada API QA terisolasi menguji filter/status/search/empty/error/retry, viewport375/768/1440 tanpa overflow halaman dan modegelap. Endpoint Laravel diuji terpisah di SQLitememory, bukan browser akun server. MicrosoftWord desktop/cetakfisik/MySQLload/aaPanel belum diverifikasi. [Bukti rinci](qa/kasbon-pph-payment-list-20261116.json).


## 16 November 2026 — Paket dokumen dan ringkasan alur terbaru

Lihat [DOCUMENT_PACKAGE](DOCUMENT_PACKAGE.md): BA PP Sah menyediakan satu Word/PDF PP–Lampiran BA PP termasuk kasbon aktif setiap pembayaran. Timeline/detail menampilkan alur aktual5/7approval, scan terpisah dan APM opsional; riwayat/otorisasi/pembayaran tetap. List Payment memakai banner putih solid tanpa gradient. Shared Lampiran SPK Word memperlebar TotalVol6%→8% (Uraian23%→21%) agar digit total tidak turun sendiri. Current-only kasbon tetap lazy/UUID-bound/3query tanpa file catalog atau dekripsi arsip; tidak ada migration/data live rewrite. Bukti dan batas pengujian dicatat pada [QA](qa/document-package-20261116.json).
