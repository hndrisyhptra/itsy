# Master Mandor — 26 November 2026

## Terbaru — pilih Mandor sejak Negosiasi & BA KH Baru

Pada **Penugasan Mandor & Lingkup → Nama Mandor**, klik dropdown lalu ketik nama Mandor terdaftar. Daftar memakai master bersama seluruh Branch dan unit, maksimal20opsi; nama lebih spesifik mempersempit hasil. Tombol panah/Enter memilih, Escape menutup. Pencarian berjeda350ms, permintaan lama dibatalkan dan hasil lama tidak dipakai. Pilihan kosong tidak mengambil Mandor pertama otomatis. Label foto lengkap hanya menunjukkan kedua foto sudah tersedia, bukan seluruh rekening lengkap.

Jika nama tidak ditemukan, muncul **Tambah Mandor Baru**. Klik→konfirmasi **YA/TIDAK**; YA membuka form nama/alamat/NIK wajib, rekening/foto dapat dilengkapi. **Simpan Identitas → YA → Selesai** memilih UUID Mandor yang benar-benar tersimpan. TIDAK tidak menambah data. Form BA KH baru memerlukan pilihan master sebelum SimpanDraft; isian Mandor pada InformasiDokumen menjadi hanya-baca agar konsisten. Identitas privat tidak dimuat saat memilih BA KH; draftbrowser hanya menyimpan nama/id/versi, bukan NIK/rekening/foto.

Saat BA KH disimpan, server memeriksa ulang akun/peran/aksesBranch+unitPP, UUID+versi master di bawah workspace lock. Nama dan `Deal.masterMandor{id,version}` berasal dari recordmaster; `mandorSelection`transien dan marker palsu dibuang. Tidak ada pencocokan otomatis berdasarkan nama. BA KH yang sudah mempunyai SPK mengunci pilihan; perubahan Mandor dilakukan melalui drafSPK yang memang diizinkan. Pengisian bebas historis/klienlama tanpa pilihan tetap kompatibel dan tidak dipaksa diadopsi pada GET.

### Identitas SPK otomatis

**Lanjut buat SPK** dari BA KH bertanda master menampilkan Alamat/NIK/rekening/bank/pemilik dan tombolpreviewfoto secara otomatis melalui satu detail privat yang diizinkan. NIK/rekening hanya di memori komponen; foto baru dibaca bila previewdiklik. SimpanSPK menunggu sumber identitas berhasil dimuat.

**SimpanDraftSPK pertama** menyalin profil dan file yang tersedia dari sumber UUID+versi BA KH menjadi encryptedpayeev1 dan immutableprivatefiles exactSPK. Penyimpanan SPK/relasi/filemetadata/profile/audit berada dalam transaksi workspace/revision existing; tidak memerlukan klikpilihulang diSPK. Rekening/foto basic masih boleh kosong pada Draft, tetapi **semua wajib sebelum pengajuan paket** melalui requirePayee existing. Isian Waspang/HP/tanggal dan lingkup/harga tidak diubah oleh sumbermaster.

Jika master berubah setelah BA KH disimpan, firstSPKsave ditolak409: bukaBAKH, cari/pilih ulang Mandor lalu simpanBAKH. Sistem tidak mengambil versi rekening lain secara diam-diam. SPK yang sudah tersimpan tidak menyalin ulang pada simpan biasa; Masterberikutnya/kasbonissued/pending/approved/paid tetap memakai snapshot tersimpan. Pilihan eksplisit di drafSPK memperbarui source BA KH+SPK secara bersamaan sesuai guardexisting.

### Penyimpanan, optimasi dan batas

Tidak ada tabel, migration, endpoint, dependency atau poller baru untuk perubahan ini. Master/schema000001–000003 dari fitur sebelumnya tetap dibutuhkan. SourceBAKH hanya JSONid/versi; audit `select-deal`/`inherit-spk` menggunakan mandor_changes existing. Sameactor+rawpayloadUUID replay diverifikasi terhadap audit sumber; percobaanulang tidak membuat profil/foto kedua. Jika gagal setelah penyalinan, hanya salinan yang terbukti tidak berelasi dibersihkan; saat statusDB tidak bisa dipastikan, file dipertahankan agar filecommitted aman. Sumbermaster dan foto lama tidak dihapus.

