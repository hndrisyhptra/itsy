# Eviden pekerjaan pengajuan Pembayaran — 5 November 2026

Admin Konstruksi/Superadmin dapat mengunggah **PDF, JPG/JPEG, PNG atau WebP** pada kartu **Eviden Pekerjaan** di detail Termin/Pembayaran. Dapat memilih beberapa file; upload dilakukan berurutan, maksimal **10 lampiran aktif per termin, masing-masing 10 MB**. Berkas tersimpan segera setelah upload dikonfirmasi server, tanpa harus menunggu Simpan Draf. Isian volume/type yang belum disimpan tetap berada pada draft formulir.

Upload/penghapusan lampiran hanya tersedia saat **Draft/Dikembalikan**. Pengajuan mengunci lampiran selama approval, penolakan atau setelah disetujui. Pengembalian memungkinkan admin melengkapi/mengeluarkan lampiran; file dan metadata lama tetap berada di riwayat. Pencatatan upload/remove memakai akun login, waktu server, revisi, receipt dan audit yang sama dengan transaksi lainnya. Lampiran ditambahkan sebagai fasilitas pendukung; tidak menambah persyaratan wajib baru pada pengajuan lama atau mengubah lima approval.

Approver melihat daftar file dan tombol **Preview/Unduh** langsung pada **Inbox Approval**, serta pada detail pengajuan. Preview PDF memakai viewer browser dalam dialog; foto memakai gambar di dialog. Buka tab baru/unduh tersedia jika browser tidak menyediakan PDF viewer. Akses mengikuti akun aktif dan Branch dokumen; file bukan aset publik. File riwayat yang dikeluarkan tetap dapat ditinjau oleh akun dengan akses dokumen tersebut.

## File dan database

File asli tersimpan pada disk Laravel `local`, root `storage/app/private`, dengan path relatif:

```text
work-evidence/{workspace_id}/{id_termin_aplikasi}/{uuid_upload}.{pdf|jpg|png|webp}
```

Path tersebut dicatat pada **`document_files.storage_key`**, bersama `storage_disk`, nama asli, MIME aktual, byte size, SHA-256, pengunggah dan waktu. Tabel baru **`term_work_evidence`** mempunyai primary key `id_term_evidence`, `source_id` (UUID upload), `term_id`, `file_id`, pengunggah/waktu dan penghapusan logis. Relasi `file_id` menunjuk `document_files.id_document_file`, `term_id` menunjuk `payment_terms.id_payment_term`. Contoh pemeriksaan path:

```sql
SELECT t.source_id AS id_termin_aplikasi, e.id_term_evidence,
       f.original_name, f.storage_disk, f.storage_key AS file_path,
       f.mime_type, f.byte_size, e.uploaded_at, e.removed_at
FROM term_work_evidence e
JOIN document_files f ON f.id_document_file = e.file_id
JOIN payment_terms t ON t.id_payment_term = e.term_id;
```

Snapshot `payment_terms.document_data.workEvidence` menyimpan metadata yang dipakai form/inbox dan riwayat. Path tidak diterima dari client. Endpoint preview mengambil path yang tersimpan di SQL, memeriksa relasi termin dan akses Branch, kemudian mengirim file privat dengan MIME yang benar, `Content-Disposition: inline`, `nosniff`, no-store dan dukungan range PDF. Unduhan memakai endpoint yang sama dengan `?download=1`.

Route: `POST /api/prototype/payments/{term}/work-evidence` dan `GET /api/prototype/payments/{term}/work-evidence/{uuid}`. Hanya endpoint upload multipart dapat menjalankan perintah internal attach; JSON command tidak menerima metadata/path file rekaan. `saveTerm` mempertahankan metadata server, dan `removeTermWorkEvidence` merupakan penghapusan logis yang divalidasi peran/status. Pembatasan upload memakai limiter yang sudah ada. Jenis/ukuran diperiksa Laravel, MIME diperiksa `finfo`, serta header/dimensi foto diperiksa `getimagesize`. Nama penyimpanan berupa UUID, terpisah dari nama asli.

Jika SQL gagal, revisi/metadata/audit dibatalkan dan file upload baru dibersihkan. Konflik revisi dan retry UUID tidak membuat file yatim/duplikat. Beberapa file dikirim sebagai transaksi per file; file yang sudah sukses tetap tersimpan jika file berikutnya gagal. Cadangkan **database dan folder privat** bersama-sama untuk pemulihan; SQL saja tidak memuat byte file.

## Pemisahan dari scan TTD dan batas

Eviden pekerjaan adalah lampiran untuk penilaian pekerjaan sebelum approval. Upload ini tidak mengisi scan TTD, tidak menjadi approval Finance, dan tidak melunasi tagihan. Setelah lima persetujuan, alur cetak/TTD fisik/upload scan/verifikasi Finance/APM tetap berlaku. Pengujian secara khusus membuktikan metadata client tidak dapat memalsukan scan/Finance/APM/pembayaran melalui `saveTerm`.

Tidak ada konversi HEIC/Office, OCR, antivirus, kompresi otomatis atau pembersihan file riwayat otomatis. File asli tidak diubah. Preview visual setelah login belum diuji manual pada sesi ini; konten file, header/range, komponen viewer, akses approver dan referensi SQL telah diuji otomatis.

## Penerapan dan bukti

Migration `2026_11_05_000002_create_term_work_evidence_table` **sudah diterapkan pada `its`**, total 57 migration. Tidak perlu mengulang migration untuk instalasi ini. Cadangan terakhir sebelum penerapan: `storage/app/private/backups/its-before-term-work-evidence-20261105-113507.sql`, 1.009.914 byte, SHA-256 `824619636c5462fd20a60ad0a46a20918b27be50c4009c66e666d8479b53af3c`. Akun/master dan seluruh baris transaksi lama diverifikasi identik setelah migration; revisi tetap 16. Tidak ada data uji/berkas eviden yang dimasukkan ke database pengguna oleh agen. Tabel eviden baru masih kosong sampai admin mengunggah berkasnya.

**116 pengujian PHP / 1.647 assertion**, **75 pengujian frontend**, build produksi dan Pint lulus. Framework tests tetap memakai SQLite `:memory:` fail-closed. Tujuh uji HTTP mencakup PDF/PNG/JPEG/WebP, path dan byte asli, preview PM/range/unduh, penguncian/return/riwayat, akses role/Branch/guest, pemalsuan metadata, file invalid/terlalu besar, retry/konflik, kegagalan SQL dan batas 10 file. Empat uji frontend mencakup route viewer/escaping, tampilan approver/admin, penghapusan logis dan metadata yang tidak dapat diganti melalui form.

Pada salinan MySQL/MariaDB terisolasi, migration, upload file sebenarnya melalui controller, penyimpanan path dan preview sebagai PM berhasil; alur kemudian dilanjutkan hingga lima approval, scan, Finance dan APM tanpa regresi. Eviden uji merupakan PDF sintetis, dan scan TTD pada alur MySQL merupakan metadata sintetis; tidak ada tanda tangan rekaan pada data pengguna. Detail: `docs/qa/term-work-evidence-20261105.json`.
