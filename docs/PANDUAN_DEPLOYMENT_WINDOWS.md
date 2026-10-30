# Panduan Deployment Windows (Bundle Siap Pakai)

Dokumen ini khusus untuk pemasangan SmartPresensi di **Windows 10/11 atau Windows Server
64-bit** memakai bundle `SmartPresensi.zip` dari vendor. Semua langkah di sini memakai
wizard/PowerShell dan **tidak berlaku untuk Linux**.

> Menjalankan di Linux? Baca [Panduan Deployment Linux](PANDUAN_DEPLOYMENT_LINUX.md).
> Ringkasan perbandingan kedua jalur ada di `PANDUAN_DEPLOYMENT.md`.

---

## 1. Yang Anda butuhkan

- PC **Windows 10/11 64-bit** (Windows Server 64-bit juga bisa).
- RAM minimal **4 GB** dan ruang disk minimal **2 GB**.
- Akun **Administrator** di PC tersebut.
- Kabel/jaringan lokal (LAN) ke mesin fingerprint.
- Berkas **`SmartPresensi.zip`** dari teknisi/vendor.

---

## 2. Langkah 1 - Ekstrak berkas

1. Buat satu folder tetap, misalnya `C:\SmartPresensi`.
2. Ekstrak seluruh isi `SmartPresensi.zip` ke folder tersebut.

> **Penting:** jangan letakkan folder aplikasi di dalam OneDrive / Google Drive / folder yang
> disinkronkan ke cloud, karena dapat mengganggu layanan database.

---

## 3. Langkah 2 - Jalankan wizard pemasangan

> **Jangan tertukar:** `setup.cmd` **bukan** pembuka wizard. `setup.cmd` menjalankan
> `setup.ps1` (pemasangan lewat terminal). Untuk wizard, gunakan **`start-setup.ps1`**.

1. Buka folder instalasi.
2. **Klik kanan `start-setup.ps1`** lalu pilih **Run with PowerShell**.
   - Jika muncul UAC, pilih **Yes**.
   - Alternatif dari PowerShell sebagai Administrator:

     ```powershell
     powershell -ExecutionPolicy Bypass -File .\start-setup.ps1
     ```

3. Browser akan terbuka menampilkan **wizard pemasangan**. Ikuti 8 langkah berikut:

| # | Langkah wizard | Isinya |
| --- | --- | --- |
| 1 | Selamat Datang | Ringkasan dan prasyarat |
| 2 | Cek Sistem | Memastikan OS 64-bit dan hak Administrator |
| 3 | Cek Komponen | Memastikan PHP, Apache, dan MySQL tersedia |
| 4 | Panduan | Penjelasan singkat langkah berikutnya |
| 5 | Konfigurasi | Isi **nama sekolah**, timezone, **port web**, **port database**, serta **akun administrator pertama** (nama, email, kata sandi minimal 8 karakter) |
| 6 | Layanan | Tombol **Pasang** - memasang layanan, database, berkas `.env`, menjalankan migrasi, dan **membuat akun administrator** dari data langkah 5 |
| 7 | Uji Sistem | Health check (web, database, supervisor) |
| 8 | Selesai | Server setup berhenti; aplikasi siap dibuka |

> **Akun admin dibuat oleh wizard.** Pastikan email valid dan kata sandi minimal 8 karakter.
> Jika kolom akun administrator dikosongkan, wizard akan menolak melanjutkan instalasi.
>
> Wizard juga membuka firewall untuk port web secara otomatis. Jika port web/database default
> bentrok, ganti angkanya di langkah 5.

Jika wizard tidak bisa dibuka, jalankan pemasangan lewat terminal sebagai Administrator:

```powershell
cd C:\SmartPresensi
.\setup.ps1 -WebPort 8080 -DbPort 3307 -SchoolName "SMA Negeri 1" `
  -AdminName "Administrator Sekolah" -AdminEmail "admin@sekolah.sch.id" `
  -AdminPassword "KataSandiKuat123" -Unattended
```