Pada fixture duaunit: saveBAKH dengan pilihanmaster61HTTPquery, firstSPK+2fotoinherit48HTTPquery; list105record9query. Existingnormal/unlinkedcommands tidak mendapat lookupmaster/source tambahan; pemeriksaan loadedgraph reuse, satu lookup sumber/filebatch saja, bukan perbarisBOQ. Tidak ada PII dalam workspace/boot/localStorage atau auditplaintext. Semuagates Branch/unit/role/revision/source/payee/Cancel/Head/pendingupload/wholevolume/money/PID/approval tetap.

**QA:** full562PHP/17468assertions, focusedmaster15/463/broadfocusedsequential109/4150 dan507frontend/build/assets53refs44preloads×3/Pint/diffcheck lulus. ActualAppmock35master/20dropdown/search1/bothunits/3width2theme/keyboard/missingYA-TIDAK/basicadd/BAKH→SPKautofill/stalesourcelink,0JSerror/root overflow, screenshot diperiksa. [Audit fitur ini](qa/bakh-master-mandor-20261126.json) memisahkan bukti SQLite memory/browsermock dari MySQL/server nyata. Operator deploysource+build/checkassets; **tidak ada migration baru atau restartworker khusus untuk perubahan BA KH ini**. Tidak ada perubahandataaktif/server/env/worker/Dompis/backfill oleh agen. Aturan historis “pilih hanya sesudahDraftSPK” di bawah tetap tersedia sebagai jalur eksplisit padaSPK, namun pilihanawal kini dapat dilakukan sejakBAKH.

## Koreksi Server Error saat Simpan Impor — 26 November 2026

User mengonfirmasi terjadi di lokal pada **Simpan Impor**. Log lokal menunjukkan MySQL `42S02/1146`: tabel **mandor_imports belum ada**. Kegagalan terjadi pada pembacaan receipt sebelum import menulis master/audit; bukan bukti koneksi putus dan bukan kesalahan isi Excel. Operator perlu menjalankan `php artisan migrate` dari folder proyek sampai `2026_11_26_000003_create_mandor_import_receipts` selesai, lalu coba Simpan Impor kembali. Agen tidak menjalankan migration pada its aktif.

Controller kini mengubah hanya missingreceipttable menjadi503/code `MANDOR_IMPORT_MIGRATION_REQUIRED` dengan pesan pembaruan database; tanpa SELECT metadata/schema tambahan, exception lain tetap diteruskan. UI tidak menambahkan petunjuk gangguan koneksi untuk kondisi yang sudah diketahui ini, validasi4xx atau pemeriksaanfile. Hint UUID tetap ada hanya untuk penyimpanan yang belum pasti akibat jaringan/5xx lain. File/preview/UUID tetap tersedia untuk percobaan eksplisit setelah migration; tidak ada POST otomatis, fallback tanpa receipt atau penghilangan audit.

QA koreksi: **39PHP/935assertions** (memory), **505frontend**, TypeScript/Vite/aset53refs44preloads×3/Pint/diffcheck lulus. Uji nyata memory down000003→commit503/0masterwrite→up→sameUUIDcommit/replay; MySQL exception disimulasikan, bukan DDL fisik. Fixture1000 tetap12preview/55commitHTTPquery. BrowseractualApp/API mock menguji preview1/commit503→sukses2 dengan UUID sama/0JSerror; screenshot diperiksa. FullPHP sebelumnya556/17318 tidak dijalankan ulang untuk koreksi terbatas ini; tanpa migration aktif, server/worker/env/Dompis atau render ekspor. [Audit](qa/mandor-import-migration-error-20261126.json).

## Aturan terbaru — impor Excel, aksi foto dan kelengkapan paket

