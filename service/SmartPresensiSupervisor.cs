using System;
using System.Collections.Generic;
using System.Diagnostics;
using System.IO;
using System.Net;
using System.Net.Sockets;
using System.ServiceProcess;
using System.Text;
using System.Threading;

namespace SmartPresensiSupervisor
{
    public class Config
    {
        public string ProjectRoot = "";
        // "auto" = cari runtime\php\php.exe di akar proyek, lalu php pada PATH.
        public string PhpPath = "auto";
        public string ConnectorRelativePath = "connector\\SolutionX601Connector.exe";
        public string ConnectorArguments = "";
        public string QueueList = "default,validations,long-running";
        public int QueueTimeoutSec = 3600;
        public int LocalApiPort = 4372;
        // Sengaja dikosongkan: token wajib diisi lewat connector_config.ini saat instalasi.
        public string AuthToken = "";
        public int HeartbeatIntervalSec = 5;
        public int MysqlPort = 3306;
        public int StartupDelaySec = 10;
        public int MysqlWaitMaxSec = 120;
        public int WebServerPort = 8000;
        public bool WebServerEnabled = true;
        public bool SchedulerEnabled = true;

        public static Config Load(string configPath, string baseDir)
        {
            var cfg = new Config();

            // Akar proyek default: folder induk dari lokasi executable
            // (contoh: <root>\service -> <root>). Ini membuat bundle portabel
            // tanpa path absolut yang di-hardcode.
            string defaultRoot = Path.GetFullPath(Path.Combine(baseDir, ".."));
            cfg.ProjectRoot = defaultRoot;

            if (File.Exists(configPath))
            {
                var lines = File.ReadAllLines(configPath);
                foreach (var line in lines)
                {
                    var trimmed = line.Trim();
                    if (string.IsNullOrEmpty(trimmed) || trimmed.StartsWith("#")) continue;

                    var parts = trimmed.Split(new[] { '=' }, 2);
                    if (parts.Length != 2) continue;

                    var k = parts[0].Trim().ToLowerInvariant();
                    var v = parts[1].Trim();

                    switch (k)
                    {
                        case "project_root": cfg.ProjectRoot = v; break;
                        case "php_path": cfg.PhpPath = v; break;
                        case "connector_relative_path": cfg.ConnectorRelativePath = v; break;
                        case "connector_arguments": cfg.ConnectorArguments = v; break;
                        case "queue_list": cfg.QueueList = v; break;
                        case "queue_timeout_sec": int.TryParse(v, out cfg.QueueTimeoutSec); break;
                        case "local_api_port": int.TryParse(v, out cfg.LocalApiPort); break;
                        case "auth_token": cfg.AuthToken = v; break;
                        case "heartbeat_interval_sec": int.TryParse(v, out cfg.HeartbeatIntervalSec); break;
                        case "mysql_port": int.TryParse(v, out cfg.MysqlPort); break;
                        case "startup_delay_sec": int.TryParse(v, out cfg.StartupDelaySec); break;
                        case "mysql_wait_max_sec": int.TryParse(v, out cfg.MysqlWaitMaxSec); break;
                        case "web_server_port": int.TryParse(v, out cfg.WebServerPort); break;
                        case "web_server_enabled": cfg.WebServerEnabled = ParseBool(v); break;
                        case "scheduler_enabled": cfg.SchedulerEnabled = ParseBool(v); break;
                    }
                }
            }

            // Selesaikan akar proyek: relatif (terhadap folder induk) atau absolut.
            if (string.IsNullOrWhiteSpace(cfg.ProjectRoot))
            {
                cfg.ProjectRoot = defaultRoot;
            }
            else if (!Path.IsPathRooted(cfg.ProjectRoot))
            {
                cfg.ProjectRoot = Path.GetFullPath(Path.Combine(defaultRoot, cfg.ProjectRoot));
            }
            else
            {
                cfg.ProjectRoot = Path.GetFullPath(cfg.ProjectRoot);
            }

            // Resolusi PHP dilakukan SETELAH konfigurasi dibaca agar nilai
            // "auto" atau path relatif pada ini ikut ditangani dengan benar.
            cfg.ResolvePhpPath();

            if (!File.Exists(configPath))
            {
                cfg.Save(configPath);
            }

            return cfg;
        }