> `setup.ps1` **bukan** wizard. Opsi `-Seed` hanya untuk demo karena membuat akun bawaan
> (lihat bagian 5). Untuk produksi, gunakan `-AdminEmail` dan `-AdminPassword`, atau wizard.

---

## 4. Langkah 3 - Buka aplikasi

- Buka browser dan akses alamat yang ditampilkan wizard, umumnya
  `http://<IP-PC>:8080` (contoh: `http://192.168.1.10:8080`).
- Komputer lain di jaringan sekolah juga bisa membuka alamat yang sama.
- Login memakai email dan kata sandi administrator yang Anda isi di langkah 5 wizard.

---

## 5. Langkah 4 - Akun administrator

Pada instalasi normal, **tidak ada langkah tambahan**: wizard sudah membuat akun admin di
langkah **Layanan**.

Jika akun gagal dibuat (misalnya wizard dihentikan di tengah jalan), buat ulang dari terminal
sebagai Administrator:

```powershell
cd C:\SmartPresensi\app
..\runtime\php\php.exe artisan sp:create-admin `
  --name="Administrator Sekolah" `
  --email="admin@sekolah.sch.id" `
  --password="KataSandiKuat123"
```

> `db:seed` **bukan** cara yang disarankan untuk produksi. Seeder membuat akun contoh
> `admin@sekolah.sch.id` / `admin123`, operator contoh, dan satu mesin fingerprint contoh.
> Jika Anda tetap memakai `-Seed`, segera ganti kata sandi dan hapus/ubah mesin contoh
> tersebut.

---

## 6. Menghubungkan mesin fingerprint (Connector Windows)

1. Login ke aplikasi, buka **Mesin Fingerprint -> Tambah Mesin**. Isi nama, **IP mesin**,
   port (default `4370`), dan comm key.
2. Di halaman mesin, klik **Unduh `connector_config.ini`**.
3. Simpan berkas hasil unduhan ke folder **`connector\`** di dalam folder instalasi
   (misalnya `C:\SmartPresensi\connector\`), tanpa diubah.
4. Layanan **`SmartPresensiSupervisor`** menjalankan connector C# secara otomatis di latar
   belakang. Supervisor juga menjalankan queue worker dan scheduler Laravel.
5. Jika `connector_config.ini` baru saja diganti, restart layanan:

   ```powershell
   Restart-Service SmartPresensiSupervisor
   ```

> Pada bundle Windows, supervisor memakai connector C# `connector\SolutionX601Connector.exe`.
> Anda tidak perlu menyalakan connector sendiri; ia otomatis hidup sejak komputer menyala.
>
> Jangan mematikan `SmartPresensiSupervisor` secara permanen. Di dalamnya berjalan queue
> worker dan scheduler yang dibutuhkan untuk notifikasi WhatsApp, status mesin, dan
> rekonsiliasi absensi.

---

## 7. Melihat status layanan

Buka **Pusat Kontrol Sistem** di aplikasi untuk melihat status layanan, me-restart
connector/worker/scheduler, atau mengunduh cadangan database. Lewat terminal Windows:

```powershell
Get-Service SmartPresensiMySQL, SmartPresensiApache, SmartPresensiSupervisor
```

---

## 8. Perawatan rutin

Dari folder instalasi, sebagai Administrator:

```powershell
.\backup.ps1 -OutputDir D:\backup                                        # membuat cadangan database
.\restore.ps1 -File D:\backup\absensi_siswa_20260101.sql -Force -StopServices   # memulihkan
.\repair.ps1 -RewriteConfig -RestartServices                             # memperbaiki konfigurasi & layanan
```

- **Backup rutin** sangat disarankan (mingguan). Bisa juga dari menu **Pusat Kontrol Sistem ->
  Unduh Dump SQL**.
- Untuk **membarui versi aplikasi**, minta paket terbaru dari vendor (lihat Lampiran).
- Jangan menimpa folder `data\` dan berkas `app\.env` saat memperbarui aplikasi.

---

## 9. Webhook WhatsApp via Cloudflare Tunnel (Opsional)

Gunakan bagian ini jika status pengiriman Fonnte harus masuk ke aplikasi yang berjalan
di PC lokal, tanpa membuka port router atau memakai IP publik. Hanya endpoint webhook
yang diekspos ke internet; dashboard tetap diakses dari LAN.

**Prasyarat**

- Domain sendiri sudah aktif di Cloudflare (nameserver sudah diganti).
- `cloudflared` sudah terpasang di PC server.
- Aplikasi berjalan, misalnya port `8080` untuk bundle Windows.
- `WA_WEBHOOK_SECRET` sudah diisi dan sama dengan secret di dashboard Fonnte.

### 9.1 Atur `.env`

Buka `C:\SmartPresensi\app\.env`, lalu tambahkan atau sesuaikan:

```env
WA_WEBHOOK_BASE_URL=https://wa.domainsekolah.id
WA_WEBHOOK_PUBLIC_ONLY=true
```

- Ganti `wa.domainsekolah.id` dengan domain Anda.
- Jangan ubah `APP_URL` menjadi domain publik. `APP_URL` tetap alamat lokal karena
  dipakai untuk konfigurasi connector dan tautan internal.
- `WA_WEBHOOK_PUBLIC_ONLY=true` membuat request yang masuk lewat Cloudflare hanya
  diizinkan ke webhook dan `/up`. Request dari LAN tidak terpengaruh.

Setelah mengubah `.env`:

```powershell
.\runtime\php\php.exe artisan optimize:clear
.\runtime\php\php.exe artisan optimize
Restart-Service SmartPresensiSupervisor
```

Jika memakai PHP dari PATH atau lingkungan pengembangan, gunakan `php artisan`
sebagai ganti `.\runtime\php\php.exe artisan`.

### 9.2 Buat Named Tunnel

1. Buka dashboard Cloudflare -> **Networking -> Tunnels**.
2. Buat tunnel baru, pilih lingkungan **Windows**.
3. Salin token tunnel. **Jangan simpan token di dokumentasi atau bagikan ke pengguna lain.**
4. Buka PowerShell sebagai Administrator, lalu jalankan:

   ```powershell
   & "C:\Program Files (x86)\cloudflared\cloudflared.exe" service install <TOKEN>
   Get-Service cloudflared
   ```

   Status service harus `Running`.

5. Di detail tunnel, pilih **Public Hostname -> Add a public hostname**:

   ```text
   Subdomain : wa
   Domain    : domainsekolah.id
   Path      : ^/api/v1/whatsapp/webhook$
   Service   : HTTP
   URL       : http://127.0.0.1:8080
   ```

- Gunakan `http://`, bukan `https://`, karena aplikasi lokal tidak memakai TLS.
- Gunakan `127.0.0.1`, bukan `localhost`, untuk menghindari masalah IPv6 di Windows.
- Path di atas membatasi akses di sisi Cloudflare hanya ke webhook. Middleware aplikasi
  memberi perlindungan tambahan jika konfigurasi path berubah.
