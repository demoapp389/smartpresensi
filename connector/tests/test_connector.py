"""Uji unit connector Solution X601 (multi-mesin, kapabilitas, realtime, batching).

Jalankan: ``python -m unittest discover -s connector/tests`` dari akar proyek,
atau ``python -m unittest discover -s tests`` dari dalam folder ``connector``.
Tidak menyentuh mesin fisik: semua I/O dimock.
"""

from __future__ import annotations

import json
import socket
import struct
import sys
import tempfile
import threading
import time
import unittest
from datetime import datetime, timedelta
from pathlib import Path
from unittest import mock

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

import zkconnector  # noqa: E402
from zk_protocol import (  # noqa: E402
    CHUNK_CANDIDATES,
    CMD_ACK_OK,
    CMD_ATTLOG_RRQ,
    CMD_FREE_DATA,
    CMD_GET_TIME,
    CMD_REG_EVENT,
    CMD_SET_TIME,
    MACHINE_PREPARE_DATA_1,
    MACHINE_PREPARE_DATA_2,
    ZKDevice,
    ZKConnectionError,
    ZKProtocolError,
    _checksum,
)
from zkconnector import Connector, DeviceConfig, DeviceState, _api_token_valid, _to_bool, _to_int  # noqa: E402

TIME_FORMAT = zkconnector.TIME_FORMAT


def _encode_time(dt: datetime) -> int:
    value = ((dt.year - 2000) * 12 + (dt.month - 1)) * 31 + (dt.day - 1)
    value = (value * 24 + dt.hour) * 60 + dt.minute
    return value * 60 + dt.second


def _frame(command: int, extra: bytes = b"") -> bytes:
    body = struct.pack("<4H", command, 0, 0, 0) + extra
    return struct.pack("<HHI", MACHINE_PREPARE_DATA_1, MACHINE_PREPARE_DATA_2, len(body)) + body


def _record(pin: str, time_str: str, verify_type: int = 1) -> dict:
    dt = datetime.strptime(time_str, TIME_FORMAT)
    return {
        "pin": pin,
        "time": time_str,
        "verify_type": verify_type,
        "source_key": f"x601_{pin}_{int(dt.timestamp())}",
    }


class FakeSocket:
    """Soket palsu yang mengembalikan byte lalu raise timeout saat habis."""

    def __init__(self, data: bytes):
        self._data = data
        self.sent: list[bytes] = []

    def sendall(self, data: bytes) -> None:
        self.sent.append(data)

    def recv(self, size: int) -> bytes:
        if not self._data:
            raise socket.timeout()
        chunk, self._data = self._data[:size], self._data[size:]
        return chunk

    def settimeout(self, _seconds: float) -> None:
        return

    def close(self) -> None:
        return


class FakeResponse:
    def __init__(self, status: int, payload: dict):
        self.status = status
        self._body = json.dumps(payload).encode("utf-8")

    def __enter__(self):
        return self

    def __exit__(self, *_args) -> None:
        return None

    def read(self) -> bytes:
        return self._body


class FakeDevice:
    def __init__(
        self,
        records: list[dict],
        device_time: datetime | None = None,
        set_time_result: bool = True,
        time_error: Exception | None = None,
    ):
        self.records = records
        self.closed = False
        self.device_time = device_time or datetime.now()
        self.set_time_result = set_time_result
        self.time_error = time_error
        self.get_time_calls = 0
        self.set_time_calls: list[datetime] = []
        self.chunk_size = 1024
        self.last_chunk_size: int | None = 1024
        self.last_read_mode = "buffered"

    def get_attendance(self) -> list[dict]:
        return list(self.records)

    def get_time(self) -> datetime:
        self.get_time_calls += 1
        if self.time_error is not None:
            raise self.time_error
        return self.device_time

    def set_time(self, target: datetime) -> bool:
        self.set_time_calls.append(target)
        return self.set_time_result

    def disconnect(self) -> None:
        self.closed = True


def _write_config(tmp: Path, text: str) -> Path:
    path = tmp / "connector_config.ini"
    path.write_text(text, encoding="utf-8")
    return path