        /// <summary>
        /// Menentukan php.exe yang dipakai tanpa mengandalkan path absolut:
        /// 1) php_path dari konfigurasi bila ada dan valid,
        /// 2) <project_root>\runtime\php\php.exe bila ada,
        /// 3) "php" pada PATH sebagai upaya terakhir.
        /// Nilai "auto" (default) langsung memakai langkah 2-3.
        /// </summary>
        private void ResolvePhpPath()
        {
            if (string.IsNullOrWhiteSpace(PhpPath))
            {
                PhpPath = "auto";
            }

            string configured = PhpPath.Trim();

            if (!string.Equals(configured, "auto", StringComparison.OrdinalIgnoreCase))
            {
                string candidate = Path.IsPathRooted(configured)
                    ? configured
                    : Path.GetFullPath(Path.Combine(ProjectRoot, configured));

                if (File.Exists(candidate))
                {
                    PhpPath = candidate;
                    return;
                }
            }

            string localRuntime = Path.Combine(ProjectRoot, "runtime", "php", "php.exe");
            if (File.Exists(localRuntime))
            {
                PhpPath = localRuntime;
                return;
            }

            PhpPath = "php";
        }

        private static bool ParseBool(string v)
        {
            if (string.IsNullOrEmpty(v))
            {
                return true;
            }

            v = v.Trim().ToLowerInvariant();

            return !(v == "false" || v == "0" || v == "no" || v == "off");
        }

        public void Save(string path)
        {
            var sb = new StringBuilder();
            sb.AppendLine("# Konfigurasi Windows Service Supervisor - SmartPresensi");
            sb.AppendLine("project_root=" + ProjectRoot);
            sb.AppendLine("php_path=" + PhpPath);
            sb.AppendLine("connector_relative_path=" + ConnectorRelativePath);
            sb.AppendLine("connector_arguments=" + ConnectorArguments);
            sb.AppendLine("local_api_port=" + LocalApiPort);
            sb.AppendLine("auth_token=" + AuthToken);
            sb.AppendLine("heartbeat_interval_sec=" + HeartbeatIntervalSec);
            sb.AppendLine("queue_list=" + QueueList);
            sb.AppendLine("queue_timeout_sec=" + QueueTimeoutSec);
            sb.AppendLine("mysql_port=" + MysqlPort);
            sb.AppendLine("startup_delay_sec=" + StartupDelaySec);
            sb.AppendLine("mysql_wait_max_sec=" + MysqlWaitMaxSec);
            sb.AppendLine("web_server_enabled=" + (WebServerEnabled ? "true" : "false"));
            sb.AppendLine("web_server_port=" + WebServerPort);
            sb.AppendLine("scheduler_enabled=" + (SchedulerEnabled ? "true" : "false"));
            File.WriteAllText(path, sb.ToString());
        }
    }

    public class CircularLogBuffer
    {
        private readonly int _maxLines;
        private readonly List<string> _lines = new List<string>();
        private readonly object _lock = new object();

        public CircularLogBuffer(int maxLines)
        {
            _maxLines = maxLines;
        }

        public CircularLogBuffer() : this(100)
        {
        }

        public void Add(string line)
        {
            if (string.IsNullOrEmpty(line)) return;
            string timestamped = "[" + DateTime.Now.ToString("HH:mm:ss") + "] " + line;
            lock (_lock)
            {
                _lines.Add(timestamped);
                if (_lines.Count > _maxLines)
                {
                    _lines.RemoveAt(0);
                }
            }
        }

        public List<string> GetLines()
        {
            lock (_lock)
            {
                return new List<string>(_lines);
            }
        }
    }

    public class ManagedProcess
    {
        public string Name { get; set; }
        public string ExecutablePath { get; set; }
        public string Arguments { get; set; }
        public string WorkingDirectory { get; set; }
        public int RestartCount { get; private set; }
        public DateTime? StartedAt { get; private set; }
        public Process ProcessInstance { get; private set; }
        public CircularLogBuffer LogBuffer { get; private set; }

        private readonly object _lock = new object();
        private bool _shouldRun = true;
        private bool _hasStarted = false;

        public ManagedProcess()
        {
            LogBuffer = new CircularLogBuffer(100);
        }

