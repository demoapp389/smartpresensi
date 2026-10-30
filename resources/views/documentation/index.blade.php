@extends('layouts.app')

@section('title', 'Dokumentasi')
@section('page-pretitle', 'Administrasi Sistem')
@section('page-title', 'Pusat Dokumentasi')
@section('page-subtitle', 'Panduan penggunaan fitur SmartPresensi untuk administrator, operator, dan wali kelas')

@section('breadcrumb')
    <a href="{{ route('dashboard') }}" class="transition-colors hover:text-[var(--text)]">Beranda</a>
    <span aria-hidden="true">/</span>
    <span>Administrasi Sistem</span>
    <span aria-hidden="true">/</span>
    <span class="font-medium text-[var(--text)]" aria-current="page">Dokumentasi</span>
@endsection

@section('content')
@php
    $categories = [
        ['key' => 'all', 'label' => 'Semua'],
        ['key' => 'memulai', 'label' => 'Memulai'],
        ['key' => 'presensi', 'label' => 'Presensi'],
        ['key' => 'master', 'label' => 'Master Data'],
        ['key' => 'perangkat', 'label' => 'Perangkat'],
        ['key' => 'whatsapp', 'label' => 'WhatsApp'],
        ['key' => 'sistem', 'label' => 'Administrasi'],
    ];

    $quick = [
        ['cat' => 'memulai', 'title' => 'Memulai Cepat', 'desc' => 'Masuk, peran pengguna, dashboard, dan orientasi menu.', 'icon' => 'fingerprint', 'tile' => 'bg-[var(--primary-soft)] text-[var(--primary-accessible)]'],
        ['cat' => 'presensi', 'title' => 'Presensi & Laporan', 'desc' => 'Tarik absensi, koreksi, dan cetak laporan.', 'icon' => 'report', 'tile' => 'bg-sky-100 text-sky-600 dark:bg-sky-950/50 dark:text-sky-400'],
        ['cat' => 'perangkat', 'title' => 'Perangkat & WhatsApp', 'desc' => 'Mesin fingerprint, sidik jari, pemetaan PIN, dan notifikasi.', 'icon' => 'device', 'tile' => 'bg-violet-100 text-violet-600 dark:bg-violet-950/50 dark:text-violet-400'],
    ];

    $topics = [
        // ───────────── MEMULAI ─────────────
        [
            'cat' => 'memulai',
            'title' => 'Bagaimana cara masuk dan apa saja peran pengguna?',
            'keywords' => 'login masuk sandi peran role administrator operator wali kelas',
            'body' => 'Buka alamat aplikasi di browser (mis. <code>http://&lt;IP-server&gt;:8080</code>) lalu masukkan email dan kata sandi pada halaman login.'
                .'<ul><li><strong>Administrator</strong> &mdash; akses penuh: pengguna, mesin, jadwal, gateway WhatsApp, log audit, dan pusat kontrol sistem.</li>'
                .'<li><strong>Operator</strong> &mdash; operasional harian: presensi, koreksi, siswa &amp; kelas, serta WhatsApp.</li>'
                .'<li><strong>Wali Kelas</strong> &mdash; hanya kelas yang diampu: riwayat, laporan, dan presensi manual.</li></ul>',
        ],
        [
            'cat' => 'memulai',
            'title' => 'Menu apa saja yang tersedia dan agar tidak bingung?',
            'keywords' => 'menu sidebar navigasi kategori orientasi',
            'body' => 'Sidebar dikelompokkan menjadi beberapa kategori dan hanya menampilkan menu sesuai peran Anda:'
                .'<ul><li><strong>Monitoring</strong> &mdash; Dashboard.</li>'
                .'<li><strong>Presensi</strong> &mdash; Riwayat Presensi, Riwayat Scan, Perlu Peninjauan, Koreksi Operator/Presensi Manual, Laporan Presensi.</li>'
                .'<li><strong>Master Data</strong> &mdash; Siswa &amp; Wali, Kelas, Jadwal Absensi.</li>'
                .'<li><strong>Perangkat &amp; Integrasi</strong> &mdash; Mesin Fingerprint (Daftar Mesin, Pemetaan PIN, Riwayat Sinkronisasi) dan WhatsApp.</li>'
                .'<li><strong>Administrasi Sistem</strong> &mdash; Manajemen Pengguna, Log Audit, Pusat Kontrol Sistem, Dokumentasi.</li></ul>',
        ],
        [
            'cat' => 'memulai',
            'title' => 'Bagaimana membaca Dashboard monitoring?',
            'keywords' => 'dashboard monitoring kpi grafik tren donut rasio hadir auto refresh',
            'body' => 'Dashboard menampilkan ringkasan kehadiran hari ini: enam kartu KPI (Total Siswa, Tepat Waktu, Terlambat, Sudah Pulang, Izin/Sakit, Belum Hadir), grafik batang <strong>tren 7 hari terakhir</strong>, dan donut <strong>rasio kehadiran</strong>. Halaman otomatis menyegarkan diri setiap 5 menit.'
                .'<ul><li>Gunakan filter <strong>Tanggal</strong> dan <strong>Kelas</strong> di atas untuk melihat hari/kelas tertentu.</li>'
                .'<li>Jika ada scan bermasalah, muncul <strong>peringatan log belum terpetakan</strong> dengan tombol <strong>Tinjau Sekarang</strong>.</li>'
                .'<li>Wali kelas otomatis hanya melihat kelas yang diampu.</li></ul>',
        ],
        [
            'cat' => 'memulai',
            'title' => 'Bagaimana mengganti kata sandi dan memperbarui profil?',
            'keywords' => 'profil password kata sandi ganti akun avatar',
            'body' => 'Klik avatar di kanan atas &rarr; <strong>Profil Saya</strong>. Ubah nama, email, atau kata sandi di sana. Peran akun bersifat hanya-baca dan hanya dapat diubah Administrator melalui Manajemen Pengguna. Gunakan kata sandi minimal 8 karakter.',
        ],

        // ───────────── PRESENSI ─────────────
        [
            'cat' => 'presensi',
            'title' => 'Bagaimana menarik data absensi dari mesin?',
            'keywords' => 'sinkronisasi absensi tarik tarik data pull scan mesin',
            'body' => 'Buka <strong>Perangkat &amp; Integrasi &rarr; Mesin Fingerprint</strong>, pilih mesin, lalu jalankan <strong>Sinkronisasi Absensi</strong>. Pemantauan hasilnya ada di <strong>Riwayat Sinkronisasi</strong>. Sistem juga menarik absensi otomatis tiap 5 menit melalui Connector, tetapi tarikan manual tetap berguna setelah jam masuk/pulang.',
        ],
        [
            'cat' => 'presensi',
            'title' => 'Apa perbedaan Riwayat Presensi dan Riwayat Scan?',
            'keywords' => 'riwayat presensi raw log mentah scan immutable',
            'body' => '<strong>Riwayat Presensi</strong> menampilkan hasil olahan per siswa: waktu scan masuk/pulang, <strong>Telat (Menit)</strong>, <strong>PLG Cepat (Menit)</strong>, dan <strong>Status Kehadiran</strong>. Kolom menit bernilai <code>-</code> jika tidak ada scan valid. <strong>Riwayat Scan</strong> (Raw Logs) menampilkan catatan mentah dari mesin dan bersifat <em>immutable</em> sebagai bukti integritas.'
                .'<ul><li>Status raw log: <code>processed</code> (sudah jadi presensi), <code>unmapped</code> (PIN belum dipetakan), <code>dismissed</code> (PIN sudah dipetakan), <code>inactive_student</code> (siswa terhapus), <code>ignored</code> (duplikat), <code>unprocessed</code> (menunggu).</li>'
                .'<li>Gunakan filter Tanggal, Perangkat, dan Status untuk menelusuri.</li></ul>',
        ],
        [
            'cat' => 'presensi',
            'title' => 'Scan muncul di “Perlu Peninjauan”, apa yang harus dilakukan?',
            'keywords' => 'unmapped peninjauan belum dipetakan cocokkan pin',
            'body' => 'Menu <strong>Perlu Peninjauan</strong> menampilkan scan yang belum bisa dicocokkan ke siswa. Pilih <strong>Petakan ke Siswa</strong>, lalu tentukan siswa yang benar. Seluruh log historis dengan PIN tersebut akan <strong>otomatis diproses</strong> menjadi kehadiran — tidak ada data yang terbuang karena PIN belum dikenal.',
        ],
        [
            'cat' => 'presensi',
            'title' => 'Bagaimana mengoreksi presensi siswa yang lupa scan atau izin?',
            'keywords' => 'koreksi manual izin sakit alpa ubah kehadiran operator presensi manual',
            'body' => 'Gunakan <strong>Koreksi Operator</strong> (operator) atau <strong>Presensi Manual</strong> (wali kelas) pada tanggal terkait. Setiap koreksi <strong>wajib disertai alasan</strong> dan tercatat permanen di <strong>Log Audit</strong> beserta nama operator. Raw log tetap tidak berubah.'
                .'<ul><li><strong>Presensi Manual</strong> dipakai untuk input izin/sakit/hadir/alpa, termasuk saat mesin maintenance; jam masuk/pulang opsional.</li>'
                .'<li><strong>Koreksi</strong> mengubah status satu presensi yang sudah ada.</li>'
                .'<li>Baris presensi normal (hadir tepat waktu masuk dan pulang) tidak menampilkan tombol aksi; baris yang perlu ditinjau atau sudah diverifikasi tetap menampilkan tombol sesuai statusnya.</li></ul>',
        ],
        [
            'cat' => 'presensi',
            'title' => 'Bagaimana mengoreksi beberapa siswa sekaligus?',
            'keywords' => 'koreksi massal bulk correct banyak siswa sekaligus',
            'body' => 'Di halaman Koreksi, centang beberapa baris (atau pilih semua), lalu klik <strong>Koreksi Massal</strong>. Pilih status baru dan isi alasan bersama (mis. “Mengikuti Lomba O2SN”). Alasan yang sama akan diterapkan ke semua siswa terpilih dan terekam di Log Audit.',
        ],
        [
            'cat' => 'presensi',
            'title' => 'Bagaimana membuat dan mencetak laporan presensi?',
            'keywords' => 'laporan rekap bulanan cetak print export excel siswa kelas harian pdf',
            'body' => 'Buka <strong>Laporan Presensi</strong> dan pilih jenis laporan:'
                .'<ul><li><strong>Harian</strong> &mdash; status per siswa pada satu tanggal.</li>'
                .'<li><strong>Bulanan</strong> &mdash; rekap hadir/telat/izin/sakit/alpa, pending (perlu tinjauan), dan persentase per siswa.</li>'
                .'<li><strong>Per Kelas</strong> &mdash; rekap satu kelas dalam sebulan dengan kolom yang sama.</li>'
                .'<li><strong>Per Siswa</strong> &mdash; rincian harian: jadwal/scan masuk, <strong>Telat (Menit)</strong>, jadwal/scan pulang, <strong>PLG Cepat (Menit)</strong>, dan <strong>Keterangan</strong> (Alpa/Libur/-).</li></ul>'
                .'Gunakan tombol <strong>Cetak / PDF (A4)</strong> untuk mencetak dan <strong>Ekspor Excel</strong> untuk mengunduh hasil. Wali kelas otomatis terkunci pada kelasnya.',
        ],

        // ───────────── MASTER DATA ─────────────
        [
            'cat' => 'master',
            'title' => 'Bagaimana menambah siswa beserta data wali?',
            'keywords' => 'siswa wali tambah data induk nomor whatsapp consent',
            'body' => 'Buka <strong>Master Data &rarr; Siswa &amp; Wali &rarr; Tambah Siswa</strong>. Isi identitas siswa, kelas, serta data wali (nama, hubungan, nomor WhatsApp). Nomor wali dipakai untuk notifikasi kehadiran. Centang <strong>consent</strong> bila wali menyetujui penerimaan notifikasi.',
        ],
        [
            'cat' => 'master',
            'title' => 'Bagaimana mengimpor data siswa secara massal dari Excel?',
            'keywords' => 'impor import excel massal template unggah xlsx persetujuan consent ya tidak',
            'body' => 'Klik <strong>Import Siswa (.xlsx)</strong> di halaman Siswa, unduh <strong>templat resmi</strong>, isi sesuai kolom, lalu unggah kembali. Import berjalan dalam satu mode:'
                .'<ul><li>Baris dengan kolom <strong>Nama Siswa</strong> akan memperbarui siswa lama bila <strong>NIS</strong> atau <strong>ID Mesin</strong>-nya cocok, atau menambah siswa baru. Untuk siswa baru, <strong>NIS wajib</strong> diisi.</li>'
                .'<li>Pencocokan siswa berurutan: <strong>NIS</strong> lebih dulu; bila tidak ketemu dan mesin dipilih, dicari lewat <strong>ID Mesin</strong> (PIN yang sudah terpetakan). Siswa yang cocok lewat ID Mesin akan <strong>diperbarui</strong>, bukan dibuat ulang, dan NIS lamanya diganti NIS file bila belum dipakai siswa lain.</li>'
                .'<li>Kolom <strong>ID Mesin</strong> bersifat opsional. Untuk sekaligus memetakan PIN, pilih <strong>Mesin Fingerprint</strong> lalu isi kolom ID Mesin. Baris berisi ID Mesin namun tanpa mesin terpilih akan dicatat sebagai error dan dilewati.</li>'
                .'<li>Untuk <strong>memetakan PIN saja</strong> tanpa mengubah profil, isi kolom <strong>NIS</strong> atau <strong>ID Mesin</strong> dan biarkan <strong>Nama Siswa</strong> kosong; baris ini hanya menghubungkan PIN ke siswa yang sudah ada.</li>'
                .'<li>Kolom <strong>Persetujuan WA</strong> diisi <strong>Ya</strong> atau <strong>Tidak</strong>. Kosong berarti mempertahankan persetujuan lama; bila nomor WhatsApp berubah, persetujuan otomatis direset sampai diisi <strong>Ya</strong> kembali. Isi <strong>Ya</strong> hanya jika wali sudah menyetujui notifikasi WhatsApp.</li>'
                .'<li>Selama proses berjalan, bilah progres tampil di dalam jendela import; setelah selesai, ringkasan <strong>Hasil Import Terakhir</strong> (siswa baru, diperbarui, pemetaan ID Mesin, dilewati, dan baris bermasalah) juga tampil di jendela yang sama.</li></ul>'
                .'NIS boleh panjang, sedangkan ID mesin adalah nomor pendek khusus perangkat. Kelas yang belum ada di master dibuat otomatis. Nomor WhatsApp otomatis dinormalisasi ke format internasional (628...). Template lama tanpa kolom Persetujuan WA tetap dapat diimpor.',
        ],
        [
            'cat' => 'master',
            'title' => 'Apa bedanya NIS dengan ID Mesin (PIN fingerprint)?',
            'keywords' => 'nis id mesin pin fingerprint beda panjang pendek mapping',
            'body' => 'NIS adalah identitas sekolah dan boleh panjang atau kosong. ID Mesin adalah nomor pendek yang tersimpan di perangkat fingerprint dan dipakai saat siswa melakukan scan. Keduanya tidak harus sama. ID Mesin harus sudah terdaftar di mesin fisik dan belum dipakai siswa lain; jika ID tersebut sudah dipetakan, sistem akan menolak. Aplikasi tidak dapat membuat atau mengubah ID di mesin secara otomatis.',
        ],
        [
            'cat' => 'master',
            'title' => 'Bagaimana mengelola kelas dan wali kelas?',
            'keywords' => 'kelas wali kelas tingkat rombel tahun ajaran',
            'body' => 'Buka <strong>Master Data &rarr; Kelas</strong> untuk menambah/mengubah kelas dan tahun ajaran. Wali kelas ditetapkan pada akun pengguna berperan <em>wali kelas</em> (lihat Manajemen Pengguna) agar mereka hanya melihat kelas yang diampu.',
        ],
        [
            'cat' => 'master',
            'title' => 'Bagaimana memindahkan siswa ke kelas lain secara massal?',
            'keywords' => 'pindah kelas pindah rombel massal bulk assign class',
            'body' => 'Di halaman Siswa, centang beberapa siswa, lalu pada bilah aksi pilih <strong>Pindah/Petakan ke Kelas</strong>, tentukan kelas tujuan, dan klik <strong>Terapkan Kelas</strong>. Berguna saat kenaikan kelas atau penataan ulang rombel.',
        ],
        [
            'cat' => 'master',
            'title' => 'Bagaimana cara menghapus, memulihkan, atau menghapus permanen siswa?',
            'keywords' => 'hapus sampah trash soft delete pulihkan restore hapus permanen force delete',
            'body' => 'Penghapusan bersifat <strong>dua tahap</strong>:'
                .'<ul><li><strong>Hapus</strong> memindahkan siswa ke <strong>Sampah</strong> (data masih dapat dipulihkan).</li>'
                .'<li>Tab <strong>Sampah / Terhapus</strong> menampilkan siswa terhapus; gunakan <strong>Pulihkan</strong> untuk mengembalikan.</li>'
                .'<li><strong>Hapus Permanen</strong> menghapus siswa beserta relasi presensi dan wali secara tetap &mdash; tidak dapat dibatalkan.</li></ul>'
                .'Aksi massal (pulihkan / hapus permanen) juga tersedia melalui bilah aksi setelah mencentang beberapa baris.',
        ],
        [
            'cat' => 'master',
            'title' => 'Bagaimana mengatur jadwal absensi dan hari libur?',
            'keywords' => 'jadwal jam masuk pulang terlambat libur kalender toleransi',
            'body' => 'Buka <strong>Jadwal Absensi</strong>.'
                .'<ul><li><strong>Jadwal Mingguan</strong> &mdash; atur Awal Buka Scan, Jam Masuk, Batas Toleransi, Awal Jendela Pulang, dan Batas Akhir Pulang. Untuk sekolah 5 hari kerja, nonaktifkan hari Sabtu agar dihitung libur.</li>'
                .'<li><strong>Kalender Hari Libur</strong> &mdash; tambahkan libur nasional, cuti bersama, atau libur semester; pada hari libur scan tidak dihitung terlambat/alpa.</li></ul>'
                .'Scan pertama sebelum batas akhir masuk menentukan status <em>Tepat Waktu</em> atau <em>Terlambat</em>; scan setelah awal jendela pulang menjadi <em>Pulang</em>.',
        ],

        // ───────────── PERANGKAT ─────────────
        [
            'cat' => 'perangkat',
            'title' => 'Bagaimana mendaftarkan mesin fingerprint baru?',
            'keywords' => 'mesin fingerprint daftar ip port comm key koneksi tambah',
            'body' => 'Buka <strong>Mesin Fingerprint &rarr; Daftar Mesin &rarr; Tambah Mesin</strong>. Isi nama (pola <code>Tipe - Lokasi</code> agar lokasi tampil), alamat IP LAN, port (default <code>4370</code>), comm key, dan timezone. Setelah disimpan, jalankan <strong>Tes</strong> untuk memastikan terhubung.',
        ],
        [
            'cat' => 'perangkat',
            'title' => 'Bagaimana mengubah konfigurasi mesin dan menandai mode pemeliharaan?',
            'keywords' => 'konfigurasi mesin edit ip port comm key timezone maintenance pemeliharaan',
            'body' => 'Buka halaman detail mesin &rarr; <strong>Konfigurasi Mesin</strong>. Anda dapat mengubah nama, IP, port, comm key, dan timezone. Mode <strong>Dalam Pemeliharaan / Nonaktif Sementara</strong> menonaktifkan mesin dari operasi tanpa menghapus datanya. Mengubah IP/Port mengembalikan status koneksi ke Offline hingga diverifikasi ulang.',
        ],
        [
            'cat' => 'perangkat',
            'title' => 'Bagaimana menyinkronkan siswa, absensi, dan jam mesin?',
            'keywords' => 'sinkronisasi siswa absensi waktu sync buat siswa otomatis',
            'body' => 'Pada halaman detail mesin, bagian <strong>Sinkronisasi</strong>:'
                .'<ul><li><strong>Sinkronisasi Siswa</strong> &mdash; ambil ID &amp; nama dari mesin. Opsi <em>Buat siswa otomatis</em> (mati secara bawaan) membuat siswa baru tanpa NIS dengan nama sementara <strong>Siswa PIN ...</strong> untuk PIN yang belum punya mapping; NIS dan nama asli diisi terpisah lewat edit atau import. Nama dari mesin hanya disimpan sebagai petunjuk pemetaan dan tidak pernah menimpa nama siswa yang sudah ada.</li>'
                .'<li><strong>Sinkronisasi Absensi</strong> &mdash; tarik riwayat scan terbaru.</li>'
                .'<li><strong>Sinkronisasi Waktu</strong> &mdash; samakan tanggal &amp; jam mesin dengan server. Jam yang tepat penting agar status terlambat akurat.</li></ul>',
        ],
        [
            'cat' => 'perangkat',
            'title' => 'Bagaimana memetakan ID Mesin ke siswa?',
            'keywords' => 'pemetaan mapping id mesin pin siswa fingerprint template',
            'body' => 'Buka <strong>Pemetaan ID Mesin</strong>. Terdapat dua tab: <strong>Belum Dipetakan</strong> dan <strong>Sudah Dipetakan</strong>.'
                .'<ul><li><strong>Buat Pemetaan Baru</strong> menghubungkan ID mesin dengan satu siswa. ID boleh berbeda dari NIS.</li>'
                .'<li><strong>Ganti Siswa</strong> memindahkan ID mesin ke profil siswa lain tanpa mengubah data jari di mesin.</li>'
                .'<li><strong>Lepas Pemetaan</strong> melepas ID dari server; sidik jari di mesin tetap utuh sehingga bisa dipetakan ulang.</li></ul>'
                .'ID Mesin harus sudah terdaftar di mesin fisik dan belum dipakai siswa lain. ID yang sudah dipetakan akan ditolak. Untuk memetakan banyak PIN sekaligus, gunakan <strong>Import Siswa (.xlsx)</strong> di halaman Siswa dengan mengisi kolom <strong>ID Mesin</strong> dan memilih mesin tujuan.',
        ],
        [
            'cat' => 'perangkat',
            'title' => 'Bagaimana mengirim nama seluruh siswa ke layar mesin?',
            'keywords' => 'kirim nama siswa bulk push names layar mesin',
            'body' => 'Pada halaman detail mesin, bagian <strong>Pemeliharaan Mesin &rarr; Kirim Nama Siswa</strong>. Aksi ini mendorong nama seluruh siswa terpetakan ke layar mesin agar saat scan nama yang muncul sesuai data server. Proses berjalan di latar belakang dengan indikator progres. Nama disesuaikan khusus untuk mesin (tanda kutip/simbol khusus dan karakter non-ASCII disederhanakan, dibatasi sekitar 24 karakter sesuai kapasitas perangkat), sedangkan <strong>nama asli tetap utuh</strong> di sistem, laporan, dan notifikasi WhatsApp.',
        ],
        [
            'cat' => 'perangkat',
            'title' => 'Bagaimana mengunggah (backup) sidik jari dari mesin ke server?',
            'keywords' => 'upload sidik jari backup fingerprint template mesin ke server',
            'body' => 'Pada bagian <strong>Pemeliharaan Mesin &rarr; Upload Sidik Jari</strong>. Aksi ini mengunggah (men-cadangkan) seluruh data sidik jari dari mesin ke server. Backup ini berguna sebelum mengganti/mereset mesin agar siswa tidak perlu merekam ulang.',
        ],
        [
            'cat' => 'perangkat',
            'title' => 'Bagaimana menyalin seluruh data ke mesin lain (kloning)?',
            'keywords' => 'salin data clone bulk clone mesin lain copy sidik jari',
            'body' => 'Klik <strong>Salin Data ke Mesin Lain</strong> pada halaman detail mesin, lalu pilih <strong>mesin target</strong>. Sistem menyalin seluruh nama siswa, PIN, dan sidik jari dari mesin ini ke mesin tujuan, sehingga <strong>siswa tidak perlu melakukan perekaman jari ulang</strong>. Tombol ini aktif bila minimal ada dua mesin terdaftar.',
        ],
        [
            'cat' => 'perangkat',
            'title' => 'Bagaimana menghapus satu pengguna (PIN) di memori mesin?',
            'keywords' => 'hapus pengguna mesin delete user pin sidik jari konfirmasi ketik',
            'body' => 'Pada <strong>Zona Berbahaya &rarr; Hapus Pengguna di Mesin</strong>, masukkan PIN yang akan dihapus, lalu ketik persis <code>HAPUS &lt;PIN&gt;</code> sebagai konfirmasi. Tindakan ini menghapus pengguna &amp; sidik jari pada mesin, <strong>tidak mengubah data server</strong>, dan tidak dapat dibatalkan.',
        ],
        [
            'cat' => 'perangkat',
            'title' => 'Apa saja tindakan berisiko tinggi pada mesin?',
            'keywords' => 'hapus log absensi clear logs restart mesin berbahaya',
            'body' => 'Di halaman detail mesin tersedia:'
                .'<ul><li><strong>Restart Mesin</strong> &mdash; me-restart mesin dari jarak jauh (mesin berhenti melayani absensi beberapa detik).</li>'
                .'<li><strong>Hapus Log Absensi di Mesin</strong> &mdash; menghapus riwayat di memori mesin; data yang belum ditarik ke server akan hilang permanen.</li>'
                .'<li><strong>Hapus Mesin</strong> &mdash; menghapus mesin beserta pemetaan PIN, riwayat sinkronisasi, dan log absensinya dari server.</li></ul>'
                .'Selalu jalankan sinkronisasi sebelum tindakan berisiko. Semua tindakan tercatat di Log Audit.',
        ],
        [
            'cat' => 'perangkat',
            'title' => 'Bagaimana mengatur token Connector dan mengunduh konfigurasinya?',
            'keywords' => 'token connector konfigurasi connector_config ini rotate buat ulang unduh',
            'body' => 'Pada bagian <strong>Connector &amp; Jadwal</strong>, unduh <strong>connector_config.ini</strong> (berisi IP, port, comm key, dan token) untuk dipasang pada folder Connector. Token rahasia tidak ditampilkan di halaman. Bila token bocor, gunakan <strong>Buat Ulang Token</strong> di Zona Berbahaya &mdash; token lama langsung tidak berlaku dan Connector harus dipasang ulang file konfigurasinya. Setiap unduhan konfigurasi dicatat pada Log Audit.',
        ],
        [
            'cat' => 'perangkat',
            'title' => 'Bagaimana memantau hasil sinkronisasi mesin?',
            'keywords' => 'riwayat sinkronisasi sync history berhasil gagal record',
            'body' => 'Buka <strong>Mesin Fingerprint &rarr; Riwayat Sinkronisasi</strong>. Tabel mencatat waktu, mesin, jenis (Tes Koneksi, Sinkron Siswa, Sinkron Absensi), status (Berhasil/Gagal), jumlah record, keterangan hasil, dan operator pelaksana. Gunakan filter mesin/jenis/status untuk menelusuri kegagalan.',
        ],

        // ───────────── WHATSAPP ─────────────
        [
            'cat' => 'whatsapp',
            'title' => 'Bagaimana memahami Outbox pesan dan mengirim ulang yang gagal?',
            'keywords' => 'outbox pesan notifikasi gagal retry kirim ulang whatsapp idempotency',
            'body' => 'Menu <strong>Outbox Pesan</strong> menampilkan ringkasan status (Total, Antrean, Terkirim, Diterima, Dibaca, Gagal) dan riwayat notifikasi ke wali beserta <em>idempotency key</em> untuk mencegah kiriman ganda. Pesan gagal dapat dikirim ulang <strong>satu per satu</strong>, <strong>terpilih</strong>, atau <strong>semua sekaligus</strong> melalui tombol <strong>Kirim Ulang</strong>. Filter tersedia berdasarkan status dan jenis event.',
        ],
        [
            'cat' => 'whatsapp',
            'title' => 'Kapan notifikasi dikirim, ditahan, atau dibatalkan?',
            'keywords' => 'notifikasi ditahan held dibatalkan cancelled consent opt-in valid',
            'body' => 'Notifikasi kehadiran (masuk/terlambat/pulang) dikirim otomatis setelah absensi diproses.'
                .'<ul><li>Nomor wali berstatus <strong>Valid</strong> akan diproses; nomor <strong>invalid</strong> otomatis dibatalkan untuk menghemat kuota gateway.</li>'
                .'<li>Pesan dapat berstatus <strong>ditahan</strong> (menunggu validasi nomor) sebelum dilepas otomatis.</li>'
                .'<li>Pastikan wali sudah memberi <strong>consent</strong> pada data siswa. Consent dapat dicentang di form siswa atau diisi melalui kolom <strong>Persetujuan WA</strong> saat import Excel.</li></ul>',
        ],
        [
            'cat' => 'whatsapp',
            'title' => 'Bagaimana membuat siaran pengumuman massal?',
            'keywords' => 'siaran broadcast pengumuman massal wali kelas draf',
            'body' => 'Buka <strong>Siaran Pengumuman</strong>, pilih target <strong>seluruh sekolah</strong> atau <strong>kelas tertentu</strong>, lalu tulis pesan (atau pakai <em>draf cepat</em> seperti libur, ujian, rapat). Pratinjau gaya WhatsApp tampil di kanan. Estimasi penerima (nomor wali valid) ditampilkan sebelum kirim.'
                .'<ul><li>Sistem mendeduplikasi nomor: satu nomor wali hanya menerima satu pesan per sesi.</li>'
                .'<li>Pengiriman asinkron dengan jeda aman anti-blokir dan hanya ke nomor valid.</li></ul>',
        ],
        [
            'cat' => 'whatsapp',
            'title' => 'Bagaimana mengustomisasi template pesan WhatsApp?',
            'keywords' => 'template pesan kustomisasi redaksi variabel preview aktif',
            'body' => 'Buka <strong>Template Pesan</strong>. Pilih tab template (mis. Check-In, Late, Check-Out, Broadcast), lalu ubah redaksi. Klik <em>chip variabel</em> untuk menyisipkan penanda otomatis: <code>:nama_siswa</code>, <code>:kelas</code>, <code>:tanggal</code>, <code>:waktu</code>, <code>:status</code>, <code>:nama_sekolah</code>, <code>:nama_wali</code>, dan <code>:pesan</code> (khusus broadcast). Gunakan toggle <strong>Aktif</strong> untuk mengaktifkan/menonaktifkan satu template. Pratinjau langsung tampil di sebelah kanan.',
        ],
        [
            'cat' => 'whatsapp',
            'title' => 'Bagaimana memeriksa pengaturan gateway dan uji kirim?',
            'keywords' => 'gateway fonnte meta token kredensial uji kirim pengaturan',
            'body' => 'Administrator dapat membuka <strong>Pengaturan Gateway</strong> untuk melihat driver aktif, kredensial (tersamar), dan parameter validasi. Nilai dibaca dari berkas <code>config/whatsapp.php</code>/<code>.env</code> dan <strong>tidak dapat diubah dari halaman ini</strong>. Gunakan panel <strong>Endpoint Webhook Publik</strong> untuk menyalin URL webhook ke Fonnte, dan panel <strong>Uji Kirim Pesan</strong> untuk memverifikasi konektivitas ke nomor tujuan.',
        ],
        [
            'cat' => 'whatsapp',
            'title' => 'Dari mana URL webhook publik dan bagaimana mengganti domainnya?',
            'keywords' => 'webhook url publik domain cloudflare tunnel fonnte wa_webhook_base_url',
            'body' => 'Buka <strong>Pengaturan Gateway &rarr; Endpoint Webhook Publik</strong>. URL berasal dari <code>WA_WEBHOOK_BASE_URL</code> di <code>.env</code>; tombol <strong>Salin URL</strong> menyalin alamat yang harus diisi di dashboard Fonnte.'
                .'<ul><li>Jika domain berubah: ubah <code>WA_WEBHOOK_BASE_URL</code>, jalankan <code>php artisan optimize:clear</code> lalu <code>php artisan optimize</code>, perbarui URL di Fonnte, lalu kirim notifikasi uji.</li>'
                .'<li>Jangan mengubah <code>APP_URL</code> hanya untuk webhook karena alamat connector mengikuti nilai tersebut.</li>'
                .'<li>Jika <strong>Mode Akses</strong> menunjukkan <strong>Hanya webhook</strong>, request melalui Cloudflare hanya diizinkan ke webhook dan <code>/up</code>; akses UI tetap dari LAN.</li></ul>',
        ],
        [
            'cat' => 'whatsapp',
            'title' => 'Bagaimana memvalidasi nomor WhatsApp wali?',
            'keywords' => 'validasi nomor wali valid pending invalid unknown bulk fonnte',
            'body' => 'Buka <strong>Validasi Nomor Wali</strong>. Kartu statistik menampilkan jumlah <strong>Valid</strong>, <strong>Pending</strong>, <strong>Invalid</strong>, dan <strong>Unknown</strong>.'
                .'<ul><li><strong>Validasi Pending (maks 50)</strong> &mdash; memeriksa nomor yang belum pernah divalidasi.</li>'
                .'<li><strong>Validasi Ulang Invalid</strong> &mdash; memeriksa ulang nomor yang sebelumnya gagal.</li>'
                .'<li>Validasi berjalan di latar belakang; notifikasi ke nomor tidak valid otomatis ditahan.</li>'
                .'<li>Halaman ini adalah satu-satunya tempat menjalankan validasi; halaman <strong>Siswa &amp; Wali</strong> hanya menautkan ke sini.</li></ul>',
        ],

        // ───────────── ADMINISTRASI ─────────────
        [
            'cat' => 'sistem',
            'title' => 'Bagaimana mengelola pengguna sistem?',
            'keywords' => 'manajemen pengguna user admin operator wali kelas akun peran',
            'body' => 'Buka <strong>Administrasi Sistem &rarr; Manajemen Pengguna</strong>. Tambah atau ubah akun, tetapkan peran (<strong>Administrator</strong>, <strong>Operator Absensi</strong>, <strong>Wali Kelas</strong>), dan pilih kelas untuk wali kelas. Gunakan toggle <strong>Akun Aktif</strong> untuk menonaktifkan akses login tanpa menghapus akun. Administrator tidak dapat menghapus akunnya sendiri.',
        ],
        [
            'cat' => 'sistem',
            'title' => 'Apa isi Log Audit dan mengapa penting?',
            'keywords' => 'log audit jejak perubahan keamanan koreksi ip actor',
            'body' => 'Menu <strong>Log Audit</strong> merekam perubahan data sensitif, konfigurasi jadwal, koreksi manual, operasi mesin, dan unduhan/backup. Setiap baris memuat waktu, aktor (pengguna), aksi, entitas, IP address, serta detail perubahan (before/after). Catatan bersifat tetap sebagai bukti kepatuhan &mdash; gunakan untuk menelusuri siapa mengubah apa dan kapan.',
        ],
        [
            'cat' => 'sistem',
            'title' => 'Bagaimana memantau layanan dan me-restart background service?',
            'keywords' => 'pusat kontrol sistem layanan service restart connector worker sinkron massal',
            'body' => 'Buka <strong>Pusat Kontrol Sistem</strong>. Halaman menampilkan status keseluruhan (sehat/perlu perhatian/nonaktif) beserta heartbeat dan tiga kartu layanan:'
                .'<ul><li><strong>C# Fingerprint Connector</strong> &mdash; pembaca scan mesin (PID, auto-restart, waktu mulai).</li>'
                .'<li><strong>Laravel Queue Worker</strong> &mdash; pemroses antrean notifikasi (pending &amp; failed jobs).</li>'
                .'<li><strong>Terminal Mesin Fingerprint</strong> &mdash; status online/offline tiap mesin.</li></ul>'
                .'Tersedia aksi cepat <strong>Sinkron Massal</strong> dan <strong>Restart Semua Layanan</strong>, serta <em>live log viewer</em> untuk melihat log Connector/Worker.',
        ],
        [
            'cat' => 'sistem',
            'title' => 'Bagaimana membuat cadangan (backup) database?',
            'keywords' => 'backup cadangan database sql dump unduh',
            'body' => 'Di <strong>Pusat Kontrol Sistem</strong>, panel <strong>Pencadangan Database</strong> menyediakan tombol <strong>Unduh Dump SQL Sekarang</strong>. Anda akan mengunduh salinan penuh basis data (siswa, mesin, presensi, log mentah, dan audit) langsung dari browser. Aktvitas ekspor tercatat di Audit Log. Buat cadangan rutin, terutama sebelum perubahan besar atau pembaruan.',
        ],
        [
            'cat' => 'sistem',
            'title' => 'Bagaimana memasang agar sistem otomatis berjalan saat komputer menyala?',
            'keywords' => 'windows service otomatis auto start supervisor pemasangan teknisi',
            'body' => 'Teknisi/Administrator cukup menjalankan skrip instalasi Windows Service <strong>satu kali</strong> dari folder Kontrol:'
                .'<ul><li>Jalankan <code>Kontrol\Mulai SmartPresensi.cmd</code>.</li>'
                .'<li>Windows Service <code>SmartPresensiSupervisor</code> akan otomatis berjalan di latar belakang saat komputer menyala, tanpa perlu login akun Windows.</li>'
                .'<li>Operator cukup membuka browser untuk memantau kehadiran dan mengendalikan sistem dari dashboard.</li></ul>',
        ],
        [
            'cat' => 'sistem',
            'title' => 'Bagaimana alur kerja harian yang disarankan?',
            'keywords' => 'alur sop harian pagi siang pulang rutin',
            'body' => 'Contoh runtinitas harian:'
                .'<ul><li><strong>Pagi</strong> &mdash; pastikan Pusat Kontrol Sistem “Berjalan Normal”, mesin Online, lalu pantau Dashboard.</li>'
                .'<li><strong>Setelah jam masuk</strong> &mdash; tangani scan di <strong>Perlu Peninjauan</strong>, lalu input izin/sakit melalui Koreksi/Presensi Manual.</li>'
                .'<li><strong>Siang/Sore</strong> &mdash; jalankan <strong>Sinkronisasi Absensi</strong>, cek <strong>Outbox</strong> untuk pesan gagal, dan kirim ulang bila perlu.</li>'
                .'<li><strong>Berkala</strong> &mdash; validasi nomor wali, cetak/ekspor laporan, dan unduh backup database.</li></ul>',
        ],
    ];
