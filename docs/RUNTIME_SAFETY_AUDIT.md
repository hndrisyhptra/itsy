# Audit penyimpanan dan kinerja — 25 November 2026

Audit lokal atas permintaan pemeriksaan semua fitur, keamanan penyimpanan, dan bottleneck. Dua masalah yang dapat direproduksi diperbaiki. Database aplikasi aktif, data pengguna, `.env`, APP_KEY, worker aktif, server aaPanel, dan proyek Dompis tidak diubah. Tidak ada migration baru, reset, seeding live, commit, push, atau deployment.

## Perbaikan

### Penyimpanan volume BA RP

`RelationalWorkflowStore` sebelumnya memeriksa keberadaan komponen dan melakukan update-or-insert satu per satu. Penyimpanan 500 baris/1.000 komponen pada fixture HTTP terautentikasi memerlukan **3.038 query**, termasuk **3.001 query detail komponen**. Lookup sekarang dibatasi ke kontrak yang tepat dan dibagi maksimum 500 ID; upsert detail maksimum 100 baris memakai unique key `(term_id, contract_component_id)` dan hanya memperbarui `actual_quantity`. Hasil fixture yang sama menjadi **50 query HTTP / 13 query detail**. Ini jumlah query, bukan klaim peningkatan kecepatan server dengan faktor yang sama.

Uji memastikan 1.000 detail terbaca kembali persis, nilai nol tetap, penghapusan satu baris menyisakan 998 detail dengan seluruh ID lama tetap, dan kontrak/saldo/keputusan/APM tidak berubah. Kegagalan yang dipicu pada batch selanjutnya membatalkan parent JSON, detail, revisi, receipt, dan perubahan terkait dalam transaksi yang sama. Jalur actuals identik tetap dilewati seperti sebelumnya. Presisi historis tidak dikonversi.

### Konfirmasi simpan draf harus membuktikan PP yang tepat

Regresi baru membuktikan receipt milik PP A sebelumnya dapat mengonfirmasi PP B yang juga terlihat oleh aktor yang sama. Pemeriksaan receipt dan akses saja belum membuktikan target perintah.

Server kini menyimpan `draftSaveReceipt` pada JSON PP yang benar, atomik dengan data/revisi/audit/receipt. Marker memuat UUID, ID PP, aktor, nama perintah, hash payload asli dan revisi; input marker dari browser dibuang. Hash payload dihitung sekali sebelum retry transaksi. Tidak ada kolom/tabel/query baru untuk marker ini.

Recovery GET existing tetap membaca receipt committed milik aktor/workspace/perintah yang sah dan graph yang telah dibatasi akses. Konfirmasi hanya diberikan jika marker PP cocok dengan UUID, target, aktor, perintah dan revisi receipt. PP lain, actor/scope lain, receipt tak dikenal, atau marker lama/hilang tidak menghasilkan konfirmasi. Replay draf dengan marker terkini memeriksa payload; isi berbeda dengan UUID sama ditolak 409. Pengujian legacy marker-less tidak mengubah perilaku replay historis menjadi mekanisme baru.

Marker hanya menyimpan bukti simpan draf terakhir pada PP tersebut. Receipt lebih lama setelah simpan PP berikutnya tidak otomatis dikonfirmasi. Receipt sebelum pembaruan tanpa marker juga tidak otomatis dikonfirmasi saat respons hilang; form tetap tersedia untuk pemeriksaan eksplisit. Respons simpan normal tetap berjalan, PP lama tidak perlu diajukan ulang. Tidak ada backfill/read-time write atau pengubahan approval/status/nominal historis.

Normal GET tetap 17 query dan recovery 18 query pada fixture: satu SELECT receipt terindeks tambahan, bukan satu query per PP. Browser tetap satu POST, maksimal satu recovery GET, tanpa retry POST otomatis atau poller baru.

## Cakupan dan hasil pengujian