        public bool IsRunning
        {
            get
            {
                try
                {
                    return ProcessInstance != null && !ProcessInstance.HasExited;
                }
                catch
                {
                    return false;
                }
            }
        }

        public int ProcessId
        {
            get
            {
                try
                {
                    return IsRunning ? ProcessInstance.Id : 0;
                }
                catch
                {
                    return 0;
                }
            }
        }

        public void Start()
        {
            lock (_lock)
            {
                _shouldRun = true;
                _hasStarted = true;
                LaunchInternal();
            }
        }

        public void Stop()
        {
            lock (_lock)
            {
                _shouldRun = false;
                KillInternal();
            }
        }

        public void Restart()
        {
            lock (_lock)
            {
                LogBuffer.Add("=== RESTART DIMINTA OLEH OPERATOR / SUPERVISOR ===");
                KillInternal();
                Thread.Sleep(1000);
                _shouldRun = true;
                _hasStarted = true;
                RestartCount++;
                LaunchInternal();
            }
        }

        public void CheckAndRecover()
        {
            lock (_lock)
            {
                if (!_shouldRun || !_hasStarted) return;

                if (!IsRunning)
                {
                    LogBuffer.Add("WARN: Proses terhenti unexpectedly. Melakukan pemulihan otomatis...");
                    RestartCount++;
                    LaunchInternal();
                }
            }
        }

        private void LaunchInternal()
        {
            try
            {
                if (IsRunning) return;

                if (!File.Exists(ExecutablePath) && ExecutablePath != "php")
                {
                    LogBuffer.Add("ERROR: File executable tidak ditemukan: " + ExecutablePath);
                    return;
                }

                var psi = new ProcessStartInfo
                {
                    FileName = ExecutablePath,
                    Arguments = Arguments,
                    WorkingDirectory = WorkingDirectory,
                    UseShellExecute = false,
                    RedirectStandardOutput = true,
                    RedirectStandardError = true,
                    CreateNoWindow = true
                };

                ProcessInstance = new Process { StartInfo = psi, EnableRaisingEvents = true };

                ProcessInstance.OutputDataReceived += (s, e) =>
                {
                    if (e.Data != null) LogBuffer.Add(e.Data);
                };
                ProcessInstance.ErrorDataReceived += (s, e) =>
                {
                    if (e.Data != null) LogBuffer.Add("[ERR] " + e.Data);
                };

                ProcessInstance.Start();
                ProcessInstance.BeginOutputReadLine();
                ProcessInstance.BeginErrorReadLine();

                StartedAt = DateTime.Now;
                LogBuffer.Add("Proses " + Name + " berhasil dijalankan (PID: " + ProcessInstance.Id + ").");
            }
            catch (Exception ex)
            {
                LogBuffer.Add("FATAL: Gagal menjalankan " + Name + ": " + ex.Message);
            }
        }

        private void KillInternal()
        {
            try
            {
                if (ProcessInstance != null && !ProcessInstance.HasExited)
                {
                    ProcessInstance.Kill();
                    ProcessInstance.WaitForExit(3000);
                }
            }
            catch { }
            finally
            {
                ProcessInstance = null;
                StartedAt = null;
            }
        }
    }

    public class SupervisorCore
    {
        public static Config Cfg;
        public static ManagedProcess ConnectorProc;
        public static ManagedProcess WorkerProc;
        public static ManagedProcess WebServerProc;
        public static ManagedProcess SchedulerProc;
        public static DateTime StartTime;

        private static HttpListener _listener;
        private static bool _isRunning = false;
        private static Thread _monitorThread;