- Jangan membuat record CNAME manual. Public Hostname akan membuatnya otomatis.
- Jika port aplikasi berbeda, sesuaikan URL service. Untuk `php artisan serve`
  pengembangan, biasanya `http://127.0.0.1:8000`.

### 9.3 Atur Fonnte

Di dashboard Fonnte, buka pengaturan device:

- Webhook URL: `https://wa.domainsekolah.id/api/v1/whatsapp/webhook`
- Secret webhook: samakan dengan `WA_WEBHOOK_SECRET`
- Auto Read: aktifkan agar status `read` ikut terkirim

### 9.4 Uji

```powershell
curl.exe -i https://wa.domainsekolah.id/api/v1/whatsapp/webhook
curl.exe -i https://wa.domainsekolah.id/login
```

Hasil yang diharapkan:

- Webhook mengembalikan respons dari aplikasi, misalnya `403` karena parameter
  verifikasi tidak lengkap. Itu berarti tunnel sudah sampai ke Laravel.
- `/login` mengembalikan `404` karena path publik dibatasi.
- Login dari alamat LAN tetap berjalan normal.

Untuk uji status pengiriman, gunakan notifikasi absensi nyata. Tombol **Uji Kirim Pesan**
di halaman gateway tidak membuat baris Outbox, sehingga status `delivered/read` tidak
akan terlihat di Outbox.