| Pemeriksaan | Hasil aktual | Batas bukti |
| --- | --- | --- |
| Suite PHP sebelum/sesudah | 525 tes/15.397 assertion → **528 tes/16.441 assertion** lulus | XAMPP PHP 8.2.4, SQLite `:memory:`, final 119,516 detik/142,50 MB, process limit 512 MB |
| Frontend | **491 tes** lulus | Domain, komponen, formula dan semantik ekspor; bukan setiap interaksi akun nyata |
| Fitur suite | Akun/hak akses, PP, BOQ, BA KH/SPK, BA RP/BA PP, Construction/Operation approval, return/reset/Cancel, kasbon/PPh, pembayaran/private file, queue, master/import/PID/ledger, dashboard/timeline/export, Telegram fakes | Mempertahankan guard dan regresi existing; tidak membuktikan semua kombinasi produksi bebas error |
| MariaDB fisik terpisah | 10.4.28: actuals insert/update mempertahankan ID; SQL trigger failure membatalkan actuals/JSON/revisi/receipt; marker PP tepat; target PP lain tidak terkonfirmasi; hash private/paid fixture existing identik | Private socket/datadir, `skip_networking`, hanya fixture sintetis. Uji actuals fisik kecil; 500 baris diuji lewat SQLite HTTP. Controller confirmation fisik dipanggil langsung, middleware diuji pada suite HTTP |
| Browser simpan | Lima kasus: lambat, respons hilang dengan receipt benar, belum terkonfirmasi, validasi 422, target salah; satu POST, input dipertahankan saat gagal, 0 JS error | Actual App dengan API sintetis; respons lambat 1,4 detik bukan latency produksi |
| Browser data besar | 1.000 PP/deal/SPK/term; delapan route; 375/768/1440, terang/gelap; 0 JS error/root overflow, 0 write | API mocked/read-only, bukan uji concurrent load. Fixture BA PP kosong. List maksimum 20 DOM, screenshot PP 8 baris; pencarian maksimum 6 hasil |
| Build/aset | TypeScript/Vite lulus; 49 manifest references, 40 preload dependencies pada tiga profil | Artefak lokal, bukan cache/CDN/proxy aaPanel |
| Pemeriksaan sumber | Composer validate, syntax 187 file PHP aplikasi/config/migration, scoped Pint dan diff check lulus | Tidak menjalankan dependency security audit jaringan |

Screenshot daftar PP data besar, Dashboard Status Approval dan hasil recovery simpan diperiksa: tabel/tombol terbaca dan hasil canonical tersimpan muncul. Tidak ada perubahan layout/generator dokumen dalam audit ini; semantik ekspor tercakup suite, tidak merender ulang semua Word/PDF atau mencetak fisik.

MariaDB QA menggunakan root bertanda khusus `/private/tmp/area3-pid-stage4-20261124`, bukan socket/data MySQL aplikasi aktif. Tool `tools/qa_budget_pid_stage4.php` memverifikasi root/marker/socket/datadir/networking sebelum bootstrap dan menambah fase `audit-storage`. Instance privat telah dimatikan; proses selesai exit 0 dan socket/PID dihapus. Fase ini tidak boleh diarahkan ke database aplikasi aktif. PHPUnit tetap menolak database persisten.

## Batas dan penerapan

Runtime masih memakai graph workspace authorized dan lock transaksi workspace bersama. Pengurangan query actuals tidak menghapus seluruh biaya validasi/serialisasi/lock atau semua loop penulisan kontrak. Jangan menyatakan aplikasi bebas bottleneck tanpa profiling server dan beban bersamaan.

Tidak menguji aaPanel/PHP 8.4, OPcache, jaringan pengguna, batas unggahan webserver, real worker/Supervisor/cron/Telegram, transfer bank, backup/restore produksi atau beban concurrent produksi. Telegram/queue pada suite memakai fakes atau fixture terisolasi. Status server perlu dipantau setelah deployment; kegagalan eksternal tidak dapat dijamin hilang oleh audit lokal.

Deploy kode dan build aset sesuai prosedur existing; jalankan pemeriksaan aset. **Audit ini tidak menambah migration** dan tidak memerlukan perubahan `.env`/APP_KEY atau reset data. Migration fitur sebelumnya yang belum diterapkan tetap tanggung jawab operator sesuai panduannya. Restart hanya worker upload Area 3 bila masih menjalankan kode lama; jangan restart semua worker proyek lain, purge queue, atau menjalankan `migrate:fresh`/seed/reset.

Bukti terstruktur: [runtime-safety-audit-20261125.json](qa/runtime-safety-audit-20261125.json). Log/screenshot sementara berada di `/private/tmp/area3-audit-20261125-*` dan `/private/tmp/area3-runtime-audit-20261125/`; bukan artefak deployment.
