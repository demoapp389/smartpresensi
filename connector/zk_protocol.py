"""Implementasi mandiri protokol komunikasi ZKTeco (Solution X601) lewat TCP port 4370.

Modul ini menggantikan ketergantungan pada COM `zkemkeeper.ZKEM` (yang hanya ada di
Windows) sehingga connector dapat berjalan di Windows maupun Linux. Hanya memakai
pustaka standar Python.

Cakupan sengaja dibatasi pada operasi read-only yang dipakai connector:
koneksi + comm key, baca kapasitas mesin, baca seluruh user, dan baca seluruh log absensi.
Baca data memakai mode "buffered read" (CMD_PREPARE_BUFFER/CMD_READ_BUFFER) pada TCP.
Bila firmware menolak ukuran chunk besar, pembacaan diulang dengan chunk yang lebih kecil
(adaptif) lalu jatuh ke mode non-buffered (CMD_ATTLOG_RRQ) sebagai upaya terakhir.
"""

from __future__ import annotations

import socket
import struct
from datetime import datetime

USHRT_MAX = 0xFFFF

CMD_USER_WRQ = 8
CMD_USERTEMP_RRQ = 9
CMD_OPTIONS_RRQ = 11
CMD_ATTLOG_RRQ = 13
CMD_GET_FREE_SIZES = 50
CMD_GET_TIME = 201
CMD_SET_TIME = 202
CMD_CONNECT = 1000
CMD_EXIT = 1001
CMD_ENABLEDEVICE = 1002
CMD_DISABLEDEVICE = 1003
CMD_PREPARE_DATA = 1500
CMD_DATA = 1501
CMD_FREE_DATA = 1502
CMD_PREPARE_BUFFER = 1503
CMD_READ_BUFFER = 1504
CMD_AUTH = 1102
CMD_REG_EVENT = 500

CMD_ACK_OK = 2000
CMD_ACK_ERROR = 2001
CMD_ACK_DATA = 2002
CMD_ACK_UNAUTH = 2005

FCT_ATTLOG = 1
FCT_USER = 5

# Flag event realtime (RegEvent): event pencatatan absensi.
EF_ATTLOG = 1

MACHINE_PREPARE_DATA_1 = 0x5050
# Pembuka paket TCP protokol standalone ZKTeco: byte "50 50 82 7d".
# Nilai ini terverifikasi lewat capture SDK resmi (zkemkeeper) pada Solution X601.
MACHINE_PREPARE_DATA_2 = 0x7D82

TCP_MAX_CHUNK = 0xFFC0

# Ukuran chunk buffered yang dicoba berurutan. Sebagian firmware X601 menolak
# CMD_READ_BUFFER 64 KB, sehingga pembacaan harus mundur ke ukuran yang lebih kecil.
CHUNK_CANDIDATES = (TCP_MAX_CHUNK, 32768, 16384, 8192, 4096, 1024)

# Batas waktu per chunk; lebih pendek dari timeout koneksi agar siklus poll tidak
# menggantung ~55 detik saat firmware menolak chunk.
CHUNK_READ_TIMEOUT = 5.0

# Perintah yang masih bisa dibaca tanpa mode buffered (format respons CMD_PREPARE_DATA).
DIRECT_READ_COMMANDS = (CMD_ATTLOG_RRQ,)


class ZKError(Exception):
    """Kesalahan umum protokol ZK."""


class ZKConnectionError(ZKError):
    """Gagal membuka koneksi TCP ke mesin."""


class ZKProtocolError(ZKError):
    """Respons mesin tidak sesuai harapan."""


def make_commkey(key: int, session_id: int, ticks: int = 50) -> bytes:
    """Enkripsi comm key sesuai algoritma MakeKey pada SDK ZKTeco (commpro.c)."""
    key = int(key) & 0xFFFFFFFF
    session_id = int(session_id) & 0xFFFFFFFF

    k = 0
    for i in range(32):
        if key & (1 << i):
            k = (k << 1 | 1) & 0xFFFFFFFF
        else:
            k = (k << 1) & 0xFFFFFFFF
    k = (k + session_id) & 0xFFFFFFFF

    raw = struct.unpack(b"BBBB", struct.pack(b"I", k))
    raw = struct.pack(
        b"BBBB",
        raw[0] ^ ord("Z"),
        raw[1] ^ ord("K"),
        raw[2] ^ ord("S"),
        raw[3] ^ ord("O"),
    )
    raw = struct.unpack(b"HH", raw)
    raw = struct.pack(b"HH", raw[1], raw[0])

    b = 0xFF & ticks
    raw = struct.unpack(b"BBBB", raw)
    raw = struct.pack(b"BBBB", raw[0] ^ b, raw[1] ^ b, b, raw[3] ^ b)
    return raw


