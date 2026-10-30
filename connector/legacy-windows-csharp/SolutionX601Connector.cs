// Solution X601 LAN Fingerprint Connector (Windows, x86)
//
// Arsitektur multi-mesin (pola "manager + 1 worker per mesin"):
//   * Manager (mode default) membaca connector_config.ini, menjalankan satu proses worker
//     per mesin (enabled=true), memantau/menghidupkan ulang bila mati, dan menyediakan
//     Local HTTP API pada `local_api_port` (default 4371) untuk dipakai Laravel.
//   * Worker (`--worker --device=<key>`) hanya melakukan polling + ingest log ke Laravel
//     memakai token mesin masing-masing. State (checkpoint + offline buffer) per mesin.
//   * One-shot (`--test | --sync-students | --sync-logs | --sync-time | --probe`) untuk
//     pemanggilan langsung via CLI dari Laravel (fallback bila daemon tidak aktif).
//
// Setiap worker adalah proses terpisah sehingga COM SDK (zkemkeeper.dll, 32-bit in-proc)
// di-load sendiri-sendiri per mesin: satu mesin hang tidak menghentikan mesin lain.
// Akses ke satu mesin fisik (1 koneksi TCP) diserialisasi lintas proses via named mutex.
//
// Build (WAJIB x86, karena zkemkeeper.dll 32-bit): jalankan build_connector.bat

using System;
using System.Collections.Generic;
using System.Diagnostics;
using System.Globalization;
using System.IO;
using System.Net;
using System.Net.NetworkInformation;
using System.Reflection;
using System.Runtime.InteropServices;
using System.Text;
using System.Threading;

namespace SolutionX601Connector
{
    // ======================================================================
    // Model konfigurasi
    // ======================================================================

    public class DeviceConfig
    {
        public string Key = "device";
        public string Name = "";
        public string Ip = "100.50.25.67";
        public int Port = 4370;
        public string CommKey = "0";
        public string Token = "";
        public bool Enabled = true;
        public string Mode = "poll";
    }

    public class ConnectorConfig
    {
        public string ApiUrl = "http://127.0.0.1:8000/api/v1/fingerprint/ingest";
        public int PollIntervalSeconds = 5;
        public int ReconnectIntervalSeconds = 5;
        public int LocalApiPort = 4371;
        public string LocalApiToken = "";
        public int BatchSize = 20;
        public int ClockSyncMinutes = 60;
        public int ClockMaxDriftSeconds = 5;
        public bool ClockAllowBackward = false;

        public List<DeviceConfig> Devices = new List<DeviceConfig>();

        /// <summary>
        /// Memuat konfigurasi. Mendukung dua format:
        ///  - Baru (multi-mesin): [default] global + [device:&lt;key&gt;] per mesin.
        ///  - Lama (single-mesin): device_ip=... / device_token=... (dibaca sebagai 1 mesin).
        /// </summary>
        public static ConnectorConfig Load(string path)
        {
            var cfg = new ConnectorConfig();
            if (!File.Exists(path))
            {
                cfg.Devices.Add(new DeviceConfig { Key = "mesin-1" });
                return cfg;
            }

            DeviceConfig current = null;
            bool anyDevice = false;

            foreach (var raw in File.ReadAllLines(path))
            {
                var line = raw.Trim();
                if (line.Length == 0 || line.StartsWith("#") || line.StartsWith(";")) continue;

                if (line.StartsWith("["))
                {
                    int end = line.IndexOf(']');
                    if (end < 0) continue;
                    string section = line.Substring(1, end - 1).Trim();

                    if (section.Equals("default", StringComparison.OrdinalIgnoreCase))
                    {
                        current = null; // kembali ke pengaturan global
                    }
                    else if (section.StartsWith("device:", StringComparison.OrdinalIgnoreCase))
                    {
                        string key = section.Substring("device:".Length).Trim();
                        current = new DeviceConfig { Key = string.IsNullOrEmpty(key) ? "device" : key };
                        cfg.Devices.Add(current);
                        anyDevice = true;
                    }
                    else
                    {
                        current = null;
                    }
                    continue;
                }

                var parts = line.Split(new[] { '=' }, 2);
                if (parts.Length != 2) continue;
                string k = parts[0].Trim().ToLowerInvariant();
                string v = parts[1].Trim();

                // Key global (berlaku juga di dalam [default])
                switch (k)
                {
                    case "api_url": cfg.ApiUrl = v; continue;
                    case "poll_interval_seconds": int.TryParse(v, out cfg.PollIntervalSeconds); continue;
                    case "reconnect_interval_seconds": int.TryParse(v, out cfg.ReconnectIntervalSeconds); continue;
                    case "local_api_port": int.TryParse(v, out cfg.LocalApiPort); continue;
                    case "local_api_token": cfg.LocalApiToken = v; continue;
                    case "batch_size": int.TryParse(v, out cfg.BatchSize); continue;
                    case "clock_sync_minutes": int.TryParse(v, out cfg.ClockSyncMinutes); continue;
                    case "clock_max_drift_seconds": int.TryParse(v, out cfg.ClockMaxDriftSeconds); continue;
                    case "clock_allow_backward": cfg.ClockAllowBackward = ParseBool(v, false); continue;
                }

                if (current != null)
                {
                    switch (k)
                    {
                        case "ip":
                        case "device_ip": current.Ip = v; break;
                        case "port":
                        case "device_port": int.TryParse(v, out current.Port); break;
                        case "comm_key": current.CommKey = v; break;
                        case "token":
                        case "device_token": current.Token = v; break;
                        case "enabled": current.Enabled = ParseBool(v, true); break;
                        case "mode": current.Mode = v; break;
                        case "name": current.Name = v; break;
                    }
                }
                else
                {
                    switch (k)
                    {
                        case "device_ip":
                            current = new DeviceConfig { Key = "mesin-1" };
                            cfg.Devices.Add(current);
                            current.Ip = v;
                            anyDevice = true;
                            break;
                    }
                }
            }

            if (!anyDevice && cfg.Devices.Count == 0)
            {
                // File tidak berisi mesin apa pun -> jadikan satu mesin default
                cfg.Devices.Add(new DeviceConfig { Key = "mesin-1" });
            }

            foreach (var d in cfg.Devices)
            {
                if (d.Port <= 0) d.Port = 4370;
                if (string.IsNullOrEmpty(d.CommKey)) d.CommKey = "0";
                if (string.IsNullOrEmpty(d.Name)) d.Name = d.Key;
            }

            if (cfg.BatchSize <= 0) cfg.BatchSize = 20;
            if (cfg.PollIntervalSeconds <= 0) cfg.PollIntervalSeconds = 5;
            if (cfg.ClockSyncMinutes < 0) cfg.ClockSyncMinutes = 0;
            if (cfg.ClockMaxDriftSeconds < 1) cfg.ClockMaxDriftSeconds = 5;

            return cfg;
        }

        private static bool ParseBool(string v, bool fallback)
        {
            if (string.IsNullOrEmpty(v)) return fallback;
            v = v.Trim().ToLowerInvariant();
            if (v == "true" || v == "1" || v == "yes" || v == "on") return true;
            if (v == "false" || v == "0" || v == "no" || v == "off") return false;
            return fallback;
        }
    }

    public class LogRecord
    {
        public string pin;
        public string time;
        public int verify_type;
        public string source_key;
    }

    public class UserRecord
    {
        public string pin;
        public string name;
        public int privilege;
        public bool enabled;
    }

    // ======================================================================
    // Wrapper Late Binding Reflection murni untuk COM Object zkemkeeper.ZKEM.
    // Menghindari keyword 'dynamic' (DLR) agar tidak memicu FileLoadException GAC_32.
    // ======================================================================
    public static class ZkemDevice
    {
        private static object _com = null;
        private static Type _type = null;
        private static readonly object _lock = new object();

        public static bool Init()
        {
            lock (_lock)
            {
                if (_com != null) return true;
                try
                {
                    _type = Type.GetTypeFromProgID("zkemkeeper.ZKEM");
                    if (_type == null)
                    {
                        Console.WriteLine("[SDK ERROR] ProgID 'zkemkeeper.ZKEM' tidak ditemukan di Registry Windows.");
                        return false;
                    }
                    _com = Activator.CreateInstance(_type);
                    return _com != null;
                }
                catch (Exception ex)
                {
                    Console.WriteLine("[SDK ERROR] Gagal inisialisasi COM ZKEM: " + ex.Message);
                    return false;
                }
            }
        }

        public static bool IsInitialized
        {
            get { return _com != null; }
        }

        public static bool SetCommPassword(int commKey)
        {
            if (!Init()) return false;
            try
            {
                dynamic com = _com;
                return (bool)com.SetCommPassword(commKey);
            }
            catch { return false; }
        }

        public static bool Connect_Net(string ip, int port)
        {
            if (!Init()) return false;
            try
            {
                dynamic com = _com;
                return (bool)com.Connect_Net(ip, port);
            }
            catch { return false; }
        }

        public static void Disconnect()
        {
            if (_com == null) return;
            try
            {
                dynamic com = _com;
                com.Disconnect();
            }
            catch { }
        }

        public static void GetLastError(ref int errorCode)
        {
            if (_com == null) { errorCode = -1; return; }
            try
            {
                int e = errorCode;
                dynamic com = _com;
                com.GetLastError(ref e);
                errorCode = e;
            }
            catch { errorCode = -1; }
        }

