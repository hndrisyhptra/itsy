# Arsitektur aplikasi it's Area 3

## 16 November 2026 — Paket dokumen lengkap, timeline terbaru dan List Payment putih

Pengguna meminta satu Word/PDF dari PP sampai Lampiran BA PP, dan mengonfirmasi kasbon setiap pembayaran ikut. Ikon pada Aksi BA PP hanya muncul setelah Sah; tersedia pula di detail/timeline. Preview menggabungkan PP, BA KH/lampiran, SPK/lampiran, BARP/lampiran/kasbon aktif tiap pembayaran lalu BA PP/lampiran, lewat exact UUID lineage. Native Word memakai generator asli dengan section portrait/landscape, style/numbering/media terisolasi dan header kosong untuk mencegah logo diwariskan. Print menyimpan satu PDF nonblank; cap/status sel TTD tidak ikut hasil unduhan/cetak. Unggahan privat tetap terpisah. Kasbon hilang/akses gagal/versi berubah menahan ekspor; histori tanpa kasbon tidak dibuatkan snapshot baru.

Timeline/detail PP/pembayaran/BA PP/inbox memperlihatkan paket5approval, kasbon sebelum7approval, transfer/lunas, status scan terpisah dan APM opsional. Keputusan current-user/current-kasbon saja mendapat tanda selesai; riwayat lama tetap. List Payment mengganti banner gradient dengan putih/solid dan memperbaiki keterangan APM opsional. Lampiran SPK Word memperlebar TotalVol agar digit total tidak terpisah.

Query/performa: indeks relasi/total BA PP sekali, list20baris, grouping timeline sekali dan aktivitas20baris per halaman. Kasbon current_only pada GET existing memilih satu UUID aktif/3kolom tanpa file catalog/arsip decryption;3queryservice/6HTTP pada fixture, maksimum3request per paket. Tanpa mutasi SQL/schema/migration/dependency/its aktif/.env/Dompis; data, uang, approval, ledger, encrypted snapshots dan arsip tetap. Rincian/pengamanan/deploy: [DOCUMENT_PACKAGE](DOCUMENT_PACKAGE.md).

Verifikasi:311PHP/7193assertions memory,336frontend,TypeScript/Vite,Pint dan assets41refs/32preloads×3profil lulus. Native Word2paket/30halaman, browserPDF2paket/32halaman, ZIP/CRC/XML/rel/style/section/blankTTD/nonblank serta klik YA/TIDAK Word/print lulus pada fixture. Server/akunlive/Worddesktop/cetakfisik/loadtest belum diverifikasi; tidak deploy/migrate/restart. [Bukti](qa/document-package-20261116.json).

## 16 November 2026 — Native Word BA PP dan shared reconciliation appendix

`bapp-content` kini memiliki rich-text opening/result/closing yang dirender HTML dan Word. `bapp-docx` mempatch marker sekali pada retained `bapp-word-template` package (18entries), preserve15entries dan geometry/styles/theme/settings/notes/relationships; hanya body +2descriptive metadata berubah. XML escaping/marker non-recursion, original reference unchanged, blank wetTTD. `bapp-file` lightweight names/MIME; UI lazyimports kedua generator melalui busy/confirmation/status guard tanpa network/private-file query.

`bappAppendixData` adalah adapter model BARP: table data/amounts/widths/recap identik, title BAPP dan signer[Head,Waspang,Mandor]. HTML `ReconciliationAppendix` dan native `createReconciliationAppendixDocx` dibagi; dynamic signer widths3/5 menghasilkan jumlah slot yang tepat, BARP tidak berubah menjadi3. Branch/read/approval/print/settlement/storage guards tetap, tidak ada endpoint/schema/migration/write baru.

0additionalSQL; existing indexed linear model/max3tahap×2komponen, adapter O(1), ZIP memory bounded64MB, lazy package55.70kB/8.21gzip dan tanpa server temporary paths.310PHP/7137assertions,329frontend/build/assets37refs/29preloads×3profil lulus.6DOCX/8halaman dan6PDFbrowser/8halaman diperiksa, functionalReact downloadsNO0/YES1 each; fixture bukan server/loadbenchmark. [Perilaku/sumber/batas](BAPP_WORD_APPENDIX.md), [QA](qa/bapp-word-appendix-20261116.json).

## 16 November 2026 — Model surat BA PP khusus

`bappDocumentData` menggunakan canonical BARP amount/historical identity, calendar-only date formatter, singleFinal helper dan dealTotal dari props authorized. Tidak melakukan lookup akun/KHS/BOQ atau state mutation. Nilai/paragraf template untuk tahap kini tetap bruto; kumulatif tidak dihapus dari detail/lampiran. Kompleksitas linear terhadap item kesepakatan, DOM surat konstan terhadap jumlah item.0query/endpoint/schema/dependency tambahan; tidak mengubah snapshot atau archive.

`BappDocument` merender surat portrait tanpa internalnumber, dua pasangan TTD ditambah Head di bawah, label tahap bersyarat dan blank wet-signature slots. `DocumentPreview` menempatkan cabang khusus sebelum tabel generik dan memakai actual-assignee canPrintBarp + konfirmasi existing. Scoped CSS25.4mm/11pt/14pt, compact gaps untuk LOPpanjang, background putih root/modal dan printpath existing. Tidak mengubah BappDetail/attestation/release/continuation atau menambah Word export.

310PHP/7137assertions,325frontend/build/assets lulus;1000item tanpa per-row references/current account;3PDFheadless1halaman per kasus diperiksa. Bukan browser akun/aaPanel/loadtest/cetak fisik. [Perilaku/sumber](BAPP_TEMPLATE.md), [bukti](qa/bapp-template-20261116.json).

## 16 November 2026 — Proyeksi tabel BARP bergaya Lampiran SPK

`barp-appendix-content.ts` memproyeksikan `termVolumeData` menjadi harga satuan M/J, volume per LOP sampai termin sekarang, total volume per baris dan nilai M/J/Jumlah kumulatif. Single Final1 memakai nama LOP saja. Stage1/2/3 menghasilkan11/12/13kolom; tidak merender termin masa depan. Map agreement, prior accepted dan grup komponen menghindari lookup katalog per baris. Perhitungan maksimum3tahap×2komponen mempertahankan money policy masing-masing sumber. Baris dengan komitmen atau vektor aktual M/J berbeda dipisah. Footer jumlah dan tagihan saat ini dibedakan; tidak menjumlah volume lintas satuan.

`barp-appendix.tsx` dan lazy `barp-appendix-docx.ts` menggunakan header/lebar/body/footer yang sama. Native Word A4 landscape7pt dengan margin sel ringkas, dua header berulang, baris terakhir bersama rekap dan lima TTD existing. CSS khusus lampiran tidak mengubah surat utama/SPK/signers. Tidak ada perubahan backend/state/SQL/API/migration, tidak ada GET write atau penggantian snapshot. Tambahan query0; kompleksitas linear terhadap data authorized yang sudah dimuat, dengan maksimal3tahap. Fixture1000baris bukan load benchmark.

310PHP/7137assertions,320frontend/build/assets lulus; native Word4dokumen/6halaman dan PDF browser4dokumen/6halaman nonblank diperiksa. Header yang sangat tinggi pada Chrome dapat tidak berulang; Word tetap berulang. Lihat [perilaku dan batas](BARP_APPENDIX.md) serta [bukti](qa/barp-spk-grid-20261116.json). Tidak menerapkan perubahan pada server atau database aktif.

## 16 November 2026 — Tandai Lunas dengan APM opsional

Finance dapat menekan Tandai Lunas setelah bukti transfer aktif selesai diunggah dan approval/review Finance lengkap, tanpa nomor APM. Upload sendiri tidak membayar otomatis. APM dapat diisi saat pelunasan atau ditambah/diedit sesudah lunas; format/keunikan dan konfirmasi YA/TIDAK tetap. Koreksi nomor menyimpan audit before/after/actor/waktu dan tidak mengubah nilai/tanggal/penerima/bukti/keputusan maupun menambah pembayaran kedua. Berlaku pada alur kasbon-v1/kasbon-settlement-v2; pra-kasbon historis tetap.

PHP/React menambah settleFinancePayment/updatePaymentApm dan settlementRecorded; tanda pelunasan terikat kasbon/proof disimpan server, APM kosong tetap Belum Terbit. Next-term/picker, inbox/timeline, matrix CLOSE dan List Payment mengikuti lunas aktual. SQL apm_settlements tetap1baris/pengajuan; hanya apm_no diperbarui saat koreksi, paymentHistory asli tetap. Migration2026_11_16_000001 membuat apm_no nullable dengan unique/collation/FK/authority tetap dan CHECK NULL khusus kasbon+proof, trigger SQLite dipulihkan, rollback ditolak bila masih ada NULL. Tidak ada backfill/pembayaran/adopsi massal; migration belum diterapkan agen pada its aktif/server. Backupdemo233472byte/0600 sebelum edit; env/APP_KEY/PII/Dompis tidak disentuh.

Query:resolver0SQL, readiness1query per command baru, pending upload probe tetap; fixture pelunasan/koreksi39query masing-masing≤80, List Payment4query/20+1 tanpa per-row query/katalog/dekripsi. Uji UI HTML memeriksa tombol sebelum/sesudah proof, tanpa APM, editor setelah lunas dan role; bukan pengujian browser akun/aaPanel/MySQL DDL/transfer bank nyata. 303PHP/6749assertions,310frontend,TypeScript/Vite,scopedPint dan assets36refs/28preloads×3profil lulus. [Perilaku dan penerapan](PAYMENT_SETTLEMENT_OPTIONAL_APM.md), [bukti suite/query/batas](qa/optional-payment-apm-20261116.json).

## 16 November 2026 — Bukti transfer ditolak worker lokal lama

Dua task bukti transfer lokal ditolak dengan pesan kasbon belum disetujui Finance. Diagnosis baca-saja pada its menunjukkan flow kasbon-settlement-v2/version3, pengajuan Disetujui, Finance Diverifikasi dengan keputusan Setujui dan kasbonId cocok, belum APM/lunas; context task juga cocok. Worker PID23479 masih berjalan sejak15November16:41, sebelum handler/domain diperbarui16November04:48–04:51. Kode worker yang sudah dimuat berbeda dari kode web; tidak perlu mengubah approval atau status SQL.

Cache file/prefix/lokasi proyek diverifikasi sebelum queue:restart, lalu proses lama dikonfirmasi berhenti. Autostart lokal tetaptrue, worker pengganti mengikuti identitas OS web pada unggahan berikutnya. Pengguna mengonfirmasi unggahan baru berhasil. Task gagal tetap sebagai riwayat; tidak mengedit business rows, membayar, mengulang persetujuan, menghapus jobs, mengubah .env/izin privat atau menyentuh worker aplikasi lain. Setelah pembaruan handler/domain upload, restart worker proyek merupakan bagian penerapan kode; lihat UPLOAD_QUEUE_PERFORMANCE.

Regresi existing BundleSettlementTest kini benar-benar melepas guard/sesi browser sebelum memproses bukti transfer Parsial dan Final setelah tujuh keputusan, memeriksa pemulihan guard kosong, uploaded_by Finance, file privat/preview dan APM. Worker43query per fixture (batas80), tanpa query tambahan pada aplikasi atau lookup per approver. Tidak ada perubahan kode produksi/schema/migration/dependency; hanya pengujian dan dokumentasi ditambah. Pengujian memakai SQLite memory/fake storage, bukan transfer bank nyata atau load benchmark/server aaPanel. 300PHP/6593assertions,305frontend,TypeScript/Vite,Pint scoped dan assets36manifest/28preload×3profil lulus. [Bukti dan hasil lengkap](qa/transfer-worker-restart-20261116.json).

## 16 November 2026 — Label termin pada kasbon

