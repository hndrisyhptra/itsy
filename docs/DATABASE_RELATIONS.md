# Buku saku relasi database its Area 3

## Tambahan audit konversi — 12 November 2026

Migration000001a menambah currency_conversion_runs (PK id_currency_run; conversion_key unik) dan currency_conversion_changes (PK id_currency_change; run_id→currency_conversion_runs.id_currency_run). Keduanya mencatat konversi uang satu kali dengan before/after terenkripsi, tanpa mengganti tabel workflow. [Aturan, kolom dan bukti](TRUNCATE_STORED_MONEY.md). Buku PDF/Word lama tetap snapshot arsitektur pada tanggal penerbitannya; belum diregenerasi untuk tambahan ini.

Edisi 5 November 2026. Struktur diperiksa langsung dari metadata database aktif **its** dalam transaksi baca saja: **61 tabel, 497 kolom, 61 primary key, dan 106 foreign key**. FK gabungan dihitung satu relasi. Tidak ada pembacaan isi akun/transaksi atau perubahan database dalam pekerjaan dokumentasi ini.

Tambahan alur setelah penyusunan buku: pengajuan PP/termin versi 3 yang ditolak kini dapat diperbaiki Admin dan diajukan ulang dari PM. Struktur tabel/106 FK tetap sama; baca [aturan penolakan dan pengajuan ulang](APPROVAL_RESUBMISSION.md) bersama buku untuk rincian perilaku terbaru.

## Hasil

- [Buku saku PDF ukuran A5](../output/pdf/Buku-Saku-Relasi-Database-its-Area-3.pdf), 42 halaman.
- [Buku saku Word](../output/documents/Buku-Saku-Relasi-Database-its-Area-3.docx), teks dapat disunting.
- [Visual dan katalog HTML](database/area3-relasi-tabel.html), bekerja offline; pencarian nama tabel/kolom/fungsi, tipe data, NULL, UNIQUE, serta FK masuk dan keluar.
- [ERD Mermaid lengkap](database/area3-relations.mmd), seluruh 61 tabel dan 106 FK aktual, termasuk FK gabungan dan relasi opsional. Berkas sumber diagram; perlu renderer Mermaid untuk menampilkannya sebagai satu ERD.
- [Metadata dan penjelasan tabel](database/schema-catalog.json), tanpa isi baris bisnis/kredensial.
- Diagram ringkas dalam [folder diagrams](database/diagrams), masing-masing SVG dan PNG.

Buku menjelaskan wilayah/akun, designator/harga, Project dan PP, BOQ, Mandor/BA KH/SPK, Pembayaran, lima approval, eviden pekerjaan, scan TTD, Finance/APM, BA PP, volume, workspace, audit, tabel impor yang disiapkan, dan infrastruktur Laravel. Contoh SQL hanya SELECT.

## Membaca diagram dan status

Sembilan diagram ringkas memilih FK utama supaya tetap terbaca pada ukuran A5. Panah menunjuk anak ke induk; garis putus-putus menunjukkan hubungan logis tanpa FK. Daftar FK lengkap tetap ada pada katalog. PK/FK serta keunikan/opsionalitas yang lengkap dapat ditelusuri pada HTML/metadata/ERD Mermaid.

Klasifikasi berdasarkan kode runtime, bukan kosong atau terisinya tabel: **42 aktif**, **11 disiapkan**, **8 infrastruktur**. Tabel aktif tidak harus sudah memiliki transaksi. Penggunaan sesi/cache/queue yang diperiksa adalah file/file/sync.

Snapshot `document_data` per dokumen masih merupakan sumber baca rincian aplikasi. `prototype_workspaces.state` adalah arsip yang tidak dipakai sebagai fallback. File asli tetap privat pada filesystem; path/metadata tersimpan dalam SQL. Riwayat Finance dan rincian release/lanjutan belum seluruhnya dinormalisasi; KHS berversi serta tabel import terstruktur belum menjadi sumber runtime. Buku membedakan semua hal ini dari relasi yang benar-benar ditegakkan FK.

## Pemeriksaan

Validasi dokumentasi memeriksa semua tabel mempunyai penjelasan dan PK; seluruh kolom penting/FK mengacu kolom nyata dan target unik; ERD memiliki tepat 61 entitas/106 relasi; sembilan SVG valid; DOCX memuat 61 entri katalog; PDF 42 halaman A5 tanpa karakter pengganti; semua nama tabel ditemukan dalam teks PDF. Pencarian HTML diuji untuk hasil tunggal, tanpa hasil, dan kembali ke semua 61 tabel. Seluruh halaman hasil render diperiksa visual.

