# Perbaikan dan Pengembangan SmartPresensi

**Versi:** 2.2  
**Status:** Dokumen rencana dan kriteria penerimaan; bukan klaim fitur telah selesai  
**Lingkup:** Connector C# multi-perangkat, keandalan presensi real-time dan WhatsApp, server lokal yang bisa diakses lewat LAN, serta instalasi Windows/Linux dengan pemeriksaan dependensi otomatis.  
**Di luar lingkup:** Refactor/redesain UI umum, hosting/VPS, dan penggantian Laravel. **Bug di halaman atau modul lain yang ditemukan saat pekerjaan ini berlangsung tetap boleh diperbaiki secara terukur**, tanpa memperluas pekerjaan menjadi refactor UI umum.

## 1. Keputusan dan prinsip yang sudah ditetapkan

1. **C# adalah bahasa dan implementasi connector utama.** Prioritaskan pemulihan/penguatan C# yang sudah ada, bukan migrasi kembali ke Python. Audit kode existing sebelum restore; jangan menimpa pekerjaan lain.
2. **Windows menjadi platform operasional pertama.** Linux adalah target pengembangan berikutnya, tanpa mengorbankan implementasi C# Windows yang sudah stabil.
3. **Aplikasi tidak boleh dikunci pada merek atau seri mesin tertentu.** Dukungan aktual ditentukan oleh adapter, protokol, SDK, dan pengujian model/firmware. Jangan menjanjikan kompatibilitas universal.
4. **Satu instalasi SmartPresensi di satu komputer server lokal sekolah**: Laravel, database, queue, dan connector; komputer lain mengakses dashboard melalui browser di LAN/WiFi sekolah. Tidak menggunakan hosting atau VPS.
5. **Setiap mesin dapat berisi siswa berbeda.** ID/PIN pada dua mesin boleh sama tanpa berarti siswa sama.
6. **Satu pusat pengelolaan connector** dapat mengendalikan beberapa worker/proses independen. Tidak wajib tiga aplikasi dibuka manual ataupun tiga Windows Service. Bentuk isolasi ditetapkan melalui pengujian SDK.
7. **Tidak membuat `Setup.exe` maupun `Launcher.exe` sebagai pintu masuk yang diwajibkan.** Windows menggunakan `setup.ps1` (opsional wrapper `.cmd`) dan `repair.ps1`; Linux menggunakan `install.sh` dan `repair.sh`. Keduanya menampilkan panduan/dialog atau antarmuka interaktif yang mudah dipahami. Operasional harian melalui dashboard browser dan layanan OS otomatis. File biner internal seperti executable connector C# di Windows tetap diperbolehkan.
8. **Installer harus mengarahkan pengguna**, mendeteksi kekurangan dependensi, memberi solusi/perbaikan, dan tidak sekadar menyalin folder lalu gagal diam-diam.
9. **C# satu-satunya connector aktif yang ditargetkan.** Python adalah implementasi sementara untuk dievaluasi selama transisi. **Setelah C# terverifikasi optimal dan lulus seluruh kriteria penerimaan, hapus kode, konfigurasi, task scheduler/service, dependensi, dan dokumentasi operasional Python yang tidak lagi diperlukan**, setelah membuat backup, mengonfirmasi tidak ada fitur yang hanya ada di Python, dan memperoleh persetujuan eksplisit. Jangan menjalankan dua jalur koneksi terhadap mesin yang sama.
10. **Jangan mengganggu logika validasi nomor WhatsApp yang saat ini sudah berjalan** (alur, API validasi, cache/status, trigger tambah/ubah nomor, dan aturan pengiriman). Jadikan perilaku existing sebagai baseline dan pertahankan API/kontraknya. Perubahan hanya jika bug terbukti, dengan tes regresi dan persetujuan untuk perubahan perilaku.
11. Apabila audit atau pengujian menemukan bug nyata di halaman/modul lain, lakukan perbaikan minimal sesuai akar masalah, meskipun berada di luar halaman connector; catat bukti, dampak, dan tes regresi. Jangan melakukan redesign/refactor kosmetik yang tidak berkaitan.
12. Pertahankan arsitektur Laravel, skema database, aturan sekolah, dan integrasi WhatsApp yang sudah berfungsi. Semua perubahan melalui audit, rencana, implementasi bertahap, pengujian, dan laporan.

