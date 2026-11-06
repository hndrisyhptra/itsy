# Alur approval dan akun versi 3 — 4 November 2026

Pembaruan 6 November: approver keempat PP/Pembayaran baru menjadi **Mgr Project Support**; penutup PP baru memakai empat jabatan baku. Assignment/keputusan Business Support historis tetap berlaku. Aturan terkini: [PROJECT_SUPPORT_APPROVAL](PROJECT_SUPPORT_APPROVAL.md). Penjelasan sebelumnya di bawah bersifat historis bila berbeda.

Permintaan terbaru menggantikan alur dua approver untuk **PP baru dan termin baru**. Dokumen yang sudah tersimpan mempertahankan workflowVersion dan riwayatnya; tidak ada keputusan, tanda tangan atau bukti yang dibuat otomatis.

## Akun dan login

Tabel users mempertahankan id_user, name, email, password, remember_token dan active, lalu menambahkan:

| Kolom | Aturan |
|---|---|
| username | VARCHAR(50), unik; login memakai username, bukan email |
| nik | VARCHAR(50), unik bila diisi; identitas pegawai, nol di depan dipertahankan |
| id_regions | FK nullable ke regions.id_region |
| id_branch | FK nullable ke branches.id_branch, penempatan utama |
| id_service_areas | FK nullable ke service_areas.id_service_area |
| role | Kode peran VARCHAR(40), harus konsisten dengan roles/user_assignments |

Username diwajibkan pada form, dinormalisasi ke huruf kecil, 3–50 karakter (huruf, angka, titik, garis bawah, tanda hubung). Migration memberi akun lama username `user-{id_user}` tanpa mengganti password atau identitas lama. Superadmin dapat mengganti username melalui Kelola akun. NIK/penempatan lama tidak ditebak dan tetap NULL bila belum diberikan.

Validasi server memeriksa Branch berada dalam Region, Service Area berada dalam Branch, dan Branch utama termasuk Branch penugasan. Kolom penempatan adalah data identitas utama; akses tetap berasal dari daftar penugasan Branch yang dikelola Superadmin. Memilih Region/Branch utama tidak otomatis menambah akses. users.role yang tidak cocok dengan penugasan aktif ditolak; tidak ada role switch di jalur login.

Urutan terbaru juga berlaku pada pengajuan berjalan; approval yang sudah diberikan tetap dihitung melalui pencarian role yang belum menyetujui. Lihat [PROJECT_SUPPORT_APPROVAL](PROJECT_SUPPORT_APPROVAL.md) untuk kompatibilitas run SQL dan penyesuaian Business Support yang sudah diotorisasi.

## Project Plan Baru

1. **Admin Konstruksi** membuat PP (Superadmin tetap dapat membantu tindakan operasional), dimulai dengan BOQ, kemudian Header dan seluruh rencana.
2. **PM Konstruksi** yang dipilih memberikan approval pertama.
3. **Admin Area** yang dipilih memberikan approval kedua.
4. **Mgr Project Support** yang dipilih memberikan approval ketiga.
5. **Mgr Deployment** yang dipilih memberikan approval keempat.
6. **Head of PMO** yang dipilih memberikan approval kelima.

Kelima akun dipilih pada Header PP dan wajib lengkap/aktif saat pengajuan, dengan penugasan Branch yang mencakup PP. Draf boleh disimpan sebelum seluruh approver dipilih; pilihan yang sudah diisi tetap harus valid. Server mengambil role/identitas dari sesi login. Superadmin, akun lain dengan role sama, atau approver tahap berikutnya tidak dapat menggantikan approver yang sedang ditunggu. PP menjadi Sah dan membuka BA KH/SPK setelah **5/5**, bukan setelah PM saja atau PM + Admin Area.

Pengembalian/penolakan membutuhkan alasan. Pada alur versi 3, **Dikembalikan maupun Ditolak** harus diperbaiki dan diajukan ulang oleh Admin Konstruksi (Superadmin tetap dapat membantu tindakan operasional). Pengajuan tidak otomatis kembali ke inbox approver. Lima approval dimulai kembali dari PM; persetujuan pada siklus sebelumnya tidak dihitung. Nomor PP, ID dokumen dan append-only approvalHistory dipertahankan, termasuk akun, waktu, keputusan Tolak dan catatannya. Ketentuan pengajuan ulang juga berlaku untuk PP versi 3 yang sudah berstatus Ditolak sebelum pembaruan. Dokumen historis versi 1/2 mempertahankan aturan/keputusan lamanya. Lihat [APPROVAL_RESUBMISSION](APPROVAL_RESUBMISSION.md).

## Pengajuan pembayaran termin

