# Pemetaan master dan primary key Area 3

Tanggal: 3 November 2026. Migration Laravel tersedia untuk database its; runtime SQLite yang ada tidak diubah dalam pekerjaan ini. Lihat MYSQL_MIGRATION.md.

## Sumber dan jumlah baris

| File kiriman | Tabel tujuan | Baris |
|---|---|---:|
| areas.sql | areas | 1 |
| regions (1).sql | regions | 3 |
| branches.sql | branches | 16 |
| service_areas (2).sql | service_areas | 392 |
| packages.sql | packages | 2 |
| designator_types.sql | designator_types | 5 |
| designators.sql | designators | 724 |
| designator_package_prices.sql | designator_package_prices | 1030 |

Seluruh 2.173 baris dipertahankan termasuk ID, celah penomoran, nilai NULL/nol, satuan, soft delete, audit dan timestamp sumber. Seed tambahan hanya menambahkan kode_branch. Nama Service Area yang berulang tetap baris berbeda berdasarkan id_service_area/workzone.

## Kode Branch tambahan

| id_branch | code sumber | name sumber | kode_branch |
|---:|---|---|---|
| 2 | SDA | SIDOARJO | R535 |
| 3 | SBY | SURABAYA | R501 |
| 4 | MDN | MADIUN | R529 |
| 5 | JBR | JEMBER | R536 |
| 6 | LMG | LAMONGAN | R504 |
| 7 | MLG | MALANG | R531 |
| 8 | YGY | YOGYAKARTA | R409 |
| 9 | SMG | SEMARANG | R428 |
| 10 | PWT | PURWOKERTO | R423 |
| 11 | PKL | PEKALONGAN | R424 |
| 12 | SKA | SURAKARTA | R426 |
| 13 | MGL | MAGELANG | R425 |
| 14 | DPS | DENPASAR | R502 |
| 15 | KPG | KUPANG | R547 |
| 16 | MTR | MATARAM | R546 |
| 17 | FLS | FLORES | R505 |

DENPASAR/R502 tercantum dua kali pada permintaan, tetapi satu kali pada sumber (id_branch 14). Hasil tetap satu baris; bukan Branch baru. Token BRANCH pada nomor PP menggunakan kode_branch, sehingga PEKALONGAN menghasilkan 0001/LG.000/TA-03R424/11-2026.

## Primary key seluruh tabel

Nama ID khusus berlaku pada primary key. Foreign key memakai nama kolom sumber/konteks dan merujuk ID induk yang benar. Migration executable tersedia di database/migrations; lihat MYSQL_MIGRATION.md. users.id_user memakai BIGINT UNSIGNED bawaan Laravel, dan tidak ada area3_users. Tabel penghubung mempunyai ID sendiri dan tetap mempunyai UNIQUE untuk kombinasi bisnisnya.

| Tabel | Primary key |
|---|---|
| users | id_user |
| roles | id_role |
| areas | id_area |
| regions | id_region |
| branches | id_branch |
| service_areas | id_service_area |
| user_assignments | id_user_assignment |
| work_units | id_work_unit |
| measurement_units | id_measurement_unit |
| packages | id_package |
| designator_types | id_designator_type |
| designators | id_designator |
| designator_package_prices | id_designator_package_price |
| khs_price_lists | id_khs_price_list |
| khs_prices | id_khs_price |
| projects | id_project |
| numbering_rules | id_numbering_rule |
| number_sequences | id_number_sequence |
| project_plans | id_pp |
| project_plan_team | id_pp_team |
| project_plan_schedule | id_pp_schedule |
| project_plan_costs | id_pp_cost |
| boq_lines | id_boq |
| boq_components | id_boq_component |
| contracts | id_contract |
| contract_components | id_contract_component |
| payment_terms | id_payment_term |
| term_actuals | id_term_actual |
| approval_runs | id_approval_run |
| approval_steps | id_approval_step |
| approval_decisions | id_approval_decision |
| document_files | id_document_file |
| boq_import_templates | id_boq_import_template |
| boq_import_batches | id_boq_import |
| boq_import_rows | id_boq_import_row |
| contract_documents | id_contract_document |
| term_scans | id_term_scan |
| apm_settlements | id_apm_settlement |
| ba_completions | id_ba_pp |
| volume_releases | id_volume_release |
| continuation_allocations | id_continuation_allocation |
| command_receipts | id_command_receipt |
| volume_balances | id_volume_balance |
| volume_movements | id_volume_movement |
| master_import_batches | id_master_import |
| master_import_rows | id_master_import_row |

Keunikan ID berlaku per tabel; angka ID sumber tidak diubah menjadi urutan global. Tidak ada kolom primary key generik bernama id.

## Relasi yang belum dapat dilengkapi

- created_by/updated_by pada delapan master tetap angka referensi sumber; user ID 2 belum dipetakan karena dump users tidak diberikan. Tidak dibuat akun/password produksi. FK operasional numerik ke users terpisah dari audit sumber ini.
- designator_category_id seluruhnya NULL. Tabel designator_categories belum diberikan; kolom dipertahankan tanpa mengarang kategori atau FK ke tabel yang tidak ada.
- Harga paket asli berupa satu price per designator/paket; 306 designator Material dan 418 Jasa tetap terpisah. Tidak diubah menjadi pasangan M/J berdasarkan dugaan nama kode. khs_prices.source_price_id disediakan untuk snapshot berversi berikutnya, dengan pemeriksaan designator/paket/tipe dan metadata KHS dari pengguna.

Pemeriksaan sumber dan data bersifat offline; tidak mengakses database asal. DDL/seed referensi belum diimpor manual; migration Laravel sudah diuji pada MariaDB sementara dan belum diterapkan ke database pengguna. Lihat MYSQL_CONCEPT.md untuk transaksi dan batas prototype.
