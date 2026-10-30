#!/usr/bin/env python3
"""Connector Solution X601 lintas-OS (Windows & Linux) untuk SmartPresensi.

Menggantikan connector C# yang bergantung pada COM ``zkemkeeper.ZKEM`` (Windows-only).
Menawarkan antarmuka yang identik agar Laravel (``FingerprintConnectorService``) tidak
perlu tahu bahasa pemrogramannya:

* CLI  : ``--test``, ``--probe``, ``--diagnose``, ``--sync-students``, ``--sync-logs``
  (``--since``), ``--sync-time``, ``--scan-lan``.
* Daemon: worker per mesin + Local HTTP API di ``127.0.0.1:4371``.

Mendukung **satu atau banyak mesin** dalam satu proses. Konfigurasi mesin tunggal
(format lama tanpa section) tetap dibaca apa adanya (backward compatible), sedangkan
beberapa mesin didefinisikan lewat section ``[device:<key>]``.

Log, user, dan template tidak pernah dihapus/diubah. Satu-satunya operasi tulis adalah
koreksi jam mesin (``CMD_SET_TIME``) yang dapat dinonaktifkan lewat konfigurasi.
"""

from __future__ import annotations

import configparser
import hmac
import json
import signal
import sys
import threading
import time
from collections import OrderedDict
from concurrent.futures import ThreadPoolExecutor
from datetime import datetime, timedelta
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from urllib.error import HTTPError, URLError
from urllib.parse import parse_qs, urlparse
from urllib.request import Request, urlopen

from zk_protocol import (
    CHUNK_CANDIDATES,
    CMD_ATTLOG_RRQ,
    ZKConnectionError,
    ZKDevice,
    ZKError,
    ZKProtocolError,
    probe_tcp,
)

BASE_DIR = Path(__file__).resolve().parent
CONFIG_FILE = BASE_DIR / "connector_config.ini"
STATE_DIR = BASE_DIR / "state"

TIME_FORMAT = "%Y-%m-%d %H:%M:%S"
TCP_PRE_CHECK_TIMEOUT = 1.5
HTTP_TIMEOUT = 8.0
DEVICE_LOCK_TIMEOUT = 1.5
EVENT_READ_TIMEOUT = 1.0

# Backoff saat mesin offline: 5 -> 10 -> 20 -> 40 -> 60 detik (maksimum 60).
OFFLINE_BACKOFF_SECONDS = (5, 10, 20, 40, 60)

# Mesin tunggal format lama memakai kunci ini dan menyimpan state di BASE_DIR agar
# watermark/buffer lama tidak hilang saat upgrade.
LEGACY_KEY = "default"
DEFAULT_DEVICE_IP = "100.50.25.67"
DEFAULT_DEVICE_TOKEN = "x601_sec_token_school_solution_lan"
DEFAULT_API_URL = "http://127.0.0.1:8000/api/v1/fingerprint/ingest"


# --------------------------------------------------------------------- util


def _to_int(value, fallback: int) -> int:
    try:
        return int(value)
    except (TypeError, ValueError):
        return fallback


def _to_bool(value, fallback: bool) -> bool:
    if value is None:
        return fallback
    if isinstance(value, bool):
        return value
    return str(value).strip().lower() in ("1", "true", "yes", "on", "ya")


def _parse_time(value: str | None) -> datetime | None:
    if not value:
        return None
    try:
        return datetime.strptime(value, TIME_FORMAT)
    except (TypeError, ValueError):
        return None


def _format_time(value: datetime | None) -> str:
    return value.strftime(TIME_FORMAT) if value else ""


def _safe_key(key: str) -> str:
    cleaned = "".join(ch if (ch.isalnum() or ch in "-_") else "_" for ch in key.strip())
    return cleaned or LEGACY_KEY


def _ensure_parent(path: Path) -> None:
    parent = path.parent
    if parent and not parent.exists():
        parent.mkdir(parents=True, exist_ok=True)


def _log(message: str) -> None:
    print(f"[{datetime.now().strftime('%H:%M:%S')}] {message}", flush=True)


# ------------------------------------------------------------ konfigurasi


class DeviceConfig:
    """Deskripsi satu mesin Solution X601 beserta lokasi state-nya."""

    def __init__(
        self,
        key: str,
        ip: str,
        port: int = 4370,
        comm_key: str = "0",
        token: str = "",
        enabled: bool = True,
        mode: str = "poll",
    ):
        self.key = key
        self.ip = ip
        self.port = int(port)
        self.comm_key = str(comm_key)
        self.token = token
        self.enabled = bool(enabled)
        self.mode = mode if mode in ("poll", "event") else "poll"

    @property
    def state_dir(self) -> Path:
        # Mesin legacy tetap memakai BASE_DIR demi kompatibilitas state lama.
        if self.key == LEGACY_KEY:
            return BASE_DIR
        return STATE_DIR / _safe_key(self.key)

    @property
    def watermark_file(self) -> Path:
        return self.state_dir / "checkpoint.txt"

    @property
    def buffer_file(self) -> Path:
        return self.state_dir / "offline_buffer.txt"

    def clone(self) -> "DeviceConfig":
        return DeviceConfig(
            self.key, self.ip, self.port, self.comm_key, self.token, self.enabled, self.mode
        )


