# Buku saku relasi database its Area 3

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