class ConfigTest(unittest.TestCase):
    def test_legacy_flat_config_parses_single_device(self) -> None:
        with tempfile.TemporaryDirectory() as raw:
            path = _write_config(
                Path(raw),
                "device_ip=192.168.1.10\ndevice_port=4370\ncomm_key=12\n"
                "api_url=http://server/api/v1/fingerprint/ingest\n"
                "device_token=tok-1\npoll_interval_seconds=7\n",
            )
            config = zkconnector.Config(path).load()

            self.assertEqual(list(config.devices.keys()), [zkconnector.LEGACY_KEY])
            device = config.primary_device
            self.assertEqual(device.ip, "192.168.1.10")
            self.assertEqual(device.port, 4370)
            self.assertEqual(device.comm_key, "12")
            self.assertEqual(device.token, "tok-1")
            self.assertEqual(config.api_url, "http://server/api/v1/fingerprint/ingest")
            self.assertEqual(config.poll_interval_seconds, 7)
            # State legacy tetap di BASE_DIR agar data lama tidak hilang.
            self.assertEqual(device.watermark_file, zkconnector.BASE_DIR / "checkpoint.txt")

    def test_multi_device_config_parses_devices_and_globals(self) -> None:
        with tempfile.TemporaryDirectory() as raw:
            path = _write_config(
                Path(raw),
                "[default]\napi_url=http://server/api/v1/fingerprint/ingest\n"
                "poll_interval_seconds=4\nbatch_size=10\nlocal_api_token=tok-local\n\n"
                "[device:gerbang]\nip=192.168.1.11\ncomm_key=0\ntoken=tok-g\n\n"
                "[device:kelas-a]\nip=192.168.1.12\nport=4371\ncomm_key=5\ntoken=tok-k\n"
                "enabled=false\nmode=event\n",
            )
            config = zkconnector.Config(path).load()

            self.assertEqual(list(config.devices.keys()), ["gerbang", "kelas-a"])
            self.assertEqual(config.batch_size, 10)
            self.assertEqual(config.local_api_token, "tok-local")

            gerbang = config.devices["gerbang"]
            self.assertEqual(gerbang.ip, "192.168.1.11")
            self.assertTrue(gerbang.enabled)
            self.assertEqual(gerbang.mode, "poll")
            self.assertEqual(gerbang.state_dir, zkconnector.STATE_DIR / "gerbang")

            kelas = config.devices["kelas-a"]
            self.assertEqual(kelas.port, 4371)
            self.assertFalse(kelas.enabled)
            self.assertEqual(kelas.mode, "event")
            self.assertEqual(kelas.comm_key, "5")

            # primary_device melewati mesin nonaktif.
            self.assertEqual(config.primary_device.key, "gerbang")

    def test_clock_config_defaults_and_values(self) -> None:
        with tempfile.TemporaryDirectory() as raw:
            path = _write_config(
                Path(raw),
                "[default]\nclock_sync_minutes=15\nclock_max_drift_seconds=8\n"
                "clock_allow_backward=true\n\n"
                "[device:gerbang]\nip=192.168.1.11\n",
            )
            config = zkconnector.Config(path).load()

            self.assertEqual(config.clock_sync_minutes, 15)
            self.assertEqual(config.clock_max_drift_seconds, 8)
            self.assertTrue(config.clock_allow_backward)

    def test_clock_config_clamps_negative_values(self) -> None:
        with tempfile.TemporaryDirectory() as raw:
            path = _write_config(
                Path(raw),
                "[default]\nclock_sync_minutes=-5\nclock_max_drift_seconds=-2\n\n"
                "[device:gerbang]\nip=192.168.1.11\n",
            )
            config = zkconnector.Config(path).load()

            self.assertEqual(config.clock_sync_minutes, 0)
            self.assertEqual(config.clock_max_drift_seconds, 0)
            self.assertFalse(config.clock_allow_backward)

    def test_config_with_utf8_bom_is_parsed(self) -> None:
        # Beberapa editor Windows (Notepad) menambahkan BOM; parser harus tetap membacanya.
        with tempfile.TemporaryDirectory() as raw:
            path = Path(raw) / "connector_config.ini"
            path.write_text(
                "[default]\napi_url=http://server/api/v1/fingerprint/ingest\n\n"
                "[device:gerbang]\nip=192.168.1.11\ntoken=tok-g\n",
                encoding="utf-8-sig",
            )
            config = zkconnector.Config(path).load()

            self.assertEqual(list(config.devices.keys()), ["gerbang"])
            self.assertEqual(config.devices["gerbang"].ip, "192.168.1.11")
            self.assertEqual(config.api_url, "http://server/api/v1/fingerprint/ingest")

    def test_missing_config_creates_legacy_default(self) -> None:
        with tempfile.TemporaryDirectory() as raw:
            path = Path(raw) / "connector_config.ini"
            config = zkconnector.Config(path).load()

            self.assertTrue(path.exists())
            self.assertIn(zkconnector.LEGACY_KEY, config.devices)
            text = path.read_text(encoding="utf-8")
            self.assertTrue(text.startswith("# Konfigurasi Koneksi"))
            self.assertIn("clock_sync_minutes=60", text)
            self.assertIn("clock_allow_backward=false", text)

    def test_save_roundtrip_multi_device(self) -> None:
        with tempfile.TemporaryDirectory() as raw:
            path = Path(raw) / "connector_config.ini"
            config = zkconnector.Config(path)
            config.api_url = "http://server/api/v1/fingerprint/ingest"
            config.clock_sync_minutes = 30
            config.clock_max_drift_seconds = 12
            config.clock_allow_backward = True
            config.devices["gerbang"] = DeviceConfig("gerbang", "10.0.0.1", 4370, "0", "tok-1")
            config.devices["kelas"] = DeviceConfig("kelas", "10.0.0.2", 4370, "9", "tok-2", mode="event")
            config.save()

            reloaded = zkconnector.Config(path).load()
            self.assertEqual(list(reloaded.devices.keys()), ["gerbang", "kelas"])
            self.assertEqual(reloaded.devices["kelas"].comm_key, "9")
            self.assertEqual(reloaded.devices["kelas"].mode, "event")
            self.assertEqual(reloaded.clock_sync_minutes, 30)
            self.assertEqual(reloaded.clock_max_drift_seconds, 12)
            self.assertTrue(reloaded.clock_allow_backward)

    def test_device_state_paths_are_sanitized(self) -> None:
        device = DeviceConfig("Ruang/Guru 1", "10.0.0.3")
        self.assertEqual(device.state_dir, zkconnector.STATE_DIR / "Ruang_Guru_1")

    def test_local_api_token_is_optional_only_when_not_configured(self) -> None:
        self.assertTrue(_api_token_valid("", ""))
        self.assertTrue(_api_token_valid("tok-local", "tok-local"))
        self.assertFalse(_api_token_valid("tok-local", ""))
        self.assertFalse(_api_token_valid("tok-local", "salah"))


