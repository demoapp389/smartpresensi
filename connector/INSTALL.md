# Connector Solution X601 (Lintas OS)

Connector ini menggantikan connector C# lama (`legacy-windows-csharp/`) yang terikat
COM `zkemkeeper.ZKEM` di Windows. Ditulis dengan Python 3 (modul standar saja), sehingga
dapat berjalan di **Windows dan Linux** tanpa instalasi dependency.

## Berkas

| Berkas | Keterangan |
| --- | --- |
| `zkconnector.py` | Program utama: CLI + daemon multi-mesin + Local HTTP API |
| `zk_protocol.py` | Implementasi protokol ZK (TCP 4370), tanpa COM |
| `connector_config.ini` | Konfigurasi koneksi & autentikasi (satu atau beberapa mesin) |
| `run_connector.bat` / `run_connector.sh` | Launcher Windows / Linux |
| `solutionx601-connector.service` | Unit systemd (Linux) |
| `checkpoint.txt`, `offline_buffer.txt` | Watermark & buffer mesin legacy (dibuat otomatis) |
| `state/<key>/` | Watermark & buffer per mesin (dibuat otomatis) |
| `tests/` | Uji unit (`python -m unittest discover -s connector/tests`) |

## Prasyarat

- Python 3.8+ (`python3` di Linux, `python`/`py` di Windows).
- Mesin Solution X601 dapat dijangkau di LAN pada port `4370`.

## Konfigurasi (`connector_config.ini`)

### Satu mesin (format lama, tetap didukung)

```ini
device_ip=100.50.25.67
device_port=4370
comm_key=0
api_url=http://127.0.0.1:8000/api/v1/fingerprint/ingest
device_token=x601_sec_token_school_solution_lan
poll_interval_seconds=5
reconnect_interval_seconds=5
local_api_port=4371
clock_sync_minutes=60
clock_max_drift_seconds=5
clock_allow_backward=false
```

### Beberapa mesin (multi-mesin)

```ini
[default]
api_url=http://127.0.0.1:8000/api/v1/fingerprint/ingest
poll_interval_seconds=5
reconnect_interval_seconds=5
local_api_port=4371
batch_size=20
clock_sync_minutes=60
clock_max_drift_seconds=5
clock_allow_backward=false

[device:gerbang]
ip=100.50.25.67
port=4370
comm_key=0
token=x601_sec_token_gerbang
enabled=true
mode=poll

[device:kelas-a]
ip=100.50.25.68
port=4370
comm_key=0
token=x601_sec_token_kelas_a
enabled=true
mode=poll
```

`device_token`/`token` harus sama dengan token mesin di dashboard. File konfigurasi
multi-mesin siap pakai (memuat **semua mesin aktif**) dapat diunduh dari halaman
**Kelola Mesin → Unduh connector_config.ini**; cukup simpan hasil unduhan apa adanya
sebagai `connector_config.ini` di folder connector. Jangan menaruh token di repositori.

Setiap mesin menyimpan watermark & offline buffer-nya sendiri di `state/<key>/`
(mesin tunggal format lama tetap memakai `checkpoint.txt`/`offline_buffer.txt` di folder
connector agar data lama tidak hilang).

**Mode ingest per mesin** (`mode`):

- `poll` (default) — tarik log inkremental tiap `poll_interval_seconds`. Jalur paling stabil.
- `event` — daftarkan mesin untuk event realtime (`CMD_REG_EVENT`) lalu rekonsiliasi lewat
  poll. **Eksperimental**: wajib diuji di laboratorium dengan mesin fisik sebelum produksi
  karena dukungan firmware berbeda-beda.