Tidak ada perubahan kode runtime, konfigurasi, migration, atau data. Suite PHP/frontend dan build aplikasi tidak diulang untuk perubahan dokumentasi ini. Pemeriksaan browser HTML manual belum dilakukan; fungsi pencarian diuji dengan harness DOM dan semua diagram diperiksa melalui render dokumen.

Sumber fungsi tabel: `app/Services/RelationalWorkflowStore.php`, akses akun/domain/controller berkas, `database/migrations`, `MYSQL_RUNTIME.md`, `WORKFLOW_V3.md`, `BOQ_IMPORT.md`, dan `TERM_WORK_EVIDENCE.md`. Dokumentasi konseptual lama tidak menggantikan implementasi aktif.

Bukti: [database-handbook-20261105.json](qa/database-handbook-20261105.json). Untuk pembaruan, gunakan snapshot metadata terbaru dan perbarui deskripsi serta diagram bersamaan. Builder/validator dokumentasi tersimpan dalam `tmp/database-handbook`; dependensi dokumen memakai runtime bundel Codex, bukan dependensi aplikasi.

## Tambahan runtime9 November2026 — Payment Parsial

Migration2026_11_09_000002 menambah partial_payment_requests (PK id_payment_request) → payment_terms/users, serta referensi komposit per termin pada apm_settlements. Otoritas settlement tepat salah satu scan lama atau request Payment terverifikasi. Diagram/katalog kolom terkini tersedia pada [PARTIAL_PAYMENT](PARTIAL_PAYMENT.md). Buku saku PDF/Word/HTML di atas tetap snapshot5 November dan belum diregenerasi; tidak diklaim sudah memuat tabel baru. Migration belum diterapkan agen pada its aktif.

## 19 November 2026 — BA RP dan BA PP setiap pengajuan sebelum pembayaran

Pengguna meminta alur sederhana SPK→BA RP+lampiran→Sahkan BA PP+lampiran→Kasbon/Pembayaran, dan mengonfirmasi Parsial juga wajib BA PP. Sidebar BA RP&BA PP dipindahkan sebelum Pembayaran dengan dua submenu/tab, daftar20/search, langkah bernomor dan satu tombol utama per tahap. Pengesahan/lanjut menunggu server, YA/TIDAK tetap, input aktual dipusatkan di BA RP, empat preview disediakan di pembayaran dan fitur release hanya Final/collapsible.

Marker server bapp-each-payment-v2/barp-each-payment-v2 pada transaksi baru memakai satu BA PP perterm existingFKfinal_term_id (tanpa typeCHECK), nomorBAPP/SPK/Tn unik, source-current invalidasi/riwayat pengesahan. v1/historical/paid tidak dibackfill; SiapkanBA PP adopsi lama eksplisit mempertahankan actualapproval/UUID. Kasbon/transfer/pelunasan menahan sumber belumSah, Partial tidak menutup PP atau membuka release/continuation. Paket Final mencakup BA PP Partial aktual/lampiran, max20parts; uang/PPh/7approval/Branch/unit/private gates tetap. Map-based loadedreadmodel/20DOM, no perrow SQL/API, safe listpoller existing; Partial transfer menambah satuindexedBAPPlookup sepertiFinal. Tidak ada migration/dependency/livewrite/its/env/server/Dompis change. [Aturan dan bukti QA](WORK_DOCUMENTS_FLOW.md).


## 19 November 2026 — Pengesahan BA RP dan tanggal BA PP tersendiri

Menambah tombol Langkah Berikutnya, Sahkan BA RP pada card dokumen, lalu Buat BA PP eksplisit dan Sahkan BA PP. New server marker barpFlow=attest-before-bapp-v1 menghindari pembuatan BA PP dari simpan draf; attestation source/actor/time, revision/receipt/Branch/unit/Head/upload guards dan invalidasi dua dokumen menjaga sumber. Tanggal BA RP dan BA PP dipilih terpisah, tempat otomatis Branch, kalender valid, legacy read memakai tanggal tersimpan tanpa backfill. Word/cetak BARP Sah tersedia dengan parent Sah, BAPP Draft tetap dikunci terpisah. Revisi setelah penolakan tetap melalui kasbon versi baru/all-seven restart. Existing JSON/tabel,0migration/APIendpoint/dependency/poller/live write. Detail serta batas verifikasi: [WORK_DOCUMENTS_FLOW](WORK_DOCUMENTS_FLOW.md), [QA](qa/barp-attestation-dates-20261119.json).
