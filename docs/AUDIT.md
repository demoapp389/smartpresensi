# Audit SmartPresensi

**Status terakhir:** 21 Oktober 2026
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