class Config:
    """Konfigurasi connector, dibaca dari ``connector_config.ini``.

    Mendukung dua format:

    * **Lama (satu mesin)** — pasangan ``key=value`` tanpa section.
    * **Baru (multi-mesin)** — section ``[default]`` untuk pengaturan global dan
      section ``[device:<key>]`` untuk tiap mesin.
    """

    def __init__(self, path: Path):
        self.path = path
        self.api_url = DEFAULT_API_URL
        self.poll_interval_seconds = 5
        self.reconnect_interval_seconds = 5
        self.local_api_port = 4371
        self.local_api_token = ""
        self.batch_size = 20
        self.clock_sync_minutes = 60
        self.clock_max_drift_seconds = 5
        self.clock_allow_backward = False
        self.devices: "OrderedDict[str, DeviceConfig]" = OrderedDict()

    # ------------------------------------------------------------- lifecycle

    def load(self) -> "Config":
        if not self.path.exists():
            self._apply_legacy_defaults()
            self.save()
            return self

        try:
            # utf-8-sig: toleran bila operator menyunting/menyimpan ulang via Notepad (BOM).
            text = self.path.read_text(encoding="utf-8-sig")
        except OSError:
            self._apply_legacy_defaults()
            return self

        self._parse(text)

        if not self.devices:
            self._apply_legacy_defaults()

        return self

    @property
    def primary_device(self) -> DeviceConfig | None:
        for device in self.devices.values():
            if device.enabled:
                return device
        for device in self.devices.values():
            return device
        return None

    # --------------------------------------------------------------- parsing

    def _apply_legacy_defaults(self) -> None:
        self.devices[LEGACY_KEY] = DeviceConfig(
            LEGACY_KEY, DEFAULT_DEVICE_IP, 4370, "0", DEFAULT_DEVICE_TOKEN
        )

    def _parse(self, text: str) -> None:
        # Format lama tak punya section; bungkus ke [default] agar terbaca configparser.
        if "[" not in text:
            text = f"[{LEGACY_KEY}]\n{text}"

        parser = configparser.ConfigParser(interpolation=None)
        try:
            parser.read_string(text)
        except configparser.Error as exc:
            _log(f"[CONFIG WARN] Gagal membaca konfigurasi: {exc}")
            return

        # Pengaturan global dari [DEFAULT] (bila ada) dan [default]/[settings]/[global].
        if parser.defaults():
            self._read_globals(dict(parser.defaults()))
        for name in (LEGACY_KEY, "settings", "global"):
            if parser.has_section(name):
                self._read_globals(dict(parser.items(name)))

        # Mesin-mesin.
        for section in parser.sections():
            data = dict(parser.items(section))
            lower = section.lower()
            if lower.startswith("device:"):
                key = section.split(":", 1)[1].strip() or LEGACY_KEY
                self._add_device(key, data)
            elif lower == LEGACY_KEY and ("device_ip" in data or "ip" in data):
                self._add_device(LEGACY_KEY, data)

    def _read_globals(self, data: dict) -> None:
        if data.get("api_url"):
            self.api_url = data["api_url"].strip()
        if "poll_interval_seconds" in data:
            self.poll_interval_seconds = _to_int(data["poll_interval_seconds"], self.poll_interval_seconds)
        if "reconnect_interval_seconds" in data:
            self.reconnect_interval_seconds = _to_int(
                data["reconnect_interval_seconds"], self.reconnect_interval_seconds
            )
        if "local_api_port" in data:
            self.local_api_port = _to_int(data["local_api_port"], self.local_api_port)
        if "local_api_token" in data:
            self.local_api_token = data["local_api_token"].strip()
        if "batch_size" in data:
            self.batch_size = max(1, _to_int(data["batch_size"], self.batch_size))
        if "clock_sync_minutes" in data:
            self.clock_sync_minutes = max(
                0, _to_int(data["clock_sync_minutes"], self.clock_sync_minutes)
            )
        if "clock_max_drift_seconds" in data:
            self.clock_max_drift_seconds = max(
                0, _to_int(data["clock_max_drift_seconds"], self.clock_max_drift_seconds)
            )
        if "clock_allow_backward" in data:
            self.clock_allow_backward = _to_bool(
                data["clock_allow_backward"], self.clock_allow_backward
            )

    def _add_device(self, key: str, data: dict) -> None:
        ip = (data.get("ip") or data.get("device_ip") or "").strip()
        if not ip:
            return

        self.devices[key] = DeviceConfig(
            key=key,
            ip=ip,
            port=_to_int(data.get("port") or data.get("device_port"), 4370),
            comm_key=(data.get("comm_key") or "0").strip(),
            token=(data.get("token") or data.get("device_token") or "").strip(),
            enabled=_to_bool(data.get("enabled"), True),
            mode=(data.get("mode") or "poll").strip().lower(),
        )

    # --------------------------------------------------------------- saving

    def save(self) -> None:
        # Mesin tunggal legacy ditulis dengan format lama agar pembaca lama tetap kompatibel.
        if len(self.devices) == 1 and LEGACY_KEY in self.devices and self.devices[LEGACY_KEY].mode == "poll":
            device = self.devices[LEGACY_KEY]
            content = (
                "# Konfigurasi Koneksi Solution X601 & Laravel Ingest API\n"
                f"device_ip={device.ip}\n"
                f"device_port={device.port}\n"
                f"comm_key={device.comm_key}\n"
                f"api_url={self.api_url}\n"
                f"device_token={device.token}\n"
                f"poll_interval_seconds={self.poll_interval_seconds}\n"
                f"reconnect_interval_seconds={self.reconnect_interval_seconds}\n"
                f"local_api_port={self.local_api_port}\n"
                f"local_api_token={self.local_api_token}\n"
                f"clock_sync_minutes={self.clock_sync_minutes}\n"
                f"clock_max_drift_seconds={self.clock_max_drift_seconds}\n"
                f"clock_allow_backward={'true' if self.clock_allow_backward else 'false'}\n"
            )
        else:
            lines = [
                "# Konfigurasi Connector Solution X601 multi-mesin (LAN)",
                "[default]",
                f"api_url={self.api_url}",
                f"poll_interval_seconds={self.poll_interval_seconds}",
                f"reconnect_interval_seconds={self.reconnect_interval_seconds}",
                f"local_api_port={self.local_api_port}",
                f"local_api_token={self.local_api_token}",
                f"batch_size={self.batch_size}",
                f"clock_sync_minutes={self.clock_sync_minutes}",
                f"clock_max_drift_seconds={self.clock_max_drift_seconds}",
                f"clock_allow_backward={'true' if self.clock_allow_backward else 'false'}",
                "",
            ]
            for device in self.devices.values():
                lines.extend(
                    [
                        f"[device:{device.key}]",
                        f"ip={device.ip}",
                        f"port={device.port}",
                        f"comm_key={device.comm_key}",
                        f"token={device.token}",
                        f"enabled={'true' if device.enabled else 'false'}",
                        f"mode={device.mode}",
                        "",
                    ]
                )
            content = "\n".join(lines)

        _ensure_parent(self.path)
        self.path.write_text(content, encoding="utf-8")