        public static void Initialize(string baseDir)
        {
            string cfgPath = Path.Combine(baseDir, "supervisor_config.ini");
            Cfg = Config.Load(cfgPath, baseDir);
            StartTime = DateTime.Now;

            string connectorExe = Path.Combine(Cfg.ProjectRoot, Cfg.ConnectorRelativePath);
            string connectorDir = Path.GetDirectoryName(connectorExe);

            ConnectorProc = new ManagedProcess
            {
                Name = "Solution X601 Connector",
                ExecutablePath = connectorExe,
                Arguments = Cfg.ConnectorArguments,
                WorkingDirectory = connectorDir
            };

            WorkerProc = new ManagedProcess
            {
                Name = "Laravel WhatsApp Queue Worker",
                ExecutablePath = Cfg.PhpPath,
                Arguments = "artisan queue:work --queue=" + Cfg.QueueList + " --tries=3 --timeout=" + Cfg.QueueTimeoutSec + " --sleep=3",
                WorkingDirectory = Cfg.ProjectRoot
            };

            WebServerProc = new ManagedProcess
            {
                Name = "Laravel Web Server",
                ExecutablePath = Cfg.PhpPath,
                Arguments = "artisan serve --host=0.0.0.0 --port=" + Cfg.WebServerPort,
                WorkingDirectory = Cfg.ProjectRoot
            };

            SchedulerProc = new ManagedProcess
            {
                Name = "Laravel Scheduler",
                ExecutablePath = Cfg.PhpPath,
                Arguments = "artisan schedule:work",
                WorkingDirectory = Cfg.ProjectRoot
            };

            if (!Cfg.WebServerEnabled)
            {
                WebServerProc = null;
            }

            if (!Cfg.SchedulerEnabled)
            {
                SchedulerProc = null;
            }
        }

        public static void Start()
        {
            _isRunning = true;

            // Connector bisa langsung start (tidak butuh database)
            ConnectorProc.Start();

            // Queue Worker perlu MySQL siap — jalankan di thread terpisah
            var workerStartThread = new Thread(() =>
            {
                // Tunda awal agar OS & Laragon punya waktu loading
                if (Cfg.StartupDelaySec > 0)
                {
                    WorkerProc.LogBuffer.Add("Menunggu " + Cfg.StartupDelaySec + " detik sebelum memulai (startup delay)...");
                    Thread.Sleep(Cfg.StartupDelaySec * 1000);
                }

                // Tunggu MySQL port tersedia
                WaitForMySql();

                // Baru mulai queue worker, scheduler, dan web server
                WorkerProc.Start();
                if (SchedulerProc != null)
                {
                    SchedulerProc.Start();
                }
                if (WebServerProc != null)
                {
                    WebServerProc.Start();
                }
            })
            { IsBackground = true, Name = "WorkerDelayedStart" };
            workerStartThread.Start();

            // Start monitoring & heartbeat thread
            _monitorThread = new Thread(MonitorLoop) { IsBackground = true, Name = "SupervisorMonitor" };
            _monitorThread.Start();

            // Start HTTP API Listener
            StartHttpListener();
        }

        /// <summary>
        /// Menunggu koneksi MySQL tersedia sebelum menjalankan queue worker.
        /// Retry setiap 5 detik, maksimal MysqlWaitMaxSec detik.
        /// </summary>
        private static void WaitForMySql()
        {
            int maxWait = Cfg.MysqlWaitMaxSec;
            int elapsed = 0;
            int retryInterval = 5;

            WorkerProc.LogBuffer.Add("Menunggu MySQL port " + Cfg.MysqlPort + " tersedia (maks " + maxWait + " detik)...");

            while (_isRunning && elapsed < maxWait)
            {
                try
                {
                    using (var client = new TcpClient())
                    {
                        var result = client.BeginConnect("127.0.0.1", Cfg.MysqlPort, null, null);
                        bool connected = result.AsyncWaitHandle.WaitOne(TimeSpan.FromSeconds(3));

                        if (connected && client.Connected)
                        {
                            WorkerProc.LogBuffer.Add("MySQL port " + Cfg.MysqlPort + " SIAP! (setelah " + elapsed + " detik)");
                            return;
                        }
                    }
                }
                catch { }

                WorkerProc.LogBuffer.Add("MySQL belum siap, retry dalam " + retryInterval + " detik... (" + elapsed + "/" + maxWait + "s)");
                Thread.Sleep(retryInterval * 1000);
                elapsed += retryInterval;
            }

            WorkerProc.LogBuffer.Add("WARN: MySQL tidak terdeteksi setelah " + maxWait + " detik. Queue worker tetap dijalankan (akan retry sendiri via Laravel).");
        }

        public static void Stop()
        {
            _isRunning = false;
            try
            {
                if (_listener != null) _listener.Stop();
            }
            catch { }

            if (ConnectorProc != null) ConnectorProc.Stop();
            if (WorkerProc != null) WorkerProc.Stop();
            if (SchedulerProc != null) SchedulerProc.Stop();
            if (WebServerProc != null) WebServerProc.Stop();

            WriteHeartbeat("stopped");
        }

