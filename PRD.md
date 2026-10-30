# Product Requirements Document (PRD)

## Sistem Absensi Solution X601 & Notifikasi WhatsApp Orang Tua

Versi 1.0 \| 12 Oktober 2026 \| Status: Draft untuk implementasi

| Atribut         | Keputusan                                                               |
|-----------------|-------------------------------------------------------------------------|
| Pengguna utama  | Operator/admin sekolah; orang tua sebagai penerima notifikasi           |
| Lokasi awal     | Komputer Windows sekolah (server lokal)                                 |
| Perangkat       | Fingerprint Solution X601 via LAN                                       |
| Stack utama     | Laravel, MySQL, C#/.NET connector, WhatsApp Business Cloud API          |
| Rencana migrasi | Laravel + DB dapat dipindah ke hosting; connector tetap dekat mesin LAN |

# 1. Latar belakang dan masalah

Proses absensi pada mesin fingerprint menghasilkan catatan kehadiran, tetapi orang tua belum menerima informasi otomatis saat siswa masuk atau pulang. Penarikan data manual memperlambat informasi, menyulitkan monitoring, dan rawan keterlambatan pelaporan.

# 2. Visi dan tujuan

Membangun sistem lokal yang ringan, aman, mudah dikelola sekolah, dan dapat mengirim notifikasi WhatsApp berbasis peristiwa absensi tanpa menghambat proses scan.

- Membaca log baru Solution X601 lewat jaringan lokal tanpa ekspor USB rutin.

- Menyimpan riwayat scan mentah dan hasil klasifikasi kehadiran secara terpisah.

- Mengirim pesan masuk/pulang ke wali yang telah menyetujui notifikasi.

- Mencegah notifikasi ganda dan mampu pulih setelah gangguan internet, mesin, atau komputer.

- Memungkinkan migrasi aplikasi Laravel/database ke hosting kemudian.

# 3. Asumsi, batasan, dan dependensi

- Satu instalasi digunakan satu sekolah, diawali satu mesin Solution X601; ekstensi multi-device disiapkan dalam skema database.

- Komputer sekolah memakai Windows dan menyala selama operasional sekolah; detail versi Windows perlu dikonfirmasi.

- Mesin terhubung LAN dan log dapat dibaca melalui SDK kompatibel; kompatibilitas SDK/firmware harus dibuktikan pada tahap proof of concept.

- Komputer memiliki internet keluar untuk WhatsApp API; akses port fingerprint dari internet tidak diperlukan.

- Nomor WA wali, persetujuan penerimaan pesan, akun Meta, template yang disetujui, dan anggaran biaya API tersedia sebelum go-live.

- MVP bukan sistem penggajian, pembayaran, e-Rapor, atau pemantauan lokasi; tidak mereplikasi template sidik jari/biometrik ke database aplikasi.

# 4. Pengguna dan hak akses

| Peran                 | Akses                                                                           |
|-----------------------|---------------------------------------------------------------------------------|
| Administrator sekolah | Pengaturan aplikasi, perangkat, jadwal, akun, API, audit dan backup             |
| Operator absensi      | Mengelola data siswa/wali, memantau log, koreksi absensi dengan alasan, laporan |
| Orang tua/wali        | Menerima pesan WhatsApp; tidak perlu akun dashboard pada MVP                    |
| Service connector     | Hanya mengirim log terautentikasi ke API lokal; tidak memiliki akses dashboard  |

# 5. Ruang lingkup dan prioritas

| Fitur                                                     | Prioritas | MVP                |
|-----------------------------------------------------------|-----------|--------------------|
| Baca log X601 via LAN & sinkronisasi incremental          | P0        | Ya                 |
| Master siswa, kelas, wali dan mapping ID mesin            | P0        | Ya                 |
| Log mentah, deduplikasi dan pencatatan waktu              | P0        | Ya                 |
| Aturan masuk/terlambat/pulang dan peninjauan pengecualian | P0        | Ya                 |
| WA template, queue, retry dan log status                  | P0        | Ya                 |
| Dashboard operasional & pengaturan                        | P0        | Ya                 |
| Jadwal khusus, hari libur, laporan & CSV/XLSX             | P1        | Iterasi berikutnya |
| Banyak perangkat, portal wali, SMS fallback               | P2        | Tidak              |

# 6. Arsitektur teknis