Klarifikasi pengguna: **nama, alamat dan NIK KTP wajib untuk simpan/impor master; nomor rekening, nama bank, nama pemilik rekening dan kedua foto tetap wajib sebelum pengajuan paket PP + BA KH + SPK**. Aturan ini menggantikan kewajiban rekening pada simpan awal dan kewajiban kedua foto saat memilih master pada Draft SPK. Hak lima pengelola dan master bersama seluruh Branch/unit tetap.

- Aksi daftar selalu menyediakan **Edit** dengan tooltip. Bila salah satu foto belum ada tampil **Upload**; setelah kedua foto tersimpan tampil **Preview**. Kelengkapan foto bukan bukti kelengkapan rekening. Preview hanya-baca; file privat baru dibaca setelah klik preview foto.
- Tambah/edit awal boleh hanya mengisi tiga data wajib. NIK harus teks16digit, rekening bila diisi teks1–40digit. Foto tetap JPG/PNG/WebP maksimal10MB masing-masing, antrean existing dan persentase unggah.
- Master belum lengkap boleh dipilih secara eksplisit pada Draft SPK. Profil dan foto yang sudah tersedia disalin sebagai versi penerima SPK; referensi sumber menyimpan id/versi master. **Ajukan Paket** tetap memeriksa rekening/bank/pemilik/NIK/kedua foto exactSPK melalui `requirePayee`, untuk Konstruksi maupun Operation.
- SPK terhubung master menampilkan profil hanya-baca; ubah identitas/rekening/foto di **Master Mandor**, kemudian **Periksa Data Master Terbaru → Gunakan Mandor pada SPK** pada draf yang diizinkan. Simpan penerima/unggah foto langsung ke jalur SPK lama ditolak jika sudah terhubung master. SPK lama tanpa master tetap memakai jalur manual existing.
- Kasbon menampilkan sumber versi penerima master beserta NIK/rekening/bank/pemilik dari snapshot SPK. **Tidak ada sinkronisasi otomatis** ke SPK terbit, kasbon issued/pending/approved/paid, nama historis, harga, PPh, nilai transfer, approval atau ledger. Berkas yang sudah diterbitkan tetap memakai snapshotnya.

### Format Bulk Import

Gunakan **Template Excel / Bulk Import → Download Template Mandor**. Sheet `Data` memuat header berurutan:

| Kolom | Isian | Aturan |
|---|---|---|
| nama_mandor | wajib | nama, maksimal150karakter |
| alamat | wajib | maksimal1000karakter |
| nik_ktp | wajib | Teks16digit; pencocok unik perworkspace |
| no_rekening | opsional | Teks1–40digit bila diisi |
| nama_bank | opsional | maksimal100karakter |
| nama_pemilik_rekening | opsional | maksimal150karakter |

Template kosong asli XLSX, kolom NIK/rekening berformat Teks; pertahankan nol awal. Angka numerik Excel pada kolom identitas ditolak untuk mencegah kehilangan digit/nol; rumus tidak dihitung atau diterima pada import Mandor. Maksimal **1000baris /5MB**, batas ZIP32MB/20k sel; DTD/entity/macro/struktur taksesuai ditolak. Foto tidak diimpor lewat Excel.

**Periksa File → YA** membaca file tanpa businesswrite; preview nama/nomorbaris/aksi dengan pagination20baris. NIK baru menambah; NIK existing memperbarui nama/alamat serta kolom opsional yang diisi. **Kolom rekening/bank/pemilik kosong mempertahankan data lama**, bukan menghapus; pengosongan sengaja dilakukan lewat Edit. ID/origin/created_at/file lama tetap, record yang tidak ada pada file tidak dihapus. Duplikat NIK di dalam file atau satu baris tidak valid menolak seluruh file.