@endphp

<div x-data="{ docSearch: false }" id="doc-root" class="space-y-4">
    {{-- Hero + Pencarian --}}
    <section class="overflow-hidden rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] shadow-[var(--shadow)]">
        <div class="relative px-5 py-7 text-center sm:py-9"
             style="background-image: radial-gradient(circle at 20% 0%, var(--primary-soft), transparent 55%);">
            <span class="mx-auto flex size-11 items-center justify-center rounded-xl bg-[var(--primary-soft)] text-[var(--primary-accessible)]">
                <x-icon name="document" class="size-6" />
            </span>
            <h2 class="mt-3 text-lg font-bold text-[var(--text)]">Ada yang bisa kami bantu?</h2>
            <p class="mx-auto mt-1 max-w-xl text-xs leading-relaxed text-[var(--text-secondary)]">
                Telusuri panduan penggunaan SmartPresensi, atau pilih kategori di bawah. Untuk pemasangan teknis
                server, gunakan tautan <strong class="text-[var(--text)]">Referensi Teknis</strong> di samping
                (panduan Windows dan Linux dipisah).
            </p>
            <div class="relative mx-auto mt-4 max-w-lg">
                <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-[var(--text-muted)]">
                    <x-icon name="search" class="size-4" />
                </span>
                <input type="text" id="doc-search" autocomplete="off"
                       placeholder="Cari panduan, mis. “cetak laporan” atau “tambah siswa”…"
                       aria-label="Cari dokumentasi"
                       class="h-11 w-full rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] pl-9 pr-3 text-sm text-[var(--text)] shadow-xs outline-none transition-colors placeholder:text-[var(--text-muted)] focus:border-[var(--primary)] focus:ring-2 focus:ring-[var(--primary-soft)]" />
            </div>
        </div>
    </section>

    {{-- Kartu Aksi Cepat --}}
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
        @foreach($quick as $card)
            <button type="button" data-doc-quick="{{ $card['cat'] }}"
                    class="group flex flex-col items-start gap-2 rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] p-4 text-left shadow-[var(--shadow)] transition-colors hover:border-[var(--primary)]/50 hover:bg-[var(--surface-secondary)]">
                <span class="flex size-10 items-center justify-center rounded-lg {{ $card['tile'] }}">
                    <x-icon :name="$card['icon']" class="size-5" />
                </span>
                <span class="text-sm font-bold text-[var(--text)]">{{ $card['title'] }}</span>
                <span class="text-xs leading-relaxed text-[var(--text-secondary)]">{{ $card['desc'] }}</span>
            </button>
        @endforeach
    </div>

    {{-- Daftar Panduan + Kolom Bantuan --}}
    <div class="grid grid-cols-1 gap-4 xl:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
        <section class="rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] shadow-[var(--shadow)]">
            <div class="border-b border-[var(--border-subtle)] px-4 py-3">
                <h2 class="text-sm font-bold text-[var(--text)]">Panduan Penggunaan</h2>
            </div>
            <div class="px-4 pb-3 pt-3">
                {{-- Filter kategori --}}
                <div id="doc-cats" class="flex flex-wrap gap-1.5">
                    @foreach($categories as $category)
                        <button type="button" data-doc-cat="{{ $category['key'] }}"
                                aria-pressed="{{ $category['key'] === 'all' ? 'true' : 'false' }}"
                                class="rounded-full border border-[var(--border)] bg-[var(--surface)] px-3 py-1 text-xs font-medium text-[var(--text-secondary)] transition-colors hover:bg-[var(--surface-secondary)] hover:text-[var(--text)] aria-pressed:border-[var(--primary)] aria-pressed:bg-[var(--primary)] aria-pressed:text-white">
                            {{ $category['label'] }}
                        </button>
                    @endforeach
                </div>

                {{-- Daftar topik --}}
                <div id="doc-list" class="mt-3 divide-y divide-[var(--border-subtle)]">
                    @foreach($topics as $topic)
                        <details data-doc-item data-doc-cat="{{ $topic['cat'] }}"
                                 data-doc-text="{{ strtolower($topic['title'].' '.$topic['keywords']) }}"
                                 class="group">
                            <summary class="flex cursor-pointer list-none items-center justify-between gap-3 py-2.5 text-left marker:content-none [&::-webkit-details-marker]:hidden">
                                <span class="text-[13px] font-medium text-[var(--text)] group-hover:text-[var(--primary-accessible)]">{{ $topic['title'] }}</span>
                                <x-icon name="chevron" class="size-4 shrink-0 rotate-90 text-[var(--text-muted)] transition-transform duration-200 group-open:rotate-[270deg]" />
                            </summary>
                            <div class="doc-answer pb-3 pr-1 text-xs leading-relaxed text-[var(--text-secondary)] [&_code]:rounded [&_code]:bg-[var(--surface-secondary)] [&_code]:px-1 [&_code]:py-0.5 [&_code]:font-mono [&_code]:text-[11px] [&_code]:text-[var(--text)] [&_li]:mt-1 [&_strong]:text-[var(--text)] [&_ul]:mt-2 [&_ul]:list-disc [&_ul]:space-y-0.5 [&_ul]:pl-5">
                                {!! $topic['body'] !!}
                            </div>
                        </details>
                    @endforeach
                </div>

                {{-- Tanpa hasil --}}
                <div id="doc-empty" hidden class="py-10 text-center">
                    <p class="text-sm font-semibold text-[var(--text)]">Tidak ada hasil</p>
                    <p class="mt-1 text-xs text-[var(--text-secondary)]">Coba kata kunci lain atau pilih kategori berbeda.</p>
                </div>
            </div>
        </section>

        {{-- Kolom bantuan --}}
        <div class="space-y-4">
            <section class="rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] shadow-[var(--shadow)]">
                <div class="border-b border-[var(--border-subtle)] px-4 py-3">
                    <h2 class="text-sm font-bold text-[var(--text)]">Masih butuh bantuan?</h2>
                </div>
                <div class="p-4">
                    <p class="text-xs leading-relaxed text-[var(--text-secondary)]">
                        Jika mesin tidak terbaca atau data tidak muncul, pastikan koneksi jaringan mesin benar dan
                        jalankan <strong class="text-[var(--text)]">Sinkronisasi Absensi</strong>. Untuk masalah
                        layanan server (web/database tidak berjalan), hubungi administrator teknis sekolah.
                    </p>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <a href="{{ route('system.control') }}"
                           class="inline-flex items-center gap-1.5 rounded-[var(--radius-sm)] border border-[var(--border)] bg-[var(--surface)] px-3 py-1.5 text-xs font-medium text-[var(--text)] transition-colors hover:bg-[var(--surface-secondary)]">
                            <x-icon name="system-control" class="size-3.5" /> Pusat Kontrol Sistem
                        </a>
                        <a href="{{ route('audit.index') }}"
                           class="inline-flex items-center gap-1.5 rounded-[var(--radius-sm)] border border-[var(--border)] bg-[var(--surface)] px-3 py-1.5 text-xs font-medium text-[var(--text)] transition-colors hover:bg-[var(--surface-secondary)]">
                            <x-icon name="audit" class="size-3.5" /> Log Audit
                        </a>
                    </div>
                </div>
            </section>

            <section class="rounded-[var(--radius)] border border-[var(--border)] bg-[var(--surface)] shadow-[var(--shadow)]">
                <div class="border-b border-[var(--border-subtle)] px-4 py-3">
                    <h2 class="text-sm font-bold text-[var(--text)]">Referensi Teknis</h2>
                </div>
                <div class="p-2">
                    <a href="{{ route('documentation.deployment') }}"
                       class="group flex items-start gap-3 rounded-[var(--radius-sm)] p-2.5 transition-colors hover:bg-[var(--surface-secondary)]">
                        <span class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-[var(--primary-soft)] text-[var(--primary-accessible)]">
                            <x-icon name="document" class="size-4" />
                        </span>
                        <span class="min-w-0 flex-1">
                            <span class="flex items-center gap-1 text-xs font-semibold text-[var(--text)]">
                                Ringkasan Deployment
                                <x-icon name="chevron" class="size-3 text-[var(--text-muted)]" />
                            </span>
                            <span class="block text-[11px] text-[var(--text-muted)]">Pilih jalur instalasi Windows atau Linux.</span>
                        </span>
                    </a>
                    <a href="{{ route('documentation.deployment.windows') }}"
                       class="group flex items-start gap-3 rounded-[var(--radius-sm)] p-2.5 transition-colors hover:bg-[var(--surface-secondary)]">
                        <span class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-sky-100 text-sky-600 dark:bg-sky-950/50 dark:text-sky-400">
                            <x-icon name="system-control" class="size-4" />
                        </span>
                        <span class="min-w-0 flex-1">
                            <span class="flex items-center gap-1 text-xs font-semibold text-[var(--text)]">
                                Deployment Windows
                                <x-icon name="chevron" class="size-3 text-[var(--text-muted)]" />
                            </span>
                            <span class="block text-[11px] text-[var(--text-muted)]">Wizard bundle, layanan Windows, connector C#.</span>
                        </span>
                    </a>
                    <a href="{{ route('documentation.deployment.linux') }}"
                       class="group flex items-start gap-3 rounded-[var(--radius-sm)] p-2.5 transition-colors hover:bg-[var(--surface-secondary)]">
                        <span class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-emerald-100 text-emerald-600 dark:bg-emerald-950/50 dark:text-emerald-400">
                            <x-icon name="plug" class="size-4" />
                        </span>
                        <span class="min-w-0 flex-1">
                            <span class="flex items-center gap-1 text-xs font-semibold text-[var(--text)]">
                                Deployment Linux
                                <x-icon name="chevron" class="size-3 text-[var(--text-muted)]" />
                            </span>
                            <span class="block text-[11px] text-[var(--text-muted)]">Headless/CLI: Nginx, systemd, connector Python.</span>
                        </span>
                    </a>
                    <div class="flex items-start gap-3 rounded-[var(--radius-sm)] p-2.5">
                        <span class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                            <x-icon name="info" class="size-4" />
                        </span>
                        <div>
                            <p class="text-xs font-semibold text-[var(--text)]">Versi Komponen</p>
                            <p class="text-[11px] text-[var(--text-muted)]">Lihat <code class="font-mono">MANIFEST.txt</code> pada folder bundle untuk versi PHP/Apache/MySQL.</p>
                        </div>
                    </div>
                </div>
            </section>
        </div>
    </div>
