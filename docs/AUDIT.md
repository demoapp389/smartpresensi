# Audit SmartPresensi

**Status terakhir:** 11 November 2026
**Dokumen ini adalah satu-satunya sumber status audit di repositori.**
Dokumen audit lama sudah diringkas ke sini lalu dihapus agar tidak menjadi sumber informasi yang bertentangan.

**Dasar konsolidasi:**

- Audit temuan 20 Oktober 2026.
- Audit implementasi PRD dan `modul_finger.md` 13 Oktober 2026.
- Audit menyeluruh dan revisi 18 Oktober 2026.
- Audit kualitas kode 18 Oktober 2026.

## Ringkasan Status

| Area | Status | Keterangan |
|---|---|---|
| Artefak deployment | Selesai | `dist` lama berisi kredensial/data pribadi sudah dikarantina dan dihapus; bundle bersih dibangun ulang. |
| Webhook WhatsApp | Selesai di kode | Fail-closed, menerima signature Meta atau shared token Fonnte. Secret Fonnte masih perlu diisi di dashboard. |
| Scheduler | Selesai | `schedule:work` dikelola supervisor; heartbeat memantau scheduler, connector, worker, dan web server. |
| Sinkronisasi `dist` | Selesai 21 Oktober 2026 | ZIP baru `dist/build_2026-10-21/SmartPresensi.zip` berisi 10.076 entri (139,4 MiB), bebas file sensitif, dan memuat perbaikan terbaru; ZIP 20 Oktober dihapus. |
| Status perangkat | Selesai | Penghitungan dan badge memakai `displayStatus()`, bukan status mentah. |
| Route bulk delete perangkat | Selesai | Route, controller method, dan audit action yang tidak terpakai sudah dihapus. |
| Test rate limit dan log | Selesai | Test memakai `RefreshDatabase`; log test tidak lagi menulis ke log produksi. |
| Cache rekonsiliasi | Selesai 21 Oktober 2026 | `fingerprint:reconcile` menyimpan timestamp scalar; objek cache lama diabaikan; regression test ditambahkan. |
| Log produksi | Selesai 21 Oktober 2026 | Channel `daily`, retensi 7 hari, level `warning`; log 20-21 Oktober dan `browser.log` dihapus. |
| Daftar peninjauan | Selesai 21 Oktober 2026 | 41 log lama milik PIN yang sudah dipetakan diubah ke status `dismissed`; mapping lewat halaman perangkat kini membersihkan log stale secara otomatis. |
| Secret supervisor dan connector | Selesai | Token supervisor dirotasi; API lokal connector memakai `X-Connector-Token` dan menolak request tanpa token. |
| File tak terpakai | Selesai 21 Oktober 2026 | `gentelella-master/`, `database/database.sqlite`, `storage/t.txt`, dan `public/favicon.ico` dihapus. |
| Dokumen audit | Selesai 21 Oktober 2026 | Dokumen audit lama dikonsolidasikan ke dokumen ini. |
| Kualitas kode | Tidak ada temuan kode terbuka | Sisa keterbatasan bersifat operasional dan verifikasi perangkat. |

## Perbaikan Fase 1 (10 November 2026)

- Respons Fonnte HTTP 200 dengan `status` bukan `true` kini dianggap gagal, menyimpan
  `reason`, dan hanya memakai `id[0]` sebagai `provider_message_id`.
- Job notifikasi WhatsApp yang gagal memperoleh lock dikembalikan ke antrean
  (`release(10)`) dan diberi timeout 60 detik; command `whatsapp:sweep-queued`
  berjalan tiap 5 menit untuk menyelamatkan notifikasi `queued` yang tertahan.
- Worker Linux dipisah: `smartpresensi-worker` (`default,validations`) dan
  `smartpresensi-worker-long` (`long-running`).
- `DB_QUEUE_RETRY_AFTER` dinaikkan menjadi 3900 detik agar melebihi timeout job 3600 detik.
- Endpoint ingest connector menyimpan log lalu membalas cepat; pemrosesan absensi
  dijalankan oleh `ProcessAttendanceLogsJob`.