class ConnectorTest(unittest.TestCase):
    def setUp(self) -> None:
        self._tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self._tmp.cleanup)
        self.state_root = Path(self._tmp.name)

        self._patcher = mock.patch.object(zkconnector, "STATE_DIR", self.state_root)
        self._patcher.start()
        self.addCleanup(self._patcher.stop)

        self.config = zkconnector.Config(self.state_root / "connector_config.ini")
        self.config.api_url = "http://server/api/v1/fingerprint/ingest"
        self.config.batch_size = 20
        self.config.clock_sync_minutes = 0
        self.config.devices["gerbang"] = DeviceConfig("gerbang", "10.0.0.1", 4370, "0", "tok-1")
        self.config.devices["kelas"] = DeviceConfig("kelas", "10.0.0.2", 4370, "0", "tok-2")
        self.connector = Connector(self.config)

    def test_lock_for_is_per_device(self) -> None:
        gerbang = self.config.devices["gerbang"]
        kelas = self.config.devices["kelas"]

        self.assertIs(self.connector._lock_for(gerbang.ip, gerbang.port), self.connector._lock_for(gerbang.ip, 4370))
        self.assertIsNot(self.connector._lock_for(gerbang.ip, gerbang.port), self.connector._lock_for(kelas.ip, kelas.port))

    def test_resolve_api_target_uses_overrides_then_primary(self) -> None:
        ip, port, comm_key = self.connector._resolve_api_target({})
        self.assertEqual((ip, port, comm_key), ("10.0.0.1", 4370, "0"))

        ip, port, comm_key = self.connector._resolve_api_target({"ip": "10.9.9.9", "port": "4000", "comm_key": "7"})
        self.assertEqual((ip, port, comm_key), ("10.9.9.9", 4000, "7"))

    def test_send_batched_splits_by_batch_size(self) -> None:
        self.config.batch_size = 2
        sizes: list[int] = []
        self.connector.send_logs = lambda records, token: sizes.append(len(records)) or True

        device = self.config.devices["gerbang"]
        records = [_record("1", "2026-10-14 07:0{0}:00".format(i)) for i in range(5)]

        self.assertTrue(self.connector._send_batched(device, records))
        self.assertEqual(sizes, [2, 2, 1])

    def test_send_batched_stops_after_first_failure(self) -> None:
        self.config.batch_size = 2
        sizes: list[int] = []

        def _send(records: list[dict], _token: str) -> bool:
            sizes.append(len(records))
            return len(sizes) == 1

        self.connector.send_logs = _send
        device = self.config.devices["gerbang"]
        records = [_record("1", "2026-10-14 07:0{0}:00".format(i)) for i in range(6)]

        self.assertFalse(self.connector._send_batched(device, records))
        self.assertEqual(sizes, [2, 2])

    def test_send_logs_requires_matching_accepted_count(self) -> None:
        records = [_record("101", "2026-10-14 07:05:00"), _record("102", "2026-10-14 07:05:01")]
        response = FakeResponse(200, {"status": "success", "summary": {"accepted": 2}})

        with mock.patch.object(zkconnector, "urlopen", return_value=response):
            self.assertTrue(self.connector.send_logs(records, "tok-1"))

    def test_send_logs_rejects_partial_acceptance(self) -> None:
        records = [_record("101", "2026-10-14 07:05:00"), _record("102", "2026-10-14 07:05:01")]
        response = FakeResponse(200, {"status": "success", "summary": {"accepted": 1}})

        with mock.patch.object(zkconnector, "urlopen", return_value=response):
            self.assertFalse(self.connector.send_logs(records, "tok-1"))

    def test_send_logs_rejects_missing_accepted_summary(self) -> None:
        response = FakeResponse(200, {"status": "success"})

        with mock.patch.object(zkconnector, "urlopen", return_value=response):
            self.assertFalse(
                self.connector.send_logs(
                    [_record("101", "2026-10-14 07:05:00")], "tok-1"
                )
            )

    def test_send_logs_rejects_failed_status(self) -> None:
        response = FakeResponse(200, {"status": "error", "message": "ditolak"})

        with mock.patch.object(zkconnector, "urlopen", return_value=response):
            self.assertFalse(
                self.connector.send_logs(
                    [_record("101", "2026-10-14 07:05:00")], "tok-1"
                )
            )

    def test_status_endpoint_lists_devices(self) -> None:
        result = self.connector.handle_api("/api/status", {})
        self.assertEqual(result["status"], "ok")
        self.assertEqual([d["key"] for d in result["devices"]], ["gerbang", "kelas"])

    def test_wrap_logs_preserves_source_key(self) -> None:
        wrapped = self.connector._wrap_logs([_record("101", "2026-10-14 07:05:00")])
        self.assertTrue(wrapped["success"])
        self.assertEqual(wrapped["count"], 1)
        self.assertTrue(wrapped["logs"][0]["source_key"].startswith("x601_101_"))

    def test_probe_endpoint_returns_capabilities(self) -> None:
        self.connector.probe_capabilities = lambda ip, port, comm_key: {"live_event": True}

        result = self.connector.handle_api("/api/probe", {})

        self.assertTrue(result["success"])
        self.assertTrue(result["capabilities"]["live_event"])

    def test_unknown_endpoint_returns_error(self) -> None:
        self.assertEqual(self.connector.handle_api("/api/nope", {})["status"], "error")

    def test_run_daemon_starts_worker_per_enabled_device(self) -> None:
        started: list[str] = []
        self.connector._worker_loop = lambda device: started.append(device.key)

        with mock.patch.object(zkconnector, "_start_http_api", return_value=None):
            thread = threading.Thread(target=self.connector.run_daemon, daemon=True)
            thread.start()
            for _ in range(50):
                if len(started) == 2:
                    break
                time.sleep(0.02)
            self.connector._stop.set()
            thread.join(timeout=2.0)

        self.assertCountEqual(started, ["gerbang", "kelas"])

    def test_run_daemon_requires_enabled_device(self) -> None:
        self.config.devices["gerbang"].enabled = False
        self.config.devices["kelas"].enabled = False

        with mock.patch.object(zkconnector, "_start_http_api", return_value=None):
            self.assertEqual(self.connector.run_daemon(), 1)

    def test_poll_device_sends_only_new_records_and_advances_watermark(self) -> None:
        sent: list[dict] = []
        self.connector.send_logs = lambda records, token: sent.extend(records) or True
        self.connector._open_device = lambda ip, port, comm_key, chunk_size=None: FakeDevice(
            [
                _record("101", "2026-10-14 07:05:00"),
                _record("102", "2026-10-14 07:06:00"),
            ]
        )
        device = self.config.devices["gerbang"]

        self.connector.poll_device(device)
        self.assertEqual([r["pin"] for r in sent], ["101", "102"])
        state = self.connector.states["gerbang"]
        self.assertEqual(state.last_watermark, datetime(2026, 10, 14, 7, 6, 0))
        self.assertEqual(state.last_sync, datetime(2026, 10, 14, 7, 6, 0))
        self.assertEqual(
            state.device.watermark_file.read_text(encoding="utf-8"), "2026-10-14 07:06:00"
        )

        # Scan berikutnya: hanya yang lebih baru yang dikirim.
        sent.clear()
        self.connector._open_device = lambda ip, port, comm_key, chunk_size=None: FakeDevice(
            [
                _record("101", "2026-10-14 07:05:00"),
                _record("103", "2026-10-14 07:07:00"),
            ]
        )
        self.connector.poll_device(device)
        self.assertEqual([r["pin"] for r in sent], ["103"])

    def test_poll_device_buffers_records_when_send_fails(self) -> None:
        self.connector.send_logs = lambda records, token: False
        self.connector._open_device = lambda ip, port, comm_key, chunk_size=None: FakeDevice(
            [_record("101", "2026-10-14 07:05:00")]
        )
        device = self.config.devices["gerbang"]

        self.connector.poll_device(device)

        state = self.connector.states["gerbang"]
        self.assertEqual(state.last_watermark, datetime.min)
        buffer = state.device.buffer_file
        self.assertTrue(buffer.exists())
        self.assertIn("101|2026-10-14 07:05:00|1|x601_101_", buffer.read_text(encoding="utf-8"))

        # Saat pengiriman pulih, buffer dikirim dan dihapus.
        delivered: list[dict] = []
        self.connector.send_logs = lambda records, token: delivered.extend(records) or True
        self.connector.poll_device(device)

        self.assertFalse(buffer.exists())
        self.assertTrue(any(r["pin"] == "101" for r in delivered))

    def test_poll_device_caches_successful_chunk_size(self) -> None:
        fake = FakeDevice([])
        fake.chunk_size = 1024
        fake.last_chunk_size = 1024
        fake.last_read_mode = "buffered"
        self.connector._open_device = (
            lambda ip, port, comm_key, chunk_size=None: fake
        )
        device = self.config.devices["gerbang"]

        self.assertTrue(self.connector.poll_device(device))

        self.assertEqual(self.connector._chunk_hints["gerbang"], 1024)
        state = self.connector.states["gerbang"]
        self.assertEqual(state.last_chunk_size, 1024)
        self.assertEqual(state.last_read_mode, "buffered")

    def test_poll_device_passes_cached_chunk_hint(self) -> None:
        seen: list[int | None] = []
        fake = FakeDevice([])

        def _open(ip, port, comm_key, chunk_size=None):
            seen.append(chunk_size)
            return fake

        self.connector._chunk_hints["gerbang"] = 8192
        self.connector._open_device = _open
        device = self.config.devices["gerbang"]

        self.connector.poll_device(device)

        self.assertEqual(seen, [8192])

    def test_poll_device_returns_false_when_device_is_offline(self) -> None:
        def _offline(*_args, **_kwargs):
            raise ZKConnectionError("Mesin tidak merespons.")

        self.connector._open_device = _offline
        device = self.config.devices["gerbang"]

        self.assertFalse(self.connector.poll_device(device))
        self.assertIn("tidak merespons", self.connector.states["gerbang"].last_error)

    def test_backoff_seconds_grows_then_caps_at_sixty(self) -> None:
        delays = [self.connector._backoff_seconds(failures) for failures in range(1, 8)]

        self.assertEqual(delays, [5, 10, 20, 40, 60, 60, 60])

    def test_worker_loop_backs_off_after_failures(self) -> None:
        self.connector.poll_device = lambda device: False
        waits: list[float] = []

        def _wait(seconds: float) -> None:
            waits.append(seconds)
            if len(waits) >= 4:
                self.connector._stop.set()

        self.connector._stop.wait = _wait
        self.connector._worker_loop(self.config.devices["gerbang"])

        self.assertEqual(waits, [5, 10, 20, 40])

    def test_worker_loop_resets_backoff_after_success(self) -> None:
        results = iter([False, False, True])
        self.connector.poll_device = lambda device: next(results, True)
        self.config.poll_interval_seconds = 7
        waits: list[float] = []

        def _wait(seconds: float) -> None:
            waits.append(seconds)
            if len(waits) >= 3:
                self.connector._stop.set()

        self.connector._stop.wait = _wait
        self.connector._worker_loop(self.config.devices["gerbang"])

        self.assertEqual(waits, [5, 10, 7])

    def test_diagnose_reports_tcp_unreachable(self) -> None:
        with mock.patch.object(zkconnector, "probe_tcp", return_value=False):
            result = self.connector.diagnose("10.0.0.1", 4370, "0")

        self.assertFalse(result["tcp_reachable"])
        self.assertFalse(result["connected"])
        self.assertIn("tidak dapat dihubungi", result["recommendation"])

    def test_diagnose_tests_each_chunk_size(self) -> None:
        class FakeDiagDevice:
            def read_sizes(self) -> dict:
                return {"users": 1, "records": 10}

            def get_time(self) -> datetime:
                return datetime.now()

            def read_with_buffer(
                self,
                command,
                fct=0,
                ext=0,
                preferred_chunk=None,
                allow_direct_fallback=True,
            ):
                if preferred_chunk and preferred_chunk > 32768:
                    raise ZKProtocolError("chunk ditolak")
                return b"\x04\x00\x00\x00data", 8

            def disconnect(self) -> None:
                return None

        self.connector._open_device = lambda *args, **kwargs: FakeDiagDevice()

        with mock.patch.object(zkconnector, "probe_tcp", return_value=True):
            result = self.connector.diagnose("10.0.0.1", 4370, "0")

        self.assertTrue(result["connected"])
        self.assertEqual(
            [test["chunk_size"] for test in result["chunk_tests"]],
            list(CHUNK_CANDIDATES),
        )
        self.assertEqual(
            [test["success"] for test in result["chunk_tests"]],
            [False] + [True] * (len(CHUNK_CANDIDATES) - 1),
        )
        self.assertIn("32768", result["recommendation"])

    def test_diagnose_flag_is_parsed(self) -> None:
        self.assertTrue(zkconnector.parse_arguments(["--diagnose"]).diagnose)

    def test_fetch_logs_filters_records_before_since(self) -> None:
        fake = FakeDevice(
            [
                _record("101", "2026-10-14 07:05:00"),
                _record("102", "2026-10-14 07:06:00"),
            ]
        )
        self.connector._open_device = lambda ip, port, comm_key, chunk_size=None: fake
        device = self.config.devices["gerbang"]

        logs = self.connector.fetch_logs(
            device.ip, device.port, device.comm_key, datetime(2026, 10, 14, 7, 6, 0)
        )

        self.assertEqual([record["pin"] for record in logs], ["102"])
        self.assertTrue(fake.closed)

    def test_sync_logs_api_passes_since_and_returns_it(self) -> None:
        seen: list[datetime | None] = []

        def _fetch(ip, port, comm_key, value=None):
            seen.append(value)
            return [_record("102", "2026-10-14 07:06:00")]

        self.connector.fetch_logs = _fetch

        result = self.connector.handle_api(
            "/api/sync-logs", {"since": "2026-10-14 07:06:00"}
        )

        self.assertTrue(result["success"])
        self.assertEqual(seen, [datetime(2026, 10, 14, 7, 6, 0)])
        self.assertEqual(result["since"], "2026-10-14 07:06:00")
        self.assertEqual(result["logs"][0]["pin"], "102")

    def test_sync_logs_api_ignores_invalid_since_like_csharp(self) -> None:
        self.connector.fetch_logs = lambda ip, port, comm_key, since=None: []

        result = self.connector.handle_api("/api/sync-logs", {"since": "bukan-waktu"})

        self.assertTrue(result["success"])
        self.assertEqual(result["since"], "")

    def test_run_sync_logs_passes_since_to_connector(self) -> None:
        since = datetime(2026, 10, 14, 7, 6, 0)
        seen: list[datetime | None] = []
        self.connector.fetch_logs = lambda ip, port, comm_key, value=None: (
            seen.append(value) or []
        )
        emitted: list[dict] = []
        device = self.config.devices["gerbang"]

        with mock.patch.object(zkconnector, "_emit", emitted.append):
            code = zkconnector.run_sync_logs(self.connector, device, since)

        self.assertEqual(code, 0)
        self.assertEqual(seen, [since])
        self.assertEqual(emitted[0]["since"], "2026-10-14 07:06:00")

    def test_poll_device_sends_unseen_pin_at_same_watermark(self) -> None:
        state = self.connector.states["gerbang"]
        boundary = datetime(2026, 10, 14, 7, 5, 0)
        first = _record("101", "2026-10-14 07:05:00")
        second = _record("102", "2026-10-14 07:05:00")
        state.last_watermark = boundary
        state.seen_at_watermark = {first["source_key"]}
        sent: list[dict] = []
        self.connector.send_logs = lambda records, token: sent.extend(records) or True
        self.connector._open_device = lambda ip, port, comm_key, chunk_size=None: FakeDevice([first, second])
        device = self.config.devices["gerbang"]

        self.connector.poll_device(device)

        self.assertEqual([record["pin"] for record in sent], ["102"])
        self.assertEqual(state.seen_at_watermark, {first["source_key"], second["source_key"]})

        sent.clear()
        self.connector.poll_device(device)
        self.assertEqual(sent, [])

    def test_poll_device_runs_clock_sync_on_the_same_connection(self) -> None:
        self.config.clock_sync_minutes = 60
        self.config.clock_max_drift_seconds = 60
        fake = FakeDevice([], device_time=datetime.now())
        self.connector._open_device = lambda ip, port, comm_key, chunk_size=None: fake
        device = self.config.devices["gerbang"]

        self.connector.poll_device(device)

        state = self.connector.states["gerbang"]
        self.assertEqual(fake.get_time_calls, 1)
        self.assertIsNotNone(state.last_clock_attempt)
        self.assertTrue(fake.closed)

    def test_sync_clock_corrects_slow_device(self) -> None:
        now = datetime(2026, 10, 23, 12, 0, 0)
        self.config.clock_sync_minutes = 60
        self.config.clock_max_drift_seconds = 5
        fake = FakeDevice([], device_time=now - timedelta(seconds=20))
        state = self.connector.states["gerbang"]

        self.connector._sync_clock_if_due(state, fake, now=now)

        self.assertEqual(fake.set_time_calls, [now])
        self.assertEqual(state.last_clock_sync, now)
        self.assertEqual(state.clock_drift_seconds, -20)
        self.assertEqual(state.last_clock_attempt, now)

    def test_sync_clock_skips_fast_device_when_backward_disabled(self) -> None:
        now = datetime(2026, 10, 23, 12, 0, 0)
        self.config.clock_sync_minutes = 60
        self.config.clock_max_drift_seconds = 5
        self.config.clock_allow_backward = False
        fake = FakeDevice([], device_time=now + timedelta(seconds=20))
        state = self.connector.states["gerbang"]

        self.connector._sync_clock_if_due(state, fake, now=now)

        self.assertEqual(fake.set_time_calls, [])
        self.assertIsNone(state.last_clock_sync)
        self.assertEqual(state.clock_drift_seconds, 20)
        self.assertEqual(state.last_clock_attempt, now)

    def test_sync_clock_resets_watermark_when_moving_backward(self) -> None:
        now = datetime(2026, 10, 23, 12, 0, 0)
        self.config.clock_sync_minutes = 60
        self.config.clock_max_drift_seconds = 5
        self.config.clock_allow_backward = True
        fake = FakeDevice([], device_time=now + timedelta(seconds=120))
        state = self.connector.states["gerbang"]
        state.last_watermark = now + timedelta(seconds=300)
        state.seen_at_watermark = {"x601_101_1"}

        self.connector._sync_clock_if_due(state, fake, now=now)

        self.assertEqual(fake.set_time_calls, [now])
        self.assertEqual(state.last_clock_sync, now)
        self.assertEqual(state.last_watermark, now - timedelta(seconds=1))
        self.assertEqual(state.seen_at_watermark, set())
        self.assertEqual(
            state.device.watermark_file.read_text(encoding="utf-8"),
            "2026-10-23 11:59:59",
        )

    def test_sync_clock_skips_drift_within_threshold(self) -> None:
        now = datetime(2026, 10, 23, 12, 0, 0)
        self.config.clock_sync_minutes = 60
        self.config.clock_max_drift_seconds = 5
        fake = FakeDevice([], device_time=now + timedelta(seconds=4))
        state = self.connector.states["gerbang"]

        self.connector._sync_clock_if_due(state, fake, now=now)

        self.assertEqual(fake.set_time_calls, [])
        self.assertEqual(state.clock_drift_seconds, 4)
        self.assertEqual(state.last_clock_attempt, now)

    def test_sync_clock_respects_interval(self) -> None:
        now = datetime(2026, 10, 23, 12, 0, 0)
        self.config.clock_sync_minutes = 60
        fake = FakeDevice([], device_time=now - timedelta(seconds=20))
        state = self.connector.states["gerbang"]
        state.last_clock_attempt = now - timedelta(minutes=30)

        self.connector._sync_clock_if_due(state, fake, now=now)

        self.assertEqual(fake.get_time_calls, 0)
        self.assertEqual(fake.set_time_calls, [])

    def test_sync_clock_disabled_does_not_touch_device(self) -> None:
        self.config.clock_sync_minutes = 0
        fake = FakeDevice([], device_time=datetime(2026, 10, 23, 12, 0, 0))
        state = self.connector.states["gerbang"]

        self.connector._sync_clock_if_due(
            state, fake, now=datetime(2026, 10, 23, 12, 0, 0)
        )

        self.assertEqual(fake.get_time_calls, 0)
        self.assertEqual(fake.set_time_calls, [])

    def test_sync_clock_raises_when_device_rejects_time_change(self) -> None:
        now = datetime(2026, 10, 23, 12, 0, 0)
        self.config.clock_sync_minutes = 60
        self.config.clock_max_drift_seconds = 5
        fake = FakeDevice(
            [], device_time=now - timedelta(seconds=20), set_time_result=False
        )
        state = self.connector.states["gerbang"]

        with self.assertRaises(ZKProtocolError):
            self.connector._sync_clock_if_due(state, fake, now=now)

        self.assertIsNone(state.last_clock_sync)

    def test_manual_sync_time_uses_current_host_clock(self) -> None:
        fake = FakeDevice([], device_time=datetime.now() - timedelta(seconds=10))
        self.connector._open_device = lambda ip, port, comm_key, chunk_size=None: fake
        device = self.config.devices["gerbang"]

        result = self.connector.sync_time(device.ip, device.port, device.comm_key)

        self.assertTrue(result["success"])
        self.assertTrue(result["synced"])
        self.assertEqual(len(fake.set_time_calls), 1)
        self.assertLess(abs(fake.set_time_calls[0] - datetime.now()), timedelta(seconds=5))

    def test_manual_sync_time_refuses_backward_correction(self) -> None:
        fake = FakeDevice([], device_time=datetime.now() + timedelta(minutes=5))
        self.connector._open_device = lambda ip, port, comm_key, chunk_size=None: fake
        device = self.config.devices["gerbang"]

        result = self.connector.sync_time(device.ip, device.port, device.comm_key)

        self.assertFalse(result["success"])
        self.assertEqual(result["code"], -1)
        self.assertEqual(fake.set_time_calls, [])

    def test_sync_time_api_rejects_explicit_target(self) -> None:
        result = self.connector.handle_api(
            "/api/sync-time", {"time": "2026-10-23 12:00:00"}
        )

        self.assertFalse(result["success"])
        self.assertEqual(result["code"], -998)

    def test_sync_time_api_uses_current_host_clock(self) -> None:
        fake = FakeDevice([], device_time=datetime.now() - timedelta(seconds=10))
        self.connector._open_device = lambda ip, port, comm_key, chunk_size=None: fake

        result = self.connector.handle_api("/api/sync-time", {})

        self.assertTrue(result["success"])
        self.assertEqual(len(fake.set_time_calls), 1)

    def test_run_sync_time_sets_clock_and_returns_success(self) -> None:
        fake = FakeDevice([], device_time=datetime.now() - timedelta(seconds=10))
        self.connector._open_device = lambda ip, port, comm_key, chunk_size=None: fake
        emitted: list[dict] = []
        device = self.config.devices["gerbang"]

        with mock.patch.object(zkconnector, "_emit", emitted.append):
            code = zkconnector.run_sync_time(self.connector, device)

        self.assertEqual(code, 0)
        self.assertTrue(emitted[0]["success"])
        self.assertEqual(len(fake.set_time_calls), 1)

    def test_status_endpoint_includes_clock_state(self) -> None:
        state = self.connector.states["gerbang"]
        state.last_sync = datetime(2026, 10, 23, 12, 0, 0)
        state.last_clock_sync = datetime(2026, 10, 23, 12, 1, 0)
        state.clock_drift_seconds = -20
        state.last_error = ""

        result = self.connector.handle_api("/api/status", {})
        device = result["devices"][0]

        self.assertEqual(device["last_sync"], "2026-10-23 12:00:00")
        self.assertEqual(device["last_clock_sync"], "2026-10-23 12:01:00")
        self.assertEqual(device["clock_drift_seconds"], -20)
        self.assertEqual(result["clock_sync_minutes"], 0)