        public static bool ReadAllUserID(int machineNumber)
        {
            if (!Init()) return false;
            try
            {
                dynamic com = _com;
                return (bool)com.ReadAllUserID(machineNumber);
            }
            catch { return false; }
        }

        // Catatan penting: nilai out/ref HANYA terisi lewat jalur DLR (dynamic),
        // bukan lewat Type.InvokeMember (nilainya selalu kosong/0 pada mesin X601).
        public static bool SSR_GetAllUserInfo(int machineNumber, out string enrollNumber, out string name, out string password, out int privilege, out bool enabled)
        {
            enrollNumber = "";
            name = "";
            password = "";
            privilege = 0;
            enabled = false;
            if (!Init()) return false;
            try
            {
                string en = "", nm = "", pw = "";
                int pr = 0;
                bool ena = false;
                dynamic com = _com;
                bool ok = (bool)com.SSR_GetAllUserInfo(machineNumber, ref en, ref nm, ref pw, ref pr, ref ena);
                if (ok)
                {
                    enrollNumber = en ?? "";
                    name = nm ?? "";
                    password = pw ?? "";
                    privilege = pr;
                    enabled = ena;
                }
                return ok;
            }
            catch { return false; }
        }

        public static bool ReadGeneralLogData(int machineNumber)
        {
            if (!Init()) return false;
            try
            {
                dynamic com = _com;
                return (bool)com.ReadGeneralLogData(machineNumber);
            }
            catch { return false; }
        }

        public static bool ReadTimeGLogData(int machineNumber, string startTime, string endTime)
        {
            if (!Init()) return false;
            try
            {
                dynamic com = _com;
                return (bool)com.ReadTimeGLogData(machineNumber, startTime, endTime);
            }
            catch { return false; }
        }

        public static bool GetDeviceTime(int machineNumber, out int year, out int month, out int day, out int hour, out int minute, out int second)
        {
            year = 0; month = 0; day = 0; hour = 0; minute = 0; second = 0;
            if (!Init()) return false;
            try
            {
                int y = 0, mo = 0, d = 0, h = 0, mi = 0, s = 0;
                dynamic com = _com;
                bool ok = (bool)com.GetDeviceTime(machineNumber, ref y, ref mo, ref d, ref h, ref mi, ref s);
                if (ok)
                {
                    year = y; month = mo; day = d; hour = h; minute = mi; second = s;
                }
                return ok;
            }
            catch { return false; }
        }

        public static bool SetDeviceTime2(int machineNumber, int year, int month, int day, int hour, int minute, int second)
        {
            if (!Init()) return false;
            try
            {
                dynamic com = _com;
                return (bool)com.SetDeviceTime2(machineNumber, year, month, day, hour, minute, second);
            }
            catch { return false; }
        }

        public static bool SetDeviceTime(int machineNumber, int year, int month, int day, int hour, int minute, int second)
        {
            if (!Init()) return false;
            try
            {
                dynamic com = _com;
                return (bool)com.SetDeviceTime(machineNumber, year, month, day, hour, minute, second);
            }
            catch { return false; }
        }

        public static bool SSR_GetGeneralLogData(int machineNumber, out string enrollNumber, out int verifyMode, out int inOutMode,
            out int year, out int month, out int day, out int hour, out int minute, out int second, ref int workCode)
        {
            enrollNumber = "";
            verifyMode = 0;
            inOutMode = 0;
            year = 0; month = 0; day = 0; hour = 0; minute = 0; second = 0;
            if (!Init()) return false;
            try
            {
                string en = "";
                int vm = 0, io = 0, y = 0, mo = 0, d = 0, h = 0, mi = 0, s = 0;
                int wc = workCode;
                dynamic com = _com;
                bool ok = (bool)com.SSR_GetGeneralLogData(machineNumber, ref en, ref vm, ref io, ref y, ref mo, ref d, ref h, ref mi, ref s, ref wc);
                if (ok)
                {
                    enrollNumber = en ?? "";
                    verifyMode = vm;
                    inOutMode = io;
                    year = y; month = mo; day = d; hour = h; minute = mi; second = s;
                    workCode = wc;
                }
                return ok;
            }
            catch { return false; }
        }

        // ------------------------------------------------------------------
        // Diagnostik: cari tahu metode enumerasi user/log yang benar-benar
        // didukung mesin ini. Hanya membaca; tidak mengubah data mesin.
        // Dipakai oleh one-shot `--diag`.
        // ------------------------------------------------------------------
        public static string Diagnose(string ip, int port, string commKey)
        {
            if (!Init())
            {
                return "{\"success\":false,\"message\":\"Inisialisasi COM ZKEM gagal.\"}";
            }
            if (!string.IsNullOrEmpty(commKey) && commKey != "0")
            {
                try { SetCommPassword(int.Parse(commKey)); } catch { }
            }

            int machineNumber = 1;
            bool connected = false;
            string connectError = null;
            try
            {
                connected = Connect_Net(ip, port);
            }
            catch (Exception ex) { connectError = Describe(ex); }

            // Coba ambil nomor mesin sebenarnya (opsional; abaikan bila tidak didukung).
            try
            {
                object[] mnArgs = new object[] { 0 };
                object mnRes = _type.InvokeMember("SSR_GetMachineNumber", BindingFlags.InvokeMethod, null, _com, mnArgs);
                if (mnRes != null && Convert.ToBoolean(mnRes)) machineNumber = Convert.ToInt32(mnArgs[0]);
            }
            catch { }

            int lastError = 0;
            try { GetLastError(ref lastError); } catch { }

            var sb = new StringBuilder();
            sb.Append("{\"success\":").Append(connected ? "true" : "false");
            sb.Append(",\"connected\":").Append(connected ? "true" : "false");
            sb.Append(",\"machineNumber\":").Append(machineNumber);
            sb.Append(",\"lastError\":").Append(lastError);
            if (connectError != null) sb.Append(",\"connectError\":\"").Append(Json.Escape(connectError)).Append("\"");
            if (!connected)
            {
                sb.Append("}");
                return sb.ToString();
            }

            try { _type.InvokeMember("EnableDevice", BindingFlags.InvokeMethod, null, _com, new object[] { machineNumber, false }); } catch { }

            sb.Append(",\"variants\":{");
            sb.Append("\"SSR_GetAllUserInfo\":").Append(EnumUserInfo(machineNumber));
            sb.Append(",\"GetAllUserInfo\":").Append(EnumUserInfoByMethod(machineNumber, "GetAllUserInfo"));
            sb.Append(",\"SSR_GetAllUserID\":").Append(EnumUserId(machineNumber));
            sb.Append("}");
            sb.Append(",\"logs\":").Append(EnumLogs(machineNumber));

            try { _type.InvokeMember("EnableDevice", BindingFlags.InvokeMethod, null, _com, new object[] { machineNumber, true }); } catch { }
            try { Disconnect(); } catch { }

            sb.Append("}");
            return sb.ToString();
        }

        private static string Describe(Exception ex)
        {
            var msg = ex.GetType().Name + ": " + ex.Message;
            if (ex.InnerException != null)
            {
                msg += " | inner: " + ex.InnerException.GetType().Name + ": " + ex.InnerException.Message;
            }
            return msg;
        }

        // ------------------------------------------------------------------
        // Diagnostik jalur DLR (keyword dynamic). Bila marshaling ref/out pada
        // InvokeMember bermasalah, binder DLR sering bisa mengambil nilainya.
        // ------------------------------------------------------------------
        public static string DiagnoseDynamic(string ip, int port, string commKey)
        {
            var sb = new StringBuilder();
            try
            {
                Type t = Type.GetTypeFromProgID("zkemkeeper.ZKEM");
                dynamic z = Activator.CreateInstance(t);
                if (!string.IsNullOrEmpty(commKey) && commKey != "0")
                {
                    try { z.SetCommPassword(Convert.ToInt32(commKey)); } catch { }
                }
                bool conn = z.Connect_Net(ip, port);
                sb.Append("{\"connected\":").Append(conn ? "true" : "false");

                int n = 0;
                string err = null;
                var sample = new List<string>();
                if (conn)
                {
                    try { z.ReadAllUserID(1); } catch { }
                    try
                    {
                        while (n < 2000)
                        {
                            string en = "", nm = "", pw = "";
                            int pr = 0;
                            bool ena = false;
                            bool ok = z.SSR_GetAllUserInfo(1, ref en, ref nm, ref pw, ref pr, ref ena);
                            if (!ok) break;
                            if (n < 8) sample.Add("pin=" + en + " name=" + nm + " priv=" + pr + " enabled=" + ena);
                            n++;
                        }
                    }
                    catch (Exception ex) { err = Describe(ex); }
                    try { z.Disconnect(); } catch { }
                }

                sb.Append(",\"userCount\":").Append(n);
                sb.Append(",\"sample\":[");
                for (int i = 0; i < sample.Count; i++)
                {
                    if (i > 0) sb.Append(",");
                    sb.Append("\"").Append(Json.Escape(sample[i])).Append("\"");
                }
                sb.Append("]");
                if (err != null) sb.Append(",\"error\":\"").Append(Json.Escape(err)).Append("\"");
                sb.Append("}");
            }
            catch (Exception ex)
            {
                sb.Append("{\"connected\":false,\"fatal\":\"").Append(Json.Escape(Describe(ex))).Append("\"}");
            }
            return sb.ToString();
        }