- Approval tinjauan waktu untuk scan hari ini mengirim notifikasi WhatsApp susulan
  (scan tanggal lampau tetap tidak dikirim).
- Webhook status WhatsApp memakai urutan `queued < sent < delivered < read`, menolak
  status mundur/tidak dikenal, dan `provider_message_id` kini memiliki index.
- `SCHOOL_NAME` dibersihkan dari spasi/koma di ujung.
- Footer tidak lagi menampilkan nama database internal.

## Perbaikan Fase 2 (10 November 2026)

- Daftar audit, raw log, log unmapped, riwayat presensi, dan outbox WhatsApp memakai
  pagination server-side; ekspor presensi mengalirkan baris per chunk.
- Tren dashboard diagregasi di SQL, jadwal sekolah di-cache singkat, dan query sidebar
  dipindah ke view composer agar tidak diulang tiap halaman.
- Cache jadwal hanya menyimpan ID jadwal (skalar), bukan objek model. Dengan
  `cache.serializable_classes = false`, objek yang tersimpan di store yang men-serialize
  (database/file/redis) kembali sebagai `__PHP_Incomplete_Class` sehingga pemrosesan
  log gagal dan halaman laporan error 500.
- Indeks `attendances.check_out_status` dan `audit_logs.created_at` ditambahkan.
- Aset font eksternal Google Fonts dihapus agar render tidak menunggu jaringan.

## Perbaikan Fase 3 (10 November 2026)

- Webhook WhatsApp (verifikasi GET dan status POST) dibatasi 120 request/menit per IP.
- Ingest connector menolak payload lebih dari 500 log per request.
- Middleware `EnsureUserIsActive` memutus sesi akun yang dinonaktifkan.
- Administrator aktif terakhir tidak bisa diturunkan, dinonaktifkan, atau dihapus.
- Pesan gagal impor Excel tidak lagi memuat detail exception/path server.
- Unduhan `connector_config.ini` memakai `Cache-Control: no-store`.
- Template Nginx dan unit systemd diperkuat (header keamanan, sandboxing).
- `app:preflight` memberi peringatan hardening tanpa menggagalkan deploy.

## Perbaikan Fase 4 (10 November 2026)

- Berkas view mati (`welcome.blade.php`, `vendor/pagination/default.blade.php`) dihapus.
- Method `@deprecated` `FingerprintConnectorService::syncStudents()` dihapus karena tidak
  dipakai lagi; seluruh sinkronisasi memakai `syncMachineUsers()`.
- Query database yang masih berada di view dipindah ke controller
  (`students.index` dan `reports.print`).
- Aturan validasi formulir pengguna disatukan agar store/update tidak menyimpang.
- `.gitignore` dibersihkan dari entri usang; `deploy/templates/env.template` diselaraskan
  dengan `.env.example` (enkripsi sesi, retry antrean, dan kunci presensi).

## Pengurangan Volume Log (11 November 2026)

- Audit Auto-Alpa tidak lagi ditulis per siswa. `attendance:sync-pending` menulis satu
  baris `auto_alpa_batch` per eksekusi berisi jumlah dan rentang ID presensi; jejak per
  siswa tetap melekat pada `attendances.policy_version` dan `override_reason`.
- `AuditLog` dan `NotificationLog` memakai `MassPrunable`. `model:prune` dijadwalkan
  harian 03:45: aksi sistem 30 hari, aksi pengguna 365 hari, outbox 30 hari
  (status `failed` 90 hari). Ambang dapat diubah lewat `AUDIT_*` dan `WA_LOG_*` di `.env`.
- Nomor wali berstatus `invalid` tidak lagi dibuatkan baris outbox `cancelled` karena
  pesan tersebut tidak akan pernah dikirim.