class DeviceState:
    """Watermark & offline buffer untuk satu mesin."""

    def __init__(self, device: DeviceConfig):
        self.device = device
        self.last_watermark = datetime.min
        self.seen_at_watermark: set[str] = set()
        self.last_sync: datetime | None = None
        self.last_error = ""
        self.last_clock_attempt: datetime | None = None
        self.last_clock_sync: datetime | None = None
        self.clock_drift_seconds: int | None = None
        self.last_read_mode = ""
        self.last_chunk_size: int | None = None

    def load_watermark(self) -> None:
        path = self.device.watermark_file
        try:
            if path.exists():
                text = path.read_text(encoding="utf-8").strip()
                if text:
                    self.last_watermark = datetime.strptime(text, TIME_FORMAT)
                    self.last_sync = self.last_watermark
                    _log(f"[WATERMARK] {self.device.key}: {text}")
                    return
        except (OSError, ValueError):
            pass
        self.last_watermark = datetime.min
        self.last_sync = None
        _log(f"[WATERMARK] {self.device.key}: belum ada; mulai dari log paling awal mesin.")


class Connector:
    """Orkestrasi koneksi mesin, watermark, offline buffer, dan pengiriman ke Laravel."""

    def __init__(self, config: Config):
        self.config = config
        self._stop = threading.Event()
        self._locks: dict[str, threading.Lock] = {}
        self._connected: set[str] = set()
        self._chunk_hints: dict[str, int] = {}
        self.states: "OrderedDict[str, DeviceState]" = OrderedDict(
            (key, DeviceState(device)) for key, device in config.devices.items()
        )

    # ------------------------------------------------------------------ mesin

    def _open_device(
        self, ip: str, port: int, comm_key: str, chunk_size: int | None = None
    ) -> ZKDevice:
        """Buka koneksi mesin dengan pre-check TCP port. Raise ZKError bila gagal."""
        if not probe_tcp(ip, port, TCP_PRE_CHECK_TIMEOUT):
            raise ZKConnectionError(
                f"Mesin Solution X601 di {ip}:{port} tidak merespons di jaringan LAN."
            )

        password = _to_int(comm_key, 0)
        device = ZKDevice(ip, port, timeout=10.0, password=password)
        if chunk_size:
            device.chunk_size = int(chunk_size)
        device.connect()
        return device

    def _lock_for(self, ip: str, port: int) -> threading.Lock:
        """Lock per mesin; mesin yang sama tidak diproses bersamaan."""
        port = int(port)
        for key, device in self.config.devices.items():
            if device.ip == ip and device.port == port:
                return self._locks.setdefault(key, threading.Lock())
        return self._locks.setdefault(f"{ip}:{port}", threading.Lock())

    def test_connection(self, ip: str, port: int, comm_key: str) -> dict:
        device = self._open_device(ip, port, comm_key)
        try:
            return {
                "success": True,
                "code": 0,
                "message": f"Berhasil terhubung ke mesin Solution X601 ({ip}:{port})",
            }
        finally:
            device.disconnect()

    def fetch_users(self, ip: str, port: int, comm_key: str) -> list[dict]:
        device = self._open_device(ip, port, comm_key)
        try:
            return device.get_users()
        finally:
            device.disconnect()

    def fetch_logs(
        self, ip: str, port: int, comm_key: str, since: datetime | None = None
    ) -> list[dict]:
        device = self._open_device(ip, port, comm_key)
        try:
            records = device.get_attendance()
        finally:
            device.disconnect()

        if since is None:
            return records

        return [
            record
            for record in records
            if (_parse_time(record.get("time")) or datetime.min) >= since
        ]

    def fetch_time(self, ip: str, port: int, comm_key: str) -> datetime:
        device = self._open_device(ip, port, comm_key)
        try:
            return device.get_time()
        finally:
            device.disconnect()

    def probe_capabilities(self, ip: str, port: int, comm_key: str) -> dict:
        device = self._open_device(ip, port, comm_key)
        try:
            return device.probe_capabilities()
        finally:
            device.disconnect()

    def diagnose(self, ip: str, port: int, comm_key: str) -> dict:
        """Diagnostik read-only: TCP, kapasitas, jam, dan uji tiap ukuran chunk."""
        host_time = datetime.now().replace(microsecond=0)
        result = {
            "device": f"{ip}:{port}",
            "tcp_reachable": probe_tcp(ip, port, TCP_PRE_CHECK_TIMEOUT),
            "connected": False,
            "host_time": host_time.strftime(TIME_FORMAT),
            "device_time": "",
            "drift_seconds": None,
            "users": 0,
            "records": 0,
            "chunk_tests": [],
            "recommendation": "",
            "error": "",
        }

        if not result["tcp_reachable"]:
            result["recommendation"] = (
                f"Port TCP {ip}:{port} tidak dapat dihubungi. Periksa kabel LAN, IP mesin, "
                "dan pastikan mesin menyala."
            )
            return result

        device = None
        try:
            device = self._open_device(ip, port, comm_key)
            result["connected"] = True

            try:
                sizes = device.read_sizes()
                result["users"] = int(sizes.get("users", 0))
                result["records"] = int(sizes.get("records", 0))
            except ZKError as exc:
                result["error"] = f"Gagal membaca kapasitas: {exc}"

            try:
                device_time = device.get_time()
                result["device_time"] = device_time.strftime(TIME_FORMAT)
                result["drift_seconds"] = int(
                    round((device_time - host_time).total_seconds())
                )
            except ZKError as exc:
                if not result["error"]:
                    result["error"] = f"Gagal membaca jam mesin: {exc}"
        except ZKError as exc:
            result["error"] = str(exc)
            result["recommendation"] = (
                "TCP terbuka tetapi handshake gagal. Periksa comm key dan pastikan tidak "
                "ada aplikasi/sesi lain yang sedang memakai mesin."
            )
            return result
        finally:
            if device is not None:
                try:
                    device.disconnect()
                except ZKError:
                    pass

        if result["records"] <= 0:
            result["recommendation"] = (
                "Mesin tidak memiliki log absensi, uji ukuran chunk dilewati. "
                "Lakukan uji ulang setelah ada scan."
            )
            return result

        for chunk_size in CHUNK_CANDIDATES:
            entry = {
                "chunk_size": chunk_size,
                "success": False,
                "bytes": 0,
                "total_size": 0,
                "elapsed_seconds": 0.0,
                "error": "",
            }
            started = time.monotonic()
            handle = None
            try:
                handle = self._open_device(ip, port, comm_key)
                data, total_size = handle.read_with_buffer(
                    CMD_ATTLOG_RRQ,
                    preferred_chunk=chunk_size,
                    allow_direct_fallback=False,
                )
                entry["success"] = True
                entry["bytes"] = len(data)
                entry["total_size"] = int(total_size)
            except ZKError as exc:
                entry["error"] = str(exc)
            finally:
                entry["elapsed_seconds"] = round(time.monotonic() - started, 2)
                if handle is not None:
                    try:
                        handle.disconnect()
                    except ZKError:
                        pass
            result["chunk_tests"].append(entry)

        successful = [test["chunk_size"] for test in result["chunk_tests"] if test["success"]]
        if successful:
            result["recommendation"] = (
                f"Chunk {max(successful)} byte berhasil. Connector memakai ukuran ini "
                "secara otomatis pada siklus berikutnya."
            )
        else:
            result["recommendation"] = (
                "Semua ukuran chunk gagal. Periksa sesi ganda ke port mesin "
                "(ss -tnp | grep 4370), lalu coba jalur Web SOAP bila firmware tidak "
                "mendukung pembacaan buffered."
            )

        return result

    # ------------------------------------------------------- kirim ke Laravel

    def send_logs(self, records: list[dict], token: str) -> bool:
        if not records:
            return True

        payload = json.dumps({"logs": records}).encode("utf-8")
        request = Request(
            self.config.api_url,
            data=payload,
            method="POST",
            headers={
                "Content-Type": "application/json",
                "X-Device-Token": token,
            },
        )
        try:
            with urlopen(request, timeout=HTTP_TIMEOUT) as response:
                if not 200 <= response.status < 300:
                    _log(f"[HTTP WARN] Laravel API merespons HTTP {response.status}.")
                    return False
                body = response.read()
        except (HTTPError, URLError, OSError) as exc:
            _log(f"[HTTP WARN] Gagal mengirim ke Laravel API: {exc}")
            return False

        try:
            result = json.loads(body.decode("utf-8"))
        except (UnicodeDecodeError, ValueError):
            _log("[HTTP WARN] Response Laravel API bukan JSON yang valid.")
            return False

        if not isinstance(result, dict) or result.get("status") != "success":
            message = result.get("message") if isinstance(result, dict) else ""
            _log(f"[HTTP WARN] Laravel API menolak batch: {message or 'status tidak success'}.")
            return False

        summary = result.get("summary")
        accepted = summary.get("accepted") if isinstance(summary, dict) else None
        if isinstance(accepted, bool) or not isinstance(accepted, int):
            _log("[HTTP WARN] Response Laravel API tidak memuat summary.accepted.")
            return False
        if accepted < len(records):
            _log(
                f"[HTTP WARN] Laravel hanya menerima {accepted}/{len(records)} record; "
                "watermark tidak dimajukan."
            )
            return False

        return True

    def _send_batched(self, device: DeviceConfig, records: list[dict]) -> bool:
        """Kirim per micro-batch (default 20 record); berhenti pada kegagalan pertama."""
        batch_size = max(1, int(self.config.batch_size))
        for start in range(0, len(records), batch_size):
            if not self.send_logs(records[start : start + batch_size], device.token):
                return False
        return True

    # ------------------------------------------------- watermark & buffer

    def _save_watermark(self, state: DeviceState) -> None:
        try:
            _ensure_parent(state.device.watermark_file)
            state.device.watermark_file.write_text(
                state.last_watermark.strftime(TIME_FORMAT), encoding="utf-8"
            )
        except OSError as exc:
            _log(f"[WATERMARK WARN] Gagal menyimpan watermark: {exc}")

    @staticmethod
    def _record_time(record: dict) -> datetime | None:
        return _parse_time(record.get("time"))

    def _mark_synced(self, state: DeviceState, new_records: list[dict]) -> datetime:
        """Maju-kan watermark dan simpan source_key pada timestamp tertinggi."""
        highest = state.last_watermark
        highest_keys: list[str] = []
        boundary_keys: list[str] = []

        for record in new_records:
            occurred = self._record_time(record)
            if occurred is None:
                continue
            if occurred > highest:
                highest = occurred
                highest_keys.clear()
            if occurred == highest:
                highest_keys.append(str(record.get("source_key", "")))
            if occurred == state.last_watermark:
                boundary_keys.append(str(record.get("source_key", "")))

        if highest > state.last_watermark:
            state.last_watermark = highest
            state.seen_at_watermark = set(highest_keys)
        else:
            state.seen_at_watermark.update(boundary_keys)

        self._save_watermark(state)
        return highest

    def _reset_watermark_after_clock_change(self, state: DeviceState, target: datetime) -> None:
        state.last_watermark = target - timedelta(seconds=1)
        state.last_sync = state.last_watermark
        state.seen_at_watermark.clear()
        self._save_watermark(state)
        _log(
            f"[CLOCK] {state.device.key}: watermark dimundurkan ke "
            f"{state.last_watermark.strftime(TIME_FORMAT)}."
        )

    # ----------------------------------------------------------- sinkron jam

    def sync_time(self, ip: str, port: int, comm_key: str) -> dict:
        """Sinkronkan jam mesin ke jam host saat ini; target arbitrer tidak diizinkan."""
        target = datetime.now().replace(microsecond=0)
        device = self._open_device(ip, port, comm_key)
        try:
            device_time: datetime | None = None
            drift: int | None = None
            try:
                device_time = device.get_time()
                drift = int(round((device_time - datetime.now()).total_seconds()))
            except ZKError:
                pass

            if (
                device_time is not None
                and device_time.replace(microsecond=0) > target
            ):
                return {
                    "success": False,
                    "code": -1,
                    "message": (
                        "Jam mesin lebih cepat dan koreksi mundur dinonaktifkan."
                    ),
                    "device_time": device_time.strftime(TIME_FORMAT),
                    "drift_seconds": drift,
                    "synced": False,
                }

            if not device.set_time(target):
                return {
                    "success": False,
                    "code": -997,
                    "message": "Mesin tidak menerima perintah sinkronisasi jam.",
                    "device_time": _format_time(device_time),
                    "drift_seconds": drift,
                    "synced": False,
                }

            return {
                "success": True,
                "code": 0,
                "message": f"Jam mesin disinkronkan ke {target.strftime(TIME_FORMAT)}.",
                "device_time": _format_time(device_time),
                "drift_seconds": drift,
                "synced": True,
            }
        finally:
            device.disconnect()

    def _sync_clock_if_due(
        self, state: DeviceState, device: ZKDevice, now: datetime | None = None
    ) -> None:
        if self.config.clock_sync_minutes <= 0:
            return

        now = (now or datetime.now()).replace(microsecond=0)
        if state.last_clock_attempt is not None:
            elapsed = now - state.last_clock_attempt
            if elapsed < timedelta(minutes=self.config.clock_sync_minutes):
                return

        state.last_clock_attempt = now
        machine_time = device.get_time()
        drift = int(round((machine_time - now).total_seconds()))
        state.clock_drift_seconds = drift

        if abs(drift) < self.config.clock_max_drift_seconds:
            return

        if drift > 0 and not self.config.clock_allow_backward:
            _log(
                f"[CLOCK] {state.device.key}: jam mesin lebih cepat {drift} detik; "
                "koreksi mundur dinonaktifkan."
            )
            return

        target = now
        if not device.set_time(target):
            raise ZKProtocolError("Mesin tidak menerima perintah sinkronisasi jam.")

        state.last_clock_sync = target
        if state.last_watermark != datetime.min and target < machine_time:
            self._reset_watermark_after_clock_change(state, target)

        _log(f"[CLOCK] {state.device.key}: jam disinkronkan. Drift sebelumnya {drift} detik.")

    def _append_buffer(self, state: DeviceState, records: list[dict]) -> None:
        if not records:
            return
        try:
            _ensure_parent(state.device.buffer_file)
            with state.device.buffer_file.open("a", encoding="utf-8") as handle:
                for record in records:
                    handle.write(
                        f"{record['pin']}|{record['time']}|{record['verify_type']}|{record['source_key']}\n"
                    )
            _log(f"[BUFFER] {state.device.key}: {len(records)} scan disimpan ke offline buffer.")
        except OSError as exc:
            _log(f"[BUFFER WARN] Gagal menulis offline buffer: {exc}")

    def _flush_buffer(self, device: DeviceConfig, state: DeviceState) -> None:
        path = state.device.buffer_file
        if not path.exists():
            return
        try:
            lines = [line for line in path.read_text(encoding="utf-8").splitlines() if line.strip()]
        except OSError:
            return
        if not lines:
            return

        records = []
        for line in lines:
            parts = line.split("|")
            if len(parts) >= 4:
                records.append(
                    {
                        "pin": parts[0],
                        "time": parts[1],
                        "verify_type": _to_int(parts[2], 0),
                        "source_key": parts[3],
                    }
                )

        if records and self._send_batched(device, records):
            try:
                path.unlink()
            except OSError:
                pass
            _log(f"[BUFFER SUCCESS] {device.key}: seluruh offline buffer berhasil dikirim.")

    # ---------------------------------------------------------- poll daemon

    def poll_device(self, device: DeviceConfig) -> bool | None:
        """Tarik log inkremental dari satu mesin lalu kirim ke Laravel.

        Return ``True`` bila mesin berhasil dibaca, ``False`` bila offline/gagal
        (worker akan backoff), dan ``None`` bila lock mesin sedang dipakai.
        """
        state = self.states.setdefault(device.key, DeviceState(device))
        lock = self._lock_for(device.ip, device.port)
        if not lock.acquire(timeout=DEVICE_LOCK_TIMEOUT):
            return None

        handle = None
        try:
            self._flush_buffer(device, state)

            try:
                handle = self._open_device(
                    device.ip, device.port, device.comm_key, self._chunk_hints.get(device.key)
                )
                records = handle.get_attendance()
                self._connected.add(device.key)
                state.last_read_mode = handle.last_read_mode
                state.last_chunk_size = handle.last_chunk_size

                new_records: list[dict] = []
                for record in records:
                    occurred = self._record_time(record)
                    if occurred is None:
                        continue
                    if occurred > state.last_watermark or (
                        occurred == state.last_watermark
                        and str(record.get("source_key", "")) not in state.seen_at_watermark
                    ):
                        new_records.append(record)

                if new_records:
                    _log(f"[INGEST] {device.key}: {len(new_records)} scan baru.")
                    if self._send_batched(device, new_records):
                        highest = self._mark_synced(state, new_records)
                        state.last_sync = highest
                        state.last_error = ""
                        _log(
                            f"[SYNC SUCCESS] {device.key} watermark: "
                            f"{highest.strftime(TIME_FORMAT)}"
                        )
                    else:
                        state.last_error = "Gagal mengirim log ke Laravel API."
                        self._append_buffer(state, new_records)
                else:
                    state.last_error = ""

                try:
                    self._sync_clock_if_due(state, handle)
                except ZKError as exc:
                    state.last_error = f"Sinkronisasi jam gagal: {exc}"
                    _log(f"[CLOCK] {device.key}: {exc}")

                return True
            except ZKError as exc:
                self._connected.discard(device.key)
                state.last_error = str(exc)
                _log(f"[LAN] {device.key} offline: {exc}")
                return False
            finally:
                if handle is not None:
                    # Simpan ukuran chunk yang berhasil (atau terkecil yang sudah
                    # dicoba) agar siklus berikutnya tidak mengulang timeout panjang.
                    self._chunk_hints[device.key] = handle.chunk_size
                    try:
                        handle.disconnect()
                    except ZKError:
                        pass
        finally:
            lock.release()

    def _run_event_cycle(self, device: DeviceConfig) -> bool | None:
        """Mode event: tunggu CMD_REG_EVENT sesaat, lalu poll inkremental.

        Satu koneksi per waktu (banyak mesin hanya mengizinkan satu koneksi TCP):
        sambung → daftar event → tunggu → putus → poll → sambung lagi.
        """
        lock = self._lock_for(device.ip, device.port)
        if lock.acquire(timeout=DEVICE_LOCK_TIMEOUT):
            handle = None
            try:
                handle = self._open_device(
                    device.ip, device.port, device.comm_key, self._chunk_hints.get(device.key)
                )
                if not handle.register_events():
                    raise ZKProtocolError("Mesin tidak meng-ACK CMD_REG_EVENT.")

                self._connected.add(device.key)
                deadline = time.monotonic() + max(self.config.poll_interval_seconds, 1)
                while not self._stop.is_set() and time.monotonic() < deadline:
                    if handle.read_event(EVENT_READ_TIMEOUT):
                        _log(f"[EVENT] {device.key}: event absensi diterima.")
                        break
            except ZKError as exc:
                self._connected.discard(device.key)
                _log(f"[LAN] {device.key} mode event gagal: {exc}")
                return False
            finally:
                if handle is not None:
                    try:
                        handle.disconnect()
                    except ZKError:
                        pass
                lock.release()

        # Selalu rekonsiliasi lewat poll (setelah event atau saat jadwal tiba).
        return self.poll_device(device)

    @staticmethod
    def _backoff_seconds(failures: int) -> int:
        """Jeda sebelum percobaan berikutnya: 5, 10, 20, 40, lalu 60 detik."""
        if failures <= 1:
            return OFFLINE_BACKOFF_SECONDS[0]
        index = min(failures - 1, len(OFFLINE_BACKOFF_SECONDS) - 1)
        return OFFLINE_BACKOFF_SECONDS[index]

    def _worker_loop(self, device: DeviceConfig) -> None:
        state = self.states.setdefault(device.key, DeviceState(device))
        state.load_watermark()
        failures = 0

        while not self._stop.is_set():
            try:
                if device.mode == "event":
                    result = self._run_event_cycle(device)
                else:
                    result = self.poll_device(device)
            except Exception as exc:  # noqa: BLE001 - worker harus tetap hidup
                _log(f"[ERROR] {device.key}: {exc}")
                self._connected.discard(device.key)
                result = False

            if result is False:
                failures += 1
                delay = self._backoff_seconds(failures)
                _log(
                    f"[BACKOFF] {device.key}: gagal {failures}x, "
                    f"percobaan berikutnya dalam {delay} detik."
                )
            else:
                failures = 0
                delay = self.config.poll_interval_seconds if device.mode == "poll" else 0.5

            self._stop.wait(delay)

    def run_daemon(self) -> int:
        _log("======================================================================")
        _log("    CONNECTOR SOLUTION X601 (LINTAS OS) - SMARTPRESENSI")
        _log("======================================================================")
        _log(f"[CONFIG] Laravel API    : {self.config.api_url}")
        _log(f"[CONFIG] Local API port : {self.config.local_api_port}")
        _log(f"[CONFIG] Interval poll  : {self.config.poll_interval_seconds} detik")
        _log(
            f"[CONFIG] Sync jam       : setiap {self.config.clock_sync_minutes} menit "
            f"(drift >= {self.config.clock_max_drift_seconds} detik, "
            f"mundur={'boleh' if self.config.clock_allow_backward else 'tidak'})"
        )

        enabled = [device for device in self.config.devices.values() if device.enabled]
        if not enabled:
            _log("[CONFIG] Tidak ada mesin aktif pada konfigurasi. Connector berhenti.")
            return 1

        for device in enabled:
            _log(f"[CONFIG] Mesin {device.key}: {device.ip}:{device.port} (mode={device.mode})")

        server = _start_http_api(self.config.local_api_port, self)
        if server is not None:
            _log(f"[API] Local HTTP Listener aktif di http://127.0.0.1:{self.config.local_api_port}/")
            if self.config.local_api_token:
                _log("[API] Autentikasi token lokal aktif.")

        stop_watcher = threading.Thread(target=self._wait_for_stop, daemon=True)
        stop_watcher.start()

        for device in enabled:
            threading.Thread(
                target=self._worker_loop,
                args=(device,),
                daemon=True,
                name=f"worker-{device.key}",
            ).start()

        try:
            while not self._stop.is_set():
                self._stop.wait(1.0)
        finally:
            if server is not None:
                server.shutdown()
            _log("Connector berhenti.")
        return 0

    def _wait_for_stop(self) -> None:
        def _handler(_signum, _frame):
            _log("Sinyal berhenti diterima.")
            self._stop.set()

        for signame in ("SIGINT", "SIGTERM"):
            sig = getattr(signal, signame, None)
            if sig is not None:
                try:
                    signal.signal(sig, _handler)
                except (ValueError, OSError):
                    pass

    # ------------------------------------------------------------- Local API

    def _resolve_api_target(self, query: dict[str, str]) -> tuple[str, int, str]:
        primary = self.config.primary_device
        ip = query.get("ip") or (primary.ip if primary else DEFAULT_DEVICE_IP)
        port = _to_int(query.get("port"), primary.port if primary else 4370)
        comm_key = query.get("comm_key") or (primary.comm_key if primary else "0")
        return ip, port, comm_key

    def handle_api(self, path: str, query: dict[str, str]) -> dict:
        ip, port, comm_key = self._resolve_api_target(query)

        if path.startswith("/api/status"):
            devices = []
            for device in self.config.devices.values():
                state = self.states.get(device.key)
                devices.append(
                    {
                        "key": device.key,
                        "ip": device.ip,
                        "port": device.port,
                        "enabled": device.enabled,
                        "mode": device.mode,
                        "connected": device.key in self._connected,
                        "last_sync": _format_time(state.last_sync if state else None),
                        "last_clock_sync": _format_time(
                            state.last_clock_sync if state else None
                        ),
                        "clock_drift_seconds": (
                            state.clock_drift_seconds if state else None
                        ),
                        "last_error": state.last_error if state else "",
                        "last_read_mode": state.last_read_mode if state else "",
                        "last_chunk_size": state.last_chunk_size if state else None,
                    }
                )

            return {
                "status": "ok",
                "clock_sync_minutes": self.config.clock_sync_minutes,
                "clock_max_drift_seconds": self.config.clock_max_drift_seconds,
                "clock_allow_backward": self.config.clock_allow_backward,
                "devices": devices,
            }

        if path.startswith("/api/probe"):
            return self._api_operation(
                lambda: {
                    "success": True,
                    "code": 0,
                    "capabilities": self.probe_capabilities(ip, port, comm_key),
                },
                ip,
                port,
            )

        if path.startswith("/api/test"):
            return self._api_operation(
                lambda: self.test_connection(ip, port, comm_key), ip, port
            )

        if path.startswith("/api/sync-students"):
            return self._api_operation(
                lambda: self._wrap_users(self.fetch_users(ip, port, comm_key)), ip, port
            )

        if path.startswith("/api/sync-logs"):
            since = _parse_time(query.get("since"))
            return self._api_operation(
                lambda: self._wrap_logs(
                    self.fetch_logs(ip, port, comm_key, since), since
                ),
                ip,
                port,
            )

        if path.startswith("/api/sync-time"):
            if "time" in query or "allow_backward" in query:
                return {
                    "success": False,
                    "code": -998,
                    "message": (
                        "Sinkronisasi jam hanya memakai jam host; parameter "
                        "time/allow_backward tidak didukung."
                    ),
                    "synced": False,
                }
            return self._api_operation(
                lambda: self.sync_time(ip, port, comm_key),
                ip,
                port,
            )

        return {"status": "error", "message": "Endpoint tidak dikenal."}

    def _api_operation(self, operation, ip: str, port: int) -> dict:
        lock = self._lock_for(ip, port)
        if not lock.acquire(timeout=DEVICE_LOCK_TIMEOUT):
            return {
                "success": False,
                "code": -202,
                "message": "Konektor sedang sibuk memproses mesin yang sama.",
            }
        try:
            return operation()
        except ZKError as exc:
            return {"success": False, "code": -1, "message": str(exc)}
        except Exception as exc:  # noqa: BLE001 - kembalikan JSON, jangan matikan server
            return {"success": False, "code": -900, "message": f"Exception internal: {exc}"}
        finally:
            lock.release()

    @staticmethod
    def _wrap_users(users: list[dict]) -> dict:
        return {
            "success": True,
            "code": 0,
            "count": len(users),
            "users": [
                {
                    "pin": str(user.get("pin", "")),
                    "name": user.get("name", ""),
                    "privilege": int(user.get("privilege", 0)),
                    "enabled": bool(user.get("enabled", True)),
                }
                for user in users
            ],
        }

    @staticmethod
    def _wrap_logs(records: list[dict], since: datetime | None = None) -> dict:
        return {
            "success": True,
            "code": 0,
            "count": len(records),
            "since": _format_time(since),
            "logs": [
                {
                    "pin": str(record["pin"]),
                    "time": record["time"],
                    "verify_type": int(record["verify_type"]),
                    "source_key": record["source_key"],
                }
                for record in records
            ],
        }


