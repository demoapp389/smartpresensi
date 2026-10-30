## Modul Manajemen Mesin Fingerprint (Wajib)

Aplikasi Laravel wajib menyediakan halaman **Pengaturan Mesin Fingerprint** yang memungkinkan admin mengelola mesin Solution X601 melalui Dashboard UI.

### Fitur Utama

1. Menambahkan mesin fingerprint menggunakan nama mesin, alamat IP, dan port.
2. Menguji koneksi mesin menggunakan tombol **Tes Koneksi**.
3. Menampilkan status koneksi online/offline dan waktu pengecekan terakhir.
4. Mengambil ID dan nama siswa yang sudah terdaftar pada mesin melalui tombol **Sinkronisasi Siswa**.
5. Menyimpan atau memperbarui data siswa secara otomatis tanpa membuat duplikat.
6. Mengambil riwayat absensi melalui tombol **Sinkronisasi Absensi**.
7. Menjalankan sinkronisasi absensi otomatis secara berkala.
8. Menampilkan jumlah siswa dan log absensi yang berhasil disinkronkan.
9. Menampilkan riwayat sinkronisasi dan informasi kesalahan.
10. Menjalankan seluruh fungsi melalui Dashboard UI tanpa memerlukan penggunaan CMD secara rutin.

### Ketentuan Teknis

- Gunakan Laravel sebagai aplikasi utama.
- Gunakan C# Connector berbasis ZKEM SDK untuk komunikasi mesin apabila koneksi langsung PHP tidak kompatibel.
- Connector berjalan otomatis sebagai Windows Service.
- Laravel berkomunikasi dengan connector melalui API lokal yang diautentikasi.
- Operasi sinkronisasi menggunakan pendekatan read-only terhadap mesin.
- Data sidik jari dan wajah tetap tersimpan di mesin dan tidak perlu diimpor.
- Sistem harus tetap mendukung migrasi Laravel ke hosting pada masa mendatang.

### Kriteria Penerimaan

Admin dapat membuka dashboard, mengisi alamat IP dan port mesin X601, menekan **Tes Koneksi**, lalu melakukan **Sinkronisasi Siswa** dan **Sinkronisasi Absensi** tanpa menjalankan perintah terminal secara manual.

Seluruh proses harus menampilkan status berhasil atau gagal secara jelas dan tidak boleh menghapus data yang sudah tersimpan pada mesin fingerprint.