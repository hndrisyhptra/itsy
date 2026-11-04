# Template tetap Project Plan

Pembaruan 4 November 2026 mengacu pada `template-PP-header-pendahuluan-tim-jadwalproyek.docx` yang diberikan pengguna. Dokumen sumber dipertahankan. Placeholder Leonita/Proactive, identitas proyek contoh, nilai dan tanggal contoh bukan instruksi integrasi atau data proyek baru.

## Perilaku

- PP versi 3 yang baru atau disimpan kembali sebagai Draft/Dikembalikan memakai redaksi tetap Latar Belakang, Tujuan, Lingkup Pekerjaan serta Struktur Organisasi & Tim Proyek. Tim mengikuti paragraf peran pada template; aplikasi tidak mengarang nama personel. Lima approver tetap dipilih terpisah pada Header PP.
- Aktivitas tetap: Persiapan, Material Delivery, Instalasi, Commissioning Test, Uji Terima. Pada kedua tab tersebut pengguna hanya dapat mengubah **Jml HK**. HK bilangan bulat nonnegatif, total harus positif; tahap nol dilewati. Jumlah seluruh HK menjadi durasi kalender maksimal BA KH Mandor.
- Tanggal tahap dihitung berurutan dari **Saat Penggunaan** pada Header PP, inklusif. Contoh 2 HK mulai 28 Februari 2028 berakhir 29 Februari; tahap berikutnya mulai 1 Maret. Akhir pekan/libur tetap dihitung sebagai hari kalender. Batas teknis total 1.000.000 hari dan tanggal empat digit tahun; bukan kebijakan batas kontrak baru.
- Laravel menyusun kembali seluruh redaksi, nama aktivitas, tanggal dan durasi. Input browser untuk bagian tetap diabaikan. Versi template server disimpan sebagai `planTemplateVersion: 1`, bersama `intro`, `teamText`, `durationDays`, dan `schedule` pada agregat JSON yang ada; tidak memerlukan migration.
- PP lanjutan baru memakai template yang sama dengan HK awal 1 per aktivitas seperti PP baru; pengguna menyesuaikan HK sebelum pengajuan. Catatan lanjutan disimpan terpisah dalam `continuationNotes`. Anggaran dan ledger root/alokasi tetap mengikuti BA PP, bukan duplikasi BOQ.
- Pembacaan tidak mengubah dokumen tersimpan. PP versi 1/2 yang sudah terbit mempertahankan redaksi/tim/jadwal historis. Draf versi 2 tetap memakai isian lamanya; draf versi 1 yang disimpan ulang pada workspace versi 3 mengikuti peningkatan alur yang sudah ada dan memakai template baru. PP sudah Diajukan/Sah/Ditolak tidak dapat ditulis ulang lewat form. PP versi 3 Draft/Dikembalikan baru mengadopsi template saat disimpan oleh pengguna.

## Preview dan batas output

Preview/cetak PP bertemplate mengikuti urutan judul, Nomor, enam baris Header, Pendahuluan, Struktur Tim dan Jadwal HK pada contoh. Header memakai data PP aktual, termasuk No PP dari server dan Total Nilai dari anggaran Header. Preview sebelum simpan menampilkan nomor perkiraan; nomor tersimpan menjadi acuan.

Pembaruan berikutnya memakai `01._PROJECT_PLAN_DIGITAL.v5.docx` untuk bagian IV–VIII. Sesuai instruksi terbaru pengguna, **Kurva S dihilangkan dari form dan output**, termasuk pada preview template sebelumnya. Rincian tabel anggaran, rumus dan checklist tersedia pada [PP_BUDGET_TEMPLATE](PP_BUDGET_TEMPLATE.md).

Output saat ini **HTML dan cetak/Simpan PDF browser: bagian I–VIII dengan tabel anggaran dan penandatangan**, belum generator DOCX/PDF server atau format resmi PP lengkap yang telah disetujui. PP yang sudah menyimpan planBudget memakai output bagian IV–VIII terbaru; dokumen lama mempertahankan pratinjau historisnya tanpa Kurva S. BOQ, anggaran dan keputusan lama tidak ditulis ulang saat dibaca. Belum ada klaim kesamaan tata letak Word per piksel atau hasil cetak browser satu halaman. Tidak ada TTD yang dibuat otomatis.

## Sumber implementasi dan verifikasi

- Redaksi bersama: `resources/data/project-plan-template.json`.
- Otoritas simpan: `app/Services/ProjectPlanTemplate.php`, `PrototypeWorkflowV2.php`, `PrototypeDomain.php`.
- Pratinjau/form: `resources/js/area3/plan-template.ts`, `plan-document.tsx`, `projects.tsx`, `ui.tsx`.
- Bukti kecocokan seluruh paragraf tetap, nama aktivitas dan gambar sumber: `docs/qa/fixed-pp-template-20261104.json`.
- Database its dicadangkan sebelum perubahan: `storage/app/private/backups/its-before-fixed-pp-template-20261104-162423.sql` (629.582 byte; SHA-256 dicatat pada bukti QA). Pengujian memakai SQLite :memory:; tidak ada perubahan akun atau transaksi pada database pengguna oleh pengerjaan ini.