        private static string Frag(int count, List<string> sample, string nameSample, string error)
        {
            var sb = new StringBuilder();
            sb.Append("{\"count\":").Append(count).Append(",\"sample\":[");
            for (int i = 0; i < sample.Count; i++)
            {
                if (i > 0) sb.Append(",");
                sb.Append("\"").Append(Json.Escape(sample[i])).Append("\"");
            }
            sb.Append("]");
            if (nameSample != null) sb.Append(",\"firstUserInfo\":\"").Append(Json.Escape(nameSample)).Append("\"");
            if (error != null) sb.Append(",\"error\":\"").Append(Json.Escape(error)).Append("\"");
            sb.Append("}");
            return sb.ToString();
        }

        private static void ReadAllUserIDQuiet(int mn)
        {
            try { _type.InvokeMember("ReadAllUserID", BindingFlags.InvokeMethod, null, _com, new object[] { mn }); } catch { }
        }

        private static string EnumUserInfo(int mn)
        {
            ReadAllUserIDQuiet(mn);
            int count = 0; var sample = new List<string>(); string error = null; string info = null;
            try
            {
                if (!ReadAllUserID(mn)) { return Frag(0, sample, null, "ReadAllUserID=false"); }
                while (count < 2000)
                {
                    string en, nm, pw; int pr; bool ena;
                    if (!SSR_GetAllUserInfo(mn, out en, out nm, out pw, out pr, out ena)) break;
                    if (count < 5) sample.Add(en);
                    if (count == 0) info = "pin=" + en + " name=" + nm + " priv=" + pr + " enabled=" + ena;
                    count++;
                }
            }
            catch (Exception ex) { error = Describe(ex); }
            return Frag(count, sample, info, error);
        }

        private static string EnumUserInfoByMethod(int mn, string method)
        {
            ReadAllUserIDQuiet(mn);
            int count = 0; var sample = new List<string>(); string error = null; string info = null;
            try
            {
                while (count < 2000)
                {
                    object[] a = new object[] { mn, "", "", "", 0, false };
                    object r = _type.InvokeMember(method, BindingFlags.InvokeMethod, null, _com, a);
                    if (r == null || !Convert.ToBoolean(r)) break;
                    string en = a[1] != null ? a[1].ToString() : "";
                    string nm = a[2] != null ? a[2].ToString() : "";
                    if (count < 5) sample.Add(en);
                    if (count == 0) info = "pin=" + en + " name=" + nm + " priv=" + a[4] + " enabled=" + a[5];
                    count++;
                }
            }
            catch (Exception ex) { error = Describe(ex); }
            return Frag(count, sample, info, error);
        }

        private static string EnumUserId(int mn)
        {
            ReadAllUserIDQuiet(mn);
            int count = 0; var sample = new List<string>(); string error = null; string info = null;
            try
            {
                while (count < 2000)
                {
                    object[] a = new object[] { mn, 0, 0, 0, 0, false };
                    object r = _type.InvokeMember("SSR_GetAllUserID", BindingFlags.InvokeMethod, null, _com, a);
                    if (r == null || !Convert.ToBoolean(r)) break;
                    string en = Convert.ToString(a[1]);
                    if (count < 5) sample.Add(en);
                    if (count == 0)
                    {
                        string nm = "";
                        try
                        {
                            object[] gi = new object[] { mn, Convert.ToInt32(a[1]), "", "", 0, false };
                            object gr = _type.InvokeMember("GetUserInfo", BindingFlags.InvokeMethod, null, _com, gi);
                            if (gr != null && Convert.ToBoolean(gr)) nm = Convert.ToString(gi[2]);
                        }
                        catch { }
                        info = "id=" + en + " name=" + nm;
                    }
                    count++;
                }
            }
            catch (Exception ex) { error = Describe(ex); }
            return Frag(count, sample, info, error);
        }

        private static string EnumLogs(int mn)
        {
            int count = 0; var sample = new List<string>(); string error = null;
            try
            {
                if (!ReadGeneralLogData(mn)) { return Frag(0, sample, null, "ReadGeneralLogData=false"); }
                string en = ""; int vm = 0, io = 0, y = 0, mo = 0, d = 0, h = 0, mi = 0, s = 0; int wc = 0;
                while (count < 5000)
                {
                    if (!SSR_GetGeneralLogData(mn, out en, out vm, out io, out y, out mo, out d, out h, out mi, out s, ref wc)) break;
                    if (count < 5) sample.Add(en + "@" + y + "-" + mo + "-" + d + " " + h + ":" + mi + ":" + s);
                    count++;
                }
            }
            catch (Exception ex) { error = Describe(ex); }
            return Frag(count, sample, null, error);
        }
    }

    // ======================================================================
    // Lock lintas-proses per mesin (1 mesin fisik = 1 koneksi TCP).
    // ======================================================================
    internal sealed class DeviceLock : IDisposable
    {
        private readonly Mutex _mutex;
        private bool _held;

        public DeviceLock(string ip, int port)
        {
            string name = "Global\\SmartPresensiX601_" + Sanitize(ip) + "_" + port;
            try
            {
                _mutex = new Mutex(false, name);
            }
            catch
            {
                _mutex = null;
            }
        }

        private static string Sanitize(string s)
        {
            var sb = new StringBuilder();
            foreach (char c in s)
            {
                sb.Append(char.IsLetterOrDigit(c) ? c : '_');
            }
            return sb.ToString();
        }

        public bool Acquire(int timeoutMs)
        {
            if (_mutex == null) { _held = true; return true; }
            try
            {
                _held = _mutex.WaitOne(timeoutMs);
                return _held;
            }
            catch (AbandonedMutexException)
            {
                // Proses sebelumnya mati saat memegang lock -> tetap kita kuasai.
                _held = true;
                return true;
            }
            catch
            {
                _held = false;
                return false;
            }
        }

        public void Dispose()
        {
            if (_mutex == null) return;
            try
            {
                if (_held) _mutex.ReleaseMutex();
            }
            catch { }
            try { _mutex.Dispose(); } catch { }
        }
    }

    internal static class Json
    {
        public static string Escape(string s)
        {
            if (string.IsNullOrEmpty(s)) return "";
            return s.Replace("\\", "\\\\").Replace("\"", "\\\"").Replace("\r", "").Replace("\n", " ");
        }
    }

    // ======================================================================
    // Status file per mesin (dibaca manager untuk /api/status).
    // ======================================================================
    internal static class DeviceStatus
    {
        public static void Write(string stateDir, DeviceConfig dev, bool connected, DateTime? lastSync, string lastError, DateTime? lastClockSync = null, int? clockDriftSeconds = null)
        {
            try
            {
                Directory.CreateDirectory(stateDir);
                var sb = new StringBuilder();
                sb.Append("{");
                sb.Append("\"key\":\"").Append(Json.Escape(dev.Key)).Append("\",");
                sb.Append("\"name\":\"").Append(Json.Escape(dev.Name)).Append("\",");
                sb.Append("\"ip\":\"").Append(Json.Escape(dev.Ip)).Append("\",");
                sb.Append("\"port\":").Append(dev.Port).Append(",");
                sb.Append("\"connected\":").Append(connected ? "true" : "false").Append(",");
                sb.Append("\"last_sync\":\"").Append(lastSync.HasValue ? lastSync.Value.ToString("yyyy-MM-dd HH:mm:ss") : "").Append("\",");
                sb.Append("\"last_clock_sync\":\"").Append(lastClockSync.HasValue ? lastClockSync.Value.ToString("yyyy-MM-dd HH:mm:ss") : "").Append("\",");
                sb.Append("\"clock_drift_seconds\":").Append(clockDriftSeconds.HasValue ? clockDriftSeconds.Value.ToString() : "null").Append(",");
                sb.Append("\"last_error\":\"").Append(Json.Escape(lastError ?? "")).Append("\",");
                sb.Append("\"updated_at\":\"").Append(DateTime.Now.ToString("yyyy-MM-dd HH:mm:ss")).Append("\"");
                sb.Append("}");
                File.WriteAllText(Path.Combine(stateDir, "status.json"), sb.ToString());
            }
            catch { }
        }
    }

    // ======================================================================
    // Operasi satu-shot yang dipakai oleh API manager maupun CLI.
    // Selalu mengakuisisi DeviceLock agar tidak bentrok dengan worker polling.
    // ======================================================================
    internal static class DeviceOps
    {
        private static bool IsPortOpen(string ip, int port, int timeoutMs)
        {
            try
            {
                using (var tcp = new System.Net.Sockets.TcpClient())
                {
                    var ar = tcp.BeginConnect(ip, port, null, null);
                    if (ar.AsyncWaitHandle.WaitOne(timeoutMs))
                    {
                        tcp.EndConnect(ar);
                        return true;
                    }
                    return false;
                }
            }
            catch { return false; }
        }

        private static bool ConnectDeviceDirect(string ip, int port, string commKey, out int errorCode)
        {
            errorCode = 0;
            if (!IsPortOpen(ip, port, 1500))
            {
                errorCode = -201;
                return false;
            }
            if (!ZkemDevice.Init())
            {
                errorCode = -999;
                return false;
            }
            if (!string.IsNullOrEmpty(commKey) && commKey != "0")
            {
                try { ZkemDevice.SetCommPassword(int.Parse(commKey)); } catch { }
            }
            try
            {
                bool connected = ZkemDevice.Connect_Net(ip, port);
                if (connected) return true;
                try { ZkemDevice.GetLastError(ref errorCode); } catch { }
                return false;
            }
            catch
            {
                errorCode = -998;
                return false;
            }
        }

