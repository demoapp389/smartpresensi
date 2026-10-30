# Panduan Deployment Linux (Headless / CLI)

Dokumen ini khusus untuk menjalankan SmartPresensi di **server Linux tanpa GUI** (headless),
diakses lewat SSH. Dashboard tetap dibuka dari browser di komputer lain melalui jaringan LAN.

> Memakai Windows? Baca [Panduan Deployment Windows](PANDUAN_DEPLOYMENT_WINDOWS.md).
> Ringkasan perbandingan kedua jalur ada di `PANDUAN_DEPLOYMENT.md`.

**Yang tidak dibutuhkan di server:** desktop environment, browser, X11, atau wizard grafis.
Semua pemasangan dilakukan lewat terminal.

**Yang tidak tersedia di Linux** (dan tidak diperlukan untuk absensi):

| Komponen Windows | Pengganti di Linux |
| --- | --- |
| `setup-assistant.exe`, `setup.ps1`, `setup.cmd` | `deploy/linux/install.sh` (lihat bagian 3) |
| Layanan `SmartPresensiMySQL/Apache/Supervisor` | MySQL/MariaDB, Nginx, systemd |
| `SmartPresensiSupervisor.exe` (port 4372) | unit systemd terpisah untuk worker, scheduler, connector |
| `zkemkeeper.dll` (COM 32-bit) | connector Python `connector/zkconnector.py` atau Web SOAP |
| `SolutionX601Connector.exe` (C#) | `connector/zkconnector.py` |
| `backup.ps1`, `restore.ps1`, `repair.ps1`, `update-bundle.ps1` | `mysqldump`, menu backup aplikasi, `git pull` + artisan |

> **Penting:** connector Python belum diuji ke mesin fisik dari lingkungan pengembangan.
> Wajib diuji di laboratorium dengan mesin Solution X601 sebelum produksi, termasuk `mode=event`.

---

## 1. Prasyarat

Contoh di bawah memakai **Ubuntu 24.04 LTS**. Untuk Debian/Fedora/RHEL, sesuaikan nama paket,
path socket PHP-FPM, dan nama service database.

- Linux 64-bit, RAM minimal **2 GB** (disarankan 4 GB).
- **PHP 8.3** dengan ekstensi: `pdo_mysql`, `mbstring`, `xml`, `curl`, `zip`, `bcmath`,
  `gd`, `intl`, `pcntl`, `posix`.
- **MySQL 8** atau **MariaDB 10.11+**.
- **Nginx** (contoh) atau Apache.
- **Composer 2**.
- **Node.js 22 LTS** dan npm (untuk build aset).
- **Python 3.8+** (untuk connector).
- Akses jaringan ke mesin fingerprint pada port `4370` dan/atau port Web SOAP `80`.

---

## 2. Pasang paket dasar

```bash
sudo apt update
sudo apt install -y nginx mysql-server composer unzip git python3 \
  php8.3-fpm php8.3-cli php8.3-mysql php8.3-mbstring php8.3-xml \
  php8.3-curl php8.3-zip php8.3-bcmath php8.3-gd php8.3-intl \
  php8.3-pcntl php8.3-posix
```

Pasang Node.js 22 (contoh dari NodeSource):

```bash
curl -fsSL https://deb.nodesource.com/setup_22.x | sudo -E bash -
sudo apt install -y nodejs
node -v
npm -v
```

Pastikan PHP yang dipakai CLI adalah 8.3 dan ekstensinya lengkap:

```bash
php -v
php -m | grep -E 'pdo_mysql|mbstring|xml|curl|zip|bcmath|gd|intl|pcntl|posix'
```

---

## 3. Instalasi otomatis (disarankan)

Repositori menyertakan skrip pemasangan **idempoten** yang menyiapkan `.env`, database,
aset, migrasi, akun admin, Nginx, unit systemd, dan cron scheduler.

### 3.1 Ambil source aplikasi ke server

Installer dijalankan **dari dalam folder aplikasi**, jadi source harus berada di server lebih
dulu. Contoh lokasi yang dipakai panduan ini: `/var/www/smartpresensi`.

**Cara 1 - clone dari Git (server punya internet):**

```bash
sudo mkdir -p /var/www
sudo git clone https://github.com/demoapp389/smartpresensi.git /var/www/smartpresensi
```

**Cara 2 - unggah arsip dari komputer lain (server tanpa akses Git):**

```bash
# dari komputer Anda
scp smartpresensi.zip admin@192.168.1.10:/tmp/

# di server
sudo mkdir -p /var/www/smartpresensi
sudo unzip -q /tmp/smartpresensi.zip -d /tmp/smartpresensi-src
sudo cp -a /tmp/smartpresensi-src/. /var/www/smartpresensi/
```

> Pastikan yang diekstrak adalah isi folder aplikasi (`artisan`, `composer.json`, `public/`,
> `deploy/`), bukan folder pembungkus tambahan.

**Server tanpa internet:** `composer install` dan `npm ci` memerlukan akses ke Packagist dan
npm registry. Bila server tertutup, siapkan dependensi di komputer Linux lain dengan PHP 8.3
dan Node 22, lalu unggah hasilnya:

```bash
# di komputer build
composer install --no-dev --optimize-autoloader
npm ci && npm run build

# unggah vendor/ dan public/build/ ke /var/www/smartpresensi, lalu di server:
cd /var/www/smartpresensi
sudo bash deploy/linux/install.sh --skip-composer --skip-build \
  --app-url "http://192.168.1.10" \
  --admin-name "Administrator Sekolah" \
  --admin-email "admin@sekolah.sch.id" \
  --admin-password "KataSandiKuat123"
```

Verifikasi sebelum melanjutkan:

```bash
cd /var/www/smartpresensi
ls artisan composer.json deploy/linux/install.sh
```

> Jangan menyalin `vendor/`, `node_modules/`, atau `public/build/` dari instalasi Windows.
> Bangun ulang di Linux agar ekstensi PHP dan aset cocok.

### 3.2 Jalankan installer

```bash
cd /var/www/smartpresensi
sudo bash deploy/linux/install.sh \
  --app-url "http://192.168.1.10" \
  --admin-name "Administrator Sekolah" \
  --admin-email "admin@sekolah.sch.id" \
  --admin-password "KataSandiKuat123"
```

Cek prasyarat tanpa mengubah apa pun:

```bash
sudo bash deploy/linux/install.sh --check
```

> Bila folder `deploy/linux/` tidak ada (misalnya aplikasi disalin dari bundle Windows yang tidak
> menyertakan folder `deploy/`), gunakan langkah **instalasi manual** di bagian 4.

### 3.3 Opsi penting

| Opsi | Default | Keterangan |
| --- | --- | --- |
| `--app-dir PATH` | folder repo skrip | Lokasi aplikasi (harus berisi `artisan`) |
| `--app-url URL` | `http://<IP-LAN>` | Ditulis ke `APP_URL` saat `.env` dibuat |
| `--server-name NAME` | `_` | `server_name` Nginx (isi domain/IP bila memakai virtual host) |
| `--web-port PORT` | `80` | Port Nginx |
| `--db-name` / `--db-user` / `--db-password` | `absensi_siswa` / `smartpresensi` / acak | Kredensial MySQL yang dibuat |
| `--db-host` / `--db-port` | `127.0.0.1` / `3306` | Lokasi MySQL |
| `--admin-name` / `--admin-email` / `--admin-password` | - | Akun admin pertama (dibuat bila email & sandi diisi) |
| `--scheduler cron\|systemd` | `cron` | Cara menjalankan scheduler |
| `--php-bin PATH` | `php8.3` | Binary PHP CLI |
| `--skip-composer` `--skip-build` `--skip-migrate` `--skip-admin` | mati | Lewati tahap tertentu |
| `--skip-nginx` `--skip-services` `--skip-connector` | mati | Tidak memasang konfigurasi tersebut |
| `--keep-default-site` | mati | Jangan nonaktifkan site `default` Nginx |
| `--overwrite-env` | mati | Buat ulang `.env` dari `.env.example` (berisiko) |
| `--check` | mati | Hanya periksa prasyarat lalu keluar |

### 3.4 Apa yang dilakukan skrip

1. Memeriksa root, PHP 8.3 + ekstensi, Composer, Node/npm, Python 3, Nginx, MySQL.
2. Membuat `.env` dari `.env.example` **hanya bila belum ada** (mengisi `APP_URL`,
   kredensial DB, `CONNECTOR_PYTHON_BIN=/usr/bin/python3`, dan token Local API connector acak).
3. `composer install --no-dev --optimize-autoloader` dan `npm ci && npm run build`.
4. Membuat database + user MySQL (idempoten) dan memberi grant.
5. `php artisan key:generate` (bila `APP_KEY` kosong), `migrate --force`, `sp:create-admin`.
6. Mengatur kepemilikan `www-data` dan izin `storage/`, `bootstrap/cache/`, `connector/`.
7. Memasang site Nginx dari `deploy/linux/nginx/` dan me-reload Nginx.
8. Memasang unit systemd `smartpresensi-worker` dan `solutionx601-connector` dari
   `deploy/linux/systemd/`, plus scheduler (cron `/etc/cron.d/smartpresensi-scheduler`
   atau unit systemd sesuai `--scheduler`).
9. Menjalankan `php artisan optimize` dan `php artisan app:preflight`.

Skrip aman dijalankan ulang: berkas `.env` dan `connector_config.ini` tidak ditimpa, baris
cron tidak berganda, dan unit systemd ditulis ulang lalu di-reload.

> Connector hanya diaktifkan otomatis bila `connector/connector_config.ini` sudah ada.
> Unduh berkas itu dari **Mesin Fingerprint -> Unduh `connector_config.ini`** terlebih dahulu
> (lihat bagian 6).

---

## 4. Instalasi manual (alternatif)

Lewati bagian ini bila memakai `install.sh`.

### 4.1 Siapkan database

```bash
sudo mysql
```

```sql
CREATE DATABASE absensi_siswa
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

CREATE USER 'smartpresensi'@'127.0.0.1'
  IDENTIFIED BY 'GantiDenganSandiKuat';

GRANT ALL PRIVILEGES ON absensi_siswa.* TO 'smartpresensi'@'127.0.0.1';
FLUSH PRIVILEGES;
EXIT;
```

> Jangan membuka port `3306` ke internet. Cukup akses lokal dari server aplikasi.

### 4.2 Letakkan aplikasi

Contoh lokasi: `/var/www/smartpresensi`. Jangan memakai `/var/www/html` sebagai root aplikasi.

```bash
sudo mkdir -p /var/www/smartpresensi
sudo chown -R "$USER":www-data /var/www/smartpresensi

# Salin source aplikasi ke folder tersebut, misalnya dari Git:
git clone <URL-REPOSITORI> /var/www/smartpresensi
```

Struktur yang benar:

```text
/var/www/smartpresensi/artisan
/var/www/smartpresensi/composer.json
/var/www/smartpresensi/public/
/var/www/smartpresensi/connector/
```

Pasang dependensi dan build aset **di server Linux** (jangan menyalin `vendor/`,
`node_modules/`, atau `public/build/` dari instalasi Windows):

```bash
cd /var/www/smartpresensi
composer install --no-dev --optimize-autoloader
npm ci
npm run build
```

### 4.3 Konfigurasi `.env`

```bash
cd /var/www/smartpresensi
cp .env.example .env
nano .env
```

Nilai minimum yang **wajib** disesuaikan:

```env
APP_NAME="Absensi Siswa"
APP_ENV=production
APP_DEBUG=false
APP_URL=http://192.168.1.10

APP_TIMEZONE=Asia/Jakarta
APP_LOCALE=id

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=absensi_siswa
DB_USERNAME=smartpresensi
DB_PASSWORD=GantiDenganSandiKuat

CONNECTOR_PRIMARY_DRIVER=auto
CONNECTOR_PYTHON_BIN=/usr/bin/python3
CONNECTOR_LOCAL_API_URL=http://127.0.0.1:4371
```

`SERVICE_SUPERVISOR_*` boleh dibiarkan kosong: supervisor memang tidak ada di Linux.

Lalu:

```bash
sudo -u www-data php8.3 artisan key:generate
sudo -u www-data php8.3 artisan migrate --force
sudo -u www-data php8.3 artisan sp:create-admin \
  --name="Administrator Sekolah" \
  --email="admin@sekolah.sch.id" \
  --password="KataSandiKuat123"
```

### 4.4 Nginx dan PHP-FPM

Template siap pakai ada di `deploy/linux/nginx/smartpresensi.conf`. Sesuaikan
`server_name`, `root`, dan `fastcgi_pass` (socket bisa berbeda antar distro), lalu:

```bash
sudo cp deploy/linux/nginx/smartpresensi.conf /etc/nginx/sites-available/smartpresensi.conf
sudo ln -sf /etc/nginx/sites-available/smartpresensi.conf /etc/nginx/sites-enabled/smartpresensi.conf
sudo rm -f /etc/nginx/sites-enabled/default   # hindari bentrok port 80
sudo nginx -t && sudo systemctl reload nginx
```

Contoh inti konfigurasi:

```nginx
server {
    listen 80;
    server_name _;
    root /var/www/smartpresensi/public;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

### 4.5 Izin berkas

```bash
cd /var/www/smartpresensi
sudo chown -R "$USER":www-data .
sudo find storage bootstrap/cache connector -type d -exec chmod 2775 {} \;
sudo find storage bootstrap/cache connector -type f -exec chmod 664 {} \;
sudo chmod +x connector/run_connector.sh
```

---

## 5. Queue worker dan scheduler

### 5.1 Queue worker (systemd)

Aplikasi memakai queue `database` dengan tiga nama queue: `default`, `validations`, dan
`long-running`. Gunakan unit dari `deploy/linux/systemd/smartpresensi-worker.service`:

```bash
sudo cp deploy/linux/systemd/smartpresensi-worker.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now smartpresensi-worker
sudo systemctl status smartpresensi-worker
journalctl -u smartpresensi-worker -f
```

> Sesuaikan `DB_QUEUE_RETRY_AFTER` agar lebih besar dari `--timeout`. Unit memakai timeout
> `3660`, jadi `.env` memakai `3720`. Jangan memakai nilai default `1860`.

### 5.2 Scheduler (wajib, pilih salah satu)

Scheduler menjalankan heartbeat status mesin, rekonsiliasi absensi, sinkronisasi jam, dan
pembersihan berkas. Tanpa scheduler, status online/offline tidak terbarui otomatis.

**Cara 1 - cron (disarankan untuk server CLI):**

```bash
sudo cp deploy/linux/cron/smartpresensi-scheduler /etc/cron.d/smartpresensi-scheduler
sudo chmod 644 /etc/cron.d/smartpresensi-scheduler
sudo systemctl restart cron
```

Isi berkas:

```cron
SHELL=/bin/sh
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
MAILTO=""
* * * * * www-data cd /var/www/smartpresensi && /usr/bin/php8.3 artisan schedule:run >> /var/www/smartpresensi/storage/logs/scheduler.log 2>&1
```

**Cara 2 - systemd:**

```bash
sudo cp deploy/linux/systemd/smartpresensi-scheduler.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now smartpresensi-scheduler
```

> Jangan menjalankan cron dan systemd scheduler bersamaan. Pilih satu.

Cek jadwal:

```bash
sudo -u www-data php8.3 artisan schedule:list
```

---

## 6. Connector Python (koneksi ke mesin)

Connector harus berada di folder `connector/` **di dalam aplikasi**, bukan salinan terpisah di
`/opt`, karena aplikasi juga menjalankan `connector/zkconnector.py` untuk tes dan fallback.

> **Urutan pembaruan penting:** pasang/pembarui aplikasi Laravel lebih dulu, baru connector.
> Jangan menjalankan connector Python dan connector C# legacy untuk mesin yang sama.

1. Di aplikasi, buka **Mesin Fingerprint -> Tambah Mesin**, lalu unduh
   `connector_config.ini`.
2. Simpan ke `/var/www/smartpresensi/connector/connector_config.ini`.
3. Pastikan `api_url` di berkas itu mengarah ke aplikasi:
   - Connector di server yang sama: `http://127.0.0.1/api/v1/fingerprint/ingest` atau
     `http://127.0.0.1:8080/...` bila memakai port non-standar.
   - Connector di komputer lain: `http://IP-SERVER/api/v1/fingerprint/ingest`.
4. Samakan pemilik folder dengan pengguna queue worker:

   ```bash
   sudo chown -R "$USER":www-data /var/www/smartpresensi/connector
   sudo find /var/www/smartpresensi/connector -type d -exec chmod 2775 {} \;
   sudo find /var/www/smartpresensi/connector -type f -exec chmod 664 {} \;
   ```

5. Pasang unit systemd (template sudah memakai path aplikasi):

   ```bash
   sudo cp deploy/linux/systemd/solutionx601-connector.service /etc/systemd/system/
   sudo systemctl daemon-reload
   sudo systemctl enable --now solutionx601-connector
   sudo systemctl status solutionx601-connector
   journalctl -u solutionx601-connector -f
   ```

6. Uji koneksi mesin:

   ```bash
   cd /var/www/smartpresensi/connector
   sudo -u www-data python3 zkconnector.py --test --ip=192.168.1.201 --port=4370
   sudo -u www-data python3 zkconnector.py --probe --device=gerbang
   ```

Connector juga menyamakan jam mesin secara otomatis setiap `clock_sync_minutes` (default
60 menit) bila selisih melebihi `clock_max_drift_seconds` (default 5 detik). Mesin yang
lebih cepat tidak dimundurkan kecuali `clock_allow_backward=true`. Pastikan jam host server
benar dan memakai timezone yang sama dengan aplikasi (`Asia/Jakarta`).

Koreksi manual:

```bash
sudo -u www-data python3 zkconnector.py --sync-time --device=gerbang
sudo -u www-data python3 zkconnector.py --sync-logs --device=gerbang --since="2026-10-23 07:00:00"
```

`--since` hanya memfilter record yang dikirim ke Laravel; connector tetap membaca log dari
mesin. Sesuaikan `--device` dengan key mesin pada `connector_config.ini`; tanpa `--device`,
connector memakai mesin aktif pertama.

**Alternatif tanpa connector:** gunakan **Web SOAP**. Di halaman mesin, jalankan **Tes
Koneksi**; jika mesin mendukung Web SOAP, aplikasi dapat memakai jalur SOAP port 80. Web SOAP
berjalan lintas platform karena hanya memakai koneksi HTTP dari PHP (tidak butuh `ext-soap`).

---

## 7. Catatan khusus server headless

1. **Timezone server** - `sudo timedatectl set-timezone Asia/Jakarta`. Connector memakai jam
   host untuk `--sync-time` dan watermark log.
2. **`disable_functions`** - `proc_open` harus aktif di **php.ini FPM** (tombol Tes
   Koneksi/Sinkron dari dashboard) dan **php.ini CLI** (scheduler/cron). Periksa:

   ```bash
   php -i | grep disable_functions
   grep -n disable_functions /etc/php/8.3/fpm/php.ini
   ```

3. **PATH sempit di systemd/cron** - gunakan path absolut (`/usr/bin/php8.3`,
   `/usr/bin/python3`), atau set `CONNECTOR_PYTHON_BIN` di `.env`.
4. **Izin tulis** - `www-data` harus bisa menulis `storage/`, `bootstrap/cache/`, dan
   `connector/state/`. Jalankan connector, worker, dan scheduler sebagai user yang sama.
5. **Firewall** - `sudo ufw allow 80/tcp` untuk dashboard; pastikan server bisa keluar ke
   port **4370** (ZK) dan/atau **80** (SOAP) mesin. Di RHEL/Fedora:
   `sudo setsebool -P httpd_can_network_connect on`.
6. **Jangan pakai `php artisan serve`** untuk produksi - gunakan Nginx + PHP-FPM.
7. **Build aset di Linux** - `npm ci && npm run build`; jangan salin `public/build` dari
   Windows.
8. **Cetak/PDF laporan** - halaman laporan adalah tampilan siap cetak yang di-print dari
   browser klien. Server tidak membutuhkan Chrome/wkhtmltopdf.
9. **Pusat Kontrol Sistem** - panel akan menampilkan supervisor mati karena supervisor
   Windows tidak ada. Gunakan `systemctl`/`journalctl`; status mesin tetap akurat karena
   heartbeat memakai scheduler + koneksi LAN.

---

## 8. Uji instalasi

```bash
cd /var/www/smartpresensi

php artisan about
php artisan migrate:status
php artisan schedule:list
php artisan app:preflight

sudo systemctl status nginx php8.3-fpm smartpresensi-worker solutionx601-connector
curl -I http://127.0.0.1/
```

Uji heartbeat dan rekonsiliasi manual:

```bash
sudo -u www-data php8.3 artisan fingerprint:health-check
sudo -u www-data php8.3 artisan fingerprint:reconcile --force
```

Lalu:

1. Buka `http://IP-SERVER` dari komputer lain.
2. Login dengan akun admin yang dibuat.
3. Buka **Mesin Fingerprint**, jalankan **Tes Koneksi**.
4. Pastikan badge mesin berubah menjadi online.
5. Lakukan satu absensi uji, lalu cek **Log Mentah**/laporan.
6. Cek antrean dan job gagal:

   ```bash
   sudo -u www-data php8.3 artisan queue:failed
   ```

---

## 9. Backup, restore, dan update

**Backup dari aplikasi:** buka menu backup/Pusat Kontrol Sistem -> **Unduh Dump SQL**.
Backup ini dibuat oleh PHP dan berjalan lintas platform. Kolom password dan token sengaja
dikosongkan pada dump.

**Backup manual:**

```bash
mysqldump -u smartpresensi -p absensi_siswa > /home/admin/backup_absensi_$(date +%F).sql
```

**Restore manual:**

```bash
mysql -u smartpresensi -p absensi_siswa < /home/admin/backup_absensi_2026-01-01.sql
```

**Update aplikasi:**

```bash
cd /var/www/smartpresensi
sudo -u www-data php8.3 artisan down

# backup database dulu
mysqldump -u smartpresensi -p absensi_siswa > /home/admin/backup_sebelum_update.sql

git pull
composer install --no-dev --optimize-autoloader
npm ci
npm run build

sudo -u www-data php8.3 artisan migrate --force
sudo -u www-data php8.3 artisan optimize

sudo systemctl restart smartpresensi-worker
sudo systemctl restart solutionx601-connector
sudo -u www-data php8.3 artisan up
```

> Jangan menimpa `.env` dan folder `storage/` saat update. Keduanya berisi konfigurasi dan
> data runtime.
>
> Jika connector berada di host terpisah, perbarui aplikasi Laravel lebih dulu dan pastikan
> endpoint ingest baru sudah aktif sebelum memperbarui connector. Connector versi baru akan
> menahan watermark bila `summary.accepted` belum tersedia, sehingga data tidak dianggap
> terkirim sebelum server siap.

---

## 10. Batasan Linux yang perlu diketahui

- **Pusat Kontrol Sistem** akan mencoba supervisor Windows di `127.0.0.1:4372`. Karena tidak
  ada supervisor di Linux, status keseluruhan bisa terlihat *degraded* atau *offline* dan
  tombol restart tidak bekerja. Ini **normal**. Gunakan:

  ```bash
  sudo systemctl restart smartpresensi-worker
  sudo systemctl restart solutionx601-connector
  sudo systemctl status smartpresensi-worker solutionx601-connector
  ```

- **Status mesin** tetap berjalan karena heartbeat memakai scheduler dan koneksi LAN, bukan
  supervisor Windows.
- **Backup lewat menu aplikasi** tetap berjalan karena dibuat oleh PHP.
- **Connector Python masih eksperimental** dan belum diuji ke mesin fisik. Uji di lab.
- **Tidak ada dukungan resmi** untuk kombinasi distribusi Linux dan mesin tertentu. Panduan
  ini menjelaskan cara memasang, bukan jaminan produksi.

---

## 11. Troubleshooting

### 11.1 Masalah umum

| Gejala | Penanganan |
| --- | --- |
| Tidak bisa login (instalasi baru) | Akun belum dibuat. Jalankan `php artisan sp:create-admin --name="..." --email="..." --password="..."`. Jangan memakai `db:seed` untuk produksi. |
| "Unsupported cipher or incorrect key length" | Kunci aplikasi rusak. Jalankan `php artisan key:generate --force`. |
| Perubahan tampilan tidak muncul | Aset belum dibangun. Jalankan `npm run build`, lalu refresh keras (Ctrl+F5). |
| Port web/database "sudah dipakai" | Ada layanan lain memakai port sama. Ganti port, atau hentikan layanan tersebut. |
| Pesan WhatsApp tidak terkirim | Cek token gateway di `.env`, lalu buka **Outbox Pesan** dan klik **Kirim Ulang** untuk pesan gagal. Pastikan queue worker berjalan. |
| Webhook Fonnte mengembalikan `403` | Secret tidak cocok. Samakan `WA_WEBHOOK_SECRET` dengan secret di Fonnte, lalu jalankan `optimize:clear` dan `optimize`. |
| Webhook Fonnte mengembalikan `502` | Aplikasi tidak berjalan di port tujuan atau tunnel berhenti. Cek `ss -ltnp` dan `systemctl status cloudflared`. |
| `/login` dari domain publik mengembalikan `404` | Normal. Akses publik memang dibatasi ke webhook; gunakan alamat LAN untuk dashboard. |
| Status `delivered/read` tidak berubah | Pastikan URL webhook benar, Auto Read aktif, dan pesan dikirim dari aplikasi sehingga `provider_message_id` cocok dengan baris Outbox. |
| Mesin fingerprint Offline | Pastikan mesin menyala dan satu jaringan. Jalankan **Tes Koneksi** pada halaman mesin, lalu sinkronkan ulang. |
| Status online/offline tidak berubah sendiri | Scheduler tidak berjalan. Cek cron/systemd scheduler dan `php artisan schedule:list`. |

### 11.2 Khusus Linux

| Gejala | Penanganan |
| --- | --- |
| Halaman 502 Bad Gateway | Cek `sudo systemctl status php8.3-fpm`; pastikan `fastcgi_pass` cocok dengan socket di `/run/php/`. |
| Halaman 500 / permission denied | Cek `storage/logs/laravel.log`; pastikan `www-data` bisa menulis `storage/` dan `bootstrap/cache/`. |
| Login gagal setelah migrasi | Buat admin dengan `sp:create-admin`. Jangan mengandalkan `db:seed`. |
| Worker tidak memproses antrean | `sudo systemctl status smartpresensi-worker`; `journalctl -u smartpresensi-worker -n 100`; cek tabel `jobs`. |
| Status mesin tidak update | Pastikan cron/systemd scheduler berjalan; jalankan `php artisan schedule:list` dan `fingerprint:health-check` manual. |
| Pusat Kontrol menampilkan supervisor mati | Normal di Linux. Gunakan `systemctl` dan `journalctl`; panel supervisor memang Windows-only. |
| Connector gagal menulis state | Pastikan connector dan queue worker memakai pengguna yang sama, atau izin tulis `connector/state/` benar. |
| Mesin tidak bisa dihubungi | Cek IP/port/comm key; coba `python3 zkconnector.py --test --ip=... --port=4370`; pastikan tidak ada firewall yang memblokir. |
| Tombol Tes Koneksi error "Connector tidak dapat dijalankan" | `proc_open` dinonaktifkan di php.ini FPM, atau `python3` tidak ada di PATH layanan. Set `CONNECTOR_PYTHON_BIN=/usr/bin/python3`. |

### 11.3 "Gagal membaca buffer pada offset 0 ukuran 65472"

Gejala di `journalctl -u solutionx601-connector`:

```text
[05:40:34] [LAN] default offline: Gagal membaca buffer pada offset 0 ukuran 65472 (kode respons ...)
```

Angka `65472` adalah `TCP_MAX_CHUNK` (64 KB). Artinya mesin menolak pembacaan buffer
dengan chunk 64 KB. Connector versi terbaru otomatis mencoba chunk yang lebih kecil
(`32768`, `16384`, `8192`, `4096`, `1024`) lalu fallback ke pembacaan non-buffered.
Jalankan diagnostik untuk melihat ukuran mana yang diterima mesin:

```bash
cd /var/www/smartpresensi
sudo -u www-data python3 connector/zkconnector.py --diagnose --device=default
```

Contoh keluaran yang perlu diperhatikan:

```json
{"device":"192.168.1.20:4370","tcp_reachable":true,"connected":true,
 "records":812,"chunk_tests":[
   {"chunk_size":65472,"success":false,"error":"..."},
   {"chunk_size":32768,"success":true,"elapsed_seconds":0.4}
 ],
 "recommendation":"Chunk 32768 byte berhasil. ..."}
```

Bila semua chunk gagal:

1. Pastikan hanya satu proses yang membuka port mesin:

   ```bash
   sudo ss -tnp | grep 4370
   ```

   Heartbeat `fingerprint:health-check` sekarang membaca status connector
   (`/api/status`) dan tidak lagi membuka koneksi TCP mentah. Bila masih ada dua
   koneksi `ESTABLISHED`, hentikan Tes Koneksi/sinkronisasi manual yang berjalan
   bersamaan.
2. Cek kapasitas dan jam mesin: `--probe` lalu `--diagnose`.
3. Bila firmware tetap menolak pembacaan buffered, gunakan jalur **Web SOAP**
   (port 80 mesin) dengan mengubah `ingest_driver` perangkat menjadi `soap`.

`BrokenPipeError: [Errno 32] Broken pipe` pada log connector bukan penyebab mesin
offline: itu terjadi saat dashboard menutup koneksi ke Local API sebelum respons
selesai dikirim. Versi terbaru menanganinya tanpa menghentikan service.

### 11.4 Diagnostik cepat

```bash
sudo systemctl status nginx php8.3-fpm smartpresensi-worker solutionx601-connector
sudo journalctl -u smartpresensi-worker -n 100
sudo journalctl -u solutionx601-connector -n 100
sudo -u www-data php8.3 artisan schedule:list
sudo -u www-data php8.3 artisan app:preflight
sudo -u www-data python3 connector/zkconnector.py --test --ip=<IP_MESIN> --port=4370
sudo -u www-data python3 connector/zkconnector.py --diagnose --device=default
```
