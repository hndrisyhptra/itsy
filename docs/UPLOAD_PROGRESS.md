# Progres pengiriman file dan status antrean — 9 November 2026

Semua upload nyata pada UI akun memakai indikator persentase bersama: **BOQ Plan**, **PDF/foto eviden pekerjaan**, **scan TTD basah**, serta **Periksa file dan Simpan impor Designator/KHS**. File template yang diunduh bukan upload. Input referensi pada editor BOQ demo lama hanya memilih nama file offline dan tidak memperoleh persentase pengiriman palsu; layar tersebut tetap tidak tersedia untuk akun aktif.

## Arti angka dan status

- **Mengirim file · 0–100%** dihitung dari `XMLHttpRequest.upload` berdasarkan byte request multipart yang terkirim. Nilai dibulatkan ke bawah dan hanya diperbarui saat persentase bulat atau fase berubah. Multipart juga mempunyai metadata selain file.
- Jika browser belum menyediakan ukuran total, indikator pengiriman tidak menampilkan angka rekaan dan menunjukkan **Ukuran belum terukur**.
- **Pengiriman 100% · Menunggu konfirmasi server** berarti request selesai dikirim, tetapi hasil belum dikonfirmasi. HTTP gagal/validasi tidak berubah menjadi sukses hanya karena byte sudah 100%.
- BOQ/eviden beralih ke **Dalam antrean** hanya setelah respons server menyatakan task tersimpan. Persentase pengiriman tetap 100%; fase worker mempunyai indikator tak tentu terpisah. Setelah polling server, status menjadi **Sedang diproses → Selesai** atau **Pemrosesan gagal**. Tidak ada estimasi persentase pemrosesan atau posisi antrean yang dibuat dari waktu tunggu.
- Daftar antrean yang dibuka kembali menampilkan 100% pengiriman dari task yang sudah diterima secara durable, bersama status server terkini. Persentase transfer sebelum task diterima tidak disimpan pada database/browser draft.
- Scan dan impor master tetap menggunakan request sinkron yang sudah ada. Status konfirmasi menunggu respons validasi/penyimpanan, bukan mengklaim masuk worker. Pemeriksaan master selesai hanya menghasilkan preview; pesan menegaskan **data belum diimpor** sampai Simpan impor dikonfirmasi.
- Jika jaringan putus/batas waktu/abort, UI menampilkan **Belum dikonfirmasi** dan mempertahankan persentase terakhir. File yang sudah diterima dapat tetap diproses server; periksa antrean/data sebelum mengunggah ulang. Tidak ada pengulangan POST otomatis. UUID retry impor master yang belum pasti tetap sama; konflik 409 tetap membutuhkan pemeriksaan ulang eksplisit.

Untuk banyak eviden, masing-masing file mempunyai nama, bar dan status sendiri (maksimal 10 sesuai batas lama). Pengiriman tetap berurutan dan pemantauan worker tetap mengikuti task masing-masing. File yang belum mulai bertanda **Belum dikirim**. Kegagalan satu file tidak mengubah file lain menjadi sukses; task yang sudah diterima tetap tersedia di daftar antrean. Callback batch lama tidak menimpa indikator batch baru. BOQ yang lama baru diganti setelah hasil valid berhasil digunakan.

## Arsitektur, keamanan dan performa

`upload-progress.ts` menyediakan model fase/transport multipart. `upload-progress-view.tsx` merender native progress dengan label nama file, status teks/role status, tema proyek dan container terbatas. Persentase dan fase tidak hanya dibedakan melalui warna. Komponen BOQ/eviden/scan/master serta daftar antrean memakai model yang sama. `postUpload` menggunakan XHR saat ada konsumen progres; pemanggil kompatibilitas tanpa callback tetap memakai fetch.

Respons transport tetap diteruskan sebagai Response ke handler lama. CSRF, sesi/credentials, request UUID, integer multipart revision, hash, mode preview/commit, pembatasan ukuran/jenis/1000row master, Branch, private paths, selected approvals, scan/Finance/APM, idempotency dan audit tidak berubah. Header Content-Type multipart dibiarkan browser membuat boundary. Timeout tetap60s untuk enqueue/master dan20s untuk scan; abort listener dan handler transport dibersihkan setelah request selesai. Seluruh UI upload nyata memasang callback progres.

**Tidak ada endpoint, schema/migration, job/worker, query SQL, permission, `.env`, business rule atau data aktif yang diubah.** Tidak ada file uji/master/akun/transaksi ditulis ke its atau Dompis Prov. Karena ini perubahan presentasi/transport dengan kontrak server yang sama, tidak dilakukan backup database baru atau deployment migration. Migration Header/Rp0 pada perubahan sebelumnya tetap mempunyai catatan deploymentnya sendiri; fitur progres ini tidak menerapkannya.

Persentase berasal dari event browser sehingga **0 request tambahan/0 SQL tambahan** untuk progres. Polling queue2s foreground/10s hidden pada waiter serta daftar 4s/15s, batas 10 task dan interval saat gagal tetap seperti sebelumnya. Pembacaan queue dan satu refresh state setelah eviden tetap ada. Regresi integrasi transport semua jalur memeriksa tepat7 GET yang memang diperlukan oleh dua siklus queue/result/refresh dan satu recovery scan, tanpa GET khusus persentase. Ini mock transport, bukan pengukuran query MySQL/latency jaringan. Suite PHP lama dengan query-budget/authorization tetap lulus. Tidak mengklaim perbaikan throughput universal atau membandingkan waktu upload melalui fixture.

## Verifikasi dan batas

- **230 tes PHP / 4473 assertions**, fail-closed SQLite `:memory:`; tidak menjalankan detached worker atau query live.
- **231 tes frontend**, termasuk6 regresi baru: byte terukur/tidak terukur, deduplikasi event, 202 versus selesai, HTTP422, abort/timeout/network/cleanup/tanpa auto-retry, label/escaping/worker indeterminate, autentikasi dan semua jalur upload dengan CSRF/revisi/UUID retry/gates yang sama.
- TypeScript/Vite production build dan diff check lulus. Tidak ada PHP diubah sehingga lint PHP baru tidak diperlukan.
- XHR/fetch/timer pada tes transport dipalsukan, sedangkan UI runtime memakai event browser dan status server nyata. SSR/markup dan kontrak transport bukan verifikasi visual browser.
- Browser autentikasi, unggah nyata lewat Apache/aaPanel/proxy, jaringan lambat, responsif/dark/keyboard dan beban serentak belum diperiksa manual; akses browser yang sebelumnya ditolak tidak dicoba ulang. File kecil dapat langsung mencapai100% tanpa angka antara. Persentase per-baris parsing/estimasi waktu tunggu worker belum tersedia dan tidak disimulasikan.

Bukti: [qa/upload-progress-20261109.json](qa/upload-progress-20261109.json). Perilaku worker/penyimpanan tetap [UPLOAD_QUEUE_PERFORMANCE](UPLOAD_QUEUE_PERFORMANCE.md), [TERM_WORK_EVIDENCE](TERM_WORK_EVIDENCE.md) dan [MASTER_BULK_IMPORT](MASTER_BULK_IMPORT.md).