        public static string Test(string ip, int port, string commKey)
        {
            using (var lk = new DeviceLock(ip, port))
            {
                if (!lk.Acquire(2500))
                {
                    return "{\"success\":false,\"code\":-202,\"message\":\"Mesin sedang sibuk diproses proses lain.\"}";
                }
                int err = 0;
                bool ok = ConnectDeviceDirect(ip, port, commKey, out err);
                if (ok)
                {
                    try { ZkemDevice.Disconnect(); } catch { }
                    return "{\"success\":true,\"code\":0,\"message\":\"Berhasil terhubung ke mesin Solution X601 (" + ip + ":" + port + ")\"}";
                }
                string msg = "Gagal terhubung ke mesin Solution X601 (" + ip + ":" + port + "). Kode error: " + err + ". Pastikan mesin menyala, kabel LAN terhubung, dan IP sesuai.";
                return "{\"success\":false,\"code\":" + err + ",\"message\":\"" + Json.Escape(msg) + "\"}";
            }
        }

        public static string Probe(string ip, int port, string commKey)
        {
            using (var lk = new DeviceLock(ip, port))
            {
                if (!lk.Acquire(2500))
                {
                    return "{\"success\":false,\"code\":-202,\"message\":\"Mesin sedang sibuk diproses proses lain.\",\"capabilities\":{}}";
                }
                int err = 0;
                bool ok = ConnectDeviceDirect(ip, port, commKey, out err);
                if (!ok)
                {
                    return "{\"success\":false,\"code\":" + err + ",\"message\":\"" + Json.Escape("Gagal probe mesin (" + ip + ":" + port + "). Kode: " + err) + "\",\"capabilities\":{}}";
                }
                try { ZkemDevice.Disconnect(); } catch { }
                string caps = "{\"tcp\":true,\"users\":true,\"logs\":true,\"realtime\":false}";
                return "{\"success\":true,\"code\":0,\"message\":\"Kapabilitas mesin terdeteksi.\",\"capabilities\":" + caps + "}";
            }
        }

        public static string SyncStudents(string ip, int port, string commKey)
        {
            using (var lk = new DeviceLock(ip, port))
            {
                if (!lk.Acquire(2500))
                {
                    return "{\"success\":false,\"code\":-202,\"message\":\"Mesin sedang sibuk diproses proses lain.\",\"users\":[]}";
                }
                int err = 0;
                if (!ConnectDeviceDirect(ip, port, commKey, out err))
                {
                    return "{\"success\":false,\"code\":" + err + ",\"message\":\"" + Json.Escape("Gagal terhubung ke mesin untuk sinkronisasi siswa. Kode: " + err) + "\",\"users\":[]}";
                }
                try
                {
                    var users = FetchUsers();
                    try { ZkemDevice.Disconnect(); } catch { }
                    var sb = new StringBuilder();
                    sb.Append("{\"success\":true,\"code\":0,\"count\":").Append(users.Count).Append(",\"users\":[");
                    for (int i = 0; i < users.Count; i++)
                    {
                        var u = users[i];
                        sb.Append("{\"pin\":\"").Append(Json.Escape(u.pin))
                          .Append("\",\"name\":\"").Append(Json.Escape(u.name))
                          .Append("\",\"privilege\":").Append(u.privilege)
                          .Append(",\"enabled\":").Append(u.enabled ? "true" : "false").Append("}");
                        if (i < users.Count - 1) sb.Append(",");
                    }
                    sb.Append("]}");
                    return sb.ToString();
                }
                catch (Exception ex)
                {
                    try { ZkemDevice.Disconnect(); } catch { }
                    return "{\"success\":false,\"code\":-997,\"message\":\"" + Json.Escape(ex.Message) + "\",\"users\":[]}";
                }
            }
        }

        public static string SyncLogs(string ip, int port, string commKey, string since)
        {
            using (var lk = new DeviceLock(ip, port))
            {
                if (!lk.Acquire(2500))
                {
                    return "{\"success\":false,\"code\":-202,\"message\":\"Mesin sedang sibuk diproses proses lain.\",\"logs\":[]}";
                }
                int err = 0;
                if (!ConnectDeviceDirect(ip, port, commKey, out err))
                {
                    return "{\"success\":false,\"code\":" + err + ",\"message\":\"" + Json.Escape("Gagal terhubung ke mesin untuk sinkronisasi absensi. Kode: " + err) + "\",\"logs\":[]}";
                }
                try
                {
                    DateTime sinceAt;
                    DateTime? sinceValue = null;
                    if (!string.IsNullOrEmpty(since) && DateTime.TryParseExact(
                        since, "yyyy-MM-dd HH:mm:ss", CultureInfo.InvariantCulture, DateTimeStyles.None, out sinceAt))
                    {
                        sinceValue = sinceAt;
                    }

                    var logs = FetchLogsSince(sinceValue);
                    try { ZkemDevice.Disconnect(); } catch { }
                    return BuildLogsJson(logs, sinceValue);
                }
                catch (Exception ex)
                {
                    try { ZkemDevice.Disconnect(); } catch { }
                    return "{\"success\":false,\"code\":-996,\"message\":\"" + Json.Escape(ex.Message) + "\",\"logs\":[]}";
                }
            }
        }

        public static string SyncTime(string ip, int port, string commKey)
        {
            using (var lk = new DeviceLock(ip, port))
            {
                if (!lk.Acquire(2500))
                {
                    return "{\"success\":false,\"code\":-202,\"message\":\"Mesin sedang sibuk diproses proses lain.\",\"synced\":false}";
                }

                int err = 0;
                if (!ConnectDeviceDirect(ip, port, commKey, out err))
                {
                    return "{\"success\":false,\"code\":" + err + ",\"message\":\"" + Json.Escape("Gagal terhubung ke mesin untuk sinkronisasi jam. Kode: " + err) + "\",\"synced\":false}";
                }

                try
                {
                    int y, mo, d, h, mi, s;
                    bool read = ZkemDevice.GetDeviceTime(1, out y, out mo, out d, out h, out mi, out s);
                    string deviceTime = read ? new DateTime(y, mo, d, h, mi, s).ToString("yyyy-MM-dd HH:mm:ss") : "";
                    int drift = 0;

                    if (read)
                    {
                        drift = (int)Math.Round((new DateTime(y, mo, d, h, mi, s) - DateTime.Now).TotalSeconds);
                    }

                    DateTime target = DateTime.Now;
                    if (read && new DateTime(y, mo, d, h, mi, s) > target)
                    {
                        return "{\"success\":false,\"code\":-1,\"message\":\"Jam mesin lebih cepat dan koreksi mundur dinonaktifkan.\",\"device_time\":\"" + deviceTime + "\",\"drift_seconds\":" + drift.ToString() + ",\"synced\":false}";
                    }

                    bool set = ZkemDevice.SetDeviceTime2(1, target.Year, target.Month, target.Day, target.Hour, target.Minute, target.Second);
                    if (!set)
                    {
                        set = ZkemDevice.SetDeviceTime(1, target.Year, target.Month, target.Day, target.Hour, target.Minute, target.Second);
                    }

                    if (!set)
                    {
                        return "{\"success\":false,\"code\":-997,\"message\":\"Mesin tidak menerima perintah sinkronisasi jam.\",\"device_time\":\"" + deviceTime + "\",\"drift_seconds\":" + (read ? drift.ToString() : "null") + ",\"synced\":false}";
                    }

                    return "{\"success\":true,\"code\":0,\"message\":\"" + Json.Escape("Jam mesin disinkronkan ke " + target.ToString("yyyy-MM-dd HH:mm:ss") + ".") + "\",\"device_time\":\"" + deviceTime + "\",\"drift_seconds\":" + (read ? drift.ToString() : "null") + ",\"synced\":true}";
                }
                catch (Exception ex)
                {
                    return "{\"success\":false,\"code\":-996,\"message\":\"" + Json.Escape(ex.Message) + "\",\"synced\":false}";
                }
                finally
                {
                    try { ZkemDevice.Disconnect(); } catch { }
                }
            }
        }

        public static List<UserRecord> FetchUsers()
        {
            var list = new List<UserRecord>();
            if (!ZkemDevice.ReadAllUserID(1))
            {
                int err = 0;
                ZkemDevice.GetLastError(ref err);
                return list;
            }
            string enrollNumber, name, password;
            int privilege;
            bool enabled;
            while (ZkemDevice.SSR_GetAllUserInfo(1, out enrollNumber, out name, out password, out privilege, out enabled))
            {
                if (!string.IsNullOrEmpty(enrollNumber))
                {
                    string cleanName = (name ?? "").Trim().Replace("\0", "");
                    if (string.IsNullOrEmpty(cleanName)) cleanName = "Siswa " + enrollNumber;
                    list.Add(new UserRecord
                    {
                        pin = enrollNumber.Trim(),
                        name = cleanName,
                        privilege = privilege,
                        enabled = enabled
                    });
                }
            }
            return list;
        }

        public static List<LogRecord> FetchAllLogs()
        {
            return FetchLogsSince(null);
        }

