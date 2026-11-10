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