## 2. Audit kondisi proyek sebelum perubahan

Inventarisasi kondisi sebenarnya, jangan berasumsi fitur telah aktif:

- Struktur proyek, versi PHP/Laravel, web server, MariaDB/MySQL, dependensi frontend, dan alur startup saat ini.
- Connector C# lama: framework/.NET, SDK/interop, bitness x86/x64, cara connect, read users, read attendance, subscribe real-time, reconnect, dan pencatatan log.
- Implementasi Python yang sempat dibuat: fungsi yang sudah lulus uji, kendala, dan perubahan Laravel/API yang mungkin diperlukan oleh C#.
- Kompatibilitas SDK untuk tiap model/firmware perangkat yang terpasang dan penggunaan COM/ActiveX apabila ada.
- Database pemetaan pengguna mesin, kunci unik log, checkpoint sync, rekonsiliasi, service/worker, outbox, retry WhatsApp, log audit, dan autentikasi API.
- Bukti pengujian terkini; pisahkan status **ada di kode**, **pernah bekerja**, **lulus uji**, dan **belum diuji**.

**Keluaran fase audit:** tabel masalah, tingkat risiko, bukti file/baris, ketergantungan, solusi minimal, dan urutan kerja. Jangan melakukan restore besar sebelum mendapat persetujuan.

## 3. Aturan transisi Python → C# dan pembersihan final

- **Selama evaluasi**: backup/arsipkan implementasi Python dan inventarisasi fitur/endpoint/konfigurasi yang digunakan; jangan hapus dulu. Pastikan hanya satu connector memperoleh ownership untuk tiap mesin pada satu waktu.
- **Gerbang kelulusan C#**: semua mesin yang dikonfigurasi dapat melakukan sinkronisasi identitas dan log, menerima scan bersamaan masuk/pulang, bertahan saat satu mesin offline, pulih setelah restart/jaringan putus, melakukan rekonsiliasi tanpa log ganda/hilang, dan tidak menghambat antrean WhatsApp. Gunakan hasil uji terukur beserta bukti log dan tanda tangan persetujuan pengguna.
- **Validasi kesetaraan fitur**: bandingkan C# dengan kemampuan Python yang sempat berjalan; migrasikan hanya fungsi penting yang belum tersedia ke C# jika diperlukan, tanpa menyalin desain dua connector aktif.
- **Setelah lulus dan disetujui**: nonaktifkan permanen layanan Python, bersihkan task scheduler/service, startup script, dependency, environment variable, endpoint khusus dan file Python yang tak dibutuhkan; hapus referensi operasional dari panduan. Gunakan daftar inventaris yang disetujui agar tidak menghapus Python/dependensi milik software lain. Backup berada di luar deployment produksi dengan ketentuan retensi jelas.
- **Verifikasi pascapembersihan**: hanya C# yang terdaftar/berjalan; tidak ada konflik port, duplicate poller atau double-sync; semua fitur dan pengiriman WhatsApp tetap lulus regression test. Siapkan rollback dari backup bila ditemukan regresi.
- Dukungan Linux di masa depan **tidak menjadi alasan mempertahankan connector Python produksi**. Linux hanya dinyatakan didukung bila adapter C# yang kompatibel telah berhasil diuji.

## 4. Arsitektur C# multi-device