        public static List<LogRecord> FetchLogsSince(DateTime? since)
        {
            var list = new List<LogRecord>();
            bool read;

            if (since.HasValue)
            {
                read = ZkemDevice.ReadTimeGLogData(1, since.Value.ToString("yyyy-MM-dd HH:mm:ss"), DateTime.Now.AddMinutes(1).ToString("yyyy-MM-dd HH:mm:ss"));
                if (!read)
                {
                    read = ZkemDevice.ReadGeneralLogData(1);
                }
            }
            else
            {
                read = ZkemDevice.ReadGeneralLogData(1);
            }

            if (!read)
            {
                int err = 0;
                ZkemDevice.GetLastError(ref err);
                return list;
            }

            string enrollNumber;
            int verifyMode, inOutMode;
            int year, month, day, hour, minute, second;
            int workCode = 0;
            while (ZkemDevice.SSR_GetGeneralLogData(1, out enrollNumber, out verifyMode, out inOutMode,
                out year, out month, out day, out hour, out minute, out second, ref workCode))
            {
                var rec = MakeLogRecord(enrollNumber, verifyMode, year, month, day, hour, minute, second);
                if (rec == null) continue;

                DateTime logTime;
                if (since.HasValue && DateTime.TryParseExact(rec.time, "yyyy-MM-dd HH:mm:ss",
                        CultureInfo.InvariantCulture, DateTimeStyles.None, out logTime) && logTime < since.Value)
                {
                    continue;
                }

                list.Add(rec);
            }
            return list;
        }

        public static LogRecord MakeLogRecord(string enrollNumber, int verifyMode, int year, int month, int day, int hour, int minute, int second)
        {
            try
            {
                DateTime logTime = new DateTime(year, month, day, hour, minute, second);
                long unixSec = (long)(logTime.ToUniversalTime() - new DateTime(1970, 1, 1)).TotalSeconds;
                return new LogRecord
                {
                    pin = enrollNumber,
                    time = logTime.ToString("yyyy-MM-dd HH:mm:ss"),
                    verify_type = verifyMode,
                    source_key = "x601_" + enrollNumber + "_" + unixSec
                };
            }
            catch { return null; }
        }

        public static string BuildLogsJson(List<LogRecord> logs, DateTime? since = null)
        {
            var sb = new StringBuilder();
            sb.Append("{\"success\":true,\"code\":0,\"count\":").Append(logs.Count)
              .Append(",\"since\":\"").Append(since.HasValue ? since.Value.ToString("yyyy-MM-dd HH:mm:ss") : "")
              .Append("\",\"logs\":[");
            for (int i = 0; i < logs.Count; i++)
            {
                var l = logs[i];
                sb.Append("{\"pin\":\"").Append(Json.Escape(l.pin))
                  .Append("\",\"time\":\"").Append(l.time)
                  .Append("\",\"verify_type\":").Append(l.verify_type)
                  .Append(",\"source_key\":\"").Append(Json.Escape(l.source_key)).Append("\"}");
                if (i < logs.Count - 1) sb.Append(",");
            }
            sb.Append("]}");
            return sb.ToString();
        }

        public static string BuildIngestPayload(List<LogRecord> logs)
        {
            var sb = new StringBuilder();
            sb.Append("{\"logs\":[");
            for (int i = 0; i < logs.Count; i++)
            {
                var l = logs[i];
                sb.Append("{\"pin\":\"").Append(Json.Escape(l.pin))
                  .Append("\",\"time\":\"").Append(l.time)
                  .Append("\",\"verify_type\":").Append(l.verify_type)
                  .Append(",\"source_key\":\"").Append(Json.Escape(l.source_key)).Append("\"}");
                if (i < logs.Count - 1) sb.Append(",");
            }
            sb.Append("]}");
            return sb.ToString();
        }
    }

    // ======================================================================
    // Worker: polling + ingest untuk SATU mesin.
    // ======================================================================
    internal sealed class DeviceWorker
    {
        private readonly ConnectorConfig _cfg;
        private readonly DeviceConfig _dev;
        private readonly string _stateDir;
        private readonly string _checkpointFile;
        private readonly string _bufferFile;
        private readonly string _legacyCheckpointFile;
        private DateTime _lastWatermark = DateTime.MinValue;
        private readonly HashSet<string> _seenAtWatermark = new HashSet<string>();
        private DateTime _lastClockAttempt = DateTime.MinValue;
        private DateTime? _lastClockSync = null;
        private int? _lastClockDriftSeconds = null;
        private readonly object _sync = new object();

        public DeviceWorker(ConnectorConfig cfg, DeviceConfig dev, string baseDir)
        {
            _cfg = cfg;
            _dev = dev;
            _stateDir = Path.Combine(baseDir, "state", dev.Key);
            _checkpointFile = Path.Combine(_stateDir, "checkpoint.txt");
            _bufferFile = Path.Combine(_stateDir, "offline_buffer.txt");
            _legacyCheckpointFile = Path.Combine(baseDir, "checkpoint.txt");
            Directory.CreateDirectory(_stateDir);
        }

        public void Run()
        {
            Console.WriteLine("[WORKER:" + _dev.Key + "] Mulai. Mesin " + _dev.Ip + ":" + _dev.Port + " (mode=" + _dev.Mode + ")");

            if (!ZkemDevice.Init())
            {
                Console.WriteLine("[WORKER:" + _dev.Key + "] FATAL: SDK zkemkeeper tidak dapat di-init.");
                DeviceStatus.Write(_stateDir, _dev, false, null, "SDK init gagal");
                return;
            }

            LoadWatermark();

            while (true)
            {
                bool connected = false;
                string lastError = "";
                try
                {
                    FlushOfflineBuffer();

                    using (var lk = new DeviceLock(_dev.Ip, _dev.Port))
                    {
                        if (lk.Acquire(2500))
                        {
                            connected = ReadCycle();
                        }
                        else
                        {
                            lastError = "Device lock tidak diperoleh (dipakai proses lain).";
                        }
                    }
                }
                catch (Exception ex)
                {
                    lastError = ex.Message;
                    Console.WriteLine("[WORKER:" + _dev.Key + "] Exception: " + ex.Message);
                }

                DeviceStatus.Write(_stateDir, _dev, connected, _lastWatermark == DateTime.MinValue ? (DateTime?)null : _lastWatermark, lastError, _lastClockSync, _lastClockDriftSeconds);

                int sleep = _cfg.PollIntervalSeconds;
                if (sleep < 1) sleep = 1;
                Thread.Sleep(sleep * 1000);
            }
        }

        /// <summary>
        /// Satu siklus baca: connect -> baca log baru -> kirim -> disconnect. Return true bila terhubung.
        /// </summary>
        private bool ReadCycle()
        {
            int err = 0;
            if (!Connect(out err))
            {
                Console.Write("[WORKER:" + _dev.Key + "] Mesin offline (kode " + err + ")\r\n");
                return false;
            }

            try
            {
                DateTime? since = _lastWatermark == DateTime.MinValue
                    ? (DateTime?)null
                    : _lastWatermark.AddSeconds(-1);

                var logs = DeviceOps.FetchLogsSince(since);

                var newRecords = new List<LogRecord>();
                var newBoundaryKeys = new List<string>();
                var highestKeys = new List<string>();
                DateTime highest = _lastWatermark;

                foreach (var rec in logs)
                {
                    DateTime t;
                    if (!DateTime.TryParseExact(rec.time, "yyyy-MM-dd HH:mm:ss",
                            CultureInfo.InvariantCulture, DateTimeStyles.None, out t)) continue;

                    bool isNew = _lastWatermark == DateTime.MinValue
                        || t > _lastWatermark
                        || (t == _lastWatermark && !_seenAtWatermark.Contains(rec.source_key));

                    if (!isNew) continue;

                    newRecords.Add(rec);

                    if (t > highest)
                    {
                        highest = t;
                        highestKeys.Clear();
                    }

                    if (t == highest)
                    {
                        highestKeys.Add(rec.source_key);
                    }

                    if (t == _lastWatermark)
                    {
                        newBoundaryKeys.Add(rec.source_key);
                    }
                }

                if (newRecords.Count > 0)
                {
                    Console.WriteLine("[WORKER:" + _dev.Key + "] Ditemukan " + newRecords.Count + " scan baru.");
                    if (SendToLaravel(newRecords))
                    {
                        if (highest > _lastWatermark)
                        {
                            _lastWatermark = highest;
                            _seenAtWatermark.Clear();
                            foreach (var key in highestKeys) _seenAtWatermark.Add(key);
                        }
                        else
                        {
                            foreach (var key in newBoundaryKeys) _seenAtWatermark.Add(key);
                        }

                        SaveWatermark(_lastWatermark);
                        Console.WriteLine("[WORKER:" + _dev.Key + "] Sync sukses. Watermark: " + _lastWatermark.ToString("yyyy-MM-dd HH:mm:ss"));
                    }
                    else
                    {
                        SaveToOfflineBuffer(newRecords);
                    }
                }

                SyncClockIfDue();
            }
            finally
            {
                try { ZkemDevice.Disconnect(); } catch { }
            }

            return true;
        }