        private static void MonitorLoop()
        {
            while (_isRunning)
            {
                try
                {
                    ConnectorProc.CheckAndRecover();
                    WorkerProc.CheckAndRecover();
                    if (SchedulerProc != null)
                    {
                        SchedulerProc.CheckAndRecover();
                    }
                    if (WebServerProc != null)
                    {
                        WebServerProc.CheckAndRecover();
                    }
                    WriteHeartbeat("running");
                }
                catch { }

                Thread.Sleep(Cfg.HeartbeatIntervalSec * 1000);
            }
        }

        private static void WriteHeartbeat(string supervisorStatus)
        {
            try
            {
                string hbDir = Path.Combine(Cfg.ProjectRoot, "storage\\app\\heartbeats");
                if (!Directory.Exists(hbDir)) Directory.CreateDirectory(hbDir);

                string hbFile = Path.Combine(hbDir, "service_status.json");

                string connStarted = ConnectorProc.StartedAt.HasValue ? ConnectorProc.StartedAt.Value.ToString("yyyy-MM-dd HH:mm:ss") : "";
                string workerStarted = WorkerProc.StartedAt.HasValue ? WorkerProc.StartedAt.Value.ToString("yyyy-MM-dd HH:mm:ss") : "";
                string schedulerStarted = (SchedulerProc != null && SchedulerProc.StartedAt.HasValue) ? SchedulerProc.StartedAt.Value.ToString("yyyy-MM-dd HH:mm:ss") : "";
                string webStarted = (WebServerProc != null && WebServerProc.StartedAt.HasValue) ? WebServerProc.StartedAt.Value.ToString("yyyy-MM-dd HH:mm:ss") : "";

                var sb = new StringBuilder();
                sb.Append("{");
                sb.Append("\"last_heartbeat\":\"").Append(DateTime.Now.ToString("yyyy-MM-dd HH:mm:ss")).Append("\",");
                sb.Append("\"supervisor\":{")
                  .Append("\"status\":\"").Append(supervisorStatus).Append("\",")
                  .Append("\"pid\":").Append(Process.GetCurrentProcess().Id).Append(",")
                  .Append("\"uptime_seconds\":").Append((int)(DateTime.Now - StartTime).TotalSeconds)
                  .Append("},");

                sb.Append("\"connector\":{")
                  .Append("\"status\":\"").Append(ConnectorProc.IsRunning ? "running" : "stopped").Append("\",")
                  .Append("\"pid\":").Append(ConnectorProc.ProcessId).Append(",")
                  .Append("\"restart_count\":").Append(ConnectorProc.RestartCount).Append(",")
                  .Append("\"started_at\":\"").Append(connStarted).Append("\"")
                  .Append("},");

                sb.Append("\"worker\":{")
                  .Append("\"status\":\"").Append(WorkerProc.IsRunning ? "running" : "stopped").Append("\",")
                  .Append("\"pid\":").Append(WorkerProc.ProcessId).Append(",")
                  .Append("\"restart_count\":").Append(WorkerProc.RestartCount).Append(",")
                  .Append("\"started_at\":\"").Append(workerStarted).Append("\"")
                  .Append("},");

                sb.Append("\"scheduler\":{")
                  .Append("\"status\":\"").Append(SchedulerProc == null ? "disabled" : (SchedulerProc.IsRunning ? "running" : "stopped")).Append("\",")
                  .Append("\"pid\":").Append(SchedulerProc == null ? 0 : SchedulerProc.ProcessId).Append(",")
                  .Append("\"restart_count\":").Append(SchedulerProc == null ? 0 : SchedulerProc.RestartCount).Append(",")
                  .Append("\"started_at\":\"").Append(schedulerStarted).Append("\"")
                  .Append("},");

                sb.Append("\"web_server\":{")
                  .Append("\"status\":\"").Append(WebServerProc == null ? "disabled" : (WebServerProc.IsRunning ? "running" : "stopped")).Append("\",")
                  .Append("\"pid\":").Append(WebServerProc == null ? 0 : WebServerProc.ProcessId).Append(",")
                  .Append("\"port\":").Append(Cfg.WebServerPort).Append(",")
                  .Append("\"restart_count\":").Append(WebServerProc == null ? 0 : WebServerProc.RestartCount).Append(",")
                  .Append("\"started_at\":\"").Append(webStarted).Append("\"")
                  .Append("}");

                sb.Append("}");

                File.WriteAllText(hbFile, sb.ToString());
            }
            catch { }
        }