### 9.5 Jika domain berubah

1. Tambahkan public hostname baru di tunnel Cloudflare.
2. Ubah `WA_WEBHOOK_BASE_URL` di `.env`.
3. Jalankan `optimize:clear`, `optimize`, lalu restart supervisor.
4. Ubah webhook URL di Fonnte.
5. Uji, lalu hapus hostname lama.

Tidak ada perubahan kode yang diperlukan.

### 9.6 Keamanan

- Jangan membuka port `8080` atau `8000` di router.
- Jangan menaruh token tunnel atau secret webhook di dokumentasi, screenshot, atau chat.
- Jangan mengaktifkan Cloudflare Access untuk hostname webhook; Fonnte tidak bisa melewati
  halaman login Access. Jika Access dipakai untuk dashboard, gunakan hostname terpisah.
- Pertahankan `WA_WEBHOOK_PUBLIC_ONLY=true` di produksi.
- Jika Cloudflare menampilkan error `502`, periksa apakah aplikasi benar-benar berjalan di
  port yang dituju dan service `cloudflared` berstatus `Running`.

---

## 10. Troubleshooting

### 10.1 Masalah umum

| Gejala | Penanganan |
| --- | --- |
| Tidak bisa login (instalasi baru) | Akun belum dibuat. Jalankan `php artisan sp:create-admin --name="..." --email="..." --password="..."` (lihat bagian 5). Jangan memakai `db:seed` untuk produksi. |
| "Unsupported cipher or incorrect key length" | Kunci aplikasi rusak. Jalankan `php artisan key:generate --force`. |
| Perubahan tampilan tidak muncul | Aset belum dibangun. Jalankan `npm run build`, lalu refresh keras (Ctrl+F5). |
| Port web/database "sudah dipakai" | Ada aplikasi lain memakai port sama (XAMPP/Laragon). Ganti port, atau hentikan aplikasi tersebut. |
| Pesan WhatsApp tidak terkirim | Cek token gateway di `.env`, lalu buka **Outbox Pesan** dan klik **Kirim Ulang** untuk pesan gagal. Pastikan queue worker berjalan. |
| Webhook Fonnte mengembalikan `403` | Secret tidak cocok. Samakan `WA_WEBHOOK_SECRET` dengan secret di Fonnte, lalu jalankan `optimize:clear` dan `optimize`. |
| Webhook Fonnte mengembalikan `502` | Aplikasi tidak berjalan di port tujuan atau service `cloudflared` berhenti. Cek `Get-NetTCPConnection -State Listen` dan `Get-Service cloudflared`. |
| `/login` dari domain publik mengembalikan `404` | Normal. Akses publik memang dibatasi ke webhook; gunakan alamat LAN untuk dashboard. |
| Status `delivered/read` tidak berubah | Pastikan URL webhook benar, Auto Read aktif, dan pesan dikirim dari aplikasi sehingga `provider_message_id` cocok dengan baris Outbox. |
| Mesin fingerprint Offline | Pastikan mesin menyala dan satu jaringan. Jalankan **Tes Koneksi** pada halaman mesin, lalu sinkronkan ulang. |
| Status online/offline tidak berubah sendiri | Scheduler tidak berjalan. Cek `SmartPresensiSupervisor` dan jalankan `php artisan schedule:list`. |

