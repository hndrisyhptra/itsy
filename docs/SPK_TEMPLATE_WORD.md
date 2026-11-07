# Preview dan unduh Word SPK Mandor — 7 November 2026

Pembaruan identitas/Word 7 November 2026: SPK baru/draf v3 memakai nama/jabatan/NIK Head of PMO terpilih secara otomatis; surat dan Lampiran SPK sama-sama menyediakan Word, termasuk NIK. Terbit/Sah mempertahankan snapshot. Lihat [SPK_SIGNER_WORD.md](SPK_SIGNER_WORD.md); aturan identitas manual di bawah merupakan baseline sebelum pembaruan ini.

**Preview SPK** sekarang memakai surat sesuai SPK Mandor.docx yang diberikan pengguna, dengan tombol **Unduh Word (.docx)** dan Cetak/Simpan PDF. Tampilan utama berupa surat A4 vertikal: logo Telkom Akses di kanan atas, judul/nomor, tanggal/tempat, penerima dan alamat, perihal, instruksi/periode kerja, tabel nilai, koordinasi Waspang, ketentuan K3 dan penandatangan Telkom Akses. Lampiran rincian harga tetap merupakan preview tersendiri; kini mengikuti [template Lampiran SPK](SPK_APPENDIX_TEMPLATE.md) dengan Nama Proyek/Vol.

Sumber: Downloads/Kelengkapan Tagihan Swakelola (Feb 2026)/SPK Mandor.docx, 144.108 byte, SHA-256 **f2f79179af2ad62d8e6339f498d0b7fd44446664c0b1d426d4558233c0c23092**. Template asli tetap utuh. Contoh identitas/tanggal/nomor telepon/nilai tidak disalin ke transaksi. Isi dokumen adalah acuan format/redaksi, bukan instruksi untuk mengubah workflow.

## Pemetaan isian

| Bagian template | Sumber aplikasi |
| --- | --- |
| Nomor SPK | spk.no dari form yang sudah diselaraskan dengan penomoran; dokumen terbit memakai nomor tersimpan |
| Tempat/tanggal surat | spk.place dan spk.date |
| Penerima Mandor Project | deal.mandor pada BA KH yang direferensikan SPK |
| Alamat | spk.address, termasuk baris baru |
| Pekerjaan/perihal/Nama Cluster | project.name pada PP yang direferensikan; satu baris pekerjaan, tanpa membuat cluster rekaan |
| Area | project.branch |
| Periode mulai–selesai | spk.start/end, bukan mengganti jadwal SPK terbit dengan PP/BA KH/akun terbaru |
| Nilai Material/Jasa/Total | Kuantitas × harga kesepakatan Mandor pada deal.rows terpilih, bukan harga Owner/KHS atau seluruh budget root |
| Waspang dan handphone | spk.supervisor/phone |
| Penandatangan dan jabatan | spk.signer/position aktual; tidak menginferensikan Head/NIK dari akun lain atau mengganti snapshot SPK dengan identitas BA KH |
| K3 dan struktur surat | Redaksi template tetap |

Tanggal tidak valid/kosong dan identitas yang belum diisi tampil sebagai elipsis; tidak diganti hari ini, nama contoh, jabatan atau NIK rekaan. Jabatan ditampilkan kapital sesuai pola template. Ruang TTD fisik tetap kosong, tanpa stempel atau gambar tanda tangan. Satu-satunya gambar adalah logo asli template.

Pembulatan mengikuti total kesepakatan yang sudah dipakai domain: total adalah jumlah tiap baris Material+Jasa yang dibulatkan dua desimal. Material menjumlahkan pembulatan Material per baris; Jasa adalah selisih total dengan Material, sehingga selisih pembulatan sen tidak menyebabkan Material+Jasa berbeda dari total dokumen. Harga/kuantitas/ledger tidak diubah. Komponen yang tidak dipilih kosong, harga nol nyata tetap 0. Regresi mencakup pecahan 0,005 dan kuantitas 1,125 serta membandingkan total dengan dealTotal.

## Implementasi dan dampak arsitektur