</div>

<script>
    (function () {
        const root = document.getElementById('doc-root');
        if (!root) {
            return;
        }

        const input = document.getElementById('doc-search');
        const chips = Array.from(root.querySelectorAll('[data-doc-cat]'));
        const quick = Array.from(root.querySelectorAll('[data-doc-quick]'));
        const items = Array.from(root.querySelectorAll('[data-doc-item]'));
        const empty = document.getElementById('doc-empty');
        let activeCat = 'all';

        function applyFilter() {
            const q = (input.value || '').trim().toLowerCase();
            let visible = 0;

            items.forEach((item) => {
                const catOk = activeCat === 'all' || item.dataset.docCat === activeCat;
                const textOk = q === '' || item.dataset.docText.includes(q);
                const show = catOk && textOk;
                item.classList.toggle('hidden', !show);
                if (show) {
                    visible += 1;
                }
            });

            empty.hidden = visible !== 0;
        }

        function activate(cat, scrollIntoView) {
            activeCat = cat;
            chips.forEach((chip) => {
                const on = chip.dataset.docCat === cat;
                chip.setAttribute('aria-pressed', on ? 'true' : 'false');
            });
            applyFilter();

            if (scrollIntoView) {
                document.getElementById('doc-list').scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        }

        chips.forEach((chip) => chip.addEventListener('click', () => activate(chip.dataset.docCat, false)));
        quick.forEach((card) => card.addEventListener('click', () => activate(card.dataset.docQuick, true)));
        input.addEventListener('input', applyFilter);
    })();
</script>
@endsection