Pembayaran pertama langsung Final (termNumber1/termTypeFinal) tidak menampilkan label Termin1. Pembayaran bertahap menampilkan hanya TerminN, termasuk termin terakhir, tanpa (Final)/(Parsial). Berlaku pada preview, HTML yang dicetak dan native Word melalui kasbonTermSuffix yang sama, untuk seluruh versi template. Nomor SPK dan metadata tersimpan tetap; nama file tetap memakai nomor termin untuk identifikasi. Tidak mengubah BARP, editor/daftar pembayaran, tujuh approval, cap, nominal net, ledger atau APM.

Presentasi murni dari props authorized yang telah dimuat: helper O(1), tanpa query/lookup SQL/API/dependency/schema/migration baru dan tanpa perubahan snapshot/riwayat. Tidak ada live its/env/Dompis/worker/deploy; tidak memerlukan backup baru karena tanpa write database.

Verifikasi:300PHP/6581assertions,305frontend,TypeScript/Vite dan assets36manifest/28preload×3profil lulus. Regresi mencakup5kombinasi nomor/jenis pada3versi template untuk HTML/Word serta snapshot unchanged. Tiga DOCX sintetis (langsung Final, Parsial1, Final2) lulus ZIP/CRC/XML dan render1halaman per dokumen; ketiga PNG diperiksa, PDF render berisi dan label sesuai. Browser print tidak dijalankan ulang untuk perubahan teks ini; print memakai komponen HTML yang sama. Browser akun/server, Word desktop dan cetak fisik belum diverifikasi. [Bukti](qa/kasbon-term-label-20261116.json).

## 16 November 2026 — Hapus penandatangan Admin Area dan Finance pada kasbon

Permintaan pengguna menghapus slot **Memeriksa · Admin Area** dan **Menyetujui · Finance** dari kasbon template3. Preview, cetak/PDF dan native Word menggunakan model signer yang sama: PM/pengaju; Project Support/Deployment/Head; Mgr Finance & Settlement/EVP. Tujuh slot fisik ditata2/3/2 dengan enam kolom dasar, colspan3/2/3; tidak menyisakan slot kedua peran yang dihapus. Alur aplikasi tetap tujuh approval termasuk Admin Area dan Finance, dengan keputusan, binding kasbonId, inbox/timeline dan Finance/APM yang sama. Maker Finance pada dokumen lama bukan slot Menyetujui Finance dan tetap dipertahankan; susunan template lama tetap.

Perubahan hanya pada kasbon-content, kasbon-document dan kasbon-docx. Nominal net/PPh, tanggal, penerima/rekening, snapshot SQL/riwayat dan kontrol akses tidak dimutasi. Tidak ada query, endpoint, lookup katalog, dependency atau migration baru; render memakai props authorized yang sudah dimuat dan tujuh sel tetap. Tanpa live its/env/Dompis/worker/commit/push/deploy; tidak perlu backup database baru untuk perubahan presentasi tanpa writes.

Verifikasi:300PHP/6581assertions,304frontend,TypeScript/Vite dan assets36manifest/28preload×3profil lulus. Existing semantic tests memastikan peran/nama yang dihapus tidak ada di HTML/Word, history aplikasi tetap7, cap nyata yang masih ditampilkan5 (atau4 bila PM tidak cocok), arsip beda kasbon tidak bercap, dan snapshot sumber tidak berubah. Native Word normal/20baris dirender1+2halaman; seluruh3PNG diperiksa serta ZIP/CRC/XML valid. Browser QA sintetis normal/nested dan panjang/nested menghasilkan PDF1/1/3/3halaman, semua berisi;5cap preview/0print, signer tersisa dan colspan benar. Preview layar juga diperiksa. Browser akun server/aaPanel/Word desktop/cetak fisik belum diverifikasi. [Bukti](qa/kasbon-signatures-20261116.json).

## 16 November 2026 — Kasbon net Jasa Mandor dan List Payment Finance

- Potongan2,5% hanya Jasa Mandor aktual; Material utuh. Pengguna mengonfirmasi transfer/APM memakai net dan bruto tetap. Quote server-owned/versioned disimpan bersama kasbon; rincian/total/terbilang/Word/cetak hanya net tanpa baris PPh. Kasbon terbit/paid lama tidak dihitung ulang.
- APM/payed history menyimpan net; status Lunas/next-term/CLOSE/timeline/outstanding/CSV memakai payable versioned, bruto/BOQ/BARP/ledger volume tetap. Full approval/Finance/bukti/scan/Branch/UUID/revision tetap wajib.
- Pembayaran → List Payment khusus Mgr Finance & Settlement/Finance: ringkasan pengajuan/ID/sudah-belum/nilai, search LOP/dokumen/APM, filter Branch/status, tahap approval/berkas, detail,20+1baris dan refresh revisi aman. Tidak menambah menu utama atau membuka bank/PII.
- Query quote0SQL; daftar4servicequery untuk fixture100pending, tanpa full aggregate/dekripsi/per-row query; issue/APM44query fixture.300PHP/6581assertions,304frontend/build/Pint/assets36refs/28preloads×3profil lulus.2Word/2PNG diperiksa; browser QA filter/retry/375/768/1440/dark lulus. Bukan load benchmark atau browser akun live.
- Backupdemo233472byte/0600; tanpa migration baru/live its/env/Dompis/commit/push/deploy. [Perilaku/arsitektur/batas](KASBON_PPH_PAYMENT_LIST.md), [bukti](qa/kasbon-pph-payment-list-20261116.json).

## 16 November 2026 — Label BARP langsung Final dan kota server-owned

`barp-labels.ts` menjadi helper ringan untuk kondisi nomor1+Final dan kota Surabaya, tanpa memuat template Word. Main HTML/nativeOOXML memakai barp-content; filename/toolbar memakai aturan sama. Lampiran memproyeksikan ledger existing menjadi11kolom untuk Final satu kali (actual pertama saja, lebar gabungan15%), atau tetap13kolom untuk cicilan. Headers/widths/recaps/note dibagi oleh HTML dan Word, sehingga colspan dan grid konsisten; nilai/ledger tidak dimutasi.
BarpTemplate PHP dan barp-form TS menetapkan kota pada defaults/explicit save dan mengabaikan incoming place; form/cache tidak dapat menimpa kota. Read/render historis memakai Surabaya dengan tanggal tersimpan tanpa transaksi atau perubahan barpHistory; tidak mengambil tanggal/identitas akun baru. Jalur metadata absent historis tetap tidak diadopsi otomatis.
Query review:0SQL/endpoint/schema/package baru, lookup identitas dan indeks source/agreement existing tetap; HTTP metadata fixture59query≤60, pure canonicalization0query. Ini bukan benchmark MySQL/latensi.296PHP/6459assertions,299frontend/build/Pint/assets lulus; empatWord ZIP/XML dan enamPNG diperiksa. [Aturan](BARP_TEMPLATE_WORD.md), [lampiran](BARP_APPENDIX.md), [bukti/batas](qa/barp-final-surabaya-20261116.json). Browser akun/Word desktop/native print/server tidak diuji ulang, tidak live migration/data mutation.

## 15 November 2026 — Paket dokumen PP dan tujuh approval kasbon

Keputusan dan batas: [BUNDLE_SETTLEMENT_APPROVAL](BUNDLE_SETTLEMENT_APPROVAL.md). ProjectContractBundle dan SettlementApprovalWorkflow memperluas domain Laravel; marker/hashes/keputusan tetap milik server. PP memakai lima keputusan yang mengesahkan BA KH/SPK sekaligus dalam transaksi existing, bukan tiga approval terpisah atau signature fiktif. JSON per-dokumen memuat manifest hash/approval binding, SQL runs/steps/decisions memakai order3 khusus pembayaran baru. Private kasbon template3 tetap terenkripsi dan mengikat versi penerima/kasbonId. Marker lama/running/paid tidak dimigrasikan ulang.
Frontend memberi draf PP→BA→SPK, paket review, kasbon sebelum PM, tujuh stages dan Finance7 transfer/APM. Word memakai kasbon-content + word-ooxml/ooxml-zip lazy, fixed public logo only, actual Finance name bila ada keputusan cocok; signature marks tidak ditulis ke OOXML. Print ancestor path/portrait khusus kasbon, Grand Total hanya sekali dan rekening dijaga bersama. Tidak menambah converter/package runtime/akses global.
Review query: approval actor IDs/names batch200 per dokumen, decision map sekali per cycle; enam assignee kasbon satu join SQL. Picker menambah kolom marker minimal, root Draft-package service3query tetap20+1; domain/manifest/signer memakai state loaded. Keputusan tujuh tahap fixture HTTP54/56/57/58/59/60/64query Parsial, Final7=66, budget80; bukan benchmark MySQL. Penulisan riwayat tetap skala siklus, aggregate boot/command lama tetap batas aplikasi. Migration hanya role/CHECK, rollback menolak data terpakai. Perubahan frontend/Word tidak menambah query katalog.
Native ZIP/XML/CRC dan seluruh3halaman Word diverifikasi; Chrome fixture preview/print biasa+nested menunjukkan7/0cap dan PDF tidak blank. Server/live migration, browser login penuh/Word desktop/cetak fisik belum diverifikasi. CLI koreksi Head historis menolak bundle baru; aliases baru tetap mengikuti penahanan descendant pada PP yang dikoreksi. Audit sebelumnya tetap utuh.

## 15 November 2026 — Diagnosis worker lama dan retry identitas Mandor

Read-only pemeriksaanits lokal menemukan dua private taskfailed, dengan akunSuperadmin aktif/SPK/Branch valid. Worker PID26843 ternyata masih memuat handler sejak7November (fitur privat11November). Sinyal restart melalui cache file khusus proyek menghentikan proses lama; user mengonfirmasi KTP berhasil setelah reload/upload baru. Tidak merewrite task lama atau transaksi, tidak menjalankan ulang taskfailed otomatis. Autostart web tetap menjaga identitasdaemon dan private permissions; worker aplikasi lain tidak dikonfigurasi ulang.

UploadTaskFailedError memisahkan terminal server failure dari uncertain transport. Request pool privat per File/kind/document hanya dilepas pada terminalfailed atau keberhasilan; percobaan manual berikutnya mendapat UUID baru setelah failure pasti, tetapi mempertahankan UUID untuk ketidakpastian jaringan/polling. Tanpa POST otomatis/polling tambahan/queue mutation. Error tetap Error subclass untuk caller lama. FinanceDocuments memakai pesan/label KTP/rekening khusus, trim tepi sebelum format validation dan pesan role/Branch aman. Nol awal/16digit/1–40digit, enkripsi/versi/ownership/receipt/audit/FinanceAPM tetap.

Query/performa review:0SQL/API/lookup tambahan, scalar validation atas6field; retry poolWeakMap tetap perFile/context. Worker sessionless KTP/bank masing-masing39query fixture1SPK≤75; payee read5query fixture.283PHP/5991assertions,291frontend/build/Pint/assets lulus. Bukan bukti throughput server. BackupdemoSQLite233472byte dibuat read-only sebelum edit; its hanya dibaca untuk diagnosis, tanpa live synthetic data/schema/migration/env/worker lain/deployserver. [Perilaku](FINANCE_KASBON.md), [bukti](qa/mandor-upload-fix-20261115.json). Manual pengguna hanya KTP lokal; bank/permissions/retry otomatis, browser responsif/serveraaPanel/Finance end-to-end tidak diuji ulang.

## 15 November 2026 — Tempat dan tanggal penerbitan SPK

SpkIssuance (PHP) dan spk-issuance.ts (TS) menetapkan Surabaya serta membatasi tanggal penerbitan pada awal pekerjaan SPK sendiri. Tanggal lebih awal tetap, kosong/invalid/terlambat memakai awal yang valid; tidak ada fallback hari ini pada pembacaan. Blank/cached form dan domain save memakai helper; SpkIssueFields menyediakan tempat readonly serta batas date input. Laravel mengulang normalisasi sebelum validasi pada new/draft save/generate/attest sehingga payload client tidak dapat melewati aturan.