# --------------------------------------------------------------------- HTTP


def _api_token_valid(expected: str, provided: str) -> bool:
    """True bila token lokal tidak diwajibkan atau token yang dikirim cocok."""
    if not expected:
        return True
    if not provided:
        return False
    return hmac.compare_digest(expected, provided)


def _start_http_api(port: int, connector: Connector) -> ThreadingHTTPServer | None:
    local_api_token = connector.config.local_api_token

    class Handler(BaseHTTPRequestHandler):
        def _write_json(self, status: int, payload: dict) -> None:
            body = json.dumps(payload).encode("utf-8")
            try:
                self.send_response(status)
                self.send_header("Content-Type", "application/json; charset=utf-8")
                self.send_header("Content-Length", str(len(body)))
                self.end_headers()
                self.wfile.write(body)
            except (BrokenPipeError, ConnectionResetError):
                # Klien (dashboard/health check) menutup koneksi lebih dulu; bukan error fatal.
                return

        def do_GET(self):  # noqa: N802 - nama method bawaan http.server
            if not _api_token_valid(local_api_token, self.headers.get("X-Connector-Token", "")):
                self._write_json(401, {"status": "error", "message": "Unauthorized."})
                return

            parsed = urlparse(self.path)
            query = {key: value[0] for key, value in parse_qs(parsed.query).items()}
            result = connector.handle_api(parsed.path.lower(), query)
            self._write_json(200, result)

        def log_message(self, *_args):  # senyapkan log default
            return

    try:
        server = ThreadingHTTPServer(("127.0.0.1", port), Handler)
    except OSError as exc:
        _log(f"[API WARN] Tidak dapat membuka local HTTP port {port}: {exc}")
        return None

    threading.Thread(target=server.serve_forever, name="ConnectorHttpListener", daemon=True).start()
    return server


