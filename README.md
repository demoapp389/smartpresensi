# SmartPresensi

Sistem absensi siswa berbasis sidik jari untuk sekolah: Laravel 13 (PHP 8.3) + MySQL,
dengan connector mesin fingerprint Solution X601 (LAN), notifikasi WhatsApp, laporan
absensi, dan panel admin.

Repositori ini disiapkan untuk pemasangan di **server Linux headless** (SSH, tanpa GUI).
Panduan lengkap ada di [`docs/PANDUAN_DEPLOYMENT_LINUX.md`](docs/PANDUAN_DEPLOYMENT_LINUX.md).

## Kebutuhan server

- Linux 64-bit (contoh: Ubuntu 24.04 LTS), RAM minimal 2 GB (disarankan 4 GB).
- PHP 8.3 + ekstensi `pdo_mysql`, `mbstring`, `xml`, `curl`, `zip`, `bcmath`, `gd`, `intl`, `pcntl`, `posix`.
- MySQL 8 atau MariaDB 10.11+.
- Nginx (contoh) atau Apache, Composer 2, Node.js 22 + npm.
- Python 3.8+ untuk connector mesin fingerprint.
- Akses LAN ke mesin fingerprint pada port `4370` (ZK) dan/atau port `80` (Web SOAP).

## Instalasi cepat (Linux)

```bash
sudo apt update
sudo apt install -y nginx mysql-server composer unzip git python3 \
  php8.3-fpm php8.3-cli php8.3-mysql php8.3-mbstring php8.3-xml \
  php8.3-curl php8.3-zip php8.3-bcmath php8.3-gd php8.3-intl \
  php8.3-pcntl php8.3-posix

git clone https://github.com/demoapp389/smartpresensi.git /var/www/smartpresensi
cd /var/www/smartpresensi

sudo bash deploy/linux/install.sh \
  --app-url "http://192.168.1.10" \
  --admin-name "Administrator Sekolah" \
  --admin-email "admin@sekolah.sch.id" \
  --admin-password "KataSandiKuat123"
```

Skrip `deploy/linux/install.sh` bersifat idempoten: menyiapkan `.env`, database, aset,
migrasi, akun admin, site Nginx, unit systemd (`smartpresensi-worker`,
`solutionx601-connector`), dan scheduler. Periksa prasyarat tanpa mengubah apa pun:

```bash
sudo bash deploy/linux/install.sh --check
```

Bila skrip tidak dipakai, ikuti langkah manual pada panduan Linux (bagian 4).

## Setelah instalasi

1. Buka `http://IP-SERVER` dari komputer lain dan login sebagai administrator.
2. Buka **Mesin Fingerprint -> Tambah Mesin**, isi IP/port/comm key, lalu unduh
   `connector_config.ini` dan simpan ke `connector/connector_config.ini`.
3. Aktifkan connector: `sudo systemctl enable --now solutionx601-connector`.
4. Uji koneksi mesin:

   ```bash
   sudo -u www-data python3 connector/zkconnector.py --test --ip=<IP_MESIN> --port=4370
   ```

Status layanan: `systemctl status nginx php8.3-fpm smartpresensi-worker solutionx601-connector`.

> **Catatan:** connector Python belum diuji terhadap mesin fisik dari lingkungan
> pengembangan. Wajib diuji di laboratorium dengan mesin Solution X601 sebelum produksi,
> termasuk `mode=event`. Alternatif tanpa connector adalah **Web SOAP** (port 80 mesin).

## Struktur penting

| Path | Keterangan |
| --- | --- |
| `deploy/linux/` | `install.sh`, unit systemd, site Nginx, dan cron scheduler untuk Linux |
| `connector/` | Connector Python (`zkconnector.py`) + dokumentasi connector |
| `docs/` | Panduan deployment Linux dan Windows |
| `app/`, `resources/`, `routes/` | Kode aplikasi Laravel |

## Deployment Windows

Panduan bundle Windows (wizard + layanan Windows + connector C#) ada di
[`docs/PANDUAN_DEPLOYMENT_WINDOWS.md`](docs/PANDUAN_DEPLOYMENT_WINDOWS.md). Ringkasan
perbandingan kedua jalur ada di [`PANDUAN_DEPLOYMENT.md`](PANDUAN_DEPLOYMENT.md).

## Lisensi

Digunakan untuk kebutuhan internal sekolah.