        private void SyncClockIfDue()
        {
            if (_cfg.ClockSyncMinutes <= 0) return;
            if ((DateTime.Now - _lastClockAttempt).TotalMinutes < _cfg.ClockSyncMinutes) return;

            _lastClockAttempt = DateTime.Now;

            int year, month, day, hour, minute, second;
            if (!ZkemDevice.GetDeviceTime(1, out year, out month, out day, out hour, out minute, out second))
            {
                Console.WriteLine("[WORKER:" + _dev.Key + "] Sync jam dilewati: GetDeviceTime tidak didukung.");
                return;
            }

            if (year < 2000 || year > 2100)
            {
                Console.WriteLine("[WORKER:" + _dev.Key + "] Sync jam dilewati: waktu mesin tidak valid.");
                return;
            }

            DateTime deviceTime = new DateTime(year, month, day, hour, minute, second);
            DateTime serverTime = DateTime.Now;
            int drift = (int)Math.Round((deviceTime - serverTime).TotalSeconds);
            _lastClockDriftSeconds = drift;

            if (Math.Abs(drift) < _cfg.ClockMaxDriftSeconds) return;

            if (drift > 0 && !_cfg.ClockAllowBackward)
            {
                Console.WriteLine("[WORKER:" + _dev.Key + "] Jam mesin lebih cepat " + drift + " detik; koreksi mundur dinonaktifkan.");
                return;
            }

            DateTime target = DateTime.Now;
            bool applied = ZkemDevice.SetDeviceTime2(1, target.Year, target.Month, target.Day, target.Hour, target.Minute, target.Second);
            if (!applied)
            {
                applied = ZkemDevice.SetDeviceTime(1, target.Year, target.Month, target.Day, target.Hour, target.Minute, target.Second);
            }

            if (!applied)
            {
                Console.WriteLine("[WORKER:" + _dev.Key + "] Sync jam gagal: SetDeviceTime tidak didukung.");
                return;
            }

            _lastClockSync = target;

            // Jam mundur dapat membuat watermark berada di masa depan.
            if (_lastWatermark != DateTime.MinValue && target < deviceTime)
            {
                _lastWatermark = target.AddSeconds(-1);
                _seenAtWatermark.Clear();
                SaveWatermark(_lastWatermark);
            }

            Console.WriteLine("[WORKER:" + _dev.Key + "] Jam disinkronkan. Drift sebelumnya " + drift + " detik.");
        }

        private bool Connect(out int errorCode)
        {
            errorCode = 0;
            if (!IsPortOpen(_dev.Ip, _dev.Port, 1500))
            {
                errorCode = -201;
                return false;
            }
            if (!string.IsNullOrEmpty(_dev.CommKey) && _dev.CommKey != "0")
            {
                try { ZkemDevice.SetCommPassword(int.Parse(_dev.CommKey)); } catch { }
            }
            try
            {
                if (ZkemDevice.Connect_Net(_dev.Ip, _dev.Port)) return true;
                try { ZkemDevice.GetLastError(ref errorCode); } catch { }
                return false;
            }
            catch
            {
                errorCode = -998;
                return false;
            }
        }

        private static bool IsPortOpen(string ip, int port, int timeoutMs)
        {
            try
            {
                using (var tcp = new System.Net.Sockets.TcpClient())
                {
                    var ar = tcp.BeginConnect(ip, port, null, null);
                    if (ar.AsyncWaitHandle.WaitOne(timeoutMs))
                    {
                        tcp.EndConnect(ar);
                        return true;
                    }
                    return false;
                }
            }
            catch { return false; }
        }

        private bool SendToLaravel(List<LogRecord> logs)
        {
            if (string.IsNullOrEmpty(_dev.Token))
            {
                Console.WriteLine("[WORKER:" + _dev.Key + "] Token mesin kosong: tidak dapat mengirim ke Laravel.");
                return false;
            }

            int batch = _cfg.BatchSize > 0 ? _cfg.BatchSize : 20;
            for (int offset = 0; offset < logs.Count; offset += batch)
            {
                var chunk = logs.GetRange(offset, Math.Min(batch, logs.Count - offset));
                if (!PostChunk(chunk)) return false;
            }
            return true;
        }

        private bool PostChunk(List<LogRecord> chunk)
        {
            try
            {
                byte[] data = Encoding.UTF8.GetBytes(DeviceOps.BuildIngestPayload(chunk));
                var req = (HttpWebRequest)WebRequest.Create(_cfg.ApiUrl);
                req.Method = "POST";
                req.ContentType = "application/json";
                req.Headers.Add("X-Device-Token", _dev.Token);
                req.Timeout = 8000;
                req.ContentLength = data.Length;
                using (var stream = req.GetRequestStream())
                {
                    stream.Write(data, 0, data.Length);
                }
                using (var res = (HttpWebResponse)req.GetResponse())
                {
                    if (res.StatusCode != HttpStatusCode.OK)
                    {
                        return false;
                    }

                    string body;
                    using (var reader = new StreamReader(res.GetResponseStream()))
                    {
                        body = reader.ReadToEnd();
                    }

                    int accepted = ExtractJsonInt(body, "\"accepted\":");
                    bool success = body.IndexOf("\"status\":\"success\"", StringComparison.Ordinal) >= 0;

                    if (!success || accepted < chunk.Count)
                    {
                        string preview = body.Length > 300 ? body.Substring(0, 300) : body;
                        Console.WriteLine("[WORKER:" + _dev.Key + "] HTTP warn: response belum menerima semua log (" + accepted + "/" + chunk.Count + "): " + preview);
                        return false;
                    }

                    return true;
                }
            }
            catch (Exception ex)
            {
                Console.WriteLine("[WORKER:" + _dev.Key + "] HTTP warn: gagal kirim ke Laravel (" + ex.Message + ")");
                return false;
            }
        }

        private static int ExtractJsonInt(string body, string key)
        {
            int index = body.IndexOf(key, StringComparison.Ordinal);
            if (index < 0) return -1;

            index += key.Length;
            while (index < body.Length && char.IsWhiteSpace(body[index])) index++;

            int start = index;
            while (index < body.Length && char.IsDigit(body[index])) index++;

            int value;
            return int.TryParse(body.Substring(start, index - start), out value) ? value : -1;
        }

        private void SaveToOfflineBuffer(List<LogRecord> records)
        {
            lock (_sync)
            {
                try
                {
                    var sb = new StringBuilder();
                    foreach (var r in records)
                    {
                        sb.AppendLine(r.pin + "|" + r.time + "|" + r.verify_type + "|" + r.source_key);
                    }
                    File.AppendAllText(_bufferFile, sb.ToString());
                    Console.WriteLine("[WORKER:" + _dev.Key + "] " + records.Count + " scan disimpan ke offline buffer.");
                }
                catch { }
            }
        }

        private void FlushOfflineBuffer()
        {
            lock (_sync)
            {
                if (!File.Exists(_bufferFile)) return;
                var lines = File.ReadAllLines(_bufferFile);
                if (lines.Length == 0) return;

                var buffered = new List<LogRecord>();
                foreach (var line in lines)
                {
                    var parts = line.Split('|');
                    if (parts.Length >= 4)
                    {
                        int vt;
                        int.TryParse(parts[2], out vt);
                        buffered.Add(new LogRecord { pin = parts[0], time = parts[1], verify_type = vt, source_key = parts[3] });
                    }
                }

                if (buffered.Count > 0 && SendToLaravel(buffered))
                {
                    File.Delete(_bufferFile);
                    Console.WriteLine("[WORKER:" + _dev.Key + "] Offline buffer terkirim kembali (" + buffered.Count + " scan).");
                }
            }
        }

        private void LoadWatermark()
        {
            try
            {
                string file = File.Exists(_checkpointFile) ? _checkpointFile
                    : (File.Exists(_legacyCheckpointFile) ? _legacyCheckpointFile : null);
                if (file != null)
                {
                    var text = File.ReadAllText(file).Trim();
                    DateTime dt;
                    if (DateTime.TryParse(text, out dt))
                    {
                        _lastWatermark = dt;
                        Console.WriteLine("[WORKER:" + _dev.Key + "] Watermark terakhir: " + _lastWatermark.ToString("yyyy-MM-dd HH:mm:ss"));
                        return;
                    }
                }
            }
            catch { }
            _lastWatermark = DateTime.MinValue;
            Console.WriteLine("[WORKER:" + _dev.Key + "] Watermark awal: mulai dari log paling awal.");
        }

        private void SaveWatermark(DateTime dt)
        {
            try { File.WriteAllText(_checkpointFile, dt.ToString("yyyy-MM-dd HH:mm:ss")); } catch { }
        }
    }

    // ======================================================================
    // Manager: menjalankan worker per mesin + Local HTTP API.
    // ======================================================================
    internal sealed class Manager
    {
        private readonly ConnectorConfig _cfg;
        private readonly string _baseDir;
        private HttpListener _listener;
        private bool _running = true;
        private readonly List<WorkerHandle> _workers = new List<WorkerHandle>();
        private IntPtr _job = IntPtr.Zero;

        public Manager(ConnectorConfig cfg, string baseDir)
        {
            _cfg = cfg;
            _baseDir = baseDir;
        }

        private sealed class WorkerHandle
        {
            public DeviceConfig Device;
            public Process Process;
            public DateTime? StartedAt;
            public int Restarts;
        }