**Sinkronisasi jam** (sama dengan connector C#):

- `clock_sync_minutes=0` menonaktifkan koreksi otomatis; default `60` menit.
- Koreksi hanya dilakukan bila selisih jam melebihi `clock_max_drift_seconds` (default `5`).
- Mesin yang lebih cepat tidak dimundurkan kecuali `clock_allow_backward=true` untuk
  koreksi otomatis. Sinkronisasi manual via CLI/API selalu menolak koreksi mundur.
- Bila jam dimundurkan, watermark yang berada di masa depan direset ke satu detik sebelum
  waktu target agar scan setelah koreksi tidak terlewat.
- Hanya jam mesin yang diubah. Log, user, dan template tetap read-only.

## Menjalankan

```sh
# Windows
run_connector.bat

# Linux
./run_connector.sh
```

Mode CLI (dipakai Laravel sebagai fallback). Flag `--device=<key>` memilih mesin; tanpa
`--device` dipakai mesin pertama yang aktif:

```sh
python3 zkconnector.py --test         --ip=100.50.25.67 --port=4370
python3 zkconnector.py --probe        --device=gerbang
python3 zkconnector.py --diagnose     --device=gerbang
python3 zkconnector.py --sync-students --device=kelas-a
python3 zkconnector.py --sync-logs    --device=gerbang
python3 zkconnector.py --sync-logs    --device=gerbang --since="2026-10-23 07:00:00"
python3 zkconnector.py --sync-time    --device=gerbang
python3 zkconnector.py --scan-lan
```

`--since` memfilter log yang dikembalikan ke Laravel (bukan mengurangi jumlah data yang
dibaca dari mesin). `--sync-time` selalu memakai jam host saat ini; target waktu eksplisit
tidak didukung agar jam mesin tidak bisa dimundurkan lewat CLI.

`--diagnose` menguji TCP, kapasitas, jam, dan setiap ukuran chunk buffered
(`65472`, `32768`, `16384`, `8192`, `4096`, `1024`) lalu mencetak rekomendasi. Dipakai saat
log connector berisi `Gagal membaca buffer pada offset 0 ukuran 65472`. Pembacaan normal
sudah otomatis memakai chunk adaptif + fallback non-buffered, jadi `--diagnose` hanya untuk
memastikan ukuran mana yang diterima firmware.

Semua mode CLI mencetak satu objek JSON ke stdout (dibaca `FingerprintConnectorService`).

Daemon mode (default tanpa argumen) menjalankan satu worker per mesin plus Local HTTP API di
`http://127.0.0.1:4371` (`/api/status`, `/api/test`, `/api/probe`, `/api/sync-students`,
`/api/sync-logs?since=...`, `/api/sync-time`). Endpoint tetap menerima override
`ip`/`port`/`comm_key`, tetapi `/api/sync-time` hanya memakai jam host dan menolak
parameter `time`/`allow_backward`.

Ingest ke Laravel hanya dianggap sukses bila response HTTP 2xx, `status=success`, dan
`summary.accepted` sedikitnya sama dengan jumlah record yang dikirim. Jika tidak, watermark
tidak dimajukan dan record disimpan ke `offline_buffer.txt` untuk dicoba lagi.

## Autostart

### Windows (Task Scheduler)

1. Buat task "At startup", Program: `python`, Arguments: `zkconnector.py`,
   Start in: folder connector.

### Linux (systemd)

Jika connector dipasang bersama aplikasi Laravel di server yang sama, jalankan connector dari
folder `<app>/connector` (misalnya `/var/www/smartpresensi/connector`), bukan salinan terpisah
di `/opt`. Aplikasi juga menjalankan `connector/zkconnector.py` untuk tes dan fallback; bila
foldernya berbeda, konfigurasi dan state bisa terpisah. Panduan lengkap ada di
`docs/PANDUAN_DEPLOYMENT_LINUX.md` bagian 6.

Contoh berikut untuk connector standalone di `/opt`:

```sh
sudo useradd -r -s /usr/sbin/nologin smartpresensi
sudo mkdir -p /opt/smartpresensi/connector
sudo cp -r ./* /opt/smartpresensi/connector/
sudo chown -R smartpresensi:smartpresensi /opt/smartpresensi/connector
sudo cp /opt/smartpresensi/connector/solutionx601-connector.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now solutionx601-connector
journalctl -u solutionx601-connector -f
```

## Integrasi Laravel

`FingerprintConnectorService` memilih otomatis: gunakan Python bila tersedia, jika tidak
jatuh ke binary C# lama. Variabel opsional di `.env`:

```env
CONNECTOR_PYTHON_BIN=python3
CONNECTOR_ENTRYPOINT=connector/zkconnector.py
CONNECTOR_LOCAL_API_URL=http://127.0.0.1:4371
```

## Uji

```sh
python -m unittest discover -s connector/tests
```

## Catatan

- **Multi-mesin**: satu proses connector melayani semua mesin pada `connector_config.ini`
  (satu worker per mesin, lock per mesin). Tidak perlu menjalankan beberapa connector.
- **Migrasi aman**: `source_key` (`x601_{pin}_{unix_sec}`) dihitung identik dengan C# dan
  jalur Web SOAP, sehingga log yang sama tidak terduplikasi antar-jalur.
- **Read-only data**: connector tidak menghapus/mengubah log, user, atau template mesin.
  Satu-satunya operasi tulis adalah koreksi jam (`CMD_SET_TIME`), dan hanya bila
  `clock_sync_minutes > 0` atau `/api/sync-time` dipanggil manual.
- **Belum diuji ke mesin fisik dari lingkungan pengembangan**; wajib uji di lab sebelum
  produksi (terutama `mode=event` / `CMD_REG_EVENT`, `CMD_GET_TIME`, dan `CMD_SET_TIME`).
- **Python fallback Linux** sudah dilengkapi parity fitur C# (incremental, ack, boundary,
  sinkronisasi jam), tetapi smoke test pada host Linux dan firmware X601 asli belum
  dilakukan.
- Supervisor `SmartPresensiSupervisor` (Windows Service) masih Windows-only dan berada di
  luar cakupan perubahan ini.
