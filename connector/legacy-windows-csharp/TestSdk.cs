using System;

namespace SolutionConnector
{
    class Program
    {
        static void Main(string[] args)
        {
            Console.WriteLine("==================================================");
            Console.WriteLine("  SOLUTION X601 SDK VERIFICATION TOOL (C# .NET)   ");
            Console.WriteLine("==================================================");

            try
            {
                Type zkemType = Type.GetTypeFromProgID("zkemkeeper.ZKEM");
                if (zkemType == null)
                {
                    Console.WriteLine("[ERROR] ProgID 'zkemkeeper.ZKEM' tidak ditemukan di Registry.");
                    return;
                }

                dynamic zkem = Activator.CreateInstance(zkemType);
                Console.WriteLine("[SUCCESS] ZKemkeeper COM Object berhasil dibuat: " + zkemType.FullName);

                string ip = args.Length > 0 ? args[0] : "192.168.1.201";
                int port = args.Length > 1 ? int.Parse(args[1]) : 4370;

                Console.WriteLine("[INFO] Mencoba koneksi ke mesin Solution X601: " + ip + ":" + port + " ...");
                bool connected = zkem.Connect_Net(ip, port);

                if (connected)
                {
                    Console.WriteLine("[SUCCESS] Berhasil terhubung ke mesin Solution X601 di " + ip + ":" + port);
                    zkem.Disconnect();
                }
                else
                {
                    int errorCode = 0;
                    zkem.GetLastError(ref errorCode);
                    Console.WriteLine("[STATUS] Belum dapat terhubung ke mesin fisik (Error Code: " + errorCode + ").");
                    Console.WriteLine("[NOTE] Pastikan kabel LAN terpasang, mesin X601 menyala, dan IP sesuai.");
                }
            }
            catch (Exception ex)
            {
                Console.WriteLine("[EXCEPTION] " + ex.Message);
            }
        }
    }
}
