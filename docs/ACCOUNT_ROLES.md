# Role akun Operation

Migration `2026_11_10_000001_add_operation_account_roles` menambahkan tujuh identitas ke katalog SQL `roles` dan form Kelola akun:

| Kode `users.role` | Label |
|---|---|
| `ADMIN_OPS` | Admin Operation |
| `ADMIN_OPS_AREA` | Admin Operation Area |
| `OFFICER_OPS1` | Officer 1 Operation |
| `OFFICER_OPS2` | Officer 2 Operation |
| `OFFICER_OPS3` | Officer 3 Operation |
| `MGR_BRANCH_OPS` | Manager Branch Operation |
| `GM_OPS` | General Manager Operation |

Kode role disimpan sebagai string, bukan enum tipe SQL. Laravel membatasi pilihan melalui `AccountAccess::ROLES` dan mencocokkan `users.role` dengan tepat satu role aktif pada `user_assignments`. Akun baru tetap harus mempunyai Branch aktif; aturan Region → Branch → Service Area dan kecocokan Branch utama tidak berubah.

Penambahan identitas ini belum memberikan hak khusus inbox, persetujuan, Finance/APM, pengelolaan master, atau bypass Branch. Hak aksi tetap ditentukan oleh pemeriksaan otorisasi pada setiap fitur. Pemetaan kewenangan Operation perlu ditetapkan sebelum mengubah aturan tersebut.

Migration hanya memasukkan tujuh baris katalog role jika belum tersedia. Tidak mengubah akun, assignment, penempatan, transaksi, snapshot, atau workspace archive; tidak menambah query pada runtime request. Rollback menolak menghapus role yang sudah dipakai akun/assignment.


## 18 November 2026 — Jabatan terpisah dari role

users.jabatan nullableVARCHAR250 dikelola Superadmin pada form/daftar akun. Label resmi ini ditangkap ke snapshot penandatangan PP/BA KH/SPK/BARP/BA PP/kasbon dan dipakai pada preview/Word/print; tidak memberikan hak akses atau menjalankan flow Operation. Edit akun tidak menulis ulang dokumen issued/approved/paid. Akun lama kosong memakai default template. Lihat [USER_JABATAN](USER_JABATAN.md) untuk migration, snapshot/query guards dan bukti QA.


## 18 November 2026 — CRUD katalog Peran Pengguna setelah Penyimpanan

Superadmin mendapat tab Peran Pengguna setelah Penyimpanan: tambah kode/nama, edit nama, hapus peran tambahan tidak terpakai, pencarian, pagination20, ikon/tooltip dan konfirmasi YA/TIDAK. Pengguna mengonfirmasi tahap ini hanya katalog; peran tambahan belum dipilih pada akun dan belum mengubah hak akses/approval/flow Operation. Kode role tetap, peran bawaan dilindungi, users/assignment historis mencegah hapus. Nama katalog terpisah dari label otoritas AccountAccess dan Jabatan dokumen.

RoleCatalog/API memakai auth/active/Superadmin/CSRF/throttle, lock provisioning bersama akun, actor recheck, fingerprint versi, UUID+payload+actor-bound replay dan audit before/after existing master_data_changes entity role dalam transaksi yang sama. ID role yang pernah dihapus tidak dipakai ulang. Loading/abort/no-store/timeout, konflik mempertahankan input, hasil tak pasti tanpa retry otomatis. Tidak menyentuh SQL workflow, keputusan, uang, arsip, private data, .env, its aktif, Dompis atau worker; tanpa schema/migration/dependency baru. [Aturan, arsitektur dan penerapan](ROLE_CATALOG.md).

Performa:3query service daftar/4HTTP termasuk auth,20+1 metadata dan2group counts, tanpa query perbaris. Fixture30extraaccounts tambah9/edit8/hapus9/replay4query. Pencarian contains/pagination bukan jaminan indeks atau benchmark beban. Regresi CRUD/audit/replay/rollback/version/refs/role/CSRF dan query lulus. Browser sintetis6kasus375/768/1440×light/dark memeriksa tab lazy,20rows/pagination/search, tambah/edit/hapus, YA/TIDAK, code lock, delete protection, stale-input dan uncertain-no-retry:0JSerror/0pageoverflow. Screenshot desktop/mobile/dark diperiksa. Bukti suite/build dan batas aktual ada pada [QA](qa/role-catalog-20261118.json); belum menguji akun nyata/server/MySQL/concurrency/load.