        public void Run()
        {
            Console.WriteLine("======================================================================");
            Console.WriteLine("    SOLUTION X601 MULTI-DEVICE CONNECTOR (MANAGER) - x86              ");
            Console.WriteLine("======================================================================");
            Console.WriteLine("[CONFIG] Laravel API : " + _cfg.ApiUrl);
            Console.WriteLine("[CONFIG] Local API   : http://127.0.0.1:" + _cfg.LocalApiPort + "/");
            Console.WriteLine("[CONFIG] Mesin aktif : " + CountEnabled() + " dari " + _cfg.Devices.Count);
            foreach (var d in _cfg.Devices)
            {
                Console.WriteLine("         - [" + (d.Enabled ? "x" : " ") + "] " + d.Key + "  " + d.Ip + ":" + d.Port + "  token=" + (string.IsNullOrEmpty(d.Token) ? "(kosong)" : "ada"));
            }
            Console.WriteLine("----------------------------------------------------------------------");

            InitJobObject();
            StartHttpListener();

            foreach (var dev in _cfg.Devices)
            {
                if (dev.Enabled) SpawnWorker(dev);
            }

            // Monitor loop: hidupkan ulang worker yang mati.
            while (_running)
            {
                try
                {
                    foreach (var w in _workers)
                    {
                        if (w.Process != null && w.Process.HasExited)
                        {
                            Console.WriteLine("[MANAGER] Worker " + w.Device.Key + " berhenti (exit=" + w.Process.ExitCode + "). Restart...");
                            w.Restarts++;
                            Thread.Sleep(Math.Max(1, _cfg.ReconnectIntervalSeconds) * 1000);
                            if (_running) StartWorkerProcess(w);
                        }
                    }
                }
                catch { }

                Thread.Sleep(3000);
            }

            Shutdown();
        }

        private void Shutdown()
        {
            foreach (var w in _workers)
            {
                try
                {
                    if (w.Process != null && !w.Process.HasExited) w.Process.Kill();
                }
                catch { }
            }
            try { if (_listener != null) _listener.Stop(); } catch { }
        }

        private int CountEnabled()
        {
            int n = 0;
            foreach (var d in _cfg.Devices) { if (d.Enabled) n++; }
            return n;
        }

        private void SpawnWorker(DeviceConfig dev)
        {
            var handle = new WorkerHandle { Device = dev };
            _workers.Add(handle);
            StartWorkerProcess(handle);
        }

        private void StartWorkerProcess(WorkerHandle handle)
        {
            try
            {
                string exe = Process.GetCurrentProcess().MainModule.FileName;
                var psi = new ProcessStartInfo
                {
                    FileName = exe,
                    Arguments = "--worker --device=" + handle.Device.Key,
                    WorkingDirectory = _baseDir,
                    UseShellExecute = false,
                    RedirectStandardOutput = true,
                    RedirectStandardError = true,
                    CreateNoWindow = true
                };
                var proc = new Process { StartInfo = psi, EnableRaisingEvents = true };
                proc.OutputDataReceived += (s, e) => { if (e.Data != null) Console.WriteLine(e.Data); };
                proc.ErrorDataReceived += (s, e) => { if (e.Data != null) Console.WriteLine("[ERR] " + e.Data); };
                proc.Start();
                proc.BeginOutputReadLine();
                proc.BeginErrorReadLine();
                handle.Process = proc;
                handle.StartedAt = DateTime.Now;
                AssignToJob(proc);
                Console.WriteLine("[MANAGER] Worker " + handle.Device.Key + " dijalankan (PID " + proc.Id + ").");
            }
            catch (Exception ex)
            {
                Console.WriteLine("[MANAGER] Gagal menjalankan worker " + handle.Device.Key + ": " + ex.Message);
            }
        }

        // ---------- HTTP API ----------
        private void StartHttpListener()
        {
            try
            {
                _listener = new HttpListener();
                _listener.Prefixes.Add("http://127.0.0.1:" + _cfg.LocalApiPort + "/");
                _listener.Start();
                var t = new Thread(() =>
                {
                    while (_running && _listener != null && _listener.IsListening)
                    {
                        try
                        {
                            var ctx = _listener.GetContext();
                            ThreadPool.QueueUserWorkItem(ProcessRequest, ctx);
                        }
                        catch { }
                    }
                }) { IsBackground = true, Name = "ManagerHttp" };
                t.Start();
                Console.WriteLine("[MANAGER] Local HTTP API aktif di http://127.0.0.1:" + _cfg.LocalApiPort + "/");
                if (!string.IsNullOrEmpty(_cfg.LocalApiToken))
                {
                    Console.WriteLine("[MANAGER] Autentikasi token lokal aktif.");
                }
            }
            catch (Exception ex)
            {
                Console.WriteLine("[MANAGER] Gagal membuka HTTP port " + _cfg.LocalApiPort + ": " + ex.Message);
            }
        }

        private DeviceConfig ResolveTarget(System.Collections.Specialized.NameValueCollection qs, out string ip, out int port, out string commKey)
        {
            ip = !string.IsNullOrEmpty(qs["ip"]) ? qs["ip"] : null;
            port = 4370;
            if (!string.IsNullOrEmpty(qs["port"])) int.TryParse(qs["port"], out port);
            commKey = !string.IsNullOrEmpty(qs["comm_key"]) ? qs["comm_key"] : "0";

            DeviceConfig dev = null;
            string deviceKey = qs["device"];
            if (!string.IsNullOrEmpty(deviceKey))
            {
                foreach (var d in _cfg.Devices)
                {
                    if (string.Equals(d.Key, deviceKey, StringComparison.OrdinalIgnoreCase)) { dev = d; break; }
                }
            }
            else if (!string.IsNullOrEmpty(ip))
            {
                foreach (var d in _cfg.Devices)
                {
                    if (d.Ip == ip && (port == 0 || d.Port == port)) { dev = d; break; }
                }
            }

            if (dev != null)
            {
                ip = dev.Ip;
                port = dev.Port;
                commKey = dev.CommKey;
            }
            if (string.IsNullOrEmpty(ip)) { ip = _cfg.Devices.Count > 0 ? _cfg.Devices[0].Ip : "100.50.25.67"; }
            if (port <= 0) port = 4370;
            return dev;
        }

        private void ProcessRequest(object state)
        {
            var ctx = (HttpListenerContext)state;
            try
            {
                string providedToken = ctx.Request.Headers["X-Connector-Token"] ?? "";
                if (!string.IsNullOrEmpty(_cfg.LocalApiToken) &&
                    !string.Equals(providedToken, _cfg.LocalApiToken, StringComparison.Ordinal))
                {
                    WriteJson(ctx, "{\"status\":\"error\",\"message\":\"Unauthorized.\"}", 401);
                    return;
                }

                string path = ctx.Request.Url.AbsolutePath.ToLowerInvariant();
                var qs = ctx.Request.QueryString;
                string responseJson;

                if (path.StartsWith("/api/status") || path == "/status")
                {
                    responseJson = BuildStatusJson();
                }
                else if (path.StartsWith("/api/test") || path.StartsWith("/api/sync-students") ||
                         path.StartsWith("/api/sync-logs") || path.StartsWith("/api/sync-time") || path.StartsWith("/api/probe"))
                {
                    string ip; int port; string commKey;
                    ResolveTarget(qs, out ip, out port, out commKey);

                    if (path.StartsWith("/api/test")) responseJson = DeviceOps.Test(ip, port, commKey);
                    else if (path.StartsWith("/api/probe")) responseJson = DeviceOps.Probe(ip, port, commKey);
                    else if (path.StartsWith("/api/sync-students")) responseJson = DeviceOps.SyncStudents(ip, port, commKey);
                    else if (path.StartsWith("/api/sync-time")) responseJson = DeviceOps.SyncTime(ip, port, commKey);
                    else responseJson = DeviceOps.SyncLogs(ip, port, commKey, qs["since"]);
                }
                else
                {
                    responseJson = "{\"status\":\"error\",\"message\":\"Endpoint tidak dikenal.\"}";
                }

                WriteJson(ctx, responseJson);
            }
            catch (Exception ex)
            {
                try { WriteJson(ctx, "{\"success\":false,\"code\":-900,\"message\":\"" + Json.Escape(ex.Message) + "\"}"); } catch { }
            }
        }

        private string BuildStatusJson()
        {
            var sb = new StringBuilder();
            sb.Append("{\"status\":\"ok\",\"mode\":\"manager\",\"devices\":[");
            bool first = true;
            foreach (var dev in _cfg.Devices)
            {
                if (!first) sb.Append(",");
                first = false;

                string statusFile = Path.Combine(_baseDir, "state", dev.Key, "status.json");
                string statusJson = null;
                try { if (File.Exists(statusFile)) statusJson = File.ReadAllText(statusFile); } catch { }

                if (!string.IsNullOrEmpty(statusJson))
                {
                    sb.Append(statusJson);
                }
                else
                {
                    sb.Append("{\"key\":\"").Append(Json.Escape(dev.Key)).Append("\",\"name\":\"").Append(Json.Escape(dev.Name))
                      .Append("\",\"ip\":\"").Append(Json.Escape(dev.Ip)).Append("\",\"port\":").Append(dev.Port)
                      .Append(",\"connected\":false,\"last_sync\":\"\",\"last_error\":\"menunggu worker\",\"updated_at\":\"\"}");
                }
            }
            sb.Append("]}");
            return sb.ToString();
        }