class ReadBufferFallbackTest(unittest.TestCase):
    """Regresi firmware yang menolak CMD_READ_BUFFER 64 KB."""

    def test_read_with_buffer_retries_smaller_chunks_then_caches_size(self) -> None:
        device = ZKDevice("127.0.0.1")
        device._reconnect = lambda: None
        attempts: list[int] = []

        def _once(command, fct, ext, chunk_size):
            attempts.append(chunk_size)
            if chunk_size > 1024:
                raise ZKProtocolError(f"ditolak {chunk_size}")
            return b"\x04\x00\x00\x00data", 8

        device._read_with_buffer_once = _once

        data, size = device.read_with_buffer(CMD_ATTLOG_RRQ)

        self.assertEqual(data, b"\x04\x00\x00\x00data")
        self.assertEqual(size, 8)
        self.assertEqual(attempts, list(CHUNK_CANDIDATES))
        self.assertEqual(device.last_chunk_size, 1024)
        self.assertEqual(device.last_read_mode, "buffered")

        # Percobaan berikutnya langsung memakai ukuran yang berhasil.
        attempts.clear()
        device.read_with_buffer(CMD_ATTLOG_RRQ)
        self.assertEqual(attempts, [1024])

    def test_read_with_buffer_falls_back_to_direct_read(self) -> None:
        device = ZKDevice("127.0.0.1")
        device._reconnect = lambda: None

        def _boom(command, fct, ext, chunk_size):
            raise ZKProtocolError("buffered ditolak")

        device._read_with_buffer_once = _boom
        device.read_direct = lambda command, fct=0, ext=0: (
            b"\x04\x00\x00\x00data",
            8,
        )

        data, size = device.read_with_buffer(CMD_ATTLOG_RRQ)

        self.assertEqual(data, b"\x04\x00\x00\x00data")
        self.assertEqual(size, 8)
        self.assertEqual(device.last_read_mode, "direct")
        self.assertIsNone(device.last_chunk_size)
        self.assertEqual(device.chunk_size, CHUNK_CANDIDATES[-1])

    def test_read_with_buffer_reports_all_attempts_on_failure(self) -> None:
        device = ZKDevice("127.0.0.1")
        device._reconnect = lambda: None

        def _boom(command, fct, ext, chunk_size):
            raise ZKProtocolError("buffered ditolak")

        device._read_with_buffer_once = _boom
        device.read_direct = lambda *args, **kwargs: (_ for _ in ()).throw(
            ZKProtocolError("direct ditolak")
        )

        with self.assertRaises(ZKProtocolError) as ctx:
            device.read_with_buffer(CMD_ATTLOG_RRQ)

        message = str(ctx.exception)
        self.assertIn("65472", message)
        self.assertIn("1024", message)
        self.assertIn("direct ditolak", message)

    def test_read_chunk_error_includes_response_code(self) -> None:
        device = ZKDevice("127.0.0.1")
        device._send_command = lambda *args, **kwargs: {"status": False, "code": 2001}

        with self.assertRaisesRegex(ZKProtocolError, "kode respons 2001"):
            device._read_chunk(0, 1024)

    def test_read_direct_truncates_trailing_bytes(self) -> None:
        device = ZKDevice("127.0.0.1")
        sent: list[int] = []
        device._send_command = lambda command, *args, **kwargs: (
            sent.append(command) or {"status": True, "code": CMD_ACK_OK}
        )
        device._recv_chunk = lambda: struct.pack("<I", 8) + b"abcdefghTRAILER"

        data, size = device.read_direct(CMD_ATTLOG_RRQ)

        self.assertEqual(data, struct.pack("<I", 8) + b"abcdefgh")
        self.assertEqual(size, 12)
        self.assertEqual(sent, [CMD_ATTLOG_RRQ, CMD_FREE_DATA])

    def test_read_direct_completes_short_payload(self) -> None:
        device = ZKDevice("127.0.0.1")
        device._send_command = lambda *args, **kwargs: {"status": True, "code": CMD_ACK_OK}
        device._recv_chunk = lambda: struct.pack("<I", 8) + b"abc"
        device._recv_raw = lambda size: b"defgh"[:size]

        data, size = device.read_direct(CMD_ATTLOG_RRQ)

        self.assertEqual(data, struct.pack("<I", 8) + b"abcdefgh")
        self.assertEqual(size, 12)

    def test_read_direct_rejects_zero_size(self) -> None:
        device = ZKDevice("127.0.0.1")
        device._send_command = lambda *args, **kwargs: {"status": True, "code": CMD_ACK_OK}
        device._recv_chunk = lambda: struct.pack("<I", 0)

        with self.assertRaisesRegex(ZKProtocolError, "ukuran data 0"):
            device.read_direct(CMD_ATTLOG_RRQ)


