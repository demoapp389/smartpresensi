// Launcher Windows untuk connector Python Solution X601.
//
// Tugasnya: menjalankan `python zkconnector.py` (mode daemon) di folder yang sama,
// meneruskan stdout/stderr (agar tertangkap supervisor), dan memastikan proses Python
// ikut mati saat launcher dimatikan (Job Object KILL_ON_JOB_CLOSE) supaya koneksi ke
// mesin tidak nyangkut.
//
// Fleksibel lintas PC:
//   1. `python_path.txt`   (satu baris: path lengkap ke python.exe) — prioritas utama.
//   2. Env `CONNECTOR_PYTHON_BIN`.
//   3. `python.exe` pada PATH.
//   4. Lokasi instalasi umum.
//
// Build: jalankan `build_launcher.bat` (memakai csc.exe bawaan Windows).

using System;
using System.Diagnostics;
using System.IO;
using System.Runtime.InteropServices;

class PythonConnectorLauncher
{
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

    private static void Main(string[] args)
    {
        string dir = AppDomain.CurrentDomain.BaseDirectory;
        string script = Path.Combine(dir, "zkconnector.py");

        Console.WriteLine("[LAUNCHER] Solution X601 Python connector");
        Console.WriteLine("[LAUNCHER] Folder : " + dir);

        if (!File.Exists(script))
        {
            Console.WriteLine("[LAUNCHER] ERROR: zkconnector.py tidak ditemukan di folder ini.");
            Environment.Exit(2);
            return;
        }

        string python = ResolvePython(dir);
        if (python == null)
        {
            Console.WriteLine("[LAUNCHER] ERROR: Python tidak ditemukan.");
            Console.WriteLine("[LAUNCHER]        Buat python_path.txt berisi path python.exe, atau set env CONNECTOR_PYTHON_BIN.");
            Environment.Exit(3);
            return;
        }

        Console.WriteLine("[LAUNCHER] Python : " + python);
        Console.WriteLine("[LAUNCHER] Skrip  : " + script);

        ProcessStartInfo psi = new ProcessStartInfo();
        psi.FileName = python;
        psi.Arguments = "\"" + script + "\"";
        psi.WorkingDirectory = dir;
        psi.UseShellExecute = false;
        psi.RedirectStandardOutput = true;
        psi.RedirectStandardError = true;
        psi.CreateNoWindow = true;

        Process proc = new Process();
        proc.StartInfo = psi;
        proc.OutputDataReceived += delegate(object sender, DataReceivedEventArgs e)
        {
            if (e.Data != null) { Console.Out.WriteLine(e.Data); }
        };
        proc.ErrorDataReceived += delegate(object sender, DataReceivedEventArgs e)
        {
            if (e.Data != null) { Console.Error.WriteLine(e.Data); }
        };

        IntPtr job = CreateJobObject(IntPtr.Zero, null);
        if (job != IntPtr.Zero)
        {
            JOBOBJECT_EXTENDED_LIMIT_INFORMATION info = new JOBOBJECT_EXTENDED_LIMIT_INFORMATION();
            info.BasicLimitInformation.LimitFlags = JOB_OBJECT_LIMIT_KILL_ON_JOB_CLOSE;
            int length = Marshal.SizeOf(typeof(JOBOBJECT_EXTENDED_LIMIT_INFORMATION));
            IntPtr buffer = Marshal.AllocHGlobal(length);
            Marshal.StructureToPtr(info, buffer, false);
            SetInformationJobObject(job, JobObjectExtendedLimitInformation, buffer, (uint)length);
            Marshal.FreeHGlobal(buffer);
        }

        proc.Start();

        if (job != IntPtr.Zero)
        {
            AssignProcessToJobObject(job, proc.Handle);
        }

        proc.BeginOutputReadLine();
        proc.BeginErrorReadLine();
        proc.WaitForExit();

        int code = proc.ExitCode;
        Console.WriteLine("[LAUNCHER] Python connector berhenti (exit=" + code + ").");
        Environment.Exit(code);
    }

    private static string ResolvePython(string dir)
    {
        string overrideFile = Path.Combine(dir, "python_path.txt");
        if (File.Exists(overrideFile))
        {
            string configured = File.ReadAllText(overrideFile).Trim();
            if (configured.Length > 0 && File.Exists(configured))
            {
                return configured;
            }
        }

        string env = Environment.GetEnvironmentVariable("CONNECTOR_PYTHON_BIN");
        if (env != null && env.Trim().Length > 0 && File.Exists(env.Trim()))
        {
            return env.Trim();
        }

        string onPath = FindOnPath("python.exe");
        if (onPath != null) { return onPath; }

        string[] candidates = new string[]
        {
            Environment.ExpandEnvironmentVariables(@"%LOCALAPPDATA%\Programs\Python\Python312\python.exe"),
            Environment.ExpandEnvironmentVariables(@"%LOCALAPPDATA%\Programs\Python\Python311\python.exe"),
            @"C:\Python312\python.exe",
            @"C:\Python311\python.exe",
            @"C:\Python310\python.exe",
            Environment.ExpandEnvironmentVariables(@"%ProgramFiles%\Python312\python.exe"),
            Environment.ExpandEnvironmentVariables(@"%ProgramFiles%\Python311\python.exe")
        };

        foreach (string candidate in candidates)
        {
            if (File.Exists(candidate)) { return candidate; }
        }

        return null;
    }

    private static string FindOnPath(string exe)
    {
        string path = Environment.GetEnvironmentVariable("PATH");
        if (path == null) { return null; }

        string[] parts = path.Split(';');
        foreach (string part in parts)
        {
            if (part.Trim().Length == 0) { continue; }
            try
            {
                string full = Path.Combine(part.Trim(), exe);
                if (File.Exists(full)) { return full; }
            }
            catch (Exception)
            {
            }
        }

        return null;
    }
}