        private static void StartHttpListener()
        {
            try
            {
                _listener = new HttpListener();
                _listener.Prefixes.Add("http://127.0.0.1:" + Cfg.LocalApiPort + "/");
                _listener.Start();

                var t = new Thread(() =>
                {
                    while (_isRunning && _listener != null && _listener.IsListening)
                    {
                        try
                        {
                            var ctx = _listener.GetContext();
                            ThreadPool.QueueUserWorkItem(ProcessApiRequest, ctx);
                        }
                        catch { }
                    }
                })
                { IsBackground = true, Name = "SupervisorHttp" };
                t.Start();
            }
            catch (Exception ex)
            {
                Console.WriteLine("[WARN] Gagal membuka HTTP port " + Cfg.LocalApiPort + ": " + ex.Message);
            }
        }

        private static void ProcessApiRequest(object state)
        {
            var ctx = (HttpListenerContext)state;
            try
            {
                // Fail-closed: bila token belum dikonfigurasi, tolak semua request agar token
                // kosong tidak pernah dianggap valid.
                if (string.IsNullOrWhiteSpace(Cfg.AuthToken))
                {
                    SendJsonResponse(ctx, 503, "{\"error\":\"Supervisor belum dikonfigurasi: auth_token kosong.\"}");
                    return;
                }

                string token = ctx.Request.Headers["X-Supervisor-Token"] ?? ctx.Request.QueryString["token"];

                if (token != Cfg.AuthToken)
                {
                    SendJsonResponse(ctx, 401, "{\"error\":\"Unauthorized. Token pengawas tidak valid.\"}");
                    return;
                }

                string path = ctx.Request.Url.AbsolutePath.ToLowerInvariant();

                if (path == "/api/status" || path == "/status")
                {
                    string connStarted = ConnectorProc.StartedAt.HasValue ? ConnectorProc.StartedAt.Value.ToString("yyyy-MM-dd HH:mm:ss") : "";
                    string workerStarted = WorkerProc.StartedAt.HasValue ? WorkerProc.StartedAt.Value.ToString("yyyy-MM-dd HH:mm:ss") : "";
                    string schedulerStarted = (SchedulerProc != null && SchedulerProc.StartedAt.HasValue) ? SchedulerProc.StartedAt.Value.ToString("yyyy-MM-dd HH:mm:ss") : "";

                    var sb = new StringBuilder();
                    sb.Append("{")
                      .Append("\"success\":true,")
                      .Append("\"supervisor\":{\"status\":\"running\",\"pid\":").Append(Process.GetCurrentProcess().Id)
                      .Append(",\"uptime_seconds\":").Append((int)(DateTime.Now - StartTime).TotalSeconds).Append("},")
                      .Append("\"connector\":{\"status\":\"").Append(ConnectorProc.IsRunning ? "running" : "stopped")
                      .Append("\",\"pid\":").Append(ConnectorProc.ProcessId)
                      .Append(",\"restarts\":").Append(ConnectorProc.RestartCount)
                      .Append(",\"started_at\":\"").Append(connStarted).Append("\"},")
                      .Append("\"worker\":{\"status\":\"").Append(WorkerProc.IsRunning ? "running" : "stopped")
                      .Append("\",\"pid\":").Append(WorkerProc.ProcessId)
                      .Append(",\"restarts\":").Append(WorkerProc.RestartCount)
                      .Append(",\"started_at\":\"").Append(workerStarted).Append("\"},")
                      .Append("\"scheduler\":{\"status\":\"").Append(SchedulerProc == null ? "disabled" : (SchedulerProc.IsRunning ? "running" : "stopped"))
                      .Append("\",\"pid\":").Append(SchedulerProc == null ? 0 : SchedulerProc.ProcessId)
                      .Append(",\"restarts\":").Append(SchedulerProc == null ? 0 : SchedulerProc.RestartCount)
                      .Append(",\"started_at\":\"").Append(schedulerStarted).Append("\"}")
                      .Append("}");
                    SendJsonResponse(ctx, 200, sb.ToString());
                }
                else if (path == "/api/restart/connector" || path == "/restart/connector")
                {
                    ConnectorProc.Restart();
                    SendJsonResponse(ctx, 200, "{\"success\":true,\"message\":\"Connector berhasil di-restart.\"}");
                }
                else if (path == "/api/restart/worker" || path == "/restart/worker")
                {
                    WorkerProc.Restart();
                    SendJsonResponse(ctx, 200, "{\"success\":true,\"message\":\"Queue Worker berhasil di-restart.\"}");
                }
                else if (path == "/api/restart/scheduler" || path == "/restart/scheduler")
                {
                    if (SchedulerProc != null)
                    {
                        SchedulerProc.Restart();
                    }
                    SendJsonResponse(ctx, 200, "{\"success\":true,\"message\":\"Scheduler berhasil di-restart.\"}");
                }
                else if (path == "/api/restart/supervisor" || path == "/restart/supervisor")
                {
                    StartSupervisorRestartHelper();
                    SendJsonResponse(ctx, 202, "{\"success\":true,\"message\":\"Restart supervisor dijadwalkan. Koneksi akan terputus sementara.\"}");
                }
                else if (path == "/api/restart/all" || path == "/restart/all")
                {
                    ConnectorProc.Restart();
                    WorkerProc.Restart();
                    if (SchedulerProc != null)
                    {
                        SchedulerProc.Restart();
                    }
                    SendJsonResponse(ctx, 200, "{\"success\":true,\"message\":\"Seluruh layanan berhasil di-restart.\"}");
                }
                else if (path == "/api/logs/connector" || path == "/logs/connector")
                {
                    var lines = ConnectorProc.LogBuffer.GetLines();
                    SendJsonResponse(ctx, 200, FormatJsonArray("logs", lines));
                }
                else if (path == "/api/logs/worker" || path == "/logs/worker")
                {
                    var lines = WorkerProc.LogBuffer.GetLines();
                    SendJsonResponse(ctx, 200, FormatJsonArray("logs", lines));
                }
                else
                {
                    SendJsonResponse(ctx, 404, "{\"error\":\"Endpoint tidak ditemukan.\"}");
                }
            }
            catch (Exception ex)
            {
                SendJsonResponse(ctx, 500, "{\"error\":\"" + EscapeJson(ex.Message) + "\"}");
            }
        }