### 10.2 Khusus Windows

| Gejala | Penanganan |
| --- | --- |
| Wizard tidak terbuka | Jalankan `start-setup.ps1` sebagai Administrator, bukan `setup.cmd`. |
| `setup.cmd` tidak membuka wizard | Itu memang perilaku yang benar. `setup.cmd` menjalankan `setup.ps1` (CLI). Gunakan `start-setup.ps1` untuk wizard. |
| MySQL gagal start | Berkas `ibdata1` terkunci proses lain. Hentikan proses `mysqld` lain, lalu jalankan `.\repair.ps1`. |
| Web belum merespons | Pastikan layanan `SmartPresensiApache` berjalan; periksa folder `data\logs`. |
| Tombol restart di Pusat Kontrol tidak bekerja | Tunggu beberapa detik, lalu jalankan `.\repair.ps1 -RestartServices`. |
| Akun admin tidak terbentuk | Wizard berhenti sebelum langkah Layanan, atau email/kata sandi tidak valid. Jalankan `sp:create-admin` seperti bagian 5. |

### 10.3 Diagnostik cepat

Dari folder instalasi, sebagai Administrator:

```powershell
Get-Service SmartPresensiMySQL, SmartPresensiApache, SmartPresensiSupervisor
steps\setup-steps.ps1 -Action diagnostic -BundleRoot .
steps\setup-steps.ps1 -Action health -BundleRoot .
```

---

## Lampiran - Membangun Paket Rilis Windows (Teknisi)

Bagian ini **hanya untuk teknisi/vendor** yang menyiapkan berkas rilis Windows. Pengguna biasa
(sekolah) tidak perlu membaca ini. Bagian ini tidak berlaku untuk Linux.

### L.1 Prasyarat PC build

- Windows 64-bit + PowerShell 5.1.
- Akses internet (unduh PHP/Apache/MySQL/VC++ resmi).
- Node.js + npm (build aset), PHP + Composer, dan Go (opsional, untuk membangun ulang wizard).

### L.2 Struktur bundle

```text
SmartPresensi\
|-- app\                    Aplikasi Laravel (vendor\, public\build\, .env dibuat saat setup)
|-- runtime\                php\ apache\ mysql\ vcredist\
|-- data\                   Data runtime - KOSONG pada rilis bersih
|-- service\                SmartPresensiSupervisor.exe
|-- com\zkemkeeper.dll      COM SDK mesin fingerprint (32-bit)
|-- templates\              Template php.ini, my.ini, httpd.conf, env.template
|-- steps\setup-steps.ps1   Mesin langkah instalasi (dipakai wizard & CLI)
|-- setup-assistant.exe     Wizard instalasi berbasis web
|-- start-setup.ps1         Pembuka wizard (Windows)
|-- setup.ps1               Pemasangan CLI (Windows)
|-- setup.cmd               Pembungkus CLI setup.ps1 (BUKAN wizard)
|-- repair.ps1 / backup.ps1 / restore.ps1
`-- MANIFEST.txt            Catatan versi komponen & checksum
```

> `data\` dan `app\.env` **tidak** ikut pada rilis bersih; keduanya dibuat saat instalasi agar
> setiap sekolah punya kunci dan kata sandi unik.

### L.3 Membangun bundle

```powershell
powershell -ExecutionPolicy Bypass -File deploy\build_bundle.ps1 -SkipDownload -ZipOnly
```

Hasil default tanpa `-ZipOnly`: folder `dist\SmartPresensi\`. Dengan `-ZipOnly`, folder hasil
build dihapus dan hanya `dist\SmartPresensi.zip` yang disimpan. Parameter penting:

| Parameter | Default | Keterangan |
| --- | --- | --- |
| `-OutputDir` | `<repo>\dist` | Folder keluaran bundle |
| `-CacheDir` | `%TEMP%\smartpresensi-cache` | Cache unduhan komponen |
| `-WebPort` | `8080` | Port web yang ditulis ke MANIFEST/config |
| `-DbPort` | `3307` | Port database yang ditulis ke MANIFEST/config |
| `-SkipDownload` | (mati) | Pakai cache tanpa unduh ulang |
| `-SkipAppCopy` | (mati) | Hanya rakit runtime (tanpa aplikasi) |
| `-Zip` | (mati) | Sekaligus buat `SmartPresensi.zip` |
| `-ZipOnly` | (mati) | Buat ZIP lalu hapus folder hasil build |

Pastikan build aset sudah dijalankan (`npm run build`) sebelum merakit bila ada perubahan
frontend.

### L.4 Membuat rilis (ZIP siap distribusi)

```powershell
powershell -ExecutionPolicy Bypass -File deploy\release.ps1
```

`release.ps1` merakit bundle bersih ke staging sementara (folder `dist\SmartPresensi` yang
berjalan tidak tersentuh) dengan `-ZipOnly`, lalu menyalin hasilnya ke `dist\SmartPresensi.zip`
dan memverifikasi bahwa `setup-assistant.exe` ada, `app\.env` tidak ada, `app\runtime` tidak
ada, `mysql\data` tidak ada, config connector/supervisor tidak terbundel, dan `data\` hanya
berisi folder kosong.

```powershell
# Membangun ulang wizard Go + aset web, lalu unduh komponen:
deploy\release.ps1 -BuildAssistant -GoExe C:\go\bin\go.exe -Download
```

### L.5 Instalasi via CLI Windows (otomatis)

Jalankan sebagai Administrator:

```powershell
.\setup.ps1 -WebPort 8080 -DbPort 3307 -SchoolName "SMA Negeri 1" `
  -AdminName "Administrator Sekolah" -AdminEmail "admin@sekolah.sch.id" `
  -AdminPassword "KataSandiKuat123" -Unattended
