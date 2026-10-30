# Panduan Deployment SmartPresensi

Dokumen ini adalah **ringkasan pemilihan jalur instalasi**. Panduan langkah demi langkah
dipisah per sistem operasi agar tidak tercampur:

| Panduan | Isi |
| --- | --- |
| [Deployment di Windows](docs/PANDUAN_DEPLOYMENT_WINDOWS.md) | Bundle siap pakai, wizard, layanan Windows, connector C#, perawatan, dan lampiran build rilis |
| [Deployment di Linux](docs/PANDUAN_DEPLOYMENT_LINUX.md) | Server headless/CLI, `install.sh`, Nginx + PHP-FPM, systemd, connector Python, dan batasan Linux |

---

## Pilih Jalur Instalasi

| | Windows (Bundle Siap Pakai) | Linux (Headless / CLI) |
| --- | --- | --- |
| Status | **Siap dipakai** | **Bisa, tetapi manual dan belum diuji produksi oleh vendor** |
| Cocok untuk | Kebanyakan sekolah, satu PC | Teknisi yang terbiasa dengan server Linux |
| Cara pasang | Wizard otomatis | Skrip `deploy/linux/install.sh` atau langkah manual |
| Yang dipasang | PHP + Apache + MySQL portable + aplikasi + connector | PHP-FPM + Nginx + MySQL/MariaDB + aplikasi + connector Python |
| Koneksi mesin fingerprint | Connector C# bawaan (otomatis) | Connector Python (eksperimental) atau Web SOAP |
| Kontrol layanan | Pusat Kontrol Sistem / Windows Service | `systemctl`, `journalctl`, cron |
| Tingkat kesulitan | **Mudah** | **Mahir** |

**Alur singkat (Windows):**

```text
Terima berkas -> Jalankan start-setup.ps1 -> Ikuti wizard -> Login -> Siap dipakai
```

**Alur singkat (Linux):**

```text
Pasang PHP/MySQL/Nginx -> Ambil source ke server (git clone / unggah ZIP)
-> install.sh (atau manual) -> Nginx/PHP-FPM -> Worker + scheduler + connector -> Uji
```

Contoh mengambil source dan menjalankan installer:

```bash
sudo git clone https://github.com/demoapp389/smartpresensi.git /var/www/smartpresensi
cd /var/www/smartpresensi
sudo bash deploy/linux/install.sh --app-url "http://192.168.1.10" \
  --admin-name "Administrator Sekolah" --admin-email "admin@sekolah.sch.id" \
  --admin-password "KataSandiKuat123"
```

> Installer harus dijalankan dari dalam folder aplikasi. Bila server tanpa internet, unggah
> `vendor/` dan `public/build/` hasil build Linux lalu tambahkan `--skip-composer --skip-build`
> (lihat panduan Linux bagian 3.1).

---

## Yang Belum Lengkap di Linux

Aplikasi web Laravel-nya **bisa** berjalan di Linux. Yang tidak bisa adalah komponen khusus
Windows berikut:

| Komponen | Linux | Keterangan |
| --- | --- | --- |
| `setup-assistant.exe`, `setup.ps1`, `setup.cmd`, `steps\setup-steps.ps1` | **Tidak bisa** | Wizard dan mesin langkah memakai PowerShell, Windows Service, registry, dan COM. Penggantinya `deploy/linux/install.sh`. |
| Layanan `SmartPresensiMySQL`, `SmartPresensiApache`, `SmartPresensiSupervisor` | **Tidak bisa** | Itu Windows Service. Di Linux gunakan MySQL/MariaDB, Nginx/Apache, dan systemd. |
| `SmartPresensiSupervisor.exe` | **Tidak bisa** | Supervisor background khusus Windows. Di Linux gunakan unit systemd terpisah untuk worker, scheduler, dan connector. |
| `zkemkeeper.dll` (COM 32-bit) | **Tidak bisa** | COM hanya tersedia di Windows. Di Linux gunakan connector Python atau Web SOAP. |
| Connector C# `SolutionX601Connector.exe` | **Tidak didukung** | Binari .NET/COM untuk Windows. Di Linux gunakan `connector/zkconnector.py`. |
| Pusat Kontrol Sistem: status supervisor dan tombol restart | **Tidak berfungsi penuh** | Panel menghubungi supervisor Windows di port `4372`. Di Linux panel dapat menampilkan status *degraded/offline* dan tombol restart gagal. Gunakan `systemctl`, `journalctl`, dan cron/systemd. |
| `backup.ps1`, `restore.ps1`, `repair.ps1`, `update-bundle.ps1` | **Tidak bisa** | Skrip PowerShell dengan path dan service Windows. Gunakan `mysqldump`, menu backup aplikasi, dan perintah Linux di panduan Linux. |
| `deploy/` dan bundle `SmartPresensi.zip` | **Tidak bisa** | Bundle hanya untuk Windows. Di Linux aplikasi dipasang dari source. |

Yang **bisa** berjalan di Linux:

- Aplikasi Laravel, halaman admin, absensi, laporan, dan API.
- MySQL atau MariaDB.
- PHP-FPM + Nginx atau Apache.
- Queue worker Laravel.
- Scheduler Laravel (wajib, untuk status online/offline dan rekonsiliasi).
- Connector Python `connector/zkconnector.py`.
- Web SOAP langsung ke mesin (port 80 HTTP mesin), tanpa connector.
- Backup database lewat menu aplikasi atau `mysqldump`.

> **Penting:** connector Python **belum diuji ke mesin fisik** dari lingkungan pengembangan.
> Wajib diuji di laboratorium dengan mesin Solution X601 sebelum dipakai produksi. Ini juga
> berlaku untuk `mode=event`.

---

## Troubleshooting

Tabel masalah umum dan langkah diagnostik ada di bagian **Troubleshooting** pada masing-masing
panduan, agar sesuai dengan perintah sistem operasi yang dipakai:

- Windows: `docs/PANDUAN_DEPLOYMENT_WINDOWS.md` bagian 10.
- Linux: `docs/PANDUAN_DEPLOYMENT_LINUX.md` bagian 11.

Masalah yang paling sering muncul di kedua platform:

| Gejala | Penanganan |
| --- | --- |
| Tidak bisa login setelah instalasi | Akun admin belum dibuat. Jalankan `php artisan sp:create-admin --name="..." --email="..." --password="..."`. Jangan memakai `db:seed` untuk produksi. |
| Perubahan tampilan tidak muncul | Aset belum dibangun. Jalankan `npm run build`, lalu refresh keras (Ctrl+F5). |
| Mesin fingerprint Offline | Pastikan mesin menyala dan satu jaringan. Jalankan **Tes Koneksi** pada halaman mesin, lalu sinkronkan ulang. |
| Status online/offline tidak berubah sendiri | Scheduler tidak berjalan. Windows: cek `SmartPresensiSupervisor`. Linux: cek cron/systemd scheduler dan `php artisan schedule:list`. |