        private static string FormatJsonArray(string key, List<string> items)
        {
            var sb = new StringBuilder();
            sb.Append("{\"").Append(key).Append("\":[");
            for (int i = 0; i < items.Count; i++)
            {
                sb.Append("\"").Append(EscapeJson(items[i])).Append("\"");
                if (i < items.Count - 1) sb.Append(",");
            }
            sb.Append("]}");
            return sb.ToString();
        }

        private static string EscapeJson(string s)
        {
            if (string.IsNullOrEmpty(s)) return "";
            return s.Replace("\\", "\\\\").Replace("\"", "\\\"").Replace("\r", "").Replace("\n", " ");
        }

        private static void SendJsonResponse(HttpListenerContext ctx, int statusCode, string json)
        {
            try
            {
                byte[] b = Encoding.UTF8.GetBytes(json);
                ctx.Response.StatusCode = statusCode;
                ctx.Response.ContentType = "application/json; charset=utf-8";
                ctx.Response.ContentLength64 = b.Length;
                ctx.Response.OutputStream.Write(b, 0, b.Length);
                ctx.Response.Close();
            }
            catch { }
        }

        private static void StartSupervisorRestartHelper()
        {
            string exePath = Process.GetCurrentProcess().MainModule.FileName;
            var psi = new ProcessStartInfo
            {
                FileName = exePath,
                Arguments = "--restart-supervisor",
                WorkingDirectory = AppDomain.CurrentDomain.BaseDirectory,
                UseShellExecute = false,
                CreateNoWindow = true
            };

            Process.Start(psi);
        }
    }

    public class SupervisorWindowsService : ServiceBase
    {
        public SupervisorWindowsService()
        {
            ServiceName = "SmartPresensiSupervisor";
        }