SpkDocument dan native Word memakai spkDocumentData yang sama. Output Terbit/Sah juga mengikuti kota/tanggal presentasi baru sesuai permintaan eksplisit, tetapi stored snapshot tidak dimutasi saat GET/download/attestation. Terbit attestation tetap memakai seluruh old record; Sah terkunci. Tidak mengubah periode/PP/BA KH, nomor, signer/NIK, nominal/ledger, payee privat atau approval. Existing work_orders.document_data/proyeksi tanggal menyimpan new/draft; tidak perlu schema/migration/backfill/archive rewrite.

Query review: scalar O(1), nol SQL/API/lookup tambahan; sepuluh vektor helper lintas bahasa memiliki query log kosong. Lookup dokumen dan jalur atomik revision/receipt/audit tetap; tidak mengklaim latency/throughput server. Backup demo SQLite privat sebelum edit, its aktif/env/worker/Dompis tidak disentuh.281 PHP/5959 assertions,290 frontend/build/Pint/assets lulus. Dua native Word CRC/XML valid, masing-masing satu halaman dan kedua PNG diperiksa; model HTML/Word, source immutability dan kalender terverifikasi. [Perilaku/batas](SPK_TEMPLATE_WORD.md), [bukti](qa/spk-issuance-20261115.json). Browser akun/live MySQL/server/desktop Word/native print/cetak fisik belum diuji untuk perubahan ini.

## 15 November 2026 — Kota tetap untuk BA Negosiasi

bakh-place.ts menyediakan konstanta ringan Surabaya untuk BakhPlaceField readonly dan model bakhDocumentData. Main HTML/print/Word memakai model yang sama; tanggal dokumen tetap. PrototypeDomain/saveDeal TS menetapkan signingPlace server-owned untuk new/draft save/generate/attest. Contracts.document_data memakai JSON projection existing, tidak ada schema/API baru. Terbit attestation tetap memakai old snapshot, Sah terkunci; semua output BA membaca kota tetap yang secara eksplisit diminta tanpa bulk rewriting historical snapshots.

Legacy place dipertahankan untuk referensi blankSpk, bukan lagi kota BA. Existing draft mempertahankan old.place; new compatibility payload menerima legacy text, tetapi signingPlace client diabaikan dan form baru legacy place tetap kosong. SPK tetap menggunakan field/validasi tempatnya sendiri; tidak mewarisi konstanta baru. Lokasi pekerjaan/Witel/PP/ledger/harga/approvals/file tetap.

Query review0SQL/API/lookup tambahan, scalarO(1) di atas lookup lama;6domain paths terukur0queries. Tambahan JSON metadata konstan, existing atomic revision/receipt/audit tetap. Backup demo SQLite read-only dibuat sebelum workflow edit; tidak menyentuh its aktif.280PHP/5906assertions/289FE/build/Pint/assets lulus; native DOCX Draft/Sah CRC/XML dan1/1pages/all2PNG diperiksa. [Aturan/batas](BAKH_TEMPLATE.md), [bukti](qa/bakh-surabaya-20261115.json). Tidak mengklaim uji beban atau live-server/desktop Word/native print/physical verification.

## 15 November 2026 — Perapian dua BOQ PP

CSS dibatasi pada dua budget-boq-table dalam preview PP: font/padding lebih kecil, fixed grid100%, lebar kolom Owner/Mandor terpisah, designator bold inherit dan wrap di kolomnya, nominal tidak dipecah per digit. CaptureProjectPlan memberi metadata compactBoq pada dua tabel; exporter PP memakai font6pt dan margin25/40twip. Helper word-ooxml.table menerima opsi margin, default55/65 tetap sehingga tabel lain/penutup dan exporter lain tidak berubah. Pengguna mengklarifikasi penutup tidak perlu dikembalikan; XML penutup pada fixture byte-identik. Header dari IV.5, semua teks/nominal/approval/storage tetap.

Review query/performa:0SQL/API/lookup/backend/schema/migration/live rewrite, kompleksitas ekspor tetap O(rendered DOM). Tidak mengambil backup DB baru karena presentasi tanpa writes.280PHP/5880assertions/289FE/build/assets lulus; existing Word regression diperluas untuk grid/font/margin dan default compatibility.2native DOCX CRC/XML, Word3/4pages,5changed PNG diperiksa+2Header hash-identik dengan render sebelumnya; preview dua tabel668px tanpa overflow pada viewport850; PDF current/nested4/5pages/3viewport nonblank. [Perilaku dan batas](PP_WORD_PRINT.md), [bukti](qa/pp-compact-boq-20261115.json). Tidak ada klaim throughput, server/desktop Word/cetak fisik.

## 15 November 2026 — Header PP dari rekap poin IV.5

Shared budgetRecapTotal menghitung rincian sekali dan dipakai BudgetPlanSection/projectPlanHeaderTotal; ProjectEditor header/summary dan ProjectPlanDocument memakai helper Header, DOCX/PDF mengikuti preview DOM. Harga BOQ tetap KHS; notice BOQ/Header disesuaikan agar tidak menjanjikan nilai Header dari upload. Editable form memakai applyBudgetPlan; readback mengambil saved canonical usage dari backend. No stored duplicate/Header-client field, project_plans.budget tetap BOQ/alokasi untuk pemakai bisnis lain. Legacy tanpa itemized budget fallback ke nilai historis; continuation memakai usage sendiri; submitted data tidak direprice/ditulis ulang.

0SQL/API/schema/migration/backend code change/live write/backup DB baru. Satu passO(usage rows)/memori konstan, tanpa catalog/account lookup.280PHP/5880assertions/289FE/build/assets lulus; tests lama diperluas untuk Header zero/edit/save/reload/preview/fallback serta recap-forgery rejection dan BOQ/COGS/margin tetap. Native Word CRC/XML4/5pages, Header=recap,2changed pages inspected/7page hashes identical to prior inspected render; PDFs current/nested/3viewport nonblank. [Authority/storage/limits](PP_HEADER_BOQ_KHS.md), [bukti](qa/pp-header-recap-20261115.json). Tidak ada klaim server/desktop Word/physical-print verification.

## 15 November 2026 — Total akhir rekap anggaran PP

BudgetPlanSection menjumlahkan b.usage[].amount satu kali memakai cents integer dan menambahkan baris terakhir Total Anggaran untuk versi2 maupun tabel historis versi1. Subtotal versi2 tetap IV.1+IV.2+IV.3, sedangkan total akhir termasuk reimbursement perizinan; COGS/margin, saved totals/snapshots dan validasi Laravel tidak berubah. Pada versi1, category total yang berulang per termin tidak dijumlahkan ulang. Form/preview memakai komponen sama; PP DOCX menangkap tabel DOM tersebut dan cetak/PDF mengikuti preview.

Review query:0SQL/API tambahan/per-row lookup, satu pass O(usage rows), extra memoryO(1), tanpa catalog read/aggregate write/schema/migration/backup baru. Tidak mengubah nominal tersimpan atau menjalankan migration konversi sebelumnya.280PHP/5877assertions/289FE/build/assets lulus; regresi semantic yang sudah ada diperluas untuk total termasuk/tanpa permit, historical repeated category dan unchanged COGS/margin/state.2DOCX native CRC/XML valid/render4+5pages/all9PNG inspected; PDF current/nested4/6pages dan3viewport nonblank. [Aturan](PP_BUDGET_TEMPLATE.md), [bukti](qa/pp-budget-total-20261115.json). Fixture terisolasi; server/desktop Word/cetak fisik belum diverifikasi.

## 15 November 2026 — Penghapusan tiga teks penutup PP

RiskQualitySection menampilkan heading penandatangan hanya saat editor (!printing) dan menghapus paragraf catatan EVP dari preview. ProjectPlanAppendix menghapus footer prototipe yang diminta. Native DOCX menangkap DOM preview yang sama, sehingga tiga teks hilang di Word/PDF tanpa filter teks tambahan, model data baru atau recalculation. Tabel/identitas/EVP/date/blank physical cells dan lima keputusan akun tetap.0SQL/API/schema/migration/live rewrite/worker change; tidak ada biaya query atau perubahan kompleksitas ekspor. Audit terdahulu yang menyebut note ikut Word tidak ditimpa; otoritas terbaru pada PP_WORD_PRINT.md.280PHP/5877assertions/289FE/build/assets, headless CRC/XML/PDF/no text dan render9WordPNG terverifikasi dengan fixture; bukan live-server/desktop Word/physical-print check.

## 13 November 2026 — Native DOCX dari preview PP dan rantai ancestor cetak

[PP_WORD_PRINT](PP_WORD_PRINT.md) menjelaskan ProjectPlanWordDownload yang mengikat ref wrapper khusus PP, melakukan clone saat klik sebelum lazy import, lalu captureProjectPlan mengubah heading/list/table/DL/colspan menjadi model presentasi. createProjectPlanDocx memakai word-ooxml/ooxml-zip untuk native OOXML editable; bukan render HTML/altChunk, tidak menghitung ulang uang/identitas atau query katalog. Teks/angka dari preview terotorisasi, signature-screen/status diabaikan, blank print cell menjadi ruang basah. Running/busy/error guards dan konfirmasi tetap. Current template dan generic legacy sama-sama memakai isi preview masing-masing. Tidak ada state/SQL/schema/migration/private-file write atau dependency runtime baru.

Blank print direproduksi sebagai dokumen0px: selector .app>*:not(.modal-backdrop) menyembunyikan main-shell yang memuat modal. Perbaikan menjaga hanya ancestor path ke document modal (termasuk nested review), menyembunyikan siblings dan melepas overflow/height body/modal. Named A4 portrait hanya wrapper PP; BA KH portrait/lampiran landscape diuji tetap. Tabel BOQ cetak lebih ringkas, angka tidak dipecah tengah digit pada fixture dan tabel TTD dijaga bersama. UI layar selain toolbar/penanda wrapper tetap.

Tambahan query/API0; capture/clone/model/ZIP O(isi DOM), tidak ada lookup master per-row; module lazy PP6,20kB/gzip2,71kB pada build fixture, bukan benchmark. 280PHP/5877assertions/289FE/build/assets32refs/25preload×3profile/diff check lulus. Headless fixture PP4/36BOQ: PDF4/6halaman current+nested, widths375/768/1440 nonblank;2DOCX native dirender LibreOffice4/5halaman dan seluruh9PNG diperiksa. Data akun/live its/Dompis/env/worker/deployment tidak disentuh; native print-dialog, desktop Microsoft Word, Safari/Firefox dan cetak fisik tetap belum diuji.

## 13 November 2026 — Baris EVP administratif dan tanggal referensi BA KH

[PP_EVP_SIGNATORY](PP_EVP_SIGNATORY.md) menambahkan identitas/nama/NIK yang diberikan pengguna sebagai baris presentasi tetap setelah empat baris akun pada penutup PP. pp-evp-signatory.ts memisahkan penanda otomatis dari signatureRows/Role/approvals; tidak menambah akun/keputusan/tahap atau membuat PP Sah. SignatureStatus berbagi struktur preview/cetak: otomatis diberi label tersendiri, print tetap kosong. Penanda selalu ada sesuai permintaan, termasuk Draft/ditolak; cap akun tetap hanya keputusan siklus aktif.

Tanggal referensi berasal dari minimum tanggal BA KH valid tersimpan milik exact PP, termasuk Draft tersimpan, minus satu hari kalender UTC; tanpa fallback kode proyek/root/akun/hari ini. Satu scan O(BA KH), memori konstan,0SQL/API tambahan dan tidak ada lookup per baris. Data sudah mengikuti scope akun/Branch. Tidak ada backend/schema/migration/penulisan nominal/snapshot/archive/approval/tanggal historis atau worker change. Browser/physical-print/server verification terpisah dari SSR/CSS dan isolated PHP tests; bukti akhir pada QA.