# ---------------------------------------------------------------------- CLI


def _emit(payload: dict) -> None:
    sys.stdout.write(json.dumps(payload, ensure_ascii=False) + "\n")
    sys.stdout.flush()


def run_test(connector: Connector, device: DeviceConfig) -> int:
    try:
        result = connector.test_connection(device.ip, device.port, device.comm_key)
        _emit(result)
        return 0
    except ZKError as exc:
        _emit({"success": False, "code": -201, "message": str(exc)})
        return 1


def run_probe(connector: Connector, device: DeviceConfig) -> int:
    try:
        caps = connector.probe_capabilities(device.ip, device.port, device.comm_key)
        _emit({"success": True, "code": 0, "device": device.key, "capabilities": caps})
        return 0
    except ZKError as exc:
        _emit({"success": False, "code": -201, "message": str(exc), "capabilities": {}})
        return 1


def run_diagnose(connector: Connector, device: DeviceConfig) -> int:
    _log(f"[DIAG] Memeriksa {device.key} ({device.ip}:{device.port}) ...")
    result = connector.diagnose(device.ip, device.port, device.comm_key)
    payload = {"success": bool(result.get("connected")), "code": 0, **result}
    _emit(payload)

    for test in result.get("chunk_tests", []):
        status = "OK" if test.get("success") else "GAGAL"
        _log(
            f"[DIAG] chunk {test['chunk_size']:>5} byte: {status} "
            f"({test['elapsed_seconds']}s)"
        )

    if result.get("recommendation"):
        _log(f"[DIAG] {result['recommendation']}")

    return 0 if result.get("connected") else 1