        protected override void OnStart(string[] args)
        {
            SupervisorCore.Initialize(AppDomain.CurrentDomain.BaseDirectory);
            SupervisorCore.Start();
        }

        protected override void OnStop()
        {
            SupervisorCore.Stop();
        }
    }

    class Program
    {
        private static Mutex _appMutex;

        static void Main(string[] args)
        {
            if (args.Length > 0 && args[0].Equals("--restart-supervisor", StringComparison.OrdinalIgnoreCase))
            {
                RestartSupervisorService();
                return;
            }

            bool createdNew = false;
            _appMutex = new Mutex(true, "Global\\SmartPresensiSupervisorMutex", out createdNew);

            if (!createdNew)
            {
                Console.ForegroundColor = ConsoleColor.Yellow;
                Console.WriteLine("[WARN] Instansi SmartPresensiSupervisor sudah aktif di background.");
                Console.ResetColor();
                return;
            }

            bool isConsoleMode = Environment.UserInteractive || (args.Length > 0 && args[0].Equals("--console", StringComparison.OrdinalIgnoreCase));

            if (isConsoleMode)
            {
                Console.Title = "SmartPresensi Background Supervisor (Connector & Queue Worker)";
                Console.ForegroundColor = ConsoleColor.Cyan;
                Console.WriteLine("======================================================================");
                Console.WriteLine("   SMARTPRESENSI WINDOWS SUPERVISOR: CONNECTOR & QUEUE WORKER         ");
                Console.WriteLine("======================================================================");
                Console.ResetColor();

                SupervisorCore.Initialize(AppDomain.CurrentDomain.BaseDirectory);
                Console.WriteLine("[DIR] Project Root : " + SupervisorCore.Cfg.ProjectRoot);
                Console.WriteLine("[DIR] PHP Executable: " + SupervisorCore.Cfg.PhpPath);
                Console.WriteLine("[API] Local HTTP API: http://127.0.0.1:" + SupervisorCore.Cfg.LocalApiPort + "/");
                Console.WriteLine("----------------------------------------------------------------------");
                Console.WriteLine("Supervisor aktif memantau C# Connector dan Laravel Queue Worker.");
                Console.WriteLine("Tekan Ctrl+C untuk menghentikan supervisor.");
                Console.WriteLine("----------------------------------------------------------------------");

                SupervisorCore.Start();

                var exitEvent = new ManualResetEvent(false);
                Console.CancelKeyPress += (s, e) =>
                {
                    e.Cancel = true;
                    exitEvent.Set();
                };

                exitEvent.WaitOne();
                Console.WriteLine("Menghentikan seluruh layanan...");
                SupervisorCore.Stop();
            }
            else
            {
                ServiceBase.Run(new SupervisorWindowsService());
            }
        }

        private static void RestartSupervisorService()
        {
            string logDir = Path.GetFullPath(Path.Combine(AppDomain.CurrentDomain.BaseDirectory, "..", "storage", "logs"));
            string logPath = Path.Combine(logDir, "supervisor_restart_helper.log");

            Action<string> log = message =>
            {
                try
                {
                    Directory.CreateDirectory(logDir);
                    File.AppendAllText(
                        logPath,
                        "[" + DateTime.Now.ToString("yyyy-MM-dd HH:mm:ss") + "] " + message + Environment.NewLine
                    );
                }
                catch { }
            };

            try
            {
                log("Helper restart supervisor dimulai.");
                Thread.Sleep(2000);

                using (var service = new ServiceController("SmartPresensiSupervisor"))
                {
                    service.Refresh();

                    if (service.Status != ServiceControllerStatus.Stopped)
                    {
                        try
                        {
                            service.Stop();
                        }
                        catch (InvalidOperationException) { }

                        service.WaitForStatus(ServiceControllerStatus.Stopped, TimeSpan.FromSeconds(40));
                    }

                    service.Refresh();
                    service.Start();
                    service.WaitForStatus(ServiceControllerStatus.Running, TimeSpan.FromSeconds(40));

                    log("Service supervisor berhasil dijalankan kembali.");
                }
            }
            catch (Exception ex)
            {
                log("Gagal restart supervisor: " + ex.Message);
            }
        }
    }
}