## 12 November 2026 — Pemotongan nominal tersimpan dan audit konversi satu kali

Otoritas [TRUNCATE_STORED_MONEY](TRUNCATE_STORED_MONEY.md) mengganti half-up dengan idr-trunc-v1; bcdiv(...,0)/Math.trunc/BigInt division menghilangkan pecahan. HALF policy lama tetap terpisah. Data migration000001a mendahului type migration000002 dan juga berlaku bila type migration sudah terpasang. TruncateStoredMoney menyusun/validasi rencana SQL+active JSON/BOQ/budget/KHS/request/encrypted kasbon sebelum UPDATE; tidak memakai aggregate writer untuk meregenerasi child/ledger/decisions. Total anggaran/header otomatis dari sumber terpotong, pembayaran aktual hanya dipotong dan identitas/status/file tetap. Konflik aktif/zero-positive dihentikan, bukan dihapus.

currency_conversion_runs dan currency_conversion_changes (run_id FK) menyimpan idempotence key, jumlah perubahan dan encrypted before/after+hash. Apply satu transaksi dengan lock workspace/revision; late SQL failure rollback audit/data/revisi dan bisa retry. Archive/receipt/audit lama tetap. CLI default read-only/its-only, tanpa endpoint baru. Baca maintenance dipaginasi200 dan workspace20/kolom terpilih, lookup indexed; query/write meningkat sesuai record, bukan janji throughput. Web save/preview0query tambahan, katalog SPK tetap4query. Rincian bukti/backup/server limits pada QA; belum apply/migrate aktif atau deploy server.

## 11 November 2026 — Kebijakan rupiah bulat, tipe uang dan matrix drilldown

[WHOLE_RUPIAH_DASHBOARD_DETAILS](WHOLE_RUPIAH_DASHBOARD_DETAILS.md) menjadi otoritas baru untuk uang operasional. Marker moneyPolicy=idr-whole-v1 dipasang server pada explicit editable/new save; BCMath/BigInt membulatkan half-up setiap M/J per baris. Formatter/terbilang rupiah bulat dipakai konten bersama layar/Word. Locked legacy tanpa marker mempertahankan rumus/keputusan/paid/history; tidak bulk rewrite snapshot/arsip/source Excel. Harga asli tetap dicek terhadap ceiling sebelum normalisasi. Angka quantity/percent dipisahkan dari uang.

Migration its-only14kolom/10tabel ke BIGINT didahului pemeriksaan seluruh kolom dan pending snapshot bill dalam halaman200, gagal sebelum DDL bila ada cents. CLI pemeriksaan tanpa write. Baseline11SELECT; tidak berjalan pada web. SQLite rebuild memulihkan trigger termasuk settlement XOR, relasi/null tetap. MySQL DDL perlu maintenance/backup terpisah karena nontransactional; belum diterapkan live. Pemilih SPK memakai policy contract pada agregasi component dalam query yang sama, tetap4servicequeries; tidak ada lookupmaster/per-row. Runtime lainnya0query baru.

Model dashboard menyimpan proyeksi metadata PP/payment contributors dari data Branch-scoped yang sudah dimuat; Map/Set/index dan memo tetap. Klik enam angka per scope, search/page20/drill kode→PP tidak memuat SQL/archive/katalog/private identity atau mengubah state. Route dari exact record; OPEN/CLOSE global visible siblings sebelum filter, root total dedup. Fixture1000rows/20DOM dan original query budgets bukan loadtest. Aggregate boot lama tetap. Bukti275PHP/5837assertions/279FE/build; browser/MySQL/Word/physical print/server belum diverifikasi.

## 11 November 2026 — Pengajuan kasbon Admin, Finance review dan revision probe

Otoritas terbaru [ADMIN_KASBON_REVIEW_SYNC](ADMIN_KASBON_REVIEW_SYNC.md) mengganti pembuat kasbon menjadi Admin Konstruksi/Superadmin setelah lima actual payment approvals; Finance hanya review/return/approve/proof/APM. FinanceDocuments memeriksa akun/Branch/PP dan eligibility di bawah workspace lock, mempertahankan server-derived canonical bill, encrypt private snapshot, versioned bank/photos dan actual issued_by. payeeVersion mengikat preview bank pada pengajuan; expectedKasbonId mengikat Finance decision pada versi review. Konflik tidak retry/menimpa rekening/keputusan diam-diam. Snapshot versi2 menambah makerRole/defaults dan area TTD Mandor; versi lama/SQL marker Kasbon Terbit/APM/history tetap kompatibel. Tidak ada schema/migration atau bulk rewrite.

Review Finance mengikuti exact term→SPK→contract→PP, memakai preview dokumen yang sudah dimuat; identitas default frozen kasbon, profil kini dimuat eksplisit. Bank/KTP/files hanya role privat/Branch, tidak boot/localStorage. includeFiles=false pada GET profil/dokumen melewati daftar yang tidak dipakai; target/account authorization dipakai ulang secara internal, field/query bounded. Fixture pengajuan44HTTP/budget75 (awal47), APM44; default profil5, lazy profil/kasbon3service masing-masing. Bukan universal performance claim. BOQ Mandor preview memakai selected saved budget dan indexed metadata/root references tanpa harga pengganti;0SQL baru/per-row catalog searches. Task relations Maps,20DOM/page untuk PP/payment/kasbon.

Authenticated GET revision membaca1counter workspace resmi tanpa create/aggregate/archive (2HTTPquery pada fixture session/auth). Poll15detik hanya visible safe lists, state Branch-scoped diambil ketika counter naik; satu in-flight request, focus minimum3detik, error backoff30/60/120. Editor/detail/dialog/native dialog/isian catatan/APM/file/pending command menjeda. Store mengecek eligibility/revision/local mutation lagi setelah respons, mengabaikan stale/invalid/aborted responses tanpa menyentuh draft/cache atau advancing editor revision. Tidak ada auto-POST/retry keputusan. Satu changed refresh tetap membaca aggregate lama; bukan pengganti relational read-model atau load benchmark.

269PHP/5771assertions/270frontend/build/check-assets terverifikasi secara terisolasi; detail query/race/role/version/render evidence dan batas server/browser pada QA/bukti terstruktur. Migration kasbon sebelumnya tetap prasyarat; tidak mengubah its/Dompis/env/APP_KEY atau menerapkan koreksi Head PP0002. Deploy kode/build lengkap dilakukan operator terpisah.

## 11 November 2026 — Koreksi administratif Head tanpa menghapus keputusan

[PP_HEAD_CORRECTION](PP_HEAD_CORRECTION.md) menjelaskan perintah CLI dan rencana spesifik PP0002 yang dipersiapkan setelah hasil SQL pengguna lengkap. ProjectHeadCorrection memeriksa shared workspace, Superadmin/Branch/Head aktif, revisi/keputusan/graf belum dibayar; backup privat diverifikasi sebelum incremental write+revision+audit+receipt atomik. Tidak ada endpoint/schema/migration/reset/archive fallback atau replacement state. Service membaca business snapshots serta target SQL, tidak bind ulang seluruh katalog master. Hash-bound request mencegah koreksi ganda.

Marker headApprovalReopen server-owned menyimpan batas history dan referensi empat keputusan aktual; ProjectHeadReopen.cycles mempertahankan siklus asli lalu memproyeksikan satu siklus administratif dengan keputusan yang dibawa. Tidak membuat aksi persetujuan baru oleh empat pengguna atau menimpa Head lama. Head mengambil keputusan baru lewat command normal; penolakan/resubmission berikutnya tetap restart lima tahap. Writer membandingkan keputusan secara strict setelah normalisasi urutan key JSON, menjaga nilai/tipe/audit immutable.

Domain execute menahan mutasi keturunan marker selama PP belum Sah, berdasarkan hubungan tersimpan; recorder React memberi feedback sama sebelum optimistis. Baca/preview dokumen historis tetap; timeline/approval memberi konteks pembukaan ulang dan Head stamp memakai approval aktif saja. Metadata marker disalin dari saved server saat revisi PP, tidak dari client. Tidak mengubah data BA KH/SPK/termin/BOQ/biaya/ledger/arsip ketika koreksi dilakukan.

Fixture84query apply satu PP, budget140/no catalog/no write proyeksi turunan; tambahan0SQL pada gerbang HTTP.263PHP/5597assertions/257frontend/build/check-assets lulus. Bukti dan batas browser/engine/server ada pada QA/dokumen perilaku; belum apply pada server/live its. Setelah marker diterapkan, deploy kode yang memahami siklus koreksi secara lengkap; jangan rollback kode parsial.

### Bukti tambahan PP0002 — 11 November 2026

Hasil SELECT dari pengguna menunjukkan BA KH/SPK Sah, termin1 Final ditolak dan APM/settlement NULL pada baris yang dibagikan. Tidak ada perubahan rancangan/runtime; konfirmasi tersebut tidak menambahkan kemampuan pencabutan keputusan. Identitas/revisi dan approval SQL masih diperiksa sebelum koreksi, tanpa menurunkan status dokumen turunan atau menghapus riwayat.

## 11 November 2026 — Review koreksi approval Head PP0002 dengan dokumen turunan

Pengguna mengonfirmasi BA KH/SPK ada dan pembayaran ditolak. Target pending Head dengan empat keputusan sebelumnya bukan command Kembalikan/resubmit normal. Domain validateDeal membutuhkan PP Sah/BOQ Final, sedangkan writer SQL mempertahankan keputusan immutable dari history; edit snapshot/status saja tidak menyediakan pencabutan yang konsisten. Tidak ada command pencabutan Head yang diterapkan. Diperlukan pemeriksaan SELECT-only identitas/revisi, dokumen turunan/APM dan run/step/decision aktual sebelum rancangan koreksi terkoordinasi. Ini review saja: tidak ada perubahan endpoint/domain/schema/query runtime, penghapusan keputusan, rewrite dokumen turunan/arsip atau koneksi server. Batas verifikasi dicatat pada LARAVEL_QA dan WORKPLAN.

## 11 November 2026 — Compatibility fix untuk upload BOQ/eviden