        private static void WriteJson(HttpListenerContext ctx, string json, int statusCode = 200)
        {
            byte[] bytes = Encoding.UTF8.GetBytes(json);
            ctx.Response.StatusCode = statusCode;
            ctx.Response.ContentType = "application/json; charset=utf-8";
            ctx.Response.ContentLength64 = bytes.Length;
            ctx.Response.OutputStream.Write(bytes, 0, bytes.Length);
            ctx.Response.Close();
        }

        // ---------- Job Object (pastikan worker ikut mati saat manager mati) ----------
        [DllImport("kernel32.dll", CharSet = CharSet.Unicode)]
        private static extern IntPtr CreateJobObject(IntPtr lpJobAttributes, string lpName);

        [DllImport("kernel32.dll")]
        [return: MarshalAs(UnmanagedType.Bool)]
        private static extern bool SetInformationJobObject(IntPtr hJob, int jobObjectInfoClass, IntPtr lpJobObjectInfo, uint cbJobObjectInfoLength);

        [DllImport("kernel32.dll", SetLastError = true)]
        [return: MarshalAs(UnmanagedType.Bool)]
        private static extern bool AssignProcessToJobObject(IntPtr hJob, IntPtr hProcess);

        [StructLayout(LayoutKind.Sequential)]
        private struct JOBOBJECT_BASIC_LIMIT_INFORMATION
        {
            public long PerProcessUserTimeLimit;
            public long PerJobUserTimeLimit;
            public uint LimitFlags;
            public UIntPtr MinimumWorkingSetSize;
            public UIntPtr MaximumWorkingSetSize;
            public uint ActiveProcessLimit;
            public UIntPtr Affinity;
            public uint PriorityClass;
            public uint SchedulingClass;
        }

        [StructLayout(LayoutKind.Sequential)]
        private struct IO_COUNTERS
        {
            public ulong ReadOperationCount;
            public ulong WriteOperationCount;
            public ulong OtherOperationCount;
            public ulong ReadTransferCount;
            public ulong WriteTransferCount;
            public ulong OtherTransferCount;
        }

        [StructLayout(LayoutKind.Sequential)]
        private struct JOBOBJECT_EXTENDED_LIMIT_INFORMATION
        {
            public JOBOBJECT_BASIC_LIMIT_INFORMATION BasicLimitInformation;
            public IO_COUNTERS IoInfo;
            public UIntPtr ProcessMemoryLimit;
            public UIntPtr JobMemoryLimit;
            public UIntPtr PeakProcessMemoryUsed;
            public UIntPtr PeakJobMemoryUsed;
        }

        private const int JobObjectExtendedLimitInformation = 9;
        private const uint JOB_OBJECT_LIMIT_KILL_ON_JOB_CLOSE = 0x2000;

        private void InitJobObject()
        {
            _job = CreateJobObject(IntPtr.Zero, null);
            if (_job == IntPtr.Zero) return;
            var info = new JOBOBJECT_EXTENDED_LIMIT_INFORMATION();
            info.BasicLimitInformation.LimitFlags = JOB_OBJECT_LIMIT_KILL_ON_JOB_CLOSE;
            int length = Marshal.SizeOf(typeof(JOBOBJECT_EXTENDED_LIMIT_INFORMATION));
            IntPtr buffer = Marshal.AllocHGlobal(length);
            Marshal.StructureToPtr(info, buffer, false);
            SetInformationJobObject(_job, JobObjectExtendedLimitInformation, buffer, (uint)length);
            Marshal.FreeHGlobal(buffer);
        }

        private void AssignToJob(Process proc)
        {
            if (_job == IntPtr.Zero) return;
            try { AssignProcessToJobObject(_job, proc.Handle); } catch { }
        }
    }

    // ======================================================================
    // Program entry point
    // ======================================================================
    class Program
    {
        static int Main(string[] args)
        {
            string baseDir = AppDomain.CurrentDomain.BaseDirectory;
            string configPath = ResolveConfigPath(baseDir);
            var cfg = ConnectorConfig.Load(configPath);

            // --- One-shot CLI (dipanggil Laravel) ---
            string ipArg = GetArg(args, "--ip=");
            string portArg = GetArg(args, "--port=");
            string keyArg = GetArg(args, "--comm-key=");
            string sinceArg = GetArg(args, "--since=");
            string deviceArg = GetArg(args, "--device=") ?? GetNamedArg(args, "--device");

            int port = 4370;
            if (!string.IsNullOrEmpty(portArg)) int.TryParse(portArg, out port);

            if (HasArg(args, "--test") || HasArg(args, "--probe") || HasArg(args, "--diag") || HasArg(args, "--diag2") || HasArg(args, "--sync-students") || HasArg(args, "--sync-logs") || HasArg(args, "--sync-time"))
            {
                DeviceConfig dev = ResolveCliDevice(cfg, deviceArg, ipArg, port, keyArg);
                string json;
                if (HasArg(args, "--test")) json = DeviceOps.Test(dev.Ip, dev.Port, dev.CommKey);
                else if (HasArg(args, "--probe")) json = DeviceOps.Probe(dev.Ip, dev.Port, dev.CommKey);
                else if (HasArg(args, "--diag2")) json = ZkemDevice.DiagnoseDynamic(dev.Ip, dev.Port, dev.CommKey);
                else if (HasArg(args, "--diag")) json = ZkemDevice.Diagnose(dev.Ip, dev.Port, dev.CommKey);
                else if (HasArg(args, "--sync-students")) json = DeviceOps.SyncStudents(dev.Ip, dev.Port, dev.CommKey);
                else if (HasArg(args, "--sync-time")) json = DeviceOps.SyncTime(dev.Ip, dev.Port, dev.CommKey);
                else json = DeviceOps.SyncLogs(dev.Ip, dev.Port, dev.CommKey, sinceArg);

                Console.WriteLine(json);
                return json.Contains("\"success\":true") ? 0 : 1;
            }

            if (HasArg(args, "--scan-lan"))
            {
                ScanLanSubnet();
                return 0;
            }

            // --- Worker per mesin ---
            if (HasArg(args, "--worker"))
            {
                DeviceConfig dev = FindDevice(cfg, deviceArg);
                if (dev == null)
                {
                    Console.WriteLine("[WORKER] Mesin dengan key '" + deviceArg + "' tidak ditemukan di config.");
                    return 2;
                }
                new DeviceWorker(cfg, dev, baseDir).Run();
                return 0;
            }

            // --- Default: Manager ---
            new Manager(cfg, baseDir).Run();
            return 0;
        }

        private static string ResolveConfigPath(string baseDir)
        {
            string inBase = Path.Combine(baseDir, "connector_config.ini");
            if (File.Exists(inBase)) return inBase;

            try
            {
                string cwd = Environment.CurrentDirectory;
                string inCwd = Path.Combine(cwd, "connector_config.ini");
                if (File.Exists(inCwd)) return inCwd;
            }
            catch { }

            return inBase;
        }

        private static DeviceConfig ResolveCliDevice(ConnectorConfig cfg, string deviceKey, string ip, int port, string commKey)
        {
            DeviceConfig dev = FindDevice(cfg, deviceKey);
            if (dev == null && !string.IsNullOrEmpty(ip))
            {
                dev = new DeviceConfig { Key = "cli", Ip = ip, Port = port, CommKey = string.IsNullOrEmpty(commKey) ? "0" : commKey };
            }
            if (dev == null)
            {
                dev = cfg.Devices.Count > 0 ? cfg.Devices[0] : new DeviceConfig();
            }
            if (!string.IsNullOrEmpty(ip)) dev.Ip = ip;
            if (port > 0) dev.Port = port;
            if (!string.IsNullOrEmpty(commKey)) dev.CommKey = commKey;
            return dev;
        }

        private static DeviceConfig FindDevice(ConnectorConfig cfg, string key)
        {
            if (string.IsNullOrEmpty(key)) return null;
            foreach (var d in cfg.Devices)
            {
                if (string.Equals(d.Key, key, StringComparison.OrdinalIgnoreCase)) return d;
            }
            return null;
        }

        private static bool HasArg(string[] args, string flag)
        {
            foreach (var a in args)
            {
                if (a.Equals(flag, StringComparison.OrdinalIgnoreCase)) return true;
            }
            return false;
        }

        private static string GetArg(string[] args, string prefix)
        {
            foreach (var a in args)
            {
                if (a.StartsWith(prefix, StringComparison.OrdinalIgnoreCase)) return a.Substring(prefix.Length);
            }
            return null;
        }

        private static string GetNamedArg(string[] args, string name)
        {
            for (int i = 0; i < args.Length - 1; i++)
            {
                if (args[i].Equals(name, StringComparison.OrdinalIgnoreCase)) return args[i + 1];
            }
            return null;
        }

        private static void ScanLanSubnet()
        {
            Console.WriteLine("[SCAN] Memindai subnet 100.50.25.1 - 100.50.25.254 pada port 4370 ...");
            string subnet = "100.50.25.";
            for (int i = 1; i <= 254; i++)
            {
                string ip = subnet + i;
                try
                {
                    using (var ping = new Ping())
                    {
                        var reply = ping.Send(ip, 150);
                        if (reply.Status == IPStatus.Success)
                        {
                            Console.WriteLine("-> Ditemukan host aktif: " + ip);
                        }
                    }
                }
                catch { }
            }
            Console.WriteLine("[SCAN] Pemindaian selesai.");
        }
    }
}