def _checksum(payload: bytes) -> int:
    """Hitung checksum paket sesuai algoritma zkemsdk.c."""
    total = 0
    length = len(payload)
    while length > 1:
        total += struct.unpack(b"H", payload[:2])[0]
        payload = payload[2:]
        if total > USHRT_MAX:
            total -= USHRT_MAX
        length -= 2
    if length:
        total += payload[-1]
    while total > USHRT_MAX:
        total -= USHRT_MAX
    total = ~total
    while total < 0:
        total += USHRT_MAX
    return total


def probe_tcp(ip: str, port: int, timeout: float = 1.5) -> bool:
    """Cek cepat apakah port TCP mesin dapat dihubungi (tanpa ping ICMP)."""
    try:
        with socket.create_connection((ip, port), timeout=timeout):
            return True
    except OSError:
        return False


class ZKDevice:
    """Klien protokol ZK untuk satu mesin (satu koneksi TCP aktif)."""

    def __init__(self, ip: str, port: int = 4370, timeout: float = 10.0, password: int = 0):
        self.ip = ip
        self.port = int(port)
        self.timeout = float(timeout)
        self.password = int(password)

        self._sock: socket.socket | None = None
        self._session_id = 0
        self._reply_id = USHRT_MAX - 1
        self._response = 0
        self._data = b""
        self._tcp_length = 0
        self.connected = False

        self.users = 0
        self.fingers = 0
        self.records = 0

        # Ukuran chunk terakhir yang berhasil (dipakai ulang pada koneksi berikutnya)
        # dan metadata hasil baca untuk diagnostik/heartbeat.
        self.chunk_size = TCP_MAX_CHUNK
        self.last_chunk_size: int | None = None
        self.last_read_mode = ""
        self.chunk_attempts: list[int] = []

    # ------------------------------------------------------------------ koneksi

    def connect(self) -> "ZKDevice":
        """Buka koneksi TCP dan lakukan handshake CMD_CONNECT (+ CMD_AUTH jika perlu)."""
        try:
            self._sock = socket.create_connection((self.ip, self.port), timeout=self.timeout)
            self._sock.settimeout(self.timeout)
        except OSError as exc:
            raise ZKConnectionError(f"Gagal membuka koneksi TCP ke {self.ip}:{self.port} ({exc})") from exc

        self._session_id = 0
        self._reply_id = USHRT_MAX - 1

        response = self._send_command(CMD_CONNECT)
        self._session_id = struct.unpack("<4H", self._data_recv[8:16])[2]

        if response["code"] == CMD_ACK_UNAUTH:
            command_string = make_commkey(self.password, self._session_id)
            response = self._send_command(CMD_AUTH, command_string)

        if not response["status"]:
            self.close()
            raise ZKProtocolError(f"Mesin menolak koneksi (kode {response['code']})")

        self.connected = True
        return self

    def disconnect(self) -> bool:
        """Kirim CMD_EXIT lalu tutup soket."""
        try:
            if self.connected:
                self._send_command(CMD_EXIT)
        except ZKError:
            pass
        finally:
            self.connected = False
            self.close()
        return True

    def close(self) -> None:
        if self._sock is not None:
            try:
                self._sock.close()
            except OSError:
                pass
            self._sock = None

    # ------------------------------------------------------------- paket & I/O

    def _tcp_top(self, packet: bytes) -> bytes:
        top = struct.pack("<HHI", MACHINE_PREPARE_DATA_1, MACHINE_PREPARE_DATA_2, len(packet))
        return top + packet

    def _create_header(self, command: int, command_string: bytes, session_id: int, reply_id: int) -> bytes:
        buf = struct.pack("<4H", command, 0, session_id, reply_id) + command_string
        checksum = _checksum(buf)

        reply_id += 1
        if reply_id >= USHRT_MAX:
            reply_id -= USHRT_MAX

        return struct.pack("<4H", command, checksum, session_id, reply_id) + command_string

    def _test_tcp_top(self, packet: bytes) -> int:
        if len(packet) <= 8:
            return 0
        head = struct.unpack("<HHI", packet[:8])
        if head[0] == MACHINE_PREPARE_DATA_1 and head[1] == MACHINE_PREPARE_DATA_2:
            return head[2]
        return 0

    def _recv_exactly(self, size: int) -> bytes:
        chunks = []
        remaining = size
        while remaining > 0:
            try:
                chunk = self._sock.recv(remaining)
            except socket.timeout:
                break
            if not chunk:
                break
            chunks.append(chunk)
            remaining -= len(chunk)
        return b"".join(chunks)

    def _send_command(self, command: int, command_string: bytes = b"", response_size: int = 8) -> dict:
        if self._sock is None:
            raise ZKConnectionError("Soket belum dibuka.")

        buf = self._create_header(command, command_string, self._session_id, self._reply_id)
        top = self._tcp_top(buf)
        try:
            self._sock.sendall(top)

            # Baca header TCP lebih dulu, lalu tepat sebesar panjang yang dilaporkan.
            # Cara ini menghindari menunggu timeout saat mesin membalas ACK pendek.
            head = self._recv_exactly(8)
            if len(head) < 8:
                raise ZKProtocolError("Paket TCP dari mesin tidak valid.")

            magic_1, magic_2, body_length = struct.unpack("<HHI", head)
            if (
                magic_1 != MACHINE_PREPARE_DATA_1
                or magic_2 != MACHINE_PREPARE_DATA_2
                or body_length < 8
            ):
                raise ZKProtocolError("Paket TCP dari mesin tidak valid.")

            body = self._recv_exactly(body_length)
            if len(body) < body_length:
                raise ZKProtocolError(
                    f"Respons mesin tidak lengkap ({len(body)}/{body_length} byte)."
                )
        except OSError as exc:
            raise ZKConnectionError(f"Gagal mengirim/menerima data: {exc}") from exc

        self._data_recv = head + body
        self._tcp_length = body_length

        header = struct.unpack("<4H", body[:8])
        self._response = header[0]
        self._reply_id = header[3]
        self._data = body[8:]

        status = self._response in (CMD_ACK_OK, CMD_PREPARE_DATA, CMD_DATA)
        return {"status": status, "code": self._response}

    # --------------------------------------------------- pembacaan data buffered

    def _recv_raw(self, size: int) -> bytes:
        return self._recv_exactly(size)

    def _recv_tcp_data(self, data_recv: bytes, size: int):
        """Kumpulkan payload dari satu/lebih paket TCP CMD_DATA sebesar `size` byte."""
        data = []
        tcp_length = self._test_tcp_top(data_recv)
        if tcp_length <= 0:
            return None, b""

        if (tcp_length - 8) < size:
            resp, broken = self._recv_tcp_data(data_recv, tcp_length - 8)
            if resp is None:
                return None, b""
            data.append(resp)
            size -= len(resp)
            data_recv = broken + self._recv_raw(size + 16)
            resp, broken = self._recv_tcp_data(data_recv, size)
            if resp is None:
                return None, b""
            data.append(resp)
            return b"".join(data), broken

        received = len(data_recv)
        response = struct.unpack("HHHH", data_recv[8:16])[0]
        if received >= (size + 32):
            if response == CMD_DATA:
                return data_recv[16 : size + 16], data_recv[size + 16 :]
            return None, b""

        data.append(data_recv[16 : size + 16])
        size -= received - 16
        broken_header = b""
        if size < 0:
            broken_header = data_recv[size:]
        if size > 0:
            data.append(self._recv_raw(size))
        return b"".join(data), broken_header

    def _recv_chunk(self) -> bytes | None:
        if self._response == CMD_DATA:
            if len(self._data) < (self._tcp_length - 8):
                need = (self._tcp_length - 8) - len(self._data)
                return self._data + self._recv_raw(need)
            return self._data

        if self._response == CMD_PREPARE_DATA:
            size = struct.unpack("I", self._data[:4])[0]
            if len(self._data) >= (8 + size):
                data_recv = self._data[8:]
            else:
                data_recv = self._data[8:] + self._recv_raw(size + 32)

            resp, broken_header = self._recv_tcp_data(data_recv, size)
            if resp is None:
                return None

            if len(broken_header) < 16:
                data_recv = broken_header + self._recv_raw(16)
            else:
                data_recv = broken_header
            if len(data_recv) < 16:
                data_recv += self._recv_raw(16 - len(data_recv))

            if not self._test_tcp_top(data_recv):
                return None
            response = struct.unpack("HHHH", data_recv[8:16])[0]
            if response == CMD_ACK_OK:
                return resp
            return None

        return None

    def _read_chunk(self, start: int, size: int) -> bytes:
        last_code = None
        command_string = struct.pack("<ii", start, size)
        for _ in range(2):
            response = self._send_command(CMD_READ_BUFFER, command_string, size + 32)
            last_code = response["code"]
            if not response["status"]:
                continue
            data = self._recv_chunk()
            if data:
                return data
        raise ZKProtocolError(
            f"Gagal membaca buffer pada offset {start} ukuran {size} "
            f"(kode respons {last_code})"
        )

    def _chunk_candidates(self, preferred_chunk: int | None = None) -> list[int]:
        """Ukuran chunk yang akan dicoba, mulai dari hint terakhir bila ada."""
        if preferred_chunk:
            return [int(preferred_chunk)]

        start = self.chunk_size if self.chunk_size in CHUNK_CANDIDATES else TCP_MAX_CHUNK
        return list(CHUNK_CANDIDATES[CHUNK_CANDIDATES.index(start) :])

    def _reconnect(self) -> None:
        """Putus lalu sambung ulang untuk mereset sesi setelah pembacaan gagal."""
        self.close()
        self.connected = False
        self.connect()

    def _read_with_buffer_once(
        self, command: int, fct: int, ext: int, chunk_size: int
    ) -> tuple[bytes, int]:
        command_string = struct.pack("<bhii", 1, command, fct, ext)
        response = self._send_command(CMD_PREPARE_BUFFER, command_string, 1024)
        if not response["status"]:
            raise ZKProtocolError(
                f"Mesin tidak mendukung pembacaan buffered (kode {response['code']})."
            )

        if response["code"] == CMD_DATA:
            if len(self._data) < (self._tcp_length - 8):
                need = (self._tcp_length - 8) - len(self._data)
                more = self._recv_raw(need)
                return self._data + more, len(self._data) + len(more)
            return self._data, len(self._data)

        size = struct.unpack("I", self._data[1:5])[0]
        data = []
        start = 0
        remain = size % chunk_size
        packets = (size - remain) // chunk_size
        for _ in range(packets):
            data.append(self._read_chunk(start, chunk_size))
            start += chunk_size
        if remain:
            data.append(self._read_chunk(start, remain))
            start += remain

        self._send_command(CMD_FREE_DATA)
        return b"".join(data), start

    def read_with_buffer(
        self,
        command: int,
        fct: int = 0,
        ext: int = 0,
        preferred_chunk: int | None = None,
        allow_direct_fallback: bool = True,
    ):
        """Baca data memakai mode buffered dengan chunk adaptif.

        Firmware yang menolak chunk besar akan dicoba ulang memakai chunk lebih kecil
        (koneksi di-reset antar-percobaan). Bila semua ukuran gagal dan perintahnya
        mendukung mode non-buffered, pembacaan langsung dipakai sebagai upaya terakhir.
        """
        original_timeout = self.timeout
        candidates = self._chunk_candidates(preferred_chunk)
        attempted: list[int] = []
        errors: list[str] = []

        try:
            self.set_timeout(min(original_timeout, CHUNK_READ_TIMEOUT))
            for index, chunk_size in enumerate(candidates):
                attempted.append(chunk_size)
                try:
                    data, size = self._read_with_buffer_once(command, fct, ext, chunk_size)
                except (ZKProtocolError, ZKConnectionError) as exc:
                    errors.append(f"chunk {chunk_size}: {exc}")
                    if index + 1 < len(candidates):
                        try:
                            self._reconnect()
                        except ZKError as reconnect_exc:
                            errors.append(f"reconnect: {reconnect_exc}")
                            break
                    continue

                self.chunk_size = chunk_size
                self.last_chunk_size = chunk_size
                self.last_read_mode = "buffered"
                self.chunk_attempts = attempted
                return data, size
        finally:
            self.set_timeout(original_timeout)

        if allow_direct_fallback and command in DIRECT_READ_COMMANDS:
            try:
                data, size = self.read_direct(command, fct, ext)
            except (ZKProtocolError, ZKConnectionError) as exc:
                errors.append(f"direct: {exc}")
            else:
                self.chunk_size = candidates[-1]
                self.last_chunk_size = None
                self.last_read_mode = "direct"
                self.chunk_attempts = attempted
                return data, size

        # Mulai dari ukuran terkecil pada percobaan berikutnya agar tidak mengulang
        # timeout panjang bila firmware memang menolak chunk besar.
        self.chunk_size = candidates[-1]
        self.last_chunk_size = None
        self.chunk_attempts = attempted
        detail = " | ".join(errors) if errors else "tidak ada respons"
        raise ZKProtocolError(
            "Gagal membaca buffer setelah mencoba ukuran chunk "
            f"{', '.join(str(size) for size in attempted)}. Rincian: {detail}"
        )

    @staticmethod
    def _split_size_prefix(data: bytes) -> tuple[int, int]:
        """Tentukan offset & ukuran payload dari respons mesin.

        Mesin bisa membalas payload berawalan 4 byte ukuran, atau paket CMD_DATA
        lengkap (header 8 byte + ukuran 4 byte + payload). Keduanya dibedakan dari
        panjang data agar payload yang kebetulan mirip kode perintah tidak salah potong.
        """
        if len(data) >= 12:
            command = struct.unpack("<H", data[:2])[0]
            size_after_header = struct.unpack("<I", data[8:12])[0]
            if (
                command in (CMD_DATA, CMD_PREPARE_DATA, CMD_ACK_OK)
                and 0 < size_after_header
                and len(data) >= 12 + size_after_header
            ):
                return 8, size_after_header

        if len(data) < 4:
            raise ZKProtocolError(
                f"Data pembacaan langsung terlalu pendek ({len(data)} byte)."
            )

        total_size = struct.unpack("<I", data[:4])[0]
        if total_size <= 0:
            raise ZKProtocolError("Mesin melaporkan ukuran data 0 pada pembacaan langsung.")

        return 0, total_size

    def read_direct(self, command: int, fct: int = 0, ext: int = 0):
        """Baca data tanpa buffer (fallback untuk firmware yang menolak CMD_PREPARE_BUFFER).

        Format respons: 4 byte ukuran total diikuti payload. Ukuran divalidasi agar
        data yang terpotong/berlebih tidak diproses sebagai record.
        """
        # Firmware X601 menerima CMD_ATTLOG_RRQ tanpa command string (seperti ReadAllGLogData).
        response = self._send_command(command, b"", 1024)
        if not response["status"]:
            raise ZKProtocolError(
                f"Mesin menolak pembacaan langsung (kode {response['code']})."
            )

        data = self._recv_chunk()
        if not data:
            raise ZKProtocolError("Mesin tidak mengirim data pada pembacaan langsung.")

        offset, total_size = self._split_size_prefix(data)
        payload = data[offset + 4 :]
        if len(payload) < total_size:
            payload += self._recv_raw(total_size - len(payload))
        payload = payload[:total_size]
        if len(payload) != total_size:
            raise ZKProtocolError(
                f"Data pembacaan langsung tidak lengkap "
                f"({len(payload)}/{total_size} byte)."
            )

        try:
            self._send_command(CMD_FREE_DATA)
        except ZKError:
            pass

        return data[offset : offset + 4] + payload, 4 + total_size



    # ------------------------------------------------------------------ operasi

    def read_sizes(self) -> dict:
        """Baca kapasitas pemakaian memori mesin (jumlah user & log)."""
        response = self._send_command(CMD_GET_FREE_SIZES, b"", 1024)
        if not response["status"]:
            raise ZKProtocolError("Gagal membaca kapasitas mesin.")

        data = self._data
        if len(data) >= 80:
            fields = struct.unpack("20i", data[:80])
            self.users = fields[4]
            self.fingers = fields[6]
            self.records = fields[8]

        return {"users": self.users, "fingers": self.fingers, "records": self.records}

    def get_users(self) -> list[dict]:
        """Ambil seluruh user terdaftar: pin, nama, privilege, enabled."""
        self.read_sizes()
        if self.users == 0:
            return []

        userdata, size = self.read_with_buffer(CMD_USERTEMP_RRQ, FCT_USER)
        if size <= 4 or len(userdata) < 4:
            return []

        total_size = struct.unpack("I", userdata[:4])[0]
        if total_size <= 0:
            return []

        packet_size = total_size // self.users if self.users else 0
        userdata = userdata[4 : 4 + total_size]

        users: list[dict] = []
        if packet_size == 28:
            while len(userdata) >= 28:
                uid, privilege, password, name, card, _group, _tz, user_id = struct.unpack(
                    "<HB5s8sIxBhI", userdata.ljust(28, b"\x00")[:28]
                )
                users.append(
                    self._build_user(uid, privilege, password, name, card, user_id, 28)
                )
                userdata = userdata[28:]
        else:
            while len(userdata) >= 72:
                uid, privilege, password, name, card, group, user_id = struct.unpack(
                    "<HB8s24sIx7sx24s", userdata.ljust(72, b"\x00")[:72]
                )
                users.append(
                    self._build_user(uid, privilege, password, name, card, user_id, 72, group)
                )
                userdata = userdata[72:]

        return users

    @staticmethod
    def _build_user(uid, privilege, password, name, card, user_id, packet_size, group=None) -> dict:
        name_str = ZKDevice._decode_field(name)
        user_id_str = str(user_id) if packet_size == 28 else ZKDevice._decode_field(user_id)

        if not name_str:
            name_str = f"NN-{user_id_str}"

        return {
            "uid": uid,
            "pin": user_id_str if user_id_str else str(uid),
            "name": name_str,
            "privilege": privilege,
            "password": ZKDevice._decode_field(password),
            "card": card,
            "group_id": str(group) if group is not None else "",
            "enabled": True,
        }

    def get_attendance(self) -> list[dict]:
        """Ambil seluruh log absensi: pin, waktu, verify_type, source_key."""
        self.read_sizes()
        if self.records == 0:
            return []

        attendance_data, size = self.read_with_buffer(CMD_ATTLOG_RRQ)
        if size < 4 or len(attendance_data) < 4:
            return []

        total_size = struct.unpack("I", attendance_data[:4])[0]
        if total_size <= 0:
            return []

        record_size = total_size // self.records if self.records else 0
        attendance_data = attendance_data[4 : 4 + total_size]

        records: list[dict] = []
        if record_size == 8:
            while len(attendance_data) >= 8:
                uid, status, timestamp, punch = struct.unpack(
                    "HB4sB", attendance_data.ljust(8, b"\x00")[:8]
                )
                attendance_data = attendance_data[8:]
                records.append(self._build_record(uid, None, status, timestamp, punch))
        elif record_size == 16:
            while len(attendance_data) >= 16:
                user_id, timestamp, status, punch, _reserved, _workcode = struct.unpack(
                    "<I4sBB2sI", attendance_data.ljust(16, b"\x00")[:16]
                )
                attendance_data = attendance_data[16:]
                records.append(self._build_record(None, str(user_id), status, timestamp, punch))
        else:
            while len(attendance_data) >= 40:
                code_init = b"\xff255\x00\x00\x00\x00\x00"
                if attendance_data.find(code_init) == 0:
                    attendance_data = attendance_data[len(code_init):]
                uid, user_id, status, timestamp, punch, _space = struct.unpack(
                    "<H24sB4sB8s", attendance_data.ljust(40, b"\x00")[:40]
                )
                attendance_data = attendance_data[record_size:]
                records.append(
                    self._build_record(uid, ZKDevice._decode_field(user_id), status, timestamp, punch)
                )

        return records

    def get_time(self) -> datetime:
        """Baca jam mesin (CMD_GET_TIME)."""
        response = self._send_command(CMD_GET_TIME, b"", 12)
        if not response["status"]:
            raise ZKProtocolError(f"Mesin menolak pembacaan jam (kode {response['code']}).")

        payload = self._data[:4]
        if len(payload) < 4:
            raise ZKProtocolError("Response jam mesin tidak lengkap.")

        try:
            return self._decode_time(payload)
        except ValueError as exc:
            raise ZKProtocolError(f"Waktu mesin tidak valid: {exc}") from exc

    def set_time(self, timestamp: datetime) -> bool:
        """Set jam mesin (CMD_SET_TIME). Return True bila mesin ACK."""
        command_string = struct.pack("<I", self._encode_time(timestamp))
        response = self._send_command(CMD_SET_TIME, command_string)
        return bool(response["status"])

    def _build_record(self, uid, user_id, status, timestamp, punch) -> dict:
        occurred_at = self._decode_time(timestamp)
        pin = user_id if user_id else str(uid)
        # Waktu lokal mesin: `timestamp()` pada datetime naif mengasumsikan zona waktu lokal,
        # sama seperti konversi ToUniversalTime() pada connector C# sehingga source_key identik.
        unix_sec = int(occurred_at.timestamp())
        return {
            "pin": pin,
            "time": occurred_at.strftime("%Y-%m-%d %H:%M:%S"),
            "verify_type": status,
            "in_out_mode": punch,
            "source_key": f"x601_{pin}_{unix_sec}",
        }

    # --------------------------------------------------- kapabilitas & realtime

    def set_timeout(self, seconds: float) -> None:
        """Ubah timeout soket (mis. membaca event realtime tanpa menunggu lama)."""
        self.timeout = float(seconds)
        if self._sock is not None:
            try:
                self._sock.settimeout(self.timeout)
            except OSError:
                pass

    def register_events(self, flag: int = EF_ATTLOG) -> bool:
        """Minta mesin mengirim event realtime (CMD_REG_EVENT). True bila di-ACK mesin."""
        response = self._send_command(CMD_REG_EVENT, struct.pack("<I", int(flag)))
        return bool(response["status"])

    def read_event(self, timeout: float | None = None) -> bool:
        """Tunggu satu paket event realtime. True bila paket berupa event absensi.

        Mengembalikan False saat timeout atau paket bukan event. Pemanggil bertanggung
        jawab atas loop dan deadline agar tidak ada koneksi yang menggantung.
        """
        if self._sock is None:
            raise ZKConnectionError("Soket belum dibuka.")
        if timeout is not None:
            self.set_timeout(timeout)

        top = self._recv_exactly(8)
        if len(top) < 8:
            return False

        head = struct.unpack("<HHI", top)
        if head[0] != MACHINE_PREPARE_DATA_1 or head[1] != MACHINE_PREPARE_DATA_2:
            return False

        length = head[2]
        if length < 8:
            return False

        body = self._recv_exactly(length)
        if len(body) < 8:
            return False

        command = struct.unpack("<H", body[:2])[0]
        return command == CMD_REG_EVENT

    def probe_capabilities(self) -> dict:
        """Deteksi kapabilitas mesin secara defensif (tanpa mengubah data mesin).

        Hasil dipakai untuk memutuskan jalur ingest (realtime event vs polling) dan
        ditampilkan pada tombol "Tes Koneksi".
        """
        caps = {
            "buffered_read": False,
            "attendance_read": False,
            "user_read": False,
            "live_event": False,
            "users": 0,
            "records": 0,
        }

        try:
            sizes = self.read_sizes()
            caps["buffered_read"] = True
            caps["attendance_read"] = True
            caps["user_read"] = True
            caps["users"] = int(sizes.get("users", 0))
            caps["records"] = int(sizes.get("records", 0))
        except ZKError:
            pass

        try:
            caps["live_event"] = self.register_events()
        except ZKError:
            caps["live_event"] = False

        return caps

    # ------------------------------------------------------------------- util

    @staticmethod
    def _decode_field(raw: bytes) -> str:
        if isinstance(raw, bytes):
            raw = raw.split(b"\x00")[0]
            return raw.decode("utf-8", errors="ignore").strip()
        return str(raw).strip()

    @staticmethod
    def _encode_time(timestamp: datetime) -> int:
        """Encode datetime menjadi 4 byte waktu mesin (algoritma EncodeTime zkemsdk.c)."""
        if not 2000 <= timestamp.year <= 2099:
            raise ValueError("Tahun waktu mesin harus berada pada rentang 2000..2099.")

        value = ((timestamp.year - 2000) * 12 + timestamp.month - 1) * 31 + timestamp.day - 1
        return ((value * 24 + timestamp.hour) * 60 + timestamp.minute) * 60 + timestamp.second

    @staticmethod
    def _decode_time(raw: bytes) -> datetime:
        """Terjemahkan 4 byte waktu mesin menjadi datetime (algoritma DecodeTime zkemsdk.c)."""
        value = struct.unpack("<I", raw)[0]
        second = value % 60
        value //= 60
        minute = value % 60
        value //= 60
        hour = value % 24
        value //= 24
        day = value % 31 + 1
        value //= 31
        month = value % 12 + 1
        value //= 12
        year = value + 2000
        return datetime(year, month, day, hour, minute, second)


__all__ = [
    "CHUNK_CANDIDATES",
    "CHUNK_READ_TIMEOUT",
    "ZKDevice",
    "ZKError",
    "ZKConnectionError",
    "ZKProtocolError",
    "make_commkey",
    "probe_tcp",
]