UploadQueue INSERT kini menambahkan `context_id` hanya pada bukti transfer. BOQ/evidence tidak bergantung pada schema kasbon, sehingga schema sebelum kasbon tetap menerima task/job yang tervalidasi. Private upload menambah satu registry lookup dan menolak503 sebelum staging jika migration belum selesai; tidak auto-migrate atau menghilangkan binding kasbon. Query lama tidak bertambah (fixture dua enqueue24query), hak/Branch/status/UUID/queue/private paths/receipts tetap. Tidak ada DDL/perubahan workflow/data live. Penyebab SQL1054 lokal, regresi real worker memory dan batas remote ada pada [UPLOAD_QUEUE_PERFORMANCE](UPLOAD_QUEUE_PERFORMANCE.md#11-november-2026--upload-eviden-sebelum-migration-kasbon) dan qa/evidence-schema-compatibility-20261111.json.

## 11 November 2026 — Private Mandor / immutable Finance kasbon

[FINANCE_KASBON](FINANCE_KASBON.md) mencatat pemetaan template, state machine, tiga tabel baru, extension upload context dan XOR APM/composite-term references. FinanceDocuments menyimpan ciphertext/metadata terpisah; tidak menambah PII ke workspace aggregate/boot/export/localStorage. Internal commands dan existing workspace lock membuat versi profil/issuance/file/settlement, revision/receipt/audit atomik; semua pembacaan dibatasi role serta PP Branch/workspace. Default server untuk v3 baru, adopsi lama eksplisit, marker tidak dapat dihapus melalui submit/legacy Partial request. Transfer-proof terikat kasbon saat enqueue dan saat APM; reissue memerlukan bukti baru. Tidak menambah approval EVP/Business Support atau membuat TTD.

Query minimal per dokumen; profil/file batched dan terbatas, issuance47/APM44 HTTP fixture satu termin, private payee5 termasuk workspace read. Issuance tidak menulis ulang PP/BOQ/actual/approval/file/ledger. Picker memakai proyeksi JSON tambahan pada query yang sama; laporan/timeline memakai state publik. Existing full aggregate/catalog reads tetap; tidak mengklaim throughput umum. Migration disiapkan saja; no live its/archive/env/master/Dompis changes. Bukti serta batas SQLite/browser/MySQL/print/server di LARAVEL_QA dan qa/finance-kasbon-20261111.json.

## Same-origin workspace transport — 10 November 2026

[SAME_ORIGIN_TRANSPORT](SAME_ORIGIN_TRANSPORT.md) memisahkan alamat publik browser dari alamat upstream Laravel. PrototypeController.show menghasilkan path named route + request baseUrl tanpa scheme/host; workspaceUrl menyusun path endpoint dikenal pada frontend. Command/state/recovery/CSV/backup tidak lagi memakai boot absolute URL yang dapat mengarah HTTP/host internal. Account URLs fresh boot juga relatif. Tidak mengubah proxy trust/CORS/.env atau menambah host situs hardcoded.

Network/timeout dibedakan dari response non-JSON dan validasi; rejection promise PP kini memakai pesan persistenceError yang menjelaskan hasil belum terkonfirmasi. Success/clear/navigation tetap setelah respons server; response-lost-after-save menjalankan recovery GET lama tanpa duplicate POST. Cache draf existing tetap, tidak menciptakan shared new-PP cache/auto-save.

0 SQL/endpoint/poll/dependency/request tambahan: normal save1POST, failure1POST+1GET, CSRF/revisi/UUID/Branch/auth/ledger/domain/Finance/schema unchanged. Runtime aggregate/reference cost tetap; bukan latency/load improvement claim. NewPHP boot mock covers root/subfolder/http-upstream, frontend integration covers oldHTTPboot/path/errors/uncertainty and existing upload query counts remain. 240PHP/4963assertions,244frontend/build/check:assets/Pint/diffcheck pass.

Role Operation dari pulled commit3e397b8 dipertahankan; satu assertion migrations mengikuti64. No live its/env/permission/migration/Dompis changes, no archive fallback. Server https://its.telkomakses-area3.id/ belum diakses lewat sesi autentikasi/Console/Network; root cause remote dan deployment belum diklaim terbukti. Audit qa/same-origin-transport-20261110.json.

## Katalog role akun Operation — 10 November 2026

Tujuh kode role Operation ditambahkan sebagai nilai valid pada `AccountAccess::ROLES`, `PrototypeDomain::ROLES`, dan union role TypeScript; migration guarded menambahkannya ke tabel referensi SQL `roles`. Akun tetap diikat ke satu role aktif dan Branch melalui `user_assignments`, dengan validasi penempatan yang sama. Identitas role baru tidak mengubah permission, approver chain, inbox, Finance/APM atau akses Branch; seluruh otorisasi tetap melalui pemeriksaan per fitur. Tidak ada endpoint/query request baru. Migration hanya melakukan lookup kode per role saat deploy, dan rollback menolak identitas yang telah dipakai.

Perilaku/mapping: [ACCOUNT_ROLES.md](ACCOUNT_ROLES.md). Perubahan belum diuji pada its aktif dan migration tidak dijalankan.

Catatan ini menjelaskan struktur runtime aktif dan keputusan perubahan. Baca bersama [MYSQL_RUNTIME.md](MYSQL_RUNTIME.md), [buku saku relasi tabel](DATABASE_RELATIONS.md), AGENTS.md dan [CHANGELOG.md](CHANGELOG.md). Kode Laravel merupakan otoritas validasi dan status tersimpan; dokumentasi SQL konseptual lama tidak menggantikan executable migrations atau runtime.

## Asset preload relatif untuk root/subfolder — 9 November 2026

Shared confirmation CSS diekstrak Vite ke chunk bersama auth/main. Generated helper sebelumnya membentuk /build/<dependency> dari origin root, sementara @vite dan import JS mengikuti URL modul aplikasi. Pada Apache subfolder, CSS diminta di luar application public dan lazy page gagal. base ./ sekarang menghasilkan resolusi new URL(dependency, importerUrl) serta meneruskan import.meta.url; @vite tetap memilih entry melalui manifest. Tidak memakai env/path hardcoded, tidak mengubah rute backend.

Review performa: 0 SQL/fetch/poll aplikasi baru, tidak menambahkan fallback download/reload otomatis, duplikasi katalog atau dependency runtime. CSS yang memang sudah diperlukan memakai path yang benar dan preload dedup Vite tetap. tools/check-build-assets.mjs memeriksa manifest/file dan mengeksekusi helper preload produksi dengan fake DOM pada root/Apache subfolder/CDN: 30 file references, 23 deps, 3 profiles lulus. 243 frontend dan TypeScript/Vite build lulus. Bukan real-browser/HTTP/CDN/load test; server pengguna belum diakses.

Build assets ignored by Git tetap membutuhkan build target/deployment lengkap dan refresh halaman lama. Tidak ada mutation its, migration, perubahan account/Branch/domain/ledger/.env/permission atau Dompis. Detail ACTION_CONFIRMATION.md dan qa/confirmation-css-base-20261109.json.

## Konfirmasi tindakan YA / TIDAK — 9 November 2026

[Confirmation UI](ACTION_CONFIRMATION.md) menambah lapisan client sebelum callback Button/ActionLink, upload berbasis file input dan submit akun/master. Shared confirmation.ts membuat satu native dialog saat aksi diminta; YA menjalankan jalur lama, selain itu tidak ada efek. Form submit/Enter mempertahankan validasi browser dan CSRF; logout belum membersihkan draf sebelum YA. Konfirmasi konteks yang sudah ada distandardisasi labelnya, tanpa dialog kedua.

Tidak mengubah otoritas Laravel, command/snapshot/revisi/receipt, SQL/storage/ledger, approval/scan/Finance/APM atau worker. **0 SQL/endpoint/poll/package/migration tambahan**; tidak membaca database untuk membangun dialog. Kontrol asal diperiksa kembali sesudah YA, klik ganda tidak mengantrekan aksi, pesan textContent dan modal native di atas Modal React dengan Escape terisolasi. Validasi backend tetap wajib karena dialog bukan kontrol otorisasi.

239 PHP/4.944 assertions, 243 frontend, build/diff check lulus; enam regresi fake DOM/AST mencakup aksi batal/sekali, disabled, route, literal text, submit/Enter dan entry points. Existing query-budget tests tetap lulus; tanpa klaim latency/load baru. Tidak ada live its/env/permission/Dompis changes. Browser native focus/keyboard/visual/download/print dan remote deployment belum diverifikasi. Bukti qa/action-confirmation-20261109.json.

## Payment Parsial sebelum berkas lengkap — 9 November2026

[PARTIAL_PAYMENT](PARTIAL_PAYMENT.md) mendefinisikan tiga command baru yang tetap melewati controller/SQL account/Branch, lock/revisi/receipt/audit: Admin request setelah lima approval, Finance verification, Finance APM/full settlement. Marker partial-payment-v1 dan nominal/ID/cycle/aktor server-owned, tidak diterima saveTerm; tidak ada read-time adopsi/history rewrite. Scan financeApproval terpisah dari request decision. Late private scan upload/verifikasi setelah APM hanya untuk marker ini, tanpa mengubah settlement atau menyetujui pekerjaan lagi. Kasbon secara eksplisit ditunda pengguna, tidak ada form/prasyarat/kolom spekulatif.

Normalized partial_payment_requests menyimpan siklus/keputusan/dokumentasi saat request/payment bersama snapshot payment_terms. APM menambah nullable payment_request_id, scan nullable dan XOR CHECK serta kedua composite-term FK; legacy scan authority tetap. Migration guarded its/rollbackhistory tersedia namun belum di-deploy agen. Detail relasi/kolom, mermaid dan preservation tersedia pada dokumen perilaku. Arsip unchanged; no master/account/env/permission/live transaction changes.

Proyeksi term membandingkan bagian actual/approval/file/workEvidence sebelum write; request/cycle sebelumnya diindeks dan unchanged skipped. Three-action onePP/oneTerm HTTP fixture including revision54/55/57queries (budget75),2existingpricecatalogreads and no actual/approval/scan/ledger rewrites. Payment list memo/indexed relationships and20DOMrowpage,1000rowfixture; new picker skips unused list calculations. Catalog adds2JSONprojections in existing bounded batch, no extra per-row query. Aggregate/global validation and scan projection old costs remain; not throughput/load claim.

239PHP/4944assertions,237frontend,build/Pint/diffcheck pass; HTTP no-scan settlement/next term/late scan, financialreturncycle/roles/Branch/Head/forgedmetadata/APMrollback/replay/XOR/FK/legacyupgrade and independent status tested in fail-closed memory. MariaDB10.4 private empty socket schema63 migrations/repeat passed,0users; not live migration or full MariaDB payment-flow test. Backup2.084.634byte/hash verified. Browser/click/responsif/keyboard/bank/load not claimed. See qa/partial-payment-20261109.json and deployment instructions.

## Progres semua upload file — 9 November 2026

[UPLOAD_PROGRESS](UPLOAD_PROGRESS.md) mendefinisikan model fase dan transport XHR multipart bersama untuk BOQ/eviden/scan serta preview/commit master. Browser upload events menentukan persen byte; unknown total tetap indeterminate dan request 100% masih menunggu konfirmasi. BOQ/eviden memakai task server queued/processing/completed/failed dengan bar worker tanpa angka rekaan, termasuk setelah reload. Scan/master tetap sinkron dan master preview tidak menjadi write. Multi-evidence merender per file dan menolak callback batch lama.

Kontrak POST/response lama tetap: CSRF/credentials/UUID/revisi/hash/status/authorization/private file/workflow audit. Tidak ada perubahan PHP/SQL/schema/job/worker/.env/permission/data, sehingga tidak ada backup workflow baru atau migration deployment. Handler dan abort listener dibersihkan; timeout 60s / scan20s, tidak ada POST auto-retry. XHR event dideduplikasi per persen bulat/fase;0 request/SQL baru untuk persen. Polling/batas queue lama tetap, termasuk biaya waiter dan daftar bersamaan; tidak mengklaim request queue terhapus atau throughput universal. Integrasi fakeXHR semua jalur memeriksa tepat7 GET lama/0 progressGET; seluruh suite230 PHP/4473 assertions dan231 frontend/build/diffcheck lulus. Browser/aaPanel/Apache/jaringan nyata/responsif/load belum diperiksa manual. Lihat qa/upload-progress-20261109.json.

## Header → BOQ dan harga KHS otomatis — 9 November 2026

[PP_HEADER_BOQ_KHS](PP_HEADER_BOQ_KHS.md) menjadi authority terbaru untuk PP induk v3 editable: canonical SQL KHS m/s/version di setiap explicit save, metadata khs-auto-v1/catatan sistem, total Header dari BOQ dan Draft Rp0 sebelum upload. Header/BOQ bertukar posisi; field total baca saja. Excel/provenance tetap manifest privat server, snapshot diajukan/Sah/lanjutan/v1/v2 tetap. Tidak ada read-time transaction rewrite, endpoint baru atau migration data; migration 2026_11_09_000001 hanya mengganti CHECK budget secara atomik, guard its dan rollback nol fail-closed. Belum di-deploy agen ke live.

Map/array_column/Set mengindeks KHS/sumber/ID BOQ/Owner/Mandor sebelum iterasi; tidak ada lookup SQL per baris tambahan. Fixture domain500row PHP0SQL/TS melarang repeated linear KHS lookup. HTTP26row dibatasi220 query termasuk revision read+normalized write, katalog harga tepat2; ledger/write masih tumbuh terhadap jumlah item, aggregate/catalog boot dan render tetap ada. Ini bukti regresi scoped, bukan load test/latency universal. Snapshot SQL, private source hash/manifest dan archive teruji tidak berubah selain dokumen yang disimpan. Lihat QA/qa/pp-header-boq-khs-20261109.json untuk hasil suite dan batas browser/MySQL migration verification.

## Registrasi CLI pada Supervisor server yang sudah aktif — 9 November 2026

Klarifikasi pengguna meminta langsung paste ke Terminal aaPanel. Runbook menyediakan resep heredoc mandiri memakai generator Artisan yang sudah di-deploy, tanpa upload script baru. Resep menjaga active daemon/include dan scope satu program; syntax AST/scope diperiksa, bukan dijalankan terhadap service nyata. Script reusable di bawah tetap alternatif. Tidak ada jalur request/SQL baru pada alternatif operasional ini.

User mengonfirmasi Supervisor menjalankan worker Dompis/wibsy dan meminta menambah its saja. `deploy/register-upload-worker.py` adalah alat root Linux terpisah dari web: memeriksa project/user/akses/PHP-config its, menjalankan generator sebagai user PHP-FPM, membatasi hasil satu program/command/user/folder/log, memverifikasi PID/config dari supervisorctl aktif dan memilih include *.ini/*.conf. Penulisan satu file atomik0600/idempotent dengan backup revisi; konflik foreign program/group/config utama ditolak. `reread` hanya membaca, `update area3-uploads`/start/status dibatasi program sendiri, tanpa daemon/service/package baru atau restart all. Worker tetap non-root dan memakai lock/heartbeat lama.

Tidak ada API/job/domain/schema/.env/permission/data/workflow rewrite. Preflight bootstrap membaca config/guard target dan izin, bukan query baris bisnis; tidak ada query request/upload baru. Script bekerja pada jumlah include config lokal yang tersedia, bukan memuat graph workflow/master. Delapan tes offline memakai subprocess palsu/temp dirs, termasuk pembatasan update serta file/program Dompis/wibsy, injeksi dan konflik, active PID, idempotency/backup dan preflight failure. 225 PHP/4382 assertions,218 frontend/build dan lint PHP embedded lulus. Supervisor nyata/PHP user/server uploads belum diverifikasi; tidak mengklaim root/service integration atau throughput. [Runbook](SERVER_UPLOAD_WORKER.md), qa/supervisor-cli-registration-20261109.json.

## Konfigurasi worker upload server aaPanel — 9 November 2026

`UploadSupervisorConfig` menghasilkan konfigurasi melalui `deploy/supervisor/area3-uploads.conf.stub` memakai base/storage path dan PHP_BINARY runtime CLI. User OS wajib eksplisit dan non-root; karakter injeksi/interpolasi/comment serta path ambigu ditolak. CLI hanya menampilkan teks, tanpa DB, command shell, file write atau proses worker. Supervisor menjalankan perintah `area3:work-uploads` yang sudah ada, mempertahankan lock/heartbeat/private identity/queue uploads→area3-uploads. Web autostart dinonaktifkan di server oleh operator, tidak diubah agen di `.env` lokal. Tidak ada API/migration/perubahan data/job/SQL/workflow.

Satu proses, autorestart termasuk exit normal, SIGTERM/group shutdown dengan 120s > timeout90s < retry180s, umask0077 dan log10MB/5 backup menjaga operasi server sesuai model upload lama. Supervisor service boot, include path aaPanel, binary/extension dan user PHP-FPM tetap harus diterapkan/diverifikasi operator; `root@dompis` bukan identity worker. Generator0 SQL/0 process; 225 PHP/4382 assertions,218 frontend/build/Pint dan independent INI parse lulus. Tidak mengklaim throughput atau worker remote sudah aktif. [SERVER_UPLOAD_WORKER.md](SERVER_UPLOAD_WORKER.md) dan qa/server-upload-worker-20261109.json mencatat langkah/batas, termasuk heartbeat lama yang dapat stale saat job panjang.

## Matriks dashboard Region → Branch — 9 November 2026

`AuthenticatedWorkspace.bind` memperkaya query referensi Branch yang sudah ada dengan left join Region/kolom eksplisit, lalu scope akun tetap per Branch PP. RelationalWorkflowStore/state authorized adalah sumber angka; tidak ada endpoint, migration atau penulisan snapshot/arsip. `dashboard-matrix` mengindeks PP→BA KH→SPK→termin dan ID Project, memakai `termTotalForDeal` yang sama dengan rumus `termTotal` lama. Memoisasi memisahkan toggle tampilan dari kalkulasi data. Region null/deleted ditandai belum ditetapkan; tidak menebak teks legacy.

ID unik dihitung terpisah untuk Branch/Region/global. CLOSE memerlukan semua PP visible pada ID selesai final dan seluruh tagihan terkait lunas/APM; klasifikasi sebelum filter Branch menghindari CLOSE palsu karena sibling visible yang belum selesai. Uang/jumlah PP mengikuti filter. Realisasi adalah `paid` tersimpan, bukan approved amount; BOQ hanya induk tersimpan tanpa menghitung ulang continuation. Tidak mengubah gate transaksi/Finance/volume.

Direct bind tetap 5 query pada katalog awal/+200 Branch dan Region, tepat satu query Branch/Region tanpa per-row SQL. Fixture 1000 PP/SPK/termin melarang collection find dan memeriksa total; aggregate boot/reference reads yang sudah ada tetap, bukan klaim latency/load umum. 222 PHP/4332 assertions, 218 frontend/build/Pint lulus; browser/dark/responsive/keyboard/load belum langsung. Definisi lengkap, file dan batas pada [DASHBOARD_MATRIX.md](DASHBOARD_MATRIX.md), bukti qa/dashboard-matrix-20261109.json.

## Pekerjaan BARP dan ledger Lampiran Rekonsiliasi — 9 November 2026

`BarpTemplate` menetapkan `barp.version=2/job` dari PP dan menangkap dua identitas Manager sesuai assignee pembayaran. Properti client/cache diabaikan; draf lama mengadopsi hanya melalui penyimpanan eksplisit. `payment_terms.document_data` dan riwayat per pengajuan tetap memakai transaksi yang ada, tanpa migration, backfill atau penulisan arsip. Metadata version2 bukan perubahan workflowVersion/approval scheme.

`term-volume.ts` membagi satu model per komponen antara editor dan lampiran: SPK, aktual termin, accepted, sisa, harga dan nilai dengan pembulatan domain. Root BOQ/KHS/previous terms diindeks sekali; tidak ada nested find per baris atau penggabungan volume Material/Jasa. `barp-appendix-content` membagi model tabel, rekap dan lima penandatangan untuk HTML/Word landscape. Historical identity fallback hanya keputusan/snapshot sesuai ID; tidak mengambil akun terkini yang bisa mengganti identitas lama. Surat utama portrait tetap terpisah.

`barp-file.ts` memisahkan filename/MIME sehingga download appendix tidak mengunduh template utama. Export native lazy tanpa endpoint/network/catalog tambahan; hanya metadata authorized yang telah dimuat. Main model membedakan PEKERJAAN versi2 dengan LOKASI historis versi1. Word tetap setelah diajukan/review-marked; official print/scan/Finance/APM/ledger tidak berubah.

Resolver 0 SQL, fixture HTTP satu PP tetap 59 queries termasuk GET revisi (batas 60, dua catalog reads bawaan), unrelated writes/arsip ditolak pengujian. Fixture 1000 baris memeriksa indexing dan ekspor utuh; aggregate boot/commands tetap dan angka ini bukan uji beban. Pemetaan visual, adaptasi screenshot, snapshot/history, 219 PHP/208 frontend, empat Word/8 PNG, serta batas browser/Word desktop/cetak fisik tercatat pada [BARP_APPENDIX.md](BARP_APPENDIX.md) dan qa/barp-appendix-20261109.json.

## BARP pembayaran: form → snapshot → preview/Word — 8 November 2026

`createTerm` menangkap identitas Waspang SPK/Mandor/Head sesuai assignment termin lewat `BarpTemplate`; `saveTerm` memvalidasi metadata editable dan menambah `barpHistory` hanya saat pengajuan. Seluruh data memakai `payment_terms.document_data`, transaksi workspace/revisi/receipts/audit yang sama; tidak ada migration atau backfill arsip. Laravel membatasi tanggal/periode SPK/teks/Homepass dan mengabaikan pemalsuan identitas/riwayat. Draf lama mengadopsi lewat penyimpanan Admin, dokumen historis tanpa metadata tetap utuh.

`barp-content.ts` menyatukan model surat untuk HTML/native DOCX: judul tahap, nominal aktual term saja, M/J/terbilang, tabel sumber, signoff fisik kosong. Cabang preview BARP melewati kalkulasi generik root/KHS/riwayat tagihan; ekspor source-derived OOXML lazy tanpa API/SQL/CLI baru. Tabel form memakai Map BOQ/KHS/previous/accepted sekali, bukan pencarian berulang per komponen. Resolver0 SQL; fixture HTTP satu PP59 queries termasuk read revisi, batas60 dan dua reference reads bawaan, unrelated writes dilarang. Batas aggregate runtime/benchmark/browser tetap dinyatakan, bukan klaim semua query sudah optimal.

Otorisasi unduh yang diminta pengguna: Word tersedia Diajukan/Disetujui, berpenanda review sampai semua keputusan akun/peran lengkap. Print resmi tetap setelah seluruh approval, demikian pula scan/Finance/APM. Lima keputusan tetap wajib walau blok TTD surat mengikuti tiga penandatangan pada template. Bukti serta batas ada di [BARP_TEMPLATE_WORD.md](BARP_TEMPLATE_WORD.md) dan qa/barp-template-word-20261108.json.

## Aliran perintah dan penyimpanan

```mermaid
flowchart TD
  UI[React: form, preview, validasi awal] --> Store[Store: command, UUID, revisi, CSRF]
  Store --> HTTP[Laravel: akun aktif dan akses Branch]
  HTTP --> WS[PrototypeWorkspaceService: transaksi dan lock workspace]
  WS --> Domain[PrototypeDomain: validasi alur dan ledger]
  Domain --> SQL[RelationalWorkflowStore: proyeksi incremental]
  SQL --> ITS[(MySQL its: tabel bisnis dan snapshot dokumen)]
  SQL --> Audit[(Receipts, audit, pergerakan volume dan revisi)]
  ITS --> Read[Pembacaan layar sesuai Branch PP]
  Read --> UI
```

- resources/js/area3/domain.ts memberi feedback form/preview yang konsisten dengan app/Services/PrototypeDomain.php. Client tidak dapat mengganti seluruh state, identitas workspace, penanggung jawab approval atau path privat.
- PrototypeWorkspaceService mengunci workspace, memeriksa revisi/idempotensi dan menjalankan domain di server. Setelah valid, RelationalWorkflowStore.write menerima state sebelum/sesudah agar hanya dokumen terkait dan ledger root terdampak yang ditulis. Proyeksi silang BA KH/SPK tetap konsisten. Revisi, SQL bisnis, receipts dan audit akun nyata atomik.
- Sumber runtime adalah baris bisnis dan document_data per dokumen. prototype_workspaces.state adalah arsip yang tidak ditulis ulang dan tidak menjadi fallback. Master dan akun dibaca dari SQL. Satu ID Project dapat memiliki banyak PP; pembatasan Branch menggunakan Branch PP masing-masing.
- File upload asli berada di storage privat; document_files menyimpan metadata/path server. Antrean database memproses BOQ dan eviden, memeriksa ulang pemilik/Branch/status dan menjaga idempotensi serta cleanup. Lihat [UPLOAD_QUEUE_PERFORMANCE.md](UPLOAD_QUEUE_PERFORMANCE.md).
- Lima approval mengikuti PM → Admin Area → Mgr Project Support → Mgr Deployment → Head of PMO. Identitas/keputusan historis dibekukan, urutan runtime mencari peran pertama yang belum menyetujui. Pembayaran memerlukan lima keputusan sebelum cetak/scan TTD fisik, lalu Finance dan APM. Lihat [PROJECT_SUPPORT_APPROVAL.md](PROJECT_SUPPORT_APPROVAL.md).
- Preview/Word memakai data berizin yang sudah dimuat. Word BA KH dimuat sesuai kebutuhan dan dibuat lokal, tanpa query/mutasi server. Tidak ada tanda tangan yang dibuat otomatis. Lihat [BAKH_WORD_EXPORT.md](BAKH_WORD_EXPORT.md).

## Keputusan 7 November 2026: pengesahan tanpa referensi teks

Permintaan pengguna menghapus isian referensi bukti pada BA KH, SPK dan BA PP. Form, preview generik dan timeline tidak menampilkan referensi itu. Laravel serta domain React tidak lagi mewajibkan teks evidence. Ini perubahan syarat pengesahan dokumen Admin; tidak mengubah rantai approval atau menggantikan upload privat Pembayaran.

| Lapisan | Keputusan |
| --- | --- |
| Form dan command | Hapus input/notice referensi; attestBapp mengirim ID dan nomor |
| Domain PHP/TS | Abaikan input evidence usang, pertahankan nilai historis tersimpan; semua guard selain kewajiban teks tetap berlaku |
| SQL | Snapshot evidence dan kolom nullable evidence_reference dipertahankan untuk riwayat; BA PP baru tanpa referensi memproyeksikan NULL; tanpa migration atau backfill |
| Performa | Normalisasi murni tanpa query baru; Map BOQ/KHS sekali per render BA PP menggantikan pencarian katalog berulang |
| Audit dan keamanan | Lock, revisi, idempotensi, audit ID akun, Branch, snapshot terbit dan ledger tetap melalui layanan yang sama |

Regresi domain membuktikan nol query untuk matriks BA KH/SPK. Fixture HTTP memakai 51–58 query termasuk revision read dan dibatasi maksimal 60, tanpa write ulang tabel PP/BOQ/approval/berkas. Angka terbatas pada fixture kecil SQLite memory, bukan tolok ukur kapasitas MySQL. Rincian perilaku, backup, hasil tes dan batas verifikasi: [DOCUMENT_ATTESTATION.md](DOCUMENT_ATTESTATION.md), [bukti QA](qa/document-attestation-20261107.json).

## Keputusan 7 November 2026: pencarian ID Project sebelum negosiasi

Negosiasi Baru menggunakan pencarian ID Project → tabel PP → konfirmasi pilihan → form. Dua endpoint GET read-only melalui ActiveAccount/Branch menyediakan metadata pilihan maksimal 20 per halaman. NegotiationProjectCatalog memakai project_plans.branch_id, label snapshot PP dan batch saldo/alokasi; tidak membaca aggregate global, master KHS, arsip atau riwayat approval untuk mencari pilihan. Layanan pencarian 2 query, daftar PP 3–4 query, tanpa N+1. PP lanjutan memakai alokasi snapshot sendiri dikurangi penugasan BA KH, bukan saldo available root atau tabel proyeksi lanjutan yang belum menjadi sumber runtime.

Pencarian tidak memesan volume atau memberi kewenangan simpan. Lanjut Negosiasi menyegarkan state melalui endpoint yang sudah ada, dan command lama tetap memeriksa semua invariant dalam transaksi. Existing document/deep link mempertahankan PP yang dipilih, tanpa memindahkan dokumen. Tidak ada schema/migration/mutasi live. Shared boot/refreshState masih menggunakan aggregate Branch yang ada; perubahan ini membatasi katalog pencarian, belum mengganti pemuatan seluruh aplikasi. Contains-search JSON/LIKE dan performa serentak tetap batas verifikasi.

Rincian endpoint, UX, autentikasi, query-budget 120 PP, pemeriksaan baca saja MySQL dan batas browser: [NEGOTIATION_PROJECT_PICKER.md](NEGOTIATION_PROJECT_PICKER.md), qa/negotiation-project-picker-20261107.json.

## Keputusan 7 November 2026: tindak lanjut SPK pada daftar BA KH

Kolom Tindak Lanjut SPK diturunkan di React dari BA KH dan SPK tersimpan yang sudah dimuat dalam state berizin. BA KH Sah tanpa SPK memunculkan Menunggu Pengajuan SPK dan tautan Buat SPK untuk Admin/Superadmin. SPK yang ada menampilkan status/nomor dan tautan dokumen yang tepat; tidak menawarkan pembuatan duplikat. Tidak ada status baru yang dipersistensikan, query/endpoint/migration, perubahan domain atau transaksi live. Link memakai alur SPK yang sudah tervalidasi, bukan auto-submit/pengesahan.

Indeks Map PP/BA KH/SPK sekali per render menggantikan find koleksi per baris; relasi memakai ID BA KH, bukan kode Project bersama. Tes markup mencakup status, role, dua PP pada satu ID Project, sumber immutable dan larangan find per baris; bukan pengukuran latensi atau browser. Detail dan batas: [NEGOTIATION_PROJECT_PICKER.md](NEGOTIATION_PROJECT_PICKER.md), qa/contract-follow-up-20261107.json.

## Keputusan 7 November 2026: surat SPK Mandor dan Word native

Preview SPK utama memakai SpkDocument dengan model spk-content dari prop berizin PP/BA KH/SPK, sebelum lookup root/KHS generik. Word mengisi slot teks pada paket salinan template sumber yang sudah disiapkan, mempertahankan header/logo/font/style/halaman dan tabel native, tanpa nama/tanggal/nilai contoh atau tanda tangan rekaan. Identitas dan periode memakai SPK tersimpan/form; harga berasal dari kesepakatan Mandor, dengan total pembulatan domain tetap sama.

Generator/template dimuat saat unduh (sekitar91,32kB/gzip13,22kB), ZIP bersama kini mendukung gambar biner. Logo asli dilayani sebagai aset statis publik dengan resolusi prefix Apache subfolder; satu GET logo saat unduh, tanpa request data bisnis/SQL baru. Tidak ada backend/migration/mutasi DB, snapshot/approval/harga/volume atau batas Branch yang berubah. Download membuat salinan lokal dan tidak menyimpan/mengesahkan transaksi. Runtime tidak bergantung pada sumber Downloads/Python/Word desktop.

Dua output aplikasi (normal dan panjang) lolos CRC/XML/relasi serta preservasi 8 bagian; render1+1 halaman diperiksa seluruhnya. Bukti 203 PHP/176 frontend/build, pemetaan, pembulatan sen, adaptasi whitespace serta batas browser/Microsoft Word/cetak: [SPK_TEMPLATE_WORD.md](SPK_TEMPLATE_WORD.md), qa/spk-template-word-20261107.json. Angka bundle bukan klaim beban/latensi umum.

## Pencatatan setiap pembaruan

Setiap fitur/perubahan berikutnya harus mencatat dampak pada otoritas domain, aliran command/read, tabel/snapshot/berkas, otorisasi/concurrency dan biaya query/lookup. Jika tidak berubah, tulis secara eksplisit bahwa tidak ada perubahan arsitektur/query beserta alasan dan bukti yang relevan. Catat keputusan ringkas di sini atau tautkan dokumen perilakunya; tambahkan audit kronologis pada CHANGELOG.md, WORKPLAN.md dan hasil nyata di LARAVEL_QA.md. Jangan menimpa keputusan historis atau mengklaim pengujian browser/load/cetak yang belum dilakukan.

Seluruh artisan test harus tetap fail-closed pada SQLite :memory:, termasuk ketika konfigurasi tercache. Backup its sebelum perubahan workflow; jangan menjalankan pengujian/migration/reset atau menanam data contoh pada database pengguna tanpa otorisasi yang sesuai. Aplikasi Dompis Prov tidak termasuk ruang lingkup proyek ini.

## Keputusan 7 November 2026: grid Lampiran SPK dan volume komponen

Model presentasi spk-appendix-content mengindeks metadata BOQ root/KHS sekali, lalu mengambil qm/qs/pm/ps hanya dari kesepakatan SPK yang dipilih. SpkAppendixTable dipakai pada form dan preview khusus yang mendahului kalkulasi generik; tidak ada API/SQL/perintah baru. Grid sebelas kolom memuat Nama Proyek/Vol, Total Vol, nilai M/J/Jumlah dan recap template. Volume M/J berbeda menghasilkan dua baris komponen, tanpa agregasi lintas unit atau pergeseran harga/volume/ledger; pembulatan sen konsisten dengan surat utama. Penandatangan dari snapshot SPK dan TTD fisik kosong. Landscape terbatas pada lampiran, portrait main SPK/Word serta list BA KH tanpa garis dipertahankan.

Review query:0 tambahan; Maps menggantikan nested find form, dibuktikan fixture1000 baris tanpa find per baris. Tidak ada perubahan penyimpanan/schema/workflow/live its.203 PHP/182 frontend/build lulus; browser localhost ditolak, visual/responsif/print belum diverifikasi. Pemetaan lengkap, sumber workbook SHA dan batas: [SPK_APPENDIX_TEMPLATE.md](SPK_APPENDIX_TEMPLATE.md), qa/spk-appendix-template-20261107.json.

## Keputusan 7 November 2026: Head PP menjadi sumber penandatangan SPK baru/draf

BakhSigner::projectIdentity/projectHeadIdentity dipakai bersama BA KH dan SpkSigner/syncSpkSigner. Kebijakan snapshot SPK berdiri sendiri: baru/draf v3 memakai Head terpilih dari PP tepat yang direferensikan BA KH (keputusan matchingID→signatureUsers matchingID→akun terpilih), sedangkan Terbit/Sah tetap identitas tersimpan. Backend menimpa incoming signer/position/identity dan menyimpannya pada work_orders.document_data, proyeksi kontrak serta audit/receipt atomik yang sudah ada; tidak mengubah arsip/schema. Missing NIK tetap kosong, missing valid Head menolak formal issuance, dan tidak ada identity lookup berdasarkan nama/global Head.

Preview/Word memakai props SPK yang disinkronkan tanpa save pada read. Header lampiran langsung nama kegiatan/VOL. Surat tetap source-derived portrait dengan slot NIK; ekspor lampiran native landscape menggunakan model tabel sama dan helper word-ooxml yang diekstrak dari BA KH. Lazy chunks terpisah, semua teks di-escape, tidak ada arbitrary path/network, tanda tangan rekaan atau command saat download.0 tambahan SQL resolver,HTTP fixture≤60 query termasuk revisi,1000-row indexed export; ini bukan klaim throughput umum.208 PHP/187 frontend/build/Pint lulus;4 output Word dirender dan7 PNG diperiksa. Detail backup,SQL mapping,ukuran chunk dan batas browser/Word desktop/cetak: [SPK_SIGNER_WORD.md](SPK_SIGNER_WORD.md),qa/spk-head-word-20261107.json.

## Keputusan 7 November 2026: pemilihan sumber pembayaran per ID Project/SPK Sah

Pengajuan Baru memakai dua GET metadata pada PaymentProjectCatalog: Project group lalu20 SPK Sah perhalaman. Workspace dan project_plans.branch_id membatasi group, sibling matching, counts dan listing;projects.branch_id tidak menjadi otoritas. Querysearch2,list4 dengan batch≤60term dan nilai SQL dua tingkat (round per sumberBOQ→sum kontrak),tanpa per-rowSQL/arsip/master/berkas/actuals/approvalhistory. Metadata adalah routinghint, bukan izin create; kontrol domain tidak dipindah ke client.

PaymentSelection PHP/payment-selection.ts dengan13vectors memilih create/open/blocked. Newsource memerlukan pilihan/summary eksplisit; existingDraft/returned/rejectedv3/active/Finance/APM/Final langsung dibuka. Store startPaymentFromSpk me-refresh stateberizin, re-evaluasi SPK/term/approver,gates lama lalu memakai createTerm dan menunggu konfirmasi; onerror tidak navigate ke IDoptimistis, retries usai responsehilang membuka existing. Backend lock/revision/receipt tetap mencegah duplikasi; tidak mengubah aturan approval/penolakan/Finance/APM/volume/status historis atau schema.

Helper offline berindeks dan transport dipisah dari core routing untuk menjaga UIpencarian di chunkPayments. Evidence215PHP/196frontend/build/Pint,120orderquerybudget dan1liveProject/1liveSPK readonly2/4queries. Sharedbootstrap/refresh aggregate belum diubah;manualbrowser/responsif/loadtest belum. Pemetaan API/data/backup dan batas: [PAYMENT_PROJECT_PICKER.md](PAYMENT_PROJECT_PICKER.md),qa/payment-project-picker-20261107.json.


## 16 November 2026 — Penandatangan Mgr Finance pada kasbon lama

Pengguna mengonfirmasi bahwa preview yang masih menampilkan Mgr Business Support berasal dari pembayaran lama. Pull/migration sebelumnya tidak mengganti snapshot historis tersebut. Kini Admin Konstruksi, Superadmin atau Finance dapat membuka **Preview Kasbon** versi aktif, memilih akun aktif **Mgr Finance & Settlement** pada Branch PP yang sama, lalu **Simpan Penandatangan** dengan konfirmasi YA/TIDAK. Preview, cetak dan Word menggunakan nama akun yang dipilih serta jabatan Mgr Finance & Settlement Area Jawa Bali. Pilihan ini hanya penandatangan fisik; tidak membuat cap/keputusan approval baru atau memindahkan keputusan Business Support ke Finance.

Berlaku untuk kasbon-v1 berstatus Disetujui, termasuk yang telah lunas. Versi arsip, pengajuan berjalan yang belum Disetujui dan alur tujuh approval tidak dapat dialihkan melalui kontrol ini. Snapshot asli finance_kasbons tetap terenkripsi dan tidak ditulis ulang; annotation server-owned kasbonPrintSigners per UUID kasbon dan kasbonPrintSignerHistory before/after/pelaku/waktu disimpan dalam payment_terms.document_data melalui lock/revision/receipt/audit yang sudah ada. Nominal bruto/net, paid, APM, penerima/rekening, bukti transfer, approval dan prototype_workspaces.state tetap. Versi kasbon baru tidak mewarisi pilihan versi sebelumnya. Nama yang telah dipilih tidak berubah otomatis ketika akun diganti namanya. Pembuatan kasbon baru tetap mengikuti tujuh approval yang berlaku; teks bantuan form kini membedakan template baru dan historis.

Implementasi menggunakan users/Branch/relasi yang sudah dimuat, satu lookup per koleksi saat aksi, tanpa query per baris rincian atau endpoint/schema/migration baru. Lazy private read tetap3query; metadata-only HTTP fixture35query (batas50), tanpa write finance_kasbons/APM/approval/actual/volume/PP. Full aggregate/reference loading pada command tetap ada; angka ini bukan benchmark beban MySQL. Simpan menunggu konfirmasi server dan memuat ulang dokumen privat sebelum preview/unduh ditampilkan kembali.

Verifikasi:306PHP/6887assertions,313frontend,TypeScript/Vite,assets36manifest/28preload×3profil dan Pint scoped lulus. Regresi paid/unpaid, role/Branch/akun nonaktif, stale revision/UUID replay, versi arsip/reissue, Head lock, larangan alur tujuh approval, original ciphertext/settlement/archive unchanged serta HTML/Word memakai identitas yang sama tanpa approval palsu. Dua DOCX sintetis untuk template lama penerbit Finance dan Admin lulus ZIP/CRC/XML independen, render1halaman masing-masing; seluruh PNG diperiksa dan PDF render berisi. Interaksi akun browser/server aaPanel, fresh browser print, Microsoft Word desktop dan cetak fisik belum diverifikasi. Backup SQLite demo dibuat privat sebelum perubahan; its aktif/.env/Dompis/server tidak diubah. [Bukti QA](qa/legacy-kasbon-signer-20261116.json).

Penerapan perubahan ini sendiri tidak memerlukan migration baru. Setelah kode diterapkan dan frontend dibangun ulang pada server, buka pembayaran lama melalui akun berwenang dan pilih penandatangan pada preview kasbon aktif. Tidak ada penggantian otomatis massal pada pembayaran yang telah tersimpan.


## 16 November 2026 — Alihkan payment lama dari Finance ke Mgr Finance

Pengguna meminta pengajuan lama yang belum disetujui Finance dipindahkan dahulu ke inbox Mgr Finance. Finance/Superadmin kini dapat memilih maksimal50 kasbon-v1 yang memenuhi syarat melalui tombol **Alihkan Payment Lama ke Mgr Finance** pada Inbox Approval, mencari ID Project/LOP/PP/Branch (20baris/halaman), memilih akun aktif yang mencakup semua Branch, lalu konfirmasi YA/TIDAK. Pemindahan atomik dan eksplisit; tidak otomatis saat deploy. Lima approval pekerjaan/SQL lama tetap, sementara review tambahan kasbon aktif harus disetujui akun Manager yang dipilih sebelum Finance dapat melanjutkan approve/upload transfer/APM/lunas. Kasbon dikembalikan dengan catatan wajib ke Admin dan versi baru memerlukan review Manager kembali, tanpa mengulang lima approval pekerjaan historis.

Metadata penugasan/keputusan aktual dan riwayat disimpan server-side pada payment_terms.document_data melalui lock/revision/receipt/audit. Inbox/count, List Payment, timeline dan kasbon aktif mengikuti marker; cap Manager hanya dari keputusan nyata dengan UUID kasbon cocok, Word/cetak TTD tetap kosong. Original ciphertext/rekening/nominal/paid/APM/proof/approval_runs/steps/decisions/volume/archive tidak diubah. Tolak status/version/Branch/role/nonaktif/duplikasi/Head lock, pengajuan tujuh approval, Finance sudah Setujui, proof/APM/pembayaran dan queued proof. Tidak ada migration baru/live its/.env/Dompis/server write. [Aturan, data, deployment dan query](LEGACY_FINANCE_ROUTING.md).


Verifikasi akhir alih inbox:310PHP/7137assertions,318frontend,TypeScript/Vite,Pint dan assets36manifest/28preload×3profil lulus; satu DOCX/1halaman dirender/diperiksa, cap hanya preview. Query single36/batch8=51, batas50/120; list tetap4query. Tidak ada private_data query per target atau perubahan proyeksi lima approval/ledger/nominal lama. Browser akun/server/MySQL load/Word desktop/cetak fisik belum diverifikasi. [Bukti](qa/legacy-finance-routing-20261116.json), [catatan QA](LARAVEL_QA.md).


## 17 November 2026 — Ikon Unduh Semua Dokumen

Presentasi `DocumentPackageButton` memakai Lucide `Download`16 dari import existing; seluruh pemakai komponen mendapat ikon yang sama. Tidak ada dependency, SQL/API, model, workflow atau state baru. Build TypeScript/Vite dan pemeriksaan aset lulus; browser/server tidak diuji ulang.


## 17 November 2026 — Matriks Pengajuan dan Terbayar serta dashboard per peran

`dashboard-matrix` memproyeksikan requested=bruto canonical tiap term Diajukan/Disetujui dan paid=nominal aktual Disetujui pada SPK Sah. Model/total/detail contributor dibagi, tanpa sum history atau reprice. `dashboard-detail` memfilter sumber per metric/scope dan modal20baris. CLOSE tetap settlementRecorded/paymentDue, root BOQ dedupe tidak berubah. `dashboard-summary` membangun orderDeals Map dan satu kalkulasi gross perterm; pending reuse canApproveTerm/canReviewLegacySettlement/financeTaskPending. Dashboard memprioritaskan role SQL tanpa mengubah visible()/Branch SQL permission atau menambah private reads. Scoped fixedcolgroup8/header2rows/grid/rightalign/horizontal panel overflow dan responsive mini values.0newSQL/API/dependency/schema/migration/livewrite; bind5query/1join fixture200masters dan indexed1000row tetap, bukan loadtest.311PHP/7241assertions,341frontend/build/assets/Pint/browser sintetis6screen+interaksi lulus; server/akunlive belum. [Aturan dan bukti](DASHBOARD_MATRIX.md).


## 17 November 2026 — Keterangan PPh dan label pembayaran Final satu kali

`KasbonAmountNotice` merupakan UI informasi dari quote/saved summary, terpisah dari KasbonDocument/nativeWord, tanpa private read tambahan. FinancePanel gross dihitung sekali; versi lama tanpa policy tetap nominal tersimpan. `payment-labels` helper pure O(1) dipakai editor/list/inbox/Finance review/dashboard/report dan alias BARP single-Final; hanya Final1 menyembunyikan penomoran. Editor tidak merender tiga sel historis/field Nomor Termin untuk Final1, tanpa memutasi termVolumeData. BA PP memakai satu kolom aktual untuk Final1; query/ledger/approval/domain commands tetap. Card next-term unavailable hilang, tetapi Finance legacy tetap dirender. Kata Termin pada route/kind internal dan teks audit asli tidak diganti. Kasbon filename Final1 berubah presentasi saja, path unggahan tetap. Tambahan0SQL/API/schema/dependency/migration; 311PHP/7241assertions,345frontend/build/assets dan browser sintetis lulus. [Aturan terbaru](KASBON_PPH_PAYMENT_LIST.md).


## 17 November 2026 — Material BOQ Mandor masuk Revenue pada margin PP

ProjectPlanBudget::apply menerima adopsi policy eksplisit dari saveProject v3 dan continuation; menambah mandorM yang sudah terakumulasi ke ownerS untuk Revenue, lalu Gross Profit/rasio. JSON planBudget.marginPolicy membedakan rumus baru dan snapshot lama tanpa kolom uang/SQL baru. Caller maintenance default hanya mengikuti marker tersimpan, tanpa adopsi massal. draftPlanBudget/budgetSummary/Material row memakai policy yang sama; locked view tetap planBudgetTotals server. Tidak ada per-row query atau mutasi saat membaca. Query tambahan0; pure service0, HTTP dua komponen83 termasuk auth/reference/incremental writes/receipt/audit/response, budget90 dan master reads batched≤2.313PHP/7278assertions,346frontend/build/assets/Pint/browser sintetis lulus. Tidak menjalankan migration/live rewrite/deploy; Word/PDF render/server/load belum diuji ulang. [Rumus dan QA](PP_BUDGET_TEMPLATE.md).


## 17 November 2026 — Canonical selected Material Owner / Non Stock

ProjectPlanBudget explicit save/submit/continuation mengadopsi selected-owner-material-nonstock-v2. Validasi Mandor independen per komponen tetap; selection qtyM>0/priceM>0 mengikat sourceRowId, harga Owner menjadi Revenue dan harga Mandor menjadi Non Stock. Loaded KHS index memberi name/unit tanpa SQL baru/per-row lookup; maintenance memakai saved metadata. Totals.mandor tetap M+J, costs.mandor hanya Jasa; COGS/recap menjumlahkan Jasa+NonStock+BOP sekali. React draft/summary/readonly IV.2/shared preview mengikuti rumus; locked tables memakai saved totals/metadata/policy. Existing SQL snapshot/budget/cost projection dan atomic locks/revision/receipts/audit, tanpa schema/API/migration/live mutation. Auto500rows sesuai BOQ, manual historical200. [Detail dan evidence](PP_BUDGET_TEMPLATE.md).