class ProtocolTest(unittest.TestCase):
    def test_build_record_source_key_matches_canonical_format(self) -> None:
        device = ZKDevice("127.0.0.1")
        scan = datetime(2026, 10, 14, 7, 5, 12)

        record = device._build_record(None, "101", 1, struct.pack("<I", _encode_time(scan)), 0)

        self.assertEqual(record["source_key"], f"x601_101_{int(scan.timestamp())}")
        self.assertEqual(record["time"], "2026-10-14 07:05:12")

    def test_create_header_uses_bytes_checksum(self) -> None:
        # Regresi: checksum harus menerima bytes (bukan tuple hasil struct.unpack);
        # jika tidak, koneksi ke mesin nyata gagal dengan TypeError sebelum terkirim.
        device = ZKDevice("127.0.0.1")

        header = device._create_header(1000, b"", 1, 1)

        self.assertEqual(len(header), 8)
        self.assertEqual(struct.unpack("<H", header[0:2])[0], 1000)
        self.assertIsInstance(_checksum(b"\x00\x01\x02\x03"), int)

    def test_tcp_top_uses_zkteco_magic(self) -> None:
        # Regresi: mesin menutup koneksi (WinError 10054) bila pembuka TCP salah.
        # Nilai benar protokol standalone ZKTeco: 50 50 82 7d.
        device = ZKDevice("127.0.0.1")

        top = device._tcp_top(b"\x00" * 8)

        self.assertEqual(top[0:4], bytes.fromhex("5050827d"))
        self.assertEqual(device._test_tcp_top(top), 8)

    def test_probe_capabilities_reports_flags(self) -> None:
        device = ZKDevice("127.0.0.1")
        device.read_sizes = lambda: {"users": 5, "fingers": 9, "records": 42}
        device.register_events = lambda flag=1: True

        caps = device.probe_capabilities()

        self.assertTrue(caps["buffered_read"])
        self.assertTrue(caps["attendance_read"])
        self.assertTrue(caps["live_event"])
        self.assertEqual(caps["users"], 5)
        self.assertEqual(caps["records"], 42)

    def test_probe_capabilities_tolerates_unsupported_device(self) -> None:
        device = ZKDevice("127.0.0.1")

        def _boom():
            raise ZKProtocolError("tidak didukung")

        device.read_sizes = _boom
        device.register_events = lambda flag=1: False

        caps = device.probe_capabilities()

        self.assertEqual(caps["buffered_read"], False)
        self.assertEqual(caps["live_event"], False)
        self.assertEqual(caps["records"], 0)

    def test_read_event_detects_event_packet(self) -> None:
        device = ZKDevice("127.0.0.1")
        device._sock = FakeSocket(_frame(CMD_REG_EVENT, b"\x01\x00\x00\x00"))

        self.assertTrue(device.read_event(timeout=1.0))

    def test_read_event_ignores_non_event_packet(self) -> None:
        device = ZKDevice("127.0.0.1")
        device._sock = FakeSocket(_frame(CMD_ACK_OK))

        self.assertFalse(device.read_event(timeout=1.0))

    def test_read_event_returns_false_on_timeout(self) -> None:
        device = ZKDevice("127.0.0.1")
        device._sock = FakeSocket(b"")

        self.assertFalse(device.read_event(timeout=1.0))

    def test_encode_time_matches_reference(self) -> None:
        scan = datetime(2026, 10, 14, 7, 5, 12)

        self.assertEqual(ZKDevice._encode_time(scan), _encode_time(scan))

    def test_encode_time_rejects_out_of_range_year(self) -> None:
        with self.assertRaises(ValueError):
            ZKDevice._encode_time(datetime(1999, 12, 31, 23, 59, 59))

    def test_send_command_reads_exact_body_and_exposes_payload(self) -> None:
        device = ZKDevice("127.0.0.1")
        device._sock = FakeSocket(_frame(CMD_ACK_OK, b"\x01\x02\x03\x04"))

        response = device._send_command(CMD_GET_TIME)

        self.assertTrue(response["status"])
        self.assertEqual(device._tcp_length, 12)
        self.assertEqual(device._data, b"\x01\x02\x03\x04")

    def test_read_sizes_parses_payload_without_header_offset(self) -> None:
        device = ZKDevice("127.0.0.1")
        fields = [0] * 20
        fields[4] = 5
        fields[6] = 9
        fields[8] = 42
        device._sock = FakeSocket(_frame(CMD_ACK_OK, struct.pack("20i", *fields)))

        sizes = device.read_sizes()

        self.assertEqual(sizes, {"users": 5, "fingers": 9, "records": 42})

    def test_get_time_decodes_machine_time(self) -> None:
        scan = datetime(2026, 10, 14, 7, 5, 12)
        device = ZKDevice("127.0.0.1")
        sock = FakeSocket(_frame(CMD_ACK_OK, struct.pack("<I", _encode_time(scan))))
        device._sock = sock

        self.assertEqual(device.get_time(), scan)
        self.assertEqual(struct.unpack("<H", sock.sent[0][8:10])[0], CMD_GET_TIME)

    def test_get_time_raises_when_device_rejects_command(self) -> None:
        device = ZKDevice("127.0.0.1")
        device._sock = FakeSocket(_frame(0xFFFF))

        with self.assertRaises(ZKProtocolError):
            device.get_time()

    def test_set_time_sends_encoded_command(self) -> None:
        scan = datetime(2026, 10, 14, 7, 5, 12)
        device = ZKDevice("127.0.0.1")
        sock = FakeSocket(_frame(CMD_ACK_OK))
        device._sock = sock

        self.assertTrue(device.set_time(scan))
        sent = sock.sent[0]
        self.assertEqual(struct.unpack("<H", sent[8:10])[0], CMD_SET_TIME)
        self.assertEqual(struct.unpack("<I", sent[16:20])[0], _encode_time(scan))

    def test_conversion_helpers(self) -> None:
        self.assertEqual(_to_int("12", 3), 12)
        self.assertEqual(_to_int("abc", 3), 3)
        self.assertTrue(_to_bool("true", False))
        self.assertFalse(_to_bool("off", True))
        self.assertTrue(_to_bool(None, True))


class DeviceStateTest(unittest.TestCase):
    def test_load_watermark_restores_from_file(self) -> None:
        with tempfile.TemporaryDirectory() as raw:
            device = DeviceConfig("gerbang", "10.0.0.1")
            with mock.patch.object(zkconnector, "STATE_DIR", Path(raw)):
                device.watermark_file.parent.mkdir(parents=True, exist_ok=True)
                device.watermark_file.write_text("2026-10-14 07:00:00", encoding="utf-8")
                state = DeviceState(device)
                state.load_watermark()

            self.assertEqual(state.last_watermark, datetime(2026, 10, 14, 7, 0, 0))
            self.assertEqual(state.last_sync, datetime(2026, 10, 14, 7, 0, 0))


if __name__ == "__main__":
    unittest.main()