- spk-content.ts menjadi model bersama isian/nominal untuk React dan Word. SpkDocument memakai prop PP/BA KH/SPK yang sudah berizin, termasuk form belum disimpan. Cabang khusus DocumentPreview berada sebelum lookup root/KHS dan perhitungan generik, sehingga tidak membaca katalog atau menghitung tabel detail yang tidak dicetak. Pemrosesan nilai O(baris kesepakatan), formatter dipakai ulang.
- spk-docx.ts diimpor saat tombol ditekan. spk-word-template.ts adalah salinan bagian OOXML sumber yang telah diberi slot teks; generator mengisi slot satu lintasan dengan escaping XML. Teks pengguna yang menyerupai slot tetap literal, tidak diinterpretasikan ulang. Tidak memakai HTML yang diubah ekstensi, screenshot, altChunk, makro atau external relationship.
- scripts/build-spk-word-template.py membangun ulang aset dari file sumber menggunakan runtime Python QA dan lxml. Runtime aplikasi hanya memerlukan TS/PNG yang sudah dihasilkan, tidak memerlukan file Downloads, Python, converter desktop atau dependency npm/Composer baru.
- Paket final berisi 13 bagian. Delapan bagian tetap byte-for-byte dari sumber: header, relasi header, logo, theme, fontTable, settings, footnotes dan endnotes. Body diberi slot dan penyesuaian aliran; styles hanya ditambah style Title; relasi/content-types dibersihkan untuk menghapus customXml dan metadata penulis docProps yang tidak diperlukan. ooxml-zip.ts kini menerima byte gambar selain XML UTF-8, dengan CRC32 dan batas 64 MiB yang sama. Ekspor BA KH tetap lulus regresi.
- Tata letak menjaga geometri sumber 11920×16840 twip, margin atas1160/kanan780/bawah280/kiri1080, logo/header, font/style, grid serta border tabel lima kolom dengan dua baris header merge. Tabel floating menjadi inline agar teks variabel tidak bertumpuk; sel boleh membungkus dan header berulang. Spacer akibat tabel floating/blank akhir dibuang. Placeholder merah menjadi hitam. Paragraf koordinasi/K3 dibuat lebih terbaca dan blok TTD dijaga utuh.
- Bila pekerjaan lebih dari 120 karakter atau alamat lebih dari 80 karakter, spasi baris utama diringkas dari 420 ke 320 twip tanpa memperkecil font atau memotong isi. Perubahan ini hanya whitespace untuk isian panjang, bukan pengurangan data. Konten yang lebih panjang lagi tetap boleh melanjutkan halaman.
- spk-assets.ts mengoreksi URL logo mengikuti prefix modul build, sehingga Artisan root, Apache subfolder dan host aset eksternal tetap didukung. Preview memuat aset PNG publik (122,55 kB). Saat unduh Word, satu GET aset logo yang sama menyediakan byte gambar; itu bukan permintaan data bisnis atau endpoint baru. Tombol memakai busy/guard klik ganda/timeout/error sebelum membuat Blob DOCX dengan nama file aman.
- Download merupakan salinan lokal preview, bukan Simpan, Generate, Sahkan, approval atau upload arsip. Tidak ada SQL/query/master lookup baru, mutasi DB/.env/worker/master/akun, schema/migration atau perubahan Branch/approval/harga/volume/penomoran. Tidak ada transaksi uji di its. Perubahan presentasi ini tidak memerlukan backup workflow baru.

## Bukti QA dan batas

**203 tes PHP / 3.796 assertions, 176 tes frontend dan build TypeScript/Vite lulus.** Tujuh regresi SPK mencakup pemetaan seluruh isian/identitas historis, paket native/geometri/merge/metadata, kesamaan HTML/Word tanpa root/KHS, nol/sel kosong/pembulatan, tanggal invalid/Unicode/XML/slot literal/nama file/error logo, fixture 1000 baris tanpa katalog dan resolusi URL root/subfolder/CDN. Regresi penomoran dan sel kosong disesuaikan dengan template utama yang kini memuat ringkasan pekerjaan, bukan tabel designator generik. Pembaca ZIP test dipusatkan di helpers/docx-parts.ts; tes ekspor BA KH tetap lulus.

Generator aplikasi yang sama dijalankan pada dua fixture privat: normal dan isian panjang. ZipFile memeriksa CRC, lxml seluruh XML/relationships, python-docx satu section/portrait/tabel tiga baris. Delapan bagian preservasi dibandingkan byte-for-byte dengan sumber. Sumber tetap mempunyai SHA yang sama. Bundled LibreOffice menghasilkan **1 halaman normal + 1 halaman isian panjang**; kedua PNG final diperiksa setelah penyesuaian spasi serta penghapusan halaman kosong. Header/logo, data, garis, seluruh kalimat dan blok TTD terlihat utuh. Fixture/render/kontrak template tetap di temporary QA, bukan transaksi pengguna atau deliverable SPK sungguhan.

Chunk Word SPK sekitar **91,32 kB / gzip 13,22 kB**, dimuat saat diminta; ZIP bersama sekitar1,16 kB/gzip0,60 kB. Ukuran bundle/fixture tidak membuktikan throughput. Browser terautentikasi, klik unduh, layout responsif 375/768/1440, Microsoft Word desktop dan cetak fisik belum diuji manual. Perbedaan font/versi Word/printer dapat mengubah page break; CSS cetak meminta A4 portrait khusus surat SPK dan dapat ditimpa dialog printer.

Audit: [CHANGELOG.md](CHANGELOG.md), [LARAVEL_QA.md](LARAVEL_QA.md), WORKPLAN.md, [arsitektur](ARCHITECTURE.md) dan qa/spk-template-word-20261107.json.