**Simpan Impor → YA** memeriksa kembali versi master di dalam transaksi/lock workspace existing. HMAC mengikat actor/workspace/bytefile/isinormal/recordid+version; perubahan master setelah preview memerlukan Periksa File ulang (409). UUID yang sama dengan actor/file/token sama mengembalikan receipt hasil commit sebelumnya; isi/actor berbeda ditolak. Konfirmasi jaringan terputus: pertahankan dialog/file lalu klik Simpan Impor kembali untuk UUID sama; tidak ada POST retry otomatis. Import sinkron terbatas1000, bukan job antrean baru; persentase upload tidak menyatakan jumlah baris sudah tersimpan.

### Penyimpanan dan optimasi tambahan

Migration baru **2026_11_26_000003_create_mandor_import_receipts** menambah tabel `mandor_imports` (PK UUID `id_mandor_import`, FKworkspace/actor, SHAfile, hashpayload, summary,timestamp) dan nullableFK `mandor_changes.import_id` + unique(import_id,mandor_id). Receipt impor dan audit perMandor disimpan atomik, audit sebelum/sesudah tetap terenkripsi; tidak menyimpan nama file sumber atau PII plaintext pada receipt. Rollback ditolak setelah terdapat impor. Migration disiapkan, **belum dijalankan pada its aktif oleh agen**.

Lookup NIK250/chunk, upsert master50/chunk dan insert audit50/chunk, tanpa query perbaris; hanya metadata workspace, tanpa membaca graph transaksi/revision. Fixture1000baris: preview12/commit55HTTPquery; list105record9query untuk semua ukuran10/20/50/100; select2foto54query kedua unit. Source readonly SPK memakai scalar JSON marker pada query authorization existing (0query tambahan). Tidak ada dependency, poller atau worker baru.

**QA terbaru:**556tesPHP/17318assertions, focused37/915,504frontend, TypeScript/Vite/assets53refs44preloads×3/Pint/diffcheck lulus. BrowseractualApp/mock6roles/375-768-1440/lightdark menguji Upload/Preview/Edit/impor45baris20-20-5/YA-TIDAK, sourceversion v3→v4 padaSPKyangsama di keduaunit, readonlyrekeningikutbaru/Waspangdirtytetap,0JSerror/root overflow. Screenshot diperiksa. Tidak ada pengujian fisik MySQL/MariaDBDDL/server/worker/desktopExcel/WordPDF baru. Lihat [audit impor](qa/master-mandor-bulk-20261126.json) untuk rincian dan batas pengujian. Bukti QA lama di bawah adalah riwayat sebelum koreksi ini. Penerapan operator harus menyelesaikan migration sampai **000003**, build/checkaset dan restart **hanya worker upload Area3**; tanpa reset/seed/backfill atau pengajuan ulang historis.

## Aturan terbaru — Mandor berlaku untuk semua Branch dan unit

Permintaan pengguna menggantikan pembatasan Branch/unit pada versi awal26November. **Satu Master Mandor bersama untuk seluruh Branch dan unit Konstruksi/Operation**, dalam workspace aplikasi autentikasi yang sama. Akun lima peran pengelola dapat mencari, melihat, menambah, mengedit dan mengunggah foto Mandor yang sama, tanpa memilih Branch/unit. Hak akses SPK/PP/kasbon tetap mengikuti penugasan dokumen pengguna; penggunaan Master Mandor tidak memberikan akses ke SPK milik Branch/unit lain.

## Penggunaan

Menu **Master Mandor** berada pada bagian Administrasi. Pengelola yang diizinkan: **Admin Konstruksi, Admin Operation, Admin Area, Officer 1 Operation, Superadmin**. Daftar master tidak dibatasi Branch/unit; hak mengelola master tidak memberi hak membuat SPK, menyetujui paket atau mereview pembayaran tambahan.