Solution X601 —(LAN TCP/IP)→ Windows Fingerprint Connector (C#/.NET + SDK vendor) —(HTTP localhost dengan autentikasi)→ Laravel API —→ MySQL (raw logs, attendance, outbox) —→ Laravel Queue Worker —(HTTPS)→ WhatsApp Cloud API —→ Wali.

Untuk shared hosting di masa depan, connector cukup diarahkan ke endpoint HTTPS hosting dan menyimpan buffer lokal saat koneksi putus. Komponen internal aplikasi tidak boleh bergantung pada IP lokal mesin.

# 7. Kebutuhan fungsional

## 7.1 Integrasi dan akuisisi data

- FR-01: Admin dapat mendaftarkan IP, port, nama, timezone, dan status mesin; kredensial mesin disimpan aman.

- FR-02: Connector melakukan tes sambungan, mengambil data user ID mesin, waktu scan, dan metadata status yang memang tersedia dari SDK.

- FR-03: Sinkronisasi hanya data baru (watermark/checkpoint), dengan deduplikasi idempotent berdasarkan identitas perangkat dan identitas transaksi/log yang stabil. Jika SDK tidak memberi ID log unik, gunakan kunci gabungan yang diuji.

- FR-04: Layanan auto-start dan reconnect dengan exponential backoff; tidak menghapus log di mesin sebagai langkah sinkronisasi normal.

- FR-05: Ketika API/database tidak tersedia, connector melakukan buffer lokal persisten dan mengirim ulang secara aman setelah pulih.

## 7.2 Data master dan kehadiran

- FR-06: CRUD siswa, NIS/NISN opsional, kelas, status aktif, ID pengguna pada mesin; satu ID mesin tidak boleh terpetakan ke dua siswa aktif pada mesin yang sama.

- FR-07: Satu siswa dapat memiliki satu atau lebih wali, dengan nomor WA format E.164, relasi, preferensi pesan, dan tanggal persetujuan.

- FR-08: Setiap scan disimpan sebagai raw log; peristiwa tidak dikenal dimasukkan ke daftar pencocokan manual, bukan dibuang.

- FR-09: Klasifikasi masuk, terlambat, pulang, pulang lebih awal, scan duplikat, dan membutuhkan peninjauan mengikuti policy jadwal/tanggal. Scan kedua tidak otomatis berarti pulang.

- FR-10: Absensi dapat dikoreksi oleh operator dengan alasan, user, waktu, nilai sebelum/sesudah; log mentah tetap immutable.

## 7.3 Notifikasi WhatsApp

- FR-11: Setelah event absensi tervalidasi, sistem membuat outbox notification untuk wali yang opt-in; satu notifikasi per siswa, wali, jenis event, tanggal sekolah kecuali kebijakan mengizinkan pengulangan.

- FR-12: Menggunakan template utility yang disetujui Meta untuk notifikasi proaktif; pesan memuat nama, kelas, tanggal/jam lokal, jenis absensi, dan nama sekolah.

- FR-13: Pengiriman bersifat asinkron melalui database queue; rate limit disesuaikan batas API dan akun, bukan jumlah scan.

- FR-14: Status pesan queued/sent/delivered/read/failed jika didukung webhook; kegagalan sementara retry dengan backoff dan batas percobaan; kegagalan permanen ditandai untuk operator.

- FR-15: Data kehadiran tetap sukses walau WhatsApp gagal; operator bisa memproses ulang pesan yang gagal dengan kendali duplikasi.

## 7.4 Dashboard, pengaturan, dan laporan

- FR-16: Dashboard menunjukkan jumlah hadir, terlambat, pulang, tidak terpetakan, dan status sinkronisasi/antrean.

- FR-17: Admin mengatur jam masuk, toleransi terlambat, awal pulang, hari sekolah/libur, zona waktu, dan kebijakan duplikasi.

- FR-18: Daftar log menampilkan siswa, waktu mesin, waktu diterima, perangkat, hasil klasifikasi, dan status WA; filter kelas/tanggal/status.

- FR-19: Ekspor CSV/XLSX dan backup database dijalankan tanpa menyertakan token API/rahasia.

- FR-20: Perubahan kebijakan dan data kritis dicatat pada audit log.

# 8. Aturan bisnis utama

| ID    | Aturan                                                                                                                            |
|-------|-----------------------------------------------------------------------------------------------------------------------------------|
| BR-01 | Tanggal sekolah dan klasifikasi memakai zona Asia/Jakarta; jam mesin harus disinkronkan dan diperiksa drift-nya.                  |
| BR-02 | Scan di periode masuk menentukan masuk/terlambat. Scan berkali-kali pada periode ini hanya mencatat raw log tambahan.             |
| BR-03 | Pulang hanya ditentukan pada jendela pulang, mode perangkat yang tervalidasi, atau persetujuan operator untuk kasus khusus.       |
| BR-04 | Log terlambat masuk akibat offline tetap dicatat dengan waktu kejadian sebenarnya; WA dapat ditandai sebagai notifikasi tertunda. |
| BR-05 | Data siswa/wali nonaktif tidak memicu WA baru; perubahan nomor wali tidak mengubah histori notifikasi terdahulu.                  |
| BR-06 | No double send: idempotency key pada outbox dan pembatasan pengiriman untuk event yang sama.                                      |

# 9. Struktur data inti

| Tabel               | Field penting                                                                                                     |
|---------------------|-------------------------------------------------------------------------------------------------------------------|
| students            | id, nis, name, class_id, active                                                                                   |
| classes             | id, name, academic_year                                                                                           |
| guardians           | id, student_id, name, wa_number, consent_at, active                                                               |
| fingerprint_devices | id, name, ip, port, timezone, active                                                                              |
| device_users        | id, device_id, machine_user_id, student_id                                                                        |
| attendance_logs     | id, device_id, machine_user_id, occurred_at, received_at, source_key, raw_payload                                 |
| attendances         | id, student_id, school_date, check_in_at, check_out_at, status, policy_version                                    |
| notification_logs   | id, attendance_id, guardian_id, event_type, idempotency_key, template_name, status, attempts, provider_message_id |
| school_schedules    | id, day_pattern, start_at, late_after, end_at, effective_date                                                     |
| audit_logs          | id, actor_id, action, entity_type, entity_id, before, after, created_at                                           |

Wajib: unique index pada (device_id, source_key), (device_id, machine_user_id) untuk mapping aktif sesuai kebijakan, dan notification idempotency_key. Tambahkan indeks school_date, student_id, occurred_at, status.

# 10. Keamanan, privasi, dan kepatuhan

- Mesin hanya dapat dijangkau dari LAN tepercaya; jangan port-forward TCP/IP mesin ke internet.

- Komunikasi connector→Laravel menggunakan API token berotasi (localhost atau HTTPS saat hosting), request signing bila perlu, dan pembatasan akses.

- Gunakan autentikasi akun dan role-based access control; password di-hash, sesuaikan timeout sesi dan audit aksi sensitif.

- Token WhatsApp di environment/secret manager, bukan di repository atau file backup publik.

- Hanya memproses ID fingerprint dan timestamp, bukan template biometrik; terapkan minimisasi data anak dan akses terbatas.

- Kumpulkan persetujuan wali dan dokumentasikan tujuan/retensi notifikasi sesuai aturan perlindungan data yang berlaku.

- Retensi raw log, attendance, dan message log harus dikonfigurasi sekolah; backup terenkripsi, uji restore berkala.

# 11. Kebutuhan non-fungsional & kapasitas

| ID     | Kebutuhan / target awal                                                                                   |
|--------|-----------------------------------------------------------------------------------------------------------|
| NFR-01 | Mampu menampung sekurangnya 2.000 log/hari (contoh 1.000 siswa × 2 scan), dengan beban puncak diuji.      |
| NFR-02 | Target log masuk database ≤30 detik pada LAN sehat; ukur saat PoC dan sesuaikan polling/event SDK.        |
| NFR-03 | Penyimpanan log tidak menunggu WA; jika API WA lambat, antrean bertambah tanpa kehilangan log.            |
| NFR-04 | RTO operasional target ≤2 jam dari backup valid dan dokumentasi restart; RPO disepakati sebelum produksi. |
| NFR-05 | Deduplikasi deterministik dan pemulihan koneksi otomatis tanpa pengiriman massal ganda.                   |
| NFR-06 | Dashboard usable pada desktop Chrome/Edge terbaru; page list dipaginasi, pencarian diindeks.              |

Target di atas adalah acceptance target desain, bukan klaim benchmark X601. Pengujian nyata menentukan interval baca, kecepatan WA, dan batas perangkat/hosting.

# 12. Skenario uji penerimaan (UAT)

| Kasus                                             | Hasil yang diharapkan                                                        |
|---------------------------------------------------|------------------------------------------------------------------------------|
| Scan masuk normal                                 | Raw log masuk, attendance valid, tepat satu pesan masuk dalam antrean.       |
| Scan berulang 3×                                  | Tiga raw log tetap tercatat; hanya satu event masuk dan satu WA masuk.       |
| Scan saat awal hari lalu scan lagi sebelum pulang | Tidak salah diklasifikasikan sebagai pulang.                                 |
| Scan pulang sesuai jadwal                         | Check-out diperbarui dan satu WA pulang dibuat.                              |
| ID mesin belum dipetakan                          | Tersimpan pada daftar perlu pencocokan, tanpa WA ke wali lain.               |
| Koneksi internet terputus                         | Absensi lokal tersimpan; WA antre; terkirim ulang saat pulih sesuai aturan.  |
| Komputer restart                                  | Connector, Laravel scheduler/worker restart otomatis; backlog diproses aman. |
| WhatsApp API rate-limited / error                 | Retry backoff, status terlihat, absensi tetap tersimpan.                     |
| Jam mesin meleset                                 | Sistem memberi peringatan; operator dapat memperbaiki dengan audit.          |
| Log lama tersinkronisasi ulang                    | Tidak ada duplikasi raw log/attendance/notifikasi.                           |

# 13. Tahapan implementasi

1.  Fase 0 — PoC SDK: identifikasi firmware, ping/SDK connect, baca satu log valid, ukur kemampuan polling dan stabilitas koneksi. Gate: ID dan waktu terbaca konsisten.

2.  Fase 1 — Fondasi Laravel: proyek, migrasi, autentikasi, tabel master, API ingest, dashboard dasar.

3.  Fase 2 — Engine absensi: import data siswa, mapping, jadwal, klasifikasi, deduplikasi, audit.

4.  Fase 3 — WA API: persetujuan wali, template approval, outbox, queue worker, webhook, retry.

5.  Fase 4 — UAT & go-live: tes offline, restart, beban scan, backup/restore, SOP operator.

6.  Fase 5 — Migrasi hosting (opsional): database dan Laravel pindah; connector X601 tetap di LAN sekolah.

# 14. Metrik keberhasilan

- Persentase scan yang masuk database, dengan target awal ≥99,9% setelah koneksi pulih dan dalam periode retensi mesin.

- Persentase event masuk/pulang yang terklasifikasi benar, target ≥99% pada skenario kebijakan yang tervalidasi.

- Jumlah pesan WhatsApp duplikat untuk event yang sama: target 0.

- Panjang antrean dan latensi end-to-end p50/p95 dipantau; SLA WA bergantung pada provider dan batas API.

- Jumlah raw log tanpa mapping siswa, kesalahan sinkronisasi, dan pesan gagal bisa dipantau operator.

# 15. Risiko dan mitigasi

| Risiko                                          | Mitigasi                                                                            |
|-------------------------------------------------|-------------------------------------------------------------------------------------|
| Firmware/SDK X601 tidak kompatibel              | PoC lebih dulu; minta SDK sesuai firmware ke vendor, tanpa menjanjikan push native. |
| Mesin dipakai bergantian sehingga antrean fisik | Atur alur scan; tambah terminal jika perlu, bukan hanya menaikkan spesifikasi PC.   |
| PC sekolah mati/tertidur                        | Disable sleep, auto-start services, UPS opsional, health check, buffer log mesin.   |
| WA API tidak tersedia/biaya meningkat           | Rate limit, queue, pengawasan biaya, retry, status kegagalan yang jelas.            |
| Salah klasifikasi pulang                        | Jendela waktu + status perangkat + peninjauan manual dan audit.                     |
| Duplikasi akibat reconnect                      | Idempotency keys dan unique DB constraints.                                         |

# 16. Keputusan awal dan pertanyaan terbuka

- Diputuskan: tahap awal seluruh aplikasi berjalan di PC lokal, tidak menggunakan hosting.

- Diputuskan: Laravel dan MySQL; connector vendor SDK terpisah dan ringan.

- Perlu verifikasi: versi Windows, IP/port mesin, firmware X601, akses SDK, jumlah siswa dan kebiasaan scan.

- Perlu keputusan: nama sekolah, jam masuk/pulang tiap jenjang, ambang keterlambatan, jumlah wali per siswa.

- Perlu keputusan: akun WhatsApp Business yang digunakan, template, persetujuan wali, dan anggaran operasional per pesan.

- Perlu keputusan: masa retensi data, jadwal backup, dan target waktu pengiriman notifikasi.

Penutup: implementasi dimulai dari proof of concept komunikasi SDK dengan X601, sebelum biaya dan waktu diarahkan ke integrasi WhatsApp. PRD ini menjadi baseline MVP dan dapat diperbarui setelah pengujian perangkat nyata.