def run_sync_students(connector: Connector, device: DeviceConfig) -> int:
    try:
        users = connector.fetch_users(device.ip, device.port, device.comm_key)
        _emit(connector._wrap_users(users))
        return 0
    except ZKError as exc:
        _emit({"success": False, "code": -997, "message": str(exc), "users": []})
        return 1


def run_sync_logs(
    connector: Connector, device: DeviceConfig, since: datetime | None = None
) -> int:
    try:
        logs = connector.fetch_logs(device.ip, device.port, device.comm_key, since)
        _emit(connector._wrap_logs(logs, since))
        return 0
    except ZKError as exc:
        _emit(
            {
                "success": False,
                "code": -996,
                "message": str(exc),
                "since": _format_time(since),
                "logs": [],
            }
        )
        return 1


def run_sync_time(connector: Connector, device: DeviceConfig) -> int:
    try:
        result = connector.sync_time(device.ip, device.port, device.comm_key)
    except ZKError as exc:
        result = {
            "success": False,
            "code": -996,
            "message": str(exc),
            "synced": False,
        }

    _emit(result)
    return 0 if result.get("success") else 1


def run_scan_lan(connector: Connector, device: DeviceConfig) -> int:
    prefix = ".".join(device.ip.split(".")[:3])
    _log(f"[SCAN] Memindai {prefix}.1 - {prefix}.254 pada port {device.port} ...")
    port = device.port

    def probe(host: int) -> str | None:
        ip = f"{prefix}.{host}"
        return ip if probe_tcp(ip, port, 0.4) else None

    with ThreadPoolExecutor(max_workers=64) as pool:
        for found in pool.map(probe, range(1, 255)):
            if found:
                _log(f"-> Mesin aktif: {found}")

    _log("[SCAN] Pemindaian selesai.")
    return 0