```

Parameter umum:

| Parameter | Keterangan |
| --- | --- |
| `-BundleRoot <path>` | Root bundle (default: folder skrip) |
| `-WebPort` / `-DbPort` | Port web / database |
| `-DbName` / `-DbUser` / `-DbPassword` | Nama DB, user, sandi (sandi acak bila kosong) |
| `-SchoolName` | Nama sekolah (tampil di aplikasi & pesan WhatsApp) |
| `-AppUrl` | URL akses (default `http://<IP-LAN>:<WebPort>`) |
| `-SupervisorToken` | Token supervisor (acak bila kosong) |
| `-AdminName` / `-AdminEmail` / `-AdminPassword` | Akun administrator pertama |
| `-SkipServices` `-SkipCom` `-SkipFirewall` `-SkipMigrate` | Lewati tahap tertentu |
| `-Seed` | Jalankan `php artisan db:seed --force` (**akun contoh, bukan produksi**) |
| `-Unattended` | Tanpa prompt interaktif |

> `setup.cmd` dapat dipakai sebagai pembungkus CLI ini, tetapi **tidak** membuka wizard.
> Untuk wizard, gunakan `start-setup.ps1`.

### L.6 Memperbarui instalasi yang sudah berjalan

```powershell
powershell -ExecutionPolicy Bypass -File deploy\update-bundle.ps1 `
  -StopServices -RunRepair -RunMigrations
```

Gunakan `-WhatIf` untuk simulasi dan `-Sync` bila ingin menghapus berkas yang sudah tidak ada
di repo. Untuk rilis penuh, pasang ZIP baru tetapi **jangan timpa `data\` dan `app\.env`**.

### L.7 Catatan distribusi

- **Kredensial** `DatabaseSeeder` bersifat contoh - ganti kata sandi bawaan atau modifikasi
  seeder sebelum distribusi massal.
- **Sumber aplikasi** (PHP + `vendor`) ikut tersalin sebagai teks biasa di `app\`. Bila akan
  didistribusikan luas, pertimbangkan proteksi berkas atau perjanjian lisensi.