```text
Mesin A (pengguna A) ─┐
Mesin B (pengguna B) ─┼── LAN ── Connector Manager (C#, utama)
Mesin C (pengguna C) ─┘                   ├── Worker/proses A + koneksi SDK A
                                        ├── Worker/proses B + koneksi SDK B
                                        ├── Worker/proses C + koneksi SDK C
                                        └── Durable spool + checkpoint + authenticated API
                                                                │
                                                     Laravel + MySQL/MariaDB
                                                                │
                                                     Raw Scan → Presensi → WA Outbox/Queue
```

- Setiap perangkat memiliki konfigurasi, objek SDK/koneksi, eksekusi serial per perangkat, heartbeat, status, timeout, retry/backoff, checkpoint, dan log sendiri.
- **Jangan berbagi objek COM/SDK antarworker** atau menggunakan objek perangkat yang sama untuk operasi paralel tanpa bukti thread-safety.
- Evaluasi dua pola: (A) satu proses C# dengan objek dan thread/apartment yang benar; (B) satu manager dengan child process terisolasi per perangkat. Utamakan **B** jika SDK rawan hang, tidak aman dipanggil paralel, atau perlu recovery terpisah; jangan mengklaim A pasti gagal.
- Kelola child process/service agar start otomatis dan restart terkontrol. Jangan membiarkan pengguna membuka tiga executable secara manual.
- Jika satu mesin putus/hang, mesin lainnya tetap memproses log. Gunakan monitoring untuk mendeteksi sinkronisasi macet dan kesalahan autentikasi.
- Beri ownership lock per perangkat agar dua connector (C#/Python/SDK/SOAP) tidak menarik event atau mengubah state secara bersamaan tanpa rancangan yang aman.
- Gunakan adapter antarmuka perangkat (`IDeviceAdapter` atau konsep serupa); pemilihan implementasi berdasarkan kemampuan aktual perangkat. **C# tetap inti connector.**
- Baca ID, nama, dan catatan scan saja; **jangan mengambil, mengubah, atau menghapus template sidik jari/wajah**.

## 5. Identitas siswa, log scan, dan presensi

- Identitas lokal perangkat adalah **`(device_id, device_user_id)`**; *bukan* PIN secara global. Satu PIN pada dua perangkat bisa merujuk ke dua siswa berbeda.
- Simpan relasi ke `student_id` internal pada tabel pemetaan dengan unique constraint pasangan ID perangkat dan ID pengguna lokal. Hindari membuat siswa baru otomatis dari setiap hasil sinkronisasi.
- Pemetaan otomatis hanya dengan identitas tepercaya (misalnya NIS yang telah diverifikasi); kemiripan nama tidak memadai. Sediakan peninjauan manual untuk *unmapped* dan *conflict*.
- Jika siswa naik kelas atau pindah mesin, buat/pelihara riwayat hubungan perangkat-siswa tanpa mengubah presensi historis.
- Pisahkan **raw scan log** dari **hasil klasifikasi presensi** (masuk/pulang). Jangan memutuskan jenis peristiwa hanya berdasarkan scan pertama/kedua; gunakan jadwal dan policy sekolah.
- Catat ID perangkat, ID pengguna, waktu pada mesin, waktu diterima server, zona waktu, sumber event, dan vendor log ID jika ada. Pertimbangkan jam mesin yang meleset.
- Buat deduplikasi/idempotency di Laravel dengan unique index dan transaksi. Gunakan vendor transaction ID bila tepercaya; jika tidak tersedia, desain kunci komposit dengan uji agar dua scan sah yang berdekatan tidak keliru digabung.
- **Event real-time** untuk latensi rendah; **polling/rekonsiliasi historis** untuk pemulihan event yang terlewat. Reconcile harus aman diulang tanpa membuat data/WA ganda.
- Simpan antrean lokal persisten (*durable spool*) sebelum dikirim/ditandai selesai; jangan kehilangan log saat Laravel, jaringan, atau proses connector berhenti. Jangan menghapus log pada mesin tanpa mekanisme retensi/backup dan persetujuan.

## 6. WhatsApp Gateway dan keselamatan pengiriman

- Semua log masuk terlebih dahulu ke Laravel. **Pengiriman WhatsApp tidak dilakukan langsung dari callback SDK C#**; WA diproses asynchronous melalui job/queue/outbox terpisah.
- WA gateway (misalnya Fonnte) memerlukan koneksi internet, walaupun pencatatan presensi LAN dapat berjalan offline.
- Gunakan kunci idempotensi notifikasi sesuai siswa + tanggal + jenis peristiwa/kebijakan; hindari pekerjaan ganda dari realtime + rekonsiliasi.
- **Pertahankan sepenuhnya logika validasi nomor WA existing yang telah berjalan**: API/provider validation, status/cache, pemicu tambah/ubah nomor, pemeriksaan berkala jika ada, consent, dan pemakaian status validasi saat antre pengiriman. Jangan rewrite, mengganti driver, mengubah trigger atau memvalidasi ulang di setiap scan. Ubah hanya apabila ada bug terbukti, setelah tes regresi, dan jangan mengubah perilaku tanpa persetujuan.
- Retry dengan backoff, batas percobaan, rate limiting, logging outbox, dan status provider. Bedakan API accepted dari delivered.
- Jika request ke provider mungkin diterima tetapi responsnya hilang, tandai **status ambigu** untuk rekonsiliasi; jangan retry secara buta. *Exactly-once delivery* tidak bisa dijamin tanpa dukungan idempotensi/konfirmasi provider.
- Simpan token secara aman, audit akses dan perubahan konfigurasi, serta verifikasi webhook bila digunakan.

## 7. Deployment lokal dan akses dari PC lain

- Satu PC menjadi server on-premise untuk Laravel, database, connector, dan queue worker. PC pengguna hanya membuka browser melalui IP/hostname LAN server.
- Sediakan cara memilih port web apabila 80/443 dipakai. Gunakan web server yang sesuai untuk pemakaian rutin, **bukan `php artisan serve` sebagai server produksi**.
- IP/hostname LAN server harus stabil dan dapat dijangkau; atur firewall terbatas untuk jaringan tepercaya. Jangan mengekspos MySQL, port SDK, connector API, atau fitur kontrol OS ke semua pengguna LAN.
- Role/permission, sesi/login, CSRF, autentikasi antarlayanan, logging, dan pembatasan akses tetap wajib. HTTPS internal disarankan bila dapat disiapkan secara benar.
- Jika PC server mati, pengguna tidak dapat mengakses dashboard dan queue berhenti; rekonsiliasi setelah restart bergantung retensi log perangkat/spool lokal.

## 8. Launcher tanpa ketergantungan GUI EXE

**Operasi harian:** layanan otomatis berjalan saat boot; pengguna membuka dashboard melalui browser. *Service Control* di dashboard boleh menampilkan status/health dan menjalankan tindakan administratif terbatas, bukan terminal bebas atau perintah shell arbitrer.

**Windows (prioritas pertama):** jalankan service melalui Windows Service/Service Manager. **Pintu masuk setup adalah `setup.ps1`**, dengan opsi `setup.cmd` sebagai pembuka PowerShell dan dialog/wizard interaktif berbahasa Indonesia. Repair melalui `repair.ps1`. **Jangan menghasilkan atau mensyaratkan `Setup.exe` maupun `Launcher.exe`.** Script boleh mengelola executable internal connector C#, dan wajib meminta persetujuan sebelum elevasi hak akses atau perubahan sistem.

**Linux (fase lanjutan):** gunakan `install.sh` dan `repair.sh`, serta `systemd` untuk web, database, queue, dan connector yang benar-benar kompatibel; `install.sh`/paket OS untuk pemasangan dan pemeliharaan. C#/.NET modern dapat berjalan di Linux, tetapi SDK berbasis **COM/ActiveX tidak berjalan native di Linux**. Gunakan adapter/protokol lintas platform **hanya jika terbukti kompatibel**; jangan mengganti C# dengan Python hanya untuk mengejar Linux.

**Penting:** tidak berarti satu arsip binary yang sama langsung jalan di Windows dan Linux; siapkan distribusi/paket spesifik OS dengan konfigurasi bersama.

## 9. Smart Installer, Dependency Checker, dan Repair

Tujuan: pengguna memindahkan/memasang SmartPresensi ke PC lain tanpa kebingungan. **Setup awal satu kali boleh**, termasuk izin administrator, tetapi tidak ada tahapan manual memasang PHP, database, atau SDK tanpa panduan jelas.

### Alur setup wajib

1. **Selamat datang:** pilih instalasi baru, update/repair, atau migrasi/pemulihan data.
2. **Periksa sistem:** OS/versi/arsitektur, RAM/ruang disk, permission, port, firewall, runtime, ekstensi PHP, DB engine, .NET, SDK native/COM dan bitness, konflik service, serta jaringan LAN.
3. **Daftar dependensi:** untuk setiap komponen tampilkan *sudah tersedia*, *termasuk bundle*, *perlu dipasang*, *tidak kompatibel*, dan alasannya; tindakan **Pasang/Perbaiki/Coba Lagi**. Jangan gagal diam-diam.
4. **Persetujuan:** paparkan komponen dan perubahan sistem, termasuk kebutuhan admin, service, registrasi DLL/COM, firewall, port, lokasi data, dan kemungkinan restart.
5. **Instalasi & konfigurasi:** bundel PHP+extensions, web server, Laravel+vendor/assets build, DB engine, C# connector (self-contained bila memungkinkan), queue, SDK/dependensi vendor yang legal didistribusikan; siapkan environment secrets dan database secara aman.
6. **Health check:** uji web/HTTP, koneksi DB, autentikasi Laravel, service connector, queue, akses LAN; uji konektivitas mesin jika perangkat tersedia. Tampilkan jelas bila pengujian tertentu belum bisa dilakukan.
7. **Selesai:** tampilkan URL dashboard, panduan singkat, status tiap layanan, lokasi laporan diagnostik, dan tombol untuk membuka aplikasi.

### Aturan dan kegagalan instalasi

- Jika runtime ada dan kompatibel, jangan menginstal ulang. Jika hilang/rusak, tawarkan pemasangan otomatis dari bundle offline, dengan sumber tepercaya dan checksum.
- Jika port bentrok, jelaskan aplikasi mana yang memakai port jika dapat diketahui dan tawarkan port lain; jangan menghentikan aplikasi pihak ketiga tanpa persetujuan.
- Jika SDK membutuhkan registrasi COM/VC++ Runtime/.NET/bits x86-x64, tampilkan penyebab spesifik dan petunjuk. Self-contained .NET **tidak otomatis menyelesaikan dependensi COM**.
- Jika koneksi mesin belum tersedia, tandai *belum teruji* dan beri panduan; jangan menyebut koneksi perangkat berhasil.
- Jangan mengklaim setup sukses penuh bila komponen wajib belum lulus health check. Tampilkan status *siap*, *siap terbatas*, atau *gagal* berdasarkan bukti.
- Sediakan **Repair Installation**, **Diagnostik Sistem**, dan **Ekspor Laporan Teknis** yang menyamarkan password, token, identitas pribadi siswa/wali, dan data sensitif.
- Data, konfigurasi, log, dan backup dipisah dari kode/runtime agar update tidak menimpa data. Hindari hardcoded path; gunakan path konfigurasi yang aman.
- Installer harus dapat dijalankan offline **jika semua redistributable dan lisensinya mengizinkan bundling**; dokumentasikan komponen yang memang perlu unduhan.
- Tidak boleh meng-overwrite database dan konfigurasi server lama tanpa backup/konfirmasi. Hindari membuat dua instance connector/database pada port/perangkat sama.

## 10. Backup, migrasi PC, dan update

1. Pada PC lama: cek kesehatan, hentikan penerimaan/worker dengan aman, tunggu pekerjaan berjalan selesai atau checkpoint tersimpan.
2. Buat **backup konsisten menggunakan mekanisme database resmi**, bukan copy file database saat service masih aktif. Ikutkan konfigurasi, pemetaan perangkat, data siswa/presensi, outbox, checkpoint, serta credential melalui mekanisme terenkripsi/aman.
3. Verifikasi backup, buat manifest versi aplikasi dan checksum, simpan berkas secara aman.
4. Pada PC baru: jalankan setup, pilih **Pulihkan dari Backup**, validasi versi/skema DB, IP/port jaringan dan akses mesin, restore dengan konfirmasi.
5. Pastikan **server lama tidak lagi menarik log mesin yang sama** sebelum connector server baru aktif; jalankan rekonsiliasi dan pemeriksaan jumlah log/antrean WA.
6. Dukung rollback bila update/restore gagal. Sertakan prosedur singkat operator untuk restart, cek status, perbaiki, backup, dan dukungan teknis.

## 11. Pengujian dan kriteria penerimaan

**Pengujian wajib (bertahap, gunakan data sintetis dan LogDriver untuk WhatsApp sebelum gateway sungguhan):**

- C# membaca ID/nama dan log setiap mesin yang dikonfigurasi, termasuk mesin dengan daftar siswa berbeda.
- PIN sama pada mesin berbeda dipetakan ke siswa yang tepat; pindah mesin tidak mengubah riwayat lama.
- Scan masuk/pulang secara hampir bersamaan di beberapa mesin; verifikasi akurasi log, jenis presensi, dan antrean WA.
- Mesin A offline atau SDK hang; mesin B/C tetap sinkron dan A pulih tanpa log ganda/hilang dalam batas retensi yang diketahui.
- Restart service, crash worker, API Laravel offline, LAN putus, queue menumpuk, gateway WhatsApp lambat/gagal, dan sinkronisasi ulang.
- Event realtime + polling historis tidak menghasilkan raw log ganda; dua scan valid berdekatan tetap terpisah.
- Latihan batch 100/500/1.000/3.000 log **sintetis** untuk latensi, throughput, CPU/RAM, delay queue, dan recovery; angka bukan klaim kapasitas pasti.
- Windows bersih tanpa PHP/.NET terinstal; Windows dengan Apache/MySQL lain; port bentrok; gagal register COM; user tanpa admin; offline bundle; repair tanpa kehilangan data.
- Dua atau lebih browser PC klien melalui LAN menggunakan login/role berbeda; cek keamanan jaringan dan pembatasan kontrol layanan.
- Percobaan backup/restore ke PC pengganti; tidak ada dua server aktif memproses perangkat yang sama.
- Linux PoC (fase lanjutan): uji aplikasi Laravel dan **connector C# dengan adapter yang benar-benar kompatibel**; jika SDK Windows-only, jangan mengklaim dukungan presensi Linux telah tersedia.

**Syarat kelulusan:** bukti log/test untuk setiap kasus; tidak ada salah identitas; tidak ada kehilangan event dalam asumsi retensi/spool yang telah diuji; tidak ada duplikasi akibat aplikasi; kerja mesin lain tidak terhenti karena satu perangkat; WA tidak menghambat ingest; installer menunjukkan error/action jelas; pemulihan data sukses. Catat keterbatasan SDK/perangkat/provider yang tidak dapat dijamin.

## 12. Kapasitas database dan ketahanan jangka panjang (1.000 siswa)

- Gunakan MySQL/MariaDB InnoDB sesuai versi yang didukung aplikasi; tidak perlu mengganti database hanya karena ada 1.000 siswa.
- Rancang untuk pertumbuhan minimal 5–10 tahun. Estimasi ilustratif 1.000 siswa × 2 scan/hari × 220 hari/tahun = 440.000 scan/tahun, atau 4,4 juta scan dalam 10 tahun; volume nyata dapat berbeda.
- Audit indeks berdasarkan query nyata: waktu presensi, siswa, perangkat, pemetaan PIN, status dan laporan; hindari N+1 query dan gunakan pagination serta ekspor bertahap.
- Terapkan unique key/idempotency log sesuai kemampuan identifikasi transaksi perangkat, tanpa menyamakan PIN antarperangkat; jangan membuat dedupe berdasarkan waktu saja.
- Pisahkan log mentah, hasil presensi resmi, outbox WA dan log teknis; tetapkan retensi log teknis tanpa menghapus riwayat resmi sembarangan.
- Gunakan transaksi database untuk operasi kritis, migration yang bisa diuji/rollback, serta backup terjadwal dan restore drill pada lingkungan terpisah.
- Lakukan load test realistis untuk >=1.000 siswa, beberapa mesin scan bersamaan, beberapa PC operator, dan antrean WA; ukur p95 response, backlog, DB growth, error serta kehilangan/duplikasi transaksi sebelum mengklaim performa memadai.
- Pastikan backup dan skenario recovery tidak merusak validasi nomor WA, checkpoint sinkronisasi, maupun pemetaan siswa.

## 13. Tahapan eksekusi (urutan prioritas)

| Fase | Pekerjaan | Hasil yang harus disetujui |
|---|---|---|
| **0. Audit** | Inventaris kode C#/Python, SDK/COM, API, DB, queue, dan dependensi | Laporan audit + risiko + rollback plan |
| **1. C# Connector** | Kembalikan/pertahankan C# utama secara aman, isolasi multi-device, error handling, reconnect | PoC multi-device dan bukti tes |
| **2. Integritas data** | Mapping `(device_id, device_user_id)`, raw log, dedupe, checkpoint, rekonsiliasi | Uji data beda mesin dan log tidak ganda |
| **3. WhatsApp** | Jaga logika validasi WA existing tanpa perubahan perilaku; audit idempotensi outbox/queue dan perbaiki hanya bug yang terbukti | Regression test validasi nomor + uji queue/mock provider |
| **3B. Bug terkait** | Perbaiki bug nyata di halaman/modul lain yang ditemukan saat audit/integrasi; patch minimal, tanpa redesign | Bukti reproduksi, tes regresi, tidak ada gangguan logika existing |
| **3C. Database jangka panjang** | Indeks, kapasitas >=1.000 siswa, integritas data 5–10 tahun, backup/restore dan load test | Benchmark, audit query dan restore drill |
| **4. Operasi LAN** | Service autostart dan akses browser dari PC lain | Health check, security, uji beberapa PC |
| **5. Windows Script Setup** | `setup.ps1`/`repair.ps1`, dependency checker, bundling, dialog terpandu, backup/restore; tanpa Setup/Launcher EXE | Uji Windows bersih dan simulasi pindah PC |
| **5B. Hapus Python setelah C# lulus** | Arsip aman, stop/unregister service/task Python, hapus kode/dependensi khusus dan jalur koneksi ganda setelah persetujuan | Inventaris penghapusan, hanya C# aktif, tes regresi lengkap |
| **6. Linux (opsional lanjutan)** | systemd, installer Linux, adapter C# kompatibel | Laporan kompatibilitas/PoC Linux |

## 14. Aturan pelaksanaan untuk AI Coding

1. **Inspect → Plan → Implement → Verify → Report.** Mulai dari membaca dokumen ini dan source yang benar-benar ada.
2. Fase 0 hanya audit; **jangan langsung membuat perubahan besar**, restore, uninstall, menghapus Python, atau mengubah database produksi sebelum persetujuan pengguna. Python **wajib dibersihkan setelah** C# lulus gerbang stabilitas dan pengguna menyetujui daftar penghapusan.
3. Setelah disetujui, lakukan patch minimal per fase, gunakan kode existing, hindari logic ganda dan ketergantungan baru yang tidak diperlukan.
4. Hindari perintah destruktif, perubahan global Windows/registrasi COM, membuka port, dan pemindahan data tanpa konfirmasi eksplisit.
5. Jangan mengunci identifier, label, adapter, dan aturan pada satu seri mesin. Gunakan model/SDK capabilities yang dapat dikonfigurasi.
6. **C# tetap connector utama** pada Windows. Python tidak dijalankan paralel pada mesin sama dan dihapus dari deployment setelah C# optimal terbukti, backup tersedia, dan disetujui.
7. Utamakan konfigurasi berbasis environment, kredensial aman, otorisasi, logging terstruktur, dan test otomatis/integrasi yang relevan.
8. Laporkan per fase: file diubah, alasan, skenario tes, hasil nyata, batasan, risiko tersisa, dan cara rollback. Jangan mengklaim fitur selesai tanpa bukti.
9. **Tidak ada refactor UI umum.** Namun bug fungsional yang ditemukan di halaman/modul mana pun saat audit atau implementasi **harus ditangani**: dokumentasikan reproduksi dan risiko, lakukan perbaikan minimal setelah persetujuan sesuai dampaknya, serta tes regresi. Perubahan visual terbatas pada kebutuhan fungsi/status/diagnostik.
10. **Logika validasi nomor WhatsApp existing adalah area terproteksi.** Jangan menulis ulang, mengganti trigger/cache/provider, atau mengubah hasil validasi; uji regresi sebelum dan sesudah setiap perubahan yang menyentuh data wali, presensi, outbox, atau queue.
11. Berhenti dan minta keputusan apabila menemukan ketidakcocokan SDK, dependensi yang tak bisa didistribusikan legal, risiko kehilangan data, atau kebutuhan privilege tinggi.

---

## 15. Prompt pelaksanaan (salin ke AI Coding)

> Baca penuh file **`Perbaikan dan Pengembangan.md`** di root SmartPresensi dan ikuti sebagai acuan teknis. **C# adalah satu-satunya connector utama yang ditargetkan**; jangan migrasi kembali ke Python. Audit kapasitas MySQL/MariaDB untuk >=1.000 siswa dan rancang backup/restore serta indeks yang terukur. Stabilkan koneksi multi-device dengan identitas `(device_id, device_user_id)`, event real-time plus rekonsiliasi, penanganan crash/offline, dan idempotensi. **Jaga logika validasi nomor WhatsApp existing tetap utuh**; jangan rewrite driver, cache, trigger atau alur validasi. Jika menemukan bug pada halaman/modul lain, perbaiki akar masalah secara minimal dan uji regresi, tanpa refactor UI umum. Setelah **C# dinyatakan optimal berdasarkan tes terukur**, inventarisasi dan **hapus seluruh implementasi Python yang tidak diperlukan** (service/task, source, dependency dan konfigurasi spesifik) **hanya setelah backup dan persetujuan saya**, lalu tes bahwa hanya jalur C# yang aktif. Untuk deployment, **jangan buat `Setup.exe` atau `Launcher.exe`**: gunakan `setup.ps1`/`repair.ps1` (opsional wrapper `.cmd`) di Windows dan `install.sh`/`repair.sh` di Linux; script wajib memeriksa dependensi, meminta izin, menampilkan petunjuk/diagnostik dan mendukung instalasi/repair. File biner internal C# tetap diperbolehkan. Laravel dan database on-premise dapat diakses melalui LAN; Linux adalah fase berikutnya dan hanya didukung jika connector C# + adapter kompatibel. Ikuti **Inspect → Plan → Implement → Verify → Report**. **Mulai hanya Fase 0: audit dan rencana dengan bukti file/baris, risiko dan tes**; jangan ubah source, layanan, database atau hapus Python sampai saya menyetujui implementasi. Laporkan per fase perubahan, tes, risiko dan rollback.