def parse_arguments(argv: list[str]):
    import argparse

    parser = argparse.ArgumentParser(description="Connector Solution X601 lintas-OS")
    parser.add_argument("--test", action="store_true", help="Uji koneksi ke mesin")
    parser.add_argument("--probe", action="store_true", help="Deteksi kapabilitas mesin (JSON)")
    parser.add_argument(
        "--diagnose",
        action="store_true",
        help="Diagnostik lengkap: TCP, kapasitas, jam, dan uji ukuran chunk",
    )
    parser.add_argument("--sync-students", action="store_true", help="Baca seluruh user mesin")
    parser.add_argument("--sync-logs", action="store_true", help="Baca seluruh log absensi")
    parser.add_argument(
        "--since",
        help="Filter --sync-logs mulai waktu YYYY-MM-DD HH:MM:SS",
    )
    parser.add_argument(
        "--sync-time",
        action="store_true",
        help="Sinkronkan jam mesin ke jam host saat ini",
    )
    parser.add_argument("--scan-lan", action="store_true", help="Pindai subnet pada port mesin")
    parser.add_argument("--device", help="Kunci mesin pada connector_config.ini (mis. gerbang)")
    parser.add_argument("--ip")
    parser.add_argument("--port", type=int)
    parser.add_argument("--comm-key")
    parser.add_argument("--token")
    parser.add_argument("--api-url")
    return parser.parse_known_args(argv)[0]