Termin baru menyalin kelima approver PP sebagai snapshot penugasan:

Admin membuat pengajuan → PM → Admin Area → Mgr Project Support → Mgr Deployment → Head of PMO → cetak → TTD fisik di luar aplikasi → Admin mengunggah scan nyata → **Finance menyetujui/memverifikasi scan** → Finance mencatat APM → tagihan lunas.

Lima approval sebelum TTD dicatat pada approvals/history. Setelah 5/5, status Disetujui berarti persetujuan pekerjaan/dokumen lengkap dan kuantitas terkunci; Finance masih dapat menunggu scan. Daftar pembayaran/tracker menampilkan tahap Finance terpisah. Berkas siap cetak memuat lima nama approver serta ruang TTD kosong; tidak menggambar tanda tangan.

Jika salah satu dari lima approver menolak termin versi 3, alasan wajib diisi, status menjadi Ditolak, dan daftar approval aktif dikosongkan. Admin dapat memperbaiki volume/jenis/eviden pada pengajuan yang sama, menyimpan Draft, lalu mengajukan kembali dari PM sampai Head of PMO. Nomor termin, SPK dan riwayat keputusan tetap sama. Penolakan tidak membuat termin pengganti, BA PP, scan sah, persetujuan Finance atau APM; seluruh gerbang tersebut tetap mengikuti alur yang berlaku. Pengajuan yang masih Draft/Ditolak tidak bisa disetujui sebelum admin mengirim ulang.

Finance adalah approval tambahan pada langkah keseluruhan ketujuh setelah pembuatan dan lima approver, tetapi diproses hanya setelah file TTD fisik diunggah. Finance tidak dipilih di rantai lima approver PP; akun Finance aktif pada Branch dokumen dapat menanganinya. Keputusan dicatat pada financeApproval/financeHistory dengan identitas akun, waktu, alasan dan scanId. File PDF/JPG/PNG disimpan privat seperti sebelumnya. Koreksi scan harus beralasan, tidak mengubah volume/approval pekerjaan, dan setelah unggah ulang Finance harus memeriksa scan yang baru. Riwayat file/keputusan lama dipertahankan.

APM hanya dapat dicatat setelah lima approval serta Finance menyetujui scan yang sama. APM menandai seluruh tagihan termin lunas secara atomik, unik, dan tidak menggandakan pembayaran. Ini pencatatan manual, bukan transfer/integrasi bank. Termin berikutnya tetap membutuhkan APM valid, maksimum tiga termin; termin ketiga wajib Final, dan tidak ada termin setelah Final lebih awal.

Volume pekerjaan menjadi accepted setelah lima approval pekerjaan. BA PP/pelepasan volume merupakan alur fisik pekerjaan yang terpisah dari pembayaran; BA PP Sah tetap wajib sebelum pelepasan dan PP lanjutan. Ketentuan ini mempertahankan ledger dan tidak menghitung anggaran root dua kali.

## Dokumen historis dan penyimpanan

- Versi 1 tetap memakai tiga role historis dan pencatatan pembayaran lamanya.
- PP/termin versi 2 yang sudah tersimpan tetap memakai PM → Manager Konstruksi; scan/APM versi 2 dipertahankan.
- Manager Konstruksi ditandai “alur lama” pada pilihan akun agar dokumen tersebut tetap dapat diselesaikan.
- Termin baru dari PP lama memerlukan penetapan **lima approver pembayaran berikutnya** pada detail PP. Penetapan tersebut tidak mengganti keputusan PP historis atau penanggung jawab termin yang sudah dibuat.
- PP lanjutan baru menggunakan versi 3 dan mewarisi lima pilihan penanggung jawab, lalu tetap diajukan melalui lima tahapnya sendiri.
- Runtime MySQL aktif membaca tabel bisnis dengan snapshot per dokumen melalui RelationalWorkflowStore; prototype_workspaces.state hanya arsip. approval_runs/approval_steps menyimpan siklus dan penugasan versi 2/3, sementara approval_decisions mempertahankan keputusan terdahulu. Siklus ditolak tetap REJECTED; pengajuan ulang membuat siklus PENDING baru. Finance setelah scan masih dicatat melalui term_scans dan riwayat snapshot termin, bukan otomatis sebuah approval_steps keenam. Lihat [MYSQL_RUNTIME](MYSQL_RUNTIME.md).
- Upload Excel format OSP FO kiriman kini dibaca dan disimpan privat; lihat [BOQ_IMPORT](BOQ_IMPORT.md). Tombol contoh tetap simulasi terpisah. Tanda tangan elektronik, verifikasi bank dan pemulihan insiden demo lama tidak ditambahkan.