1. Klik **Tambah Mandor**, tanpa pilihan Branch atau unit.
2. Isi Nama Mandor, Alamat, NIK KTP16digit, Nomor Rekening1–40digit, Nama Pemilik Rekening dan Nama Bank. Dua kolom bank/pemilik rekening dipertahankan karena diperlukan kasbon.
3. **Simpan Identitas Mandor → YA**. Data sudah tersimpan meskipun foto belum lengkap; dapat dibuka kembali dari daftar.
4. Unggah Foto KTP dan Foto Buku Tabungan (JPG/PNG/WebP, maksimal10MB masing-masing) dengan konfirmasi YA/TIDAK. Persentase unggah dan status antrean memakai komponen/queue Area3 existing. Data siap digunakan setelah kedua foto benar-benar berhasil diproses.
5. **Edit Identitas** memungkinkan perubahan dengan versi baru. Perubahan berlaku pada master bersama, tanpa memperbarui otomatis dokumen yang pernah memakai Mandor tersebut. Tidak ada penghapusan master atau import/backfill data lama otomatis pada pembaruan ini.

Satu NIK unik **pada master bersama per workspace**; nama sama dengan NIK berbeda tetap dapat didaftarkan. Duplikasi NIK pada master bersama ditolak dan disarankan memakai/memperbarui record yang ada. Daftar hanya memuat nama dan kelengkapan foto, tanpa kolom/filter Branch atau unit. Identitas lengkap/rekening hanya dibaca saat membuka detail atau memilih Mandor. Pencarian nama berjeda350ms, SQL pagination10/20/50/100(default20), total sesuai filter; tidak memuat seluruh master ke boot/workspace/localStorage.

## Pemilihan pada SPK

Simpan **Draft SPK** terlebih dahulu. Pada kartu **Pilih Mandor dari Master**, ketik minimal2huruf nama. Pencarian memakai master bersama seluruh Branch dan unit, maksimal20hasil; perjelas nama bila hasil lebih banyak. Tidak memilih record pertama atau mencocokkan nama secara otomatis.

- **Pilih / Lihat** memuat Nama, Alamat, NIK KTP, Bank, Rekening, Pemilik Rekening serta tombol preview kedua foto.
- Jika hasil kosong, tampil keterangan nama belum ada dan **Tambah Mandor Baru → YA/TIDAK**. YA membuka form master bersama yang sama, tanpa input Branch/unit. Simpan identitas dan unggah kedua foto; setelah **Selesai**, record terpilih dapat digunakan.
- **Gunakan Mandor pada SPK → YA** menyimpan Nama Mandor pada **BA KH Draft terkait**, Alamat pada SPK Draft serta versi baru penerima SPK (NIK/rekening/bank/foto). Isian SPK lain yang belum disimpan tetap berada di form; alamat mengikuti pilihan master terbaru.
- Foto disalin menjadi berkas privat milik SPK yang tepat, bukan URL publik atau pointer hidup ke master. Snapshot profil menyimpan id/versi sumber master terenkripsi; `SPK.masterMandor` hanya id/versi nonPII. Kelengkapan kasbon tetap memakai validasi penerima SPK existing.

Pemilihan hanya pada PP Draft/revisi, BA KH Draft dan SPK Draft, sebelum approval dan BA RP/pembayaran. Server memeriksa kembali exactSPK→BAKH→PP, current revision/master version, active actor dan akses Branch/unit **dokumen SPK**, Cancel/Head correction dan pending upload. Dokumen terbit/Sah, paket dalam approval atau SPK yang telah memiliki BA RP/pembayaran tidak diganti dari master. Jalur historis pengisian penerima SPK existing tetap tersedia dengan guard lamanya; fitur ini tidak memaksa adopsi master.

**Perubahan master berikutnya tidak mengubah SPK, berkas atau kasbon yang sudah tersimpan.** Rekening/foto kasbon terbit/pending/approved/paid tetap mengikuti snapshotnya. PP/BOQ/harga/budget/PPh/approval/payment/volume ledger tidak diubah oleh CRUD master.

## Tabel dan penyimpanan