def _resolve_cli_device(config: Config, args) -> DeviceConfig | None:
    if args.device:
        device = config.devices.get(args.device)
        if device is None:
            _emit(
                {
                    "success": False,
                    "code": -998,
                    "message": f"Perangkat '{args.device}' tidak ditemukan pada connector_config.ini.",
                }
            )
            return None
        device = device.clone()
    else:
        primary = config.primary_device
        if primary is None:
            _emit({"success": False, "code": -998, "message": "Tidak ada mesin pada konfigurasi."})
            return None
        device = primary.clone()

    if args.ip:
        device.ip = args.ip
    if args.port:
        device.port = int(args.port)
    if args.comm_key:
        device.comm_key = args.comm_key
    if args.token:
        device.token = args.token

    return device


def main(argv: list[str] | None = None) -> int:
    try:
        sys.stdout.reconfigure(encoding="utf-8")
    except (AttributeError, ValueError):
        pass

    args = parse_arguments(list(sys.argv[1:] if argv is None else argv))

    config = Config(CONFIG_FILE).load()
    if args.api_url:
        config.api_url = args.api_url

    # Tanpa aksi CLI apa pun → mode daemon multi-mesin.
    if not any(
        (
            args.test,
            args.probe,
            args.diagnose,
            args.sync_students,
            args.sync_logs,
            bool(args.sync_time),
            args.scan_lan,
        )
    ):
        return Connector(config).run_daemon()

    device = _resolve_cli_device(config, args)
    if device is None:
        return 1

    connector = Connector(config)

    if args.test:
        return run_test(connector, device)
    if args.probe:
        return run_probe(connector, device)
    if args.diagnose:
        return run_diagnose(connector, device)
    if args.sync_students:
        return run_sync_students(connector, device)
    if args.sync_time:
        return run_sync_time(connector, device)
    if args.sync_logs:
        since = _parse_time(args.since)
        if args.since and since is None:
            _emit(
                {
                    "success": False,
                    "code": -998,
                    "message": "Format --since harus YYYY-MM-DD HH:MM:SS.",
                    "since": "",
                    "logs": [],
                }
            )
            return 1
        return run_sync_logs(connector, device, since)
    if args.scan_lan:
        return run_scan_lan(connector, device)

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