- Halaman Audit menyembunyikan aksi sistem secara default (toggle "Tampilkan aksi
  sistem"), dan halaman Outbox menyembunyikan `held`/`cancelled` kecuali difilter.
- `app:preflight` memberi peringatan bila `audit_logs` > 100.000 atau
  `notification_logs` > 50.000 baris.

## Perbaikan Batch Audit (11 November 2026)

- **Auto-Alpa:** fallback NIS berlaku untuk semua status log yang memblokir, termasuk log tanpa `device_id`; keputusan `unknown` dari validasi Fonnte tidak lagi menurunkan wali yang sedang `valid`.
- **Keamanan:** `secret_token` perangkat disembunyikan dari serialisasi, token kosong ditolak, dan rate limit ingest dikunci per IP (bukan token yang bisa diputar). `source_key` ingest selalu dihitung ulang di server agar deduplikasi tidak bisa dilewati.
- **Absensi:** query `school_date` memakai rentang sargable, retry deadlock dilakukan di transaksi terluar, dan hanya pelanggaran unique yang dianggap balapan insert.
- **WhatsApp:** siaran memakai `ShouldBeUnique` + lock per baris outbox; status akhir (`sent`/`delivered`/`read`/`cancelled`) tidak bisa ditimpa retry atau `failed()`.
- **Operasional:** log `laravel*.log` dirotasi dan dihapus setelah 14 hari, `queue:prune-failed` harian, rekonsiliasi tanpa tumpang tindih, serta buffer/state connector per-mesin ikut dipangkas.
- **Validasi:** ingest menolak ID/waktu/`source_key` yang tidak valid atau terlalu panjang; impor XLSX dibatasi ukuran dekompresi, jumlah baris, dan kolom; ekspor presensi memvalidasi tanggal, filter, dan format serta dibatasi throttle.
- **Deployment:** template `.env` memuat `WA_META_APP_SECRET`; panduan Linux menjelaskan prosedur restore, pembuatan ulang admin, dan token perangkat setelah restore dump aplikasi.

## Tindakan yang Sudah Diverifikasi

- Webhook WhatsApp tidak lagi menerima request tanpa kredensial.
- `WA_WEBHOOK_SECRET` dibuat otomatis oleh template deployment dan wizard, serta didokumentasikan di `.env.example`.
- `WA_FONNTE_URL` memakai endpoint `/send`.
- Endpoint ping perangkat hanya menerima perangkat aktif.
- Scheduler ditambahkan ke supervisor dan terverifikasi berjalan.
- Token supervisor, token perangkat, dan password database dirotasi.
- `APP_KEY` tidak dirotasi berdasarkan keputusan pemilik.
- Connector C# dan Python memakai token lokal; request tanpa token menghasilkan `401`.
- Build bundle mengecualikan `.env`, konfigurasi connector/supervisor, database, buffer, log, private storage, dan `runtime`.
- ZIP final `dist/build_2026-10-21/SmartPresensi.zip` berisi 10.076 entri, tanpa `.env`, log, `gentelella-master`, database, atau runtime development; SHA-256 `2C114F6385FA2928BC83D3ABD14C33245F5372EF6CEC0D85992BF54A8B2A5BC3`.
- ZIP 20 Oktober yang masih memuat bug cache dan frontend lama sudah dihapus.
- Route `devices.bulk-destroy`, method `bulkDestroy`, dan audit bulk delete sudah tidak ada.
- Log 20 dan 21 Oktober serta `browser.log` dihapus; sisa log lama terkompresi; `runtime/_cache` dan karantina `dist` dihapus.
- Setelah cache key lama dihapus, scheduler menjalankan rekonsiliasi dan command berikutnya melaporkan interval 30 menit tanpa `TypeError` baru.
- Probe cache file mengembalikan `__PHP_Incomplete_Class`; command rekonsiliasi sekarang mengabaikannya dan menyimpan timestamp scalar.
- Mapping PIN lewat halaman perangkat mengubah log `unmapped` lama menjadi `dismissed`; log tetap tersimpan untuk audit tetapi tidak tampil di daftar peninjauan.
- 41 log stale dibersihkan; tersisa 0 log `unmapped` dan 1 pengguna mesin belum dipetakan (PIN `11825`) yang belum memiliki scan.
- `runtime/mysql` tidak dihapus karena berisi data live.

## Item Terbuka

### Operasional

1. **Isi `WA_WEBHOOK_SECRET` yang sama di dashboard Fonnte.** Nilai di `.env` aplikasi sudah tersedia; tanpa secret webhook menolak request. Ini langkah konfigurasi, bukan perubahan kode.
2. **Uji E2E mesin fingerprint fisik** via TCP 4370, SOAP 80, dan COM C# belum dilakukan karena perangkat tidak tersedia.
3. **Validasi deployment Linux** pada mesin nyata belum dilakukan. Panduan Linux bersifat manual; connector Python masih eksperimental.
4. **Pemasangan supervisor/connector C# pada server target** memerlukan langkah Administrator. Build C# sudah berhasil.

### Teknis Rendah

- `larastan`/PHPStan belum dipasang karena menambah dev dependency membutuhkan persetujuan.
- Dua error lama `[SyncDeviceUsers] ... Undefined variable $device` (21 Oktober 07:34 dan 08:29) belum dapat direproduksi; test sinkronisasi lulus dan source saat ini tidak memiliki referensi `$device` di luar scope. Pantau log setelah rilis.

### Keputusan yang Diterima

- Checkout pada hari tanpa jadwal tidak dibuat; perilaku ini disengaja.
- Batch `limit(100)` dipertahankan dan backlog ditangani command rekonsiliasi.
- `secret_token` perangkat disimpan plaintext; hashing ditolak karena membutuhkan rotasi seluruh token perangkat.
- `APP_KEY` tidak dirotasi.

## Riwayat Audit yang Digabung

| Tanggal | Dokumen | Hasil |
|---|---|---|
| 13 Oktober 2026 | Audit implementasi PRD dan `modul_finger.md` | Cakupan MVP sekitar 90%; fitur yang tercatat belum ada sudah diselesaikan pada pembaruan berikutnya. |
| 16 Oktober 2026 | `FULL_SYSTEM_AUDIT.md`, `REFACTOR_PLAN.md`, `TEST_PLAN.md` | Baseline 118 test; temuan P0-P3 dan rencana refactor menjadi dasar fase 1-9. |
| 18 Oktober 2026 | Audit menyeluruh, revisi, catatan temuan, dan audit kualitas kode | Temuan kode ditutup melalui fase 4-9; sisa keterbatasan perangkat fisik/Linux dan build C#. |
| 20 Oktober 2026 | Audit temuan | Audit utama untuk keamanan deployment, webhook, scheduler, status perangkat, dan perapian P2. |

## Verifikasi

- Full suite pada 21 Oktober 2026: 362 test lulus, 1.557 asersi.
- Full suite pada audit 20 Oktober 2026: 348 test lulus, 1.496 asersi.
- Test connector Python: 28 lulus.
- Pint: bersih.
- `npm run build`: berhasil.
- Compile supervisor C#: berhasil.
- Runtime connector lokal: `401` tanpa token, `200` dengan token.
- Supervisor: connector, worker, scheduler, dan web server berstatus `running`.
- ZIP final: tidak memuat `.env`, token, database, buffer, log, `gentelella-master`, atau private storage; memuat `getTimestamp()`/`DateTimeInterface` pada command rekonsiliasi dan status `dismissed` pada pemetaan.
- ZIP final: 146.223.898 byte, 10.076 entri, SHA-256 `2C114F6385FA2928BC83D3ABD14C33245F5372EF6CEC0D85992BF54A8B2A5BC3`.
- Build PHP terakhir lolos verifikasi checksum.

Perbaikan 21 Oktober 2026 mengubah command rekonsiliasi, konfigurasi log, template deployment, dan regression test. Verifikasi yang dijalankan:

- `public/favicon.ico` tidak direferensikan; seluruh layout memakai `public/favicon.svg`.
- `gentelella-master` hanya disebut sebagai pengecualian build, bukan dependensi aplikasi.
- `database/database.sqlite` dan `storage/t.txt` sudah di-ignore; `storage/t.txt` berisi output PHPUnit lama, bukan data aplikasi.