| Tabel | Primary key | Fungsi dan relasi |
|---|---|---|
| mandors | id_mandor(UUID) | workspace FK, nama, keyed HMAC NIK untuk dedup global, origin branch/unit lama nullable (bukan pembatas akses), profil terenkripsi, currentfoto IDs, version, actual actor/timestamps |
| mandor_files | id_mandor_file(UUID task) | FK mandor_id; private storage path, SHA256/MIME/size/extension dan actual uploader; berkas lama dipertahankan |
| mandor_changes | id_mandor_change(UUID request) | FK mandor_id dan actor, action/save/upload/select, actor+payload hash, before/after terenkripsi; receipt replay |
| mandor_payee_profiles(existing) | id_payee | FK SPK, versi penerima terenkripsi yang disalin dari master pada pilihan eksplisit |
| finance_private_files(existing) | id_finance_file | Foto salinan berelasi exactSPK; sumber kasbon tetap divalidasi existing |

Migration **2026_11_26_000001_create_mandor_master** hanya menambah tiga tabel beserta indeks/FK; tidak ALTER/drop/backfill tabel transaksi. Target dijaga Area3MigrationTarget (MySQL/MariaDB `its` atau SQLite`:memory:` testing). Rollback menolak bila master/berkas/audit sudah berisi. Agen tidak menjalankan migration pada its aktif.

Metadata master memakai workspace lock existing serta current row version; receipt UUID terikat actor+isi. Apply menyalin kedua file/profil lalu perintah internal `_selectMasterMandor` dan audit dalam satu transaksi/revision. Endpoint perintah umum tidak menerima internal command; field `masterMandor` klien diabaikan saat saveSPK, sumber server dipertahankan. Jika transaksi gagal, file salinan yang belum berelasi dibersihkan; file yang sudah committed tidak dihapus. Histori approval dan transaksi lama tidak direkayasa.

NIK/alamat/rekening/bank dan auditbeforeafter memakai Crypt/APP_KEY existing, tidak dicatat plaintext di list, boot, workflow state, localStorage atau log. Nama dan alamat SPK tetap field dokumen seperti sebelumnya. Foto master berada di disk `local` **mandor-private/{workspace}/{mandor}/...** di luar public; file SPK pada finance-private existing. Streaming foto master hanya lewat endpoint login dengan pemeriksaan peran/workspace ulang; salinan foto SPK tetap memeriksa akses Branch/unit dokumen, MIME/nosniff/private-no-store/same-origin frame. Jangan mengganti APP_KEY untuk menerapkan fitur ini.

API master bersama: GET/POST `/api/mandors`, lazyGET `/api/mandors/{uuid}`, private `/api/mandors/files/{uuid}`, queuedPOST `/api/mandors/{uuid}/uploads/{master-ktp|master-bank}`, explicitPOST `/api/finance/spk/{sourceSPK}/mandor`. CSRF/session/ActiveAccount/throttle existing berlaku. Queue menambah dua kind pada `upload_tasks` existing; user asli, akses terkini, byte hash dan isi foto diperiksa ulang saat worker berjalan. Admin Area/Officer1 Operation hanya memperoleh upload master, bukan BOQ/payee/payment upload baru.

## Query, QA dan batas

Fixture105Mandor: list HTTP **9query** (versi awal10), sama untuk10/20/50/100baris; tidak SELECT private_data dan tidak perrowquery. Pemilihan beserta fullworkspace response **54HTTPquery** pada Konstruksi maupun Operation, dua file dan satu profil baru. Master CRUD/worker upload tidak membaca fullgraph/arsip atau mengganti workflow revision. Apply memakai satu relational command existing; tidak mengirim fullmaster di workspace atau menambah polling selain pemantauan upload existing. Ini ukuran fixture, bukan bukti latensi/bebas bottleneck pada server produksi.

**Bukti koreksi terbaru:**548tesPHP/17098assertions dan502tesfrontend lulus, TypeScript/Vite/assets52refs43preloads×3/Pint/diffcheck; focusedMaster10/283. Uji menggunakan satuUUIDMandor pada2Branch/2unit, aksesSPKdi luarpenugasan tetap404, preservasi row/hash/file/audit/receipt/paid, duplicateDDLrefusal/rollback/FKenforcement. BrowseractualApp/mock6roles/105record/3width2theme memastikan form/filter/query tanpaBranchunit, tambah2foto/pilih/autofill/isianlainretained dan0JSerror/root overflow; screenshot diperiksa.

**Bukti versi awal:**546 tes PHP/17055 assertions dan502 tes frontend lulus sebelum koreksi global. Bukti koreksi terbaru dicatat di audit terpisah di bawah; beserta TypeScript/Vite/assets52refs43preloads×3/Pint/diffcheck. Focusedmaster8/240 dan migration+master22/551 lulus. Browser actualApp/mock API menguji enam peran, tambah dari SPK dengan2foto, pemilihan/autofill, isian SPK lain tetap, YA/TIDAK, paging105record, previewprivat serta375/768/1440 light/dark tanpa JSerror/root overflow; screenshot diperiksa.

Pengujian dan batas aktual dicatat pada [LARAVEL_QA](LARAVEL_QA.md) dan [audit versi awal](qa/master-mandor-20261126.json) dan [audit master bersama](qa/master-mandor-global-20261126.json). Data uji sintetis, SQLite memory dan browser API mock dipisahkan dari bukti fisik server. Tidak ada migration/import/backfill/worker/env/server/Dompis/commit/push/deploy yang dijalankan pada data aktif oleh agen.

## Migration lanjutan master bersama

Migration **2026_11_26_000002_share_mandor_master_across_units** diperlukan baik setelah000001 yang sudah diterapkan maupun pada database baru. Migration awal tidak diganti/dijalankan ulang untuk menghapus data. Kolom origin `branch_id`/`unit` menjadi nullable; nilai asal lama tetap utuh sebagai provenance, bukan akses. Record baru mengisiNULL. Indeks `mandor_workspace_nik` memberi keunikan NIK perworkspace dan `mandor_workspace_search` mendukung pencarian nama. ID, versi, file, ciphertext, audit dan semua snapshot SPK/kasbon tetap.

Bila terdapat NIK ganda dari versi awal lintas Branch/unit,000002 berhenti **sebelum perubahan struktur** dengan pesan rekonsiliasi; tidak menghapus, menggabungkan identitas, menunjuk ulang SPK atau memilih rekening pemenang secara otomatis. Uji retry pada data nonduplikat memeriksa hash semua row master/file/audit tetap dan receipt versi lama masih replay dengan akun/isi yang sama. Rollback000002 ditolak bila ada master bersama dengan originNULL. SQLite memory melakukan perubahan parent dengan penundaanFK sementara selama rebuild, pemeriksaan `foreign_key_check` dan pemulihan immediacy; FK enforcement tidak dinonaktifkan. MySQL/MariaDB fisik untuk000002 belum diuji.

Parameterunit/branch dari klien lama diterima opsional tetapi **tidak memfilter, menentukan akses atau memberi origin pada record baru**. Akses master tetap aktif/peran/workspace; hanya penggunaan pada SPK yang dibatasi hak dokumen. PII/rekening/file lama tidak ditulis ulang ketika membaca master atau menjalankan000002.

## Penerapan oleh operator

Setelah backup dan kode tersedia pada folder aplikasi:

```sh
php artisan migrate --force
npm run build
npm run check:assets
```

Jalankan migration sampai000003 selesai sebelum menggunakan fitur impor; data lama tidak perlu diunggah ulang. Mulai ulang **hanya worker upload Area3** agar mengenali `master-ktp/master-bank`. Pada Supervisor aaPanel yang sudah ada:

```sh
supervisorctl restart area3-uploads
supervisorctl status area3-uploads
```

Jika worker lokal sedang berjalan dari terminal, hentikan proses Area3 itu dengan Ctrl+C lalu jalankan lagi perintah worker Area3 yang biasa digunakan. Jangan restart semua program Supervisor/worker proyek lain. Tidak perlu ENV, queue/worker baru, fresh/reset/seed, import ulang PP/BOQ atau pengajuan ulang dokumen historis. Reload halaman dan uji satu Mandor yang sama pada SPK Draft Konstruksi/Operation dan Branch berbeda yang memang dapat diakses akun. [Worker existing](SERVER_UPLOAD_WORKER.md).
