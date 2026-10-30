<?php

namespace App\Services;

use App\Models\DeviceSyncLog;
use App\Models\FingerprintDevice;
use App\Support\TaskProgressReporter;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Service untuk mengelola interaksi langsung dengan Mesin Fingerprint (Solution / ZKTeco)
 * menggunakan protokol SOAP Web Service (HTTP POST /iWsService port 80).
 *
 * Keunggulan:
 * - 100% Native PHP (Cross-platform: Windows, Linux, macOS, Docker).
 * - Tidak memerlukan Windows Service, ActiveX, atau binary eksternal C#.
 * - Mendukung sinkronisasi jam, tarik log absensi, kirim nama user ke layar mesin,
 *   restart remote, dan pembersihan log.
 */
class SoapFingerprintService
{
    /**
     * Batas panjang nama yang dikirim ke memori mesin (byte).
     * Field nama pada mesin ZKTeco umumnya 24 byte; lebih dari ini ditolak/dipotong mesin.
     */
    protected const MAX_DEVICE_NAME_BYTES = 24;

    protected DeviceSyncLogService $syncLogService;

    protected AttendanceLogProcessingService $logProcessor;

    public function __construct(
        protected FingerprintIngestService $ingestService,
        protected AttendanceProcessingService $processingService,
        protected WhatsAppNotificationService $waService,
        ?DeviceSyncLogService $syncLogService = null,
        ?AttendanceLogProcessingService $logProcessor = null
    ) {
        $this->syncLogService = $syncLogService ?? app(DeviceSyncLogService::class);
        $this->logProcessor = $logProcessor ?? app(AttendanceLogProcessingService::class);
    }

    /**
     * Menentukan port SOAP Web Service yang sesuai.
     * Mesin umumnya menjalankan web server di Port 80 (meskipun port komunikasi SDK-nya 4370).
     */
    public function resolveWebPort(FingerprintDevice $device, float $probeTimeout = 1.0): int
    {
        $port = (int) $device->port;

        // Jika port sudah diset 80, gunakan 80
        if ($port === 80) {
            return 80;
        }

        // Hanya jika port diset 4370 (port ZKEM LAN lama), periksa apakah web port 80 terbuka
        if ($port === 4370) {
            $fp = @fsockopen($device->ip, 80, $errno, $errstr, $probeTimeout);
            if ($fp) {
                fclose($fp);

                return 80;
            }
        }

        return $port;
    }

    /**
     * Mengirimkan request XML ke endpoint SOAP /iWsService mesin via soket HTTP.
     */
    public function sendSoapRequest(string $ip, int $port, string $soapXml, float $timeout = 3.0): ?string
    {
        $errno = 0;
        $errstr = '';

        $fp = @fsockopen($ip, $port, $errno, $errstr, $timeout);
        if (! $fp) {
            return null;
        }

        stream_set_timeout($fp, (int) max(1, ceil($timeout)));

        $newLine = "\r\n";
        $headers = 'POST /iWsService HTTP/1.0'.$newLine
            .'Content-Type: text/xml'.$newLine
            .'Content-Length: '.strlen($soapXml).$newLine
            .'Connection: close'.$newLine.$newLine;

        fwrite($fp, $headers.$soapXml.$newLine);

        $response = '';
        while (! feof($fp)) {
            $chunk = fgets($fp, 2048);
            if ($chunk === false) {
                break;
            }
            $response .= $chunk;
        }
        fclose($fp);

        return $response;
    }

    /**
     * Mengekstrak isi di antara dua tag XML (Helper parser).
     */
    public function parseTag(string $data, string $startTag, string $endTag): string
    {
        $start = strpos($data, $startTag);
        if ($start === false) {
            return '';
        }

        $startOffset = $start + strlen($startTag);
        $end = strpos($data, $endTag, $startOffset);
        if ($end === false) {
            return '';
        }

        return substr($data, $startOffset, $end - $startOffset);
    }

    /**
     * Mengekstrak seluruh row <Row>...</Row> dari respons XML log.
     */
    public function parseRows(string $xml, string $rowTag = 'Row'): array
    {
        $rows = [];
        $offset = 0;
        $openTag = "<{$rowTag}>";
        $closeTag = "</{$rowTag}>";

        while (($start = strpos($xml, $openTag, $offset)) !== false) {
            $startContent = $start + strlen($openTag);
            $end = strpos($xml, $closeTag, $startContent);
            if ($end === false) {
                break;
            }
            $rows[] = substr($xml, $startContent, $end - $startContent);
            $offset = $end + strlen($closeTag);
        }

        return $rows;
    }

    /**
     * Uji koneksi Web SOAP ke mesin fingerprint.
     */
    public function testConnection(FingerprintDevice $device, ?int $operatorId = null): array
    {
        $port = $this->resolveWebPort($device);
        $commKey = $device->comm_key ?: '0';

        // Kirim probe GetAttLog dengan PIN 0 (cepat dan benign)
        $soapRequest = "<GetAttLog><ArgComKey xsi:type=\"xsd:integer\">{$commKey}</ArgComKey><Arg><PIN xsi:type=\"xsd:integer\">0</PIN></Arg></GetAttLog>";
        $response = $this->sendSoapRequest($device->ip, $port, $soapRequest, 2.5);

        $hasValidSoapResponse = $response && (
            str_contains($response, '<GetAttLogResponse>') ||
            str_contains($response, '<Information>') ||
            str_contains($response, '<Response>')
        );

        if (! $hasValidSoapResponse) {
            $device->update(['status' => 'offline']);

            $msg = "Mesin {$device->name} ({$device->ip}:{$port}) tidak merespons HTTP SOAP valid. Pastikan mesin menyala, kabel LAN terhubung, dan port Web aktif.";

            DeviceSyncLog::create([
                'device_id' => $device->id,
                'sync_type' => 'test_connection',
                'status' => 'failed',
                'records_count' => 0,
                'message' => $msg,
                'details' => ['driver' => 'soap', 'ip' => $device->ip, 'port' => $port],
                'operator_id' => $operatorId,
            ]);

            return [
                'success' => false,
                'message' => $msg,
            ];
        }

        $device->update([
            'status' => 'active',
            'last_seen_at' => now(),
        ]);

        $successMsg = "Koneksi Web SOAP ke mesin {$device->name} ({$device->ip}:{$port}) BERHASIL (Online).";

        DeviceSyncLog::create([
            'device_id' => $device->id,
            'sync_type' => 'test_connection',
            'status' => 'success',
            'records_count' => 0,
            'message' => $successMsg,
            'details' => ['driver' => 'soap', 'ip' => $device->ip, 'port' => $port],
            'operator_id' => $operatorId,
        ]);

        return [
            'success' => true,
            'message' => $successMsg,
        ];
    }

    /**
     * Sinkronisasikan tanggal dan jam mesin fingerprint dengan waktu server.
     */
    public function syncTime(FingerprintDevice $device, ?int $operatorId = null): array
    {
        $port = $this->resolveWebPort($device);
        $commKey = $device->comm_key ?: '0';

        $time = now($device->timezone ?: 'Asia/Jakarta');

        $dateStr = $time->format('Y-m-d');
        $timeStr = $time->format('H:i:s');

        $soapRequest = "<SetDate><ArgComKey xsi:type=\"xsd:integer\">{$commKey}</ArgComKey><Arg><Date xsi:type=\"xsd:string\">{$dateStr}</Date><Time xsi:type=\"xsd:string\">{$timeStr}</Time></Arg></SetDate>";
        $response = $this->sendSoapRequest($device->ip, $port, $soapRequest, 3.0);

        if (! $response) {
            $msg = "Gagal menyinkronkan jam: Mesin {$device->name} ({$device->ip}:{$port}) tidak merespons.";

            DeviceSyncLog::create([
                'device_id' => $device->id,
                'sync_type' => 'sync_time',
                'status' => 'failed',
                'records_count' => 0,
                'message' => $msg,
                'details' => ['driver' => 'soap', 'target' => "{$dateStr} {$timeStr}"],
                'operator_id' => $operatorId,
            ]);

            return ['success' => false, 'message' => $msg];
        }

        $info = $this->parseTag($response, '<Information>', '</Information>');
        $device->update(['last_seen_at' => now()]);

        $successMsg = "Jam mesin {$device->name} berhasil disinkronkan ke {$dateStr} {$timeStr}".($info ? " ({$info})" : '').'.';

        DeviceSyncLog::create([
            'device_id' => $device->id,
            'sync_type' => 'sync_time',
            'status' => 'success',
            'records_count' => 0,
            'message' => $successMsg,
            'details' => ['driver' => 'soap', 'time_synced' => "{$dateStr} {$timeStr}", 'raw_info' => $info],
            'operator_id' => $operatorId,
        ]);

        return ['success' => true, 'message' => $successMsg];
    }

    /**
     * Tarik riwayat absensi mentah dari mesin via SOAP (<GetAttLog>) dan masukkan ke sistem.
     */
    public function pullAttendanceLogs(FingerprintDevice $device, ?int $operatorId = null, ?TaskProgressReporter $reporter = null, bool $logFailure = true): array
    {
        $port = $this->resolveWebPort($device);
        $commKey = $device->comm_key ?: '0';

        $soapRequest = "<GetAttLog><ArgComKey xsi:type=\"xsd:integer\">{$commKey}</ArgComKey><Arg><PIN xsi:type=\"xsd:integer\">All</PIN></Arg></GetAttLog>";
        $response = $this->sendSoapRequest($device->ip, $port, $soapRequest, 10.0);

        if (! $response) {
            $device->update(['status' => 'offline']);
            $msg = "Gagal menarik data absensi: Mesin {$device->name} ({$device->ip}:{$port}) tidak merespons.";

            if ($logFailure) {
                $this->syncLogService->recordAttendanceSync(
                    $device,
                    'failed',
                    0,
                    $msg,
                    ['driver' => 'soap'],
                    $operatorId
                );
            }

            return ['success' => false, 'message' => $msg, 'records_count' => 0];
        }

        $logBlock = $this->parseTag($response, '<GetAttLogResponse>', '</GetAttLogResponse>');
        $rawRows = $this->parseRows($logBlock, 'Row');

        $formattedLogs = [];
        foreach ($rawRows as $row) {
            $pin = trim($this->parseTag($row, '<PIN>', '</PIN>'));
            $dateTime = trim($this->parseTag($row, '<DateTime>', '</DateTime>'));
            $verified = trim($this->parseTag($row, '<Verified>', '</Verified>'));
            $status = trim($this->parseTag($row, '<Status>', '</Status>'));

            if ($pin === '' || $dateTime === '') {
                continue;
            }

            try {
                $carbonDate = Carbon::parse($dateTime);
                $formattedLogs[] = [
                    'machine_user_id' => $pin,
                    'occurred_at' => $carbonDate->toDateTimeString(),
                    'source_key' => FingerprintIngestService::canonicalSourceKey($pin, $carbonDate),
                    'verified' => $verified,
                    'status' => $status,
                ];
            } catch (\Throwable) {
                // Abaikan jika format tanggal tidak valid
            }
        }

        $totalFound = count($formattedLogs);
        $ingestResult = ['inserted' => 0, 'duplicates_skipped' => 0, 'unmapped' => 0];

        if ($totalFound > 0) {
            $ingestResult = $this->ingestService->ingestBatch($device, $formattedLogs);

            $pendingCount = $this->logProcessor->pendingCountForDevice($device);

            $reporter?->setTotal(
                $totalFound + $pendingCount,
                'Log mesin tersimpan, memproses absensi...'
            );
            $reporter?->advance($totalFound, 'Log mesin tersimpan, memproses absensi...');

            $this->logProcessor->processDueForDevice($device, $reporter);
        }

        $device->update([
            'status' => 'active',
            'last_seen_at' => now(),
        ]);

        $summaryMsg = "Tarik absensi Web SOAP selesai: {$totalFound} total record terbaca ({$ingestResult['inserted']} baru, {$ingestResult['duplicates_skipped']} terduplikasi, {$ingestResult['unmapped']} belum terpetakan).";

        $this->syncLogService->recordAttendanceSync(
            $device,
            'success',
            $ingestResult['inserted'],
            $summaryMsg,
            [
                'driver' => 'soap',
                'total_scanned' => $totalFound,
                'inserted' => $ingestResult['inserted'],
                'duplicates' => $ingestResult['duplicates_skipped'],
                'unmapped' => $ingestResult['unmapped'],
            ],
            $operatorId
        );

        return [
            'success' => true,
            'message' => $summaryMsg,
            'records_count' => $ingestResult['inserted'],
            'total_scanned' => $totalFound,
            'unmapped' => $ingestResult['unmapped'],
        ];
    }

    /**
     * Mengirimkan nama pengguna dan PIN ke memori mesin agar tampil di layar LCD (<SetUserInfo>).
     */
    public function pushUserInfo(FingerprintDevice $device, string|int $pin, string $name, ?int $operatorId = null): array
    {
        $port = $this->resolveWebPort($device);
        $commKey = $device->comm_key ?: '0';

        // Nama disanitasi khusus untuk mesin; nama asli tetap tersimpan di database.
        $deviceName = $this->sanitizeDeviceName($name);
        $xmlName = htmlspecialchars($deviceName, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $xmlPin = htmlspecialchars((string) $pin, ENT_XML1, 'UTF-8');

        $soapRequest = "<SetUserInfo><ArgComKey Xsi:type=\"xsd:integer\">{$commKey}</ArgComKey><Arg><PIN>{$xmlPin}</PIN><Name>{$xmlName}</Name></Arg></SetUserInfo>";
        $response = $this->sendSoapRequest($device->ip, $port, $soapRequest, 3.0);

        if (! $response) {
            $msg = "Gagal mengirim data siswa ke mesin {$device->name}: Mesin tidak merespons.";

            return ['success' => false, 'message' => $msg];
        }

        // Segarkan database mesin agar langsung aktif di memori
        $refreshRequest = "<RefreshDB><ArgComKey xsi:type=\"xsd:integer\">{$commKey}</ArgComKey></RefreshDB>";
        $this->sendSoapRequest($device->ip, $port, $refreshRequest, 2.0);

        $info = $this->parseTag($response, '<Information>', '</Information>');
        $successMsg = "Nama '{$deviceName}' (PIN {$pin}) berhasil dikirim ke layar mesin {$device->name}".($info ? " ({$info})" : '').'.';

        DeviceSyncLog::create([
            'device_id' => $device->id,
            'sync_type' => 'push_user',
            'status' => 'success',
            'records_count' => 1,
            'message' => $successMsg,
            'details' => ['driver' => 'soap', 'pin' => $pin, 'name' => $name, 'sent_name' => $deviceName],
            'operator_id' => $operatorId,
        ]);

        return ['success' => true, 'message' => $successMsg];
    }

    /**
     * Menyiapkan nama agar diterima memori mesin fingerprint.
     *
     * Mesin ZKTeco hanya menerima teks ASCII terbatas, sehingga nama dengan
     * apostrof tipografis, karakter non-ASCII, atau karakter XML (`&`, `<`, `>`, kutip)
     * membuat request `<SetUserInfo>` gagal dan nama tidak pernah masuk. Nama asli
     * tidak diubah di database; hanya nilai yang dikirim ke mesin yang disanitasi.
     */
    protected function sanitizeDeviceName(string $name): string
    {
        $name = trim($name);

        // Normalisasi tanda baca tipografis (mis. apostrof melengkung dari Excel) ke ASCII.
        $name = str_replace(
            ["\u{2018}", "\u{2019}", "\u{201A}", "\u{201B}", "\u{02BC}", "\u{FF07}", "\u{2032}"],
            "'",
            $name
        );
        $name = str_replace(
            ["\u{201C}", "\u{201D}", "\u{201E}", "\u{00AB}", "\u{00BB}", "\u{FF02}", "\u{2033}"],
            '"',
            $name
        );
        $name = str_replace(["\u{2010}", "\u{2011}", "\u{2012}", "\u{2013}", "\u{2014}", "\u{2212}"], '-', $name);

        // Transliterasi ke ASCII sebisanya (buang aksen dan aksara yang tak didukung mesin).
        $name = $this->transliterateToAscii($name);

        // Sisakan hanya karakter cetak ASCII, rapikan spasi.
        $name = preg_replace('/[^\x20-\x7E]/', '', $name) ?? '';
        $name = preg_replace('/\s+/', ' ', $name) ?? '';
        $name = trim($name);

        // Batasi panjang agar tidak melebihi field nama di memori mesin.
        if (strlen($name) > self::MAX_DEVICE_NAME_BYTES) {
            $name = rtrim(substr($name, 0, self::MAX_DEVICE_NAME_BYTES));
        }

        return $name;
    }

    /**
     * Transliterasi string UTF-8 ke ASCII memakai intl bila ada, lalu iconv sebagai cadangan.
     */
    protected function transliterateToAscii(string $value): string
    {
        if (function_exists('transliterator_transliterate')) {
            $converted = transliterator_transliterate('Any-Latin; Latin-ASCII;', $value);
            if (is_string($converted)) {
                return $converted;
            }
        }

        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
            if (is_string($converted)) {
                return $converted;
            }
        }

        return $value;
    }

    /**
     * Me-restart mesin fingerprint secara remote (<Restart>).
     */
    public function restartMachine(FingerprintDevice $device, ?int $operatorId = null): array
    {
        $port = $this->resolveWebPort($device);
        $commKey = $device->comm_key ?: '0';

        $soapRequest = "<Restart><ArgComKey Xsi:type=\"xsd:integer\">{$commKey}</ArgComKey></Restart>";
        $response = $this->sendSoapRequest($device->ip, $port, $soapRequest, 3.0);

        if (! $response) {
            $msg = "Gagal me-restart mesin {$device->name}: Mesin tidak merespons.";

            return ['success' => false, 'message' => $msg];
        }

        $info = $this->parseTag($response, '<Information>', '</Information>');
        $successMsg = "Perintah restart berhasil dikirim ke mesin {$device->name}".($info ? " ({$info})" : '').'. Mesin akan reboot dalam beberapa detik.';

        DeviceSyncLog::create([
            'device_id' => $device->id,
            'sync_type' => 'restart_device',
            'status' => 'success',
            'records_count' => 0,
            'message' => $successMsg,
            'details' => ['driver' => 'soap'],
            'operator_id' => $operatorId,
        ]);

        return ['success' => true, 'message' => $successMsg];
    }

    /**
     * Membersihkan riwayat log pada memori mesin fingerprint (<ClearData> value 3).
     */
    public function clearAttendanceLogs(FingerprintDevice $device, ?int $operatorId = null): array
    {
        $port = $this->resolveWebPort($device);
        $commKey = $device->comm_key ?: '0';

        $soapRequest = "<ClearData><ArgComKey xsi:type=\"xsd:integer\">{$commKey}</ArgComKey><Arg><Value xsi:type=\"xsd:integer\">3</Value></Arg></ClearData>";
        $response = $this->sendSoapRequest($device->ip, $port, $soapRequest, 4.0);

        if (! $response) {
            $msg = "Gagal membersihkan log mesin {$device->name}: Mesin tidak merespons.";

            return ['success' => false, 'message' => $msg];
        }

        $info = $this->parseTag($response, '<Information>', '</Information>');
        $successMsg = "Pembersihan log pada memori mesin {$device->name} berhasil".($info ? " ({$info})" : '').'.';

        DeviceSyncLog::create([
            'device_id' => $device->id,
            'sync_type' => 'clear_data',
            'status' => 'success',
            'records_count' => 0,
            'message' => $successMsg,
            'details' => ['driver' => 'soap'],
            'operator_id' => $operatorId,
        ]);

        return ['success' => true, 'message' => $successMsg];
    }

    /**
     * Menghapus user dari memori mesin fingerprint (<DeleteUser>).
     *
     * Aksi destruktif: hanya dianggap berhasil bila mesin membalas respons
     * DeleteUser yang valid dan tanpa indikasi error. `RefreshDB` dikirim hanya
     * setelah penghapusan terkonfirmasi sukses.
     */
    public function deleteUser(FingerprintDevice $device, string|int $pin, ?int $operatorId = null): array
    {
        $port = $this->resolveWebPort($device);
        $commKey = $device->comm_key ?: '0';

        $soapRequest = "<DeleteUser><ArgComKey xsi:type=\"xsd:integer\">{$commKey}</ArgComKey><Arg><PIN xsi:type=\"xsd:integer\">{$pin}</PIN></Arg></DeleteUser>";
        $response = $this->sendSoapRequest($device->ip, $port, $soapRequest, 3.0);

        if (! $response) {
            $msg = "Gagal menghapus user PIN {$pin} dari mesin {$device->name}: Mesin tidak merespons.";
            $this->logDeleteUserResult($device, $pin, false, $msg, $operatorId);

            return ['success' => false, 'message' => $msg];
        }

        $info = $this->parseTag($response, '<Information>', '</Information>');
        $hasValidResponse = str_contains($response, '<DeleteUserResponse>') || $info !== '';
        $isError = $info !== '' && stripos($info, 'error') !== false;

        if (! $hasValidResponse || $isError) {
            $detail = $info !== '' ? " ({$info})" : ' (respons tidak valid)';
            $msg = "Gagal menghapus user PIN {$pin} dari mesin {$device->name}{$detail}. Tidak ada perubahan yang dikonfirmasi mesin.";
            $this->logDeleteUserResult($device, $pin, false, $msg, $operatorId);

            return ['success' => false, 'message' => $msg];
        }

        // Segarkan database mesin hanya setelah penghapusan terkonfirmasi sukses.
        $refreshRequest = "<RefreshDB><ArgComKey xsi:type=\"xsd:integer\">{$commKey}</ArgComKey></RefreshDB>";
        $this->sendSoapRequest($device->ip, $port, $refreshRequest, 2.0);

        $successMsg = "User PIN {$pin} berhasil dihapus dari memori mesin {$device->name}".($info ? " ({$info})" : '').'.';
        $this->logDeleteUserResult($device, $pin, true, $successMsg, $operatorId);

        return ['success' => true, 'message' => $successMsg];
    }

    /**
     * Catat hasil operasi hapus user ke DeviceSyncLog (sukses maupun gagal).
     */
    protected function logDeleteUserResult(FingerprintDevice $device, string|int $pin, bool $success, string $message, ?int $operatorId): void
    {
        DeviceSyncLog::create([
            'device_id' => $device->id,
            'sync_type' => 'delete_user',
            'status' => $success ? 'success' : 'failed',
            'records_count' => $success ? 1 : 0,
            'message' => $message,
            'details' => ['driver' => 'soap', 'pin' => $pin],
            'operator_id' => $operatorId,
        ]);
    }

    /**
     * Mengambil template biner sidik jari user dari mesin (<GetUserTemplate>).
     */
    public function getUserTemplate(FingerprintDevice $device, string|int $pin, int $fingerId = 0, ?int $operatorId = null): array
    {
        $port = $this->resolveWebPort($device);
        $commKey = $device->comm_key ?: '0';

        $soapRequest = "<GetUserTemplate><ArgComKey xsi:type=\"xsd:integer\">{$commKey}</ArgComKey><Arg><PIN xsi:type=\"xsd:integer\">{$pin}</PIN><FingerID xsi:type=\"xsd:integer\">{$fingerId}</FingerID></Arg></GetUserTemplate>";
        $response = $this->sendSoapRequest($device->ip, $port, $soapRequest, 5.0);

        if (! $response) {
            return [
                'success' => false,
                'message' => "Mesin {$device->name} tidak merespons saat mengambil template sidik jari.",
            ];
        }

        $templateBlock = $this->parseTag($response, '<GetUserTemplateResponse>', '</GetUserTemplateResponse>');
        $template = trim($this->parseTag($templateBlock, '<Template>', '</Template>'));
        $size = (int) trim($this->parseTag($templateBlock, '<Size>', '</Size>'));
        $valid = (int) trim($this->parseTag($templateBlock, '<Valid>', '</Valid>'));

        if ($template === '') {
            return [
                'success' => false,
                'message' => "Tidak ada data sidik jari yang terdaftar pada mesin {$device->name} untuk PIN {$pin} (Jari #{$fingerId}).",
            ];
        }

        return [
            'success' => true,
            'template' => $template,
            'size' => $size > 0 ? $size : strlen($template),
            'valid' => $valid,
            'finger_id' => $fingerId,
            'message' => "Template sidik jari PIN {$pin} berhasil dibaca (Ukuran: ".strlen($template).' bytes).',
        ];
    }

    /**
     * Mengirimkan template biner sidik jari ke mesin (<SetUserTemplate>).
     */
    public function setUserTemplate(FingerprintDevice $device, string|int $pin, int $fingerId, string $template, ?int $operatorId = null): array
    {
        $port = $this->resolveWebPort($device);
        $commKey = $device->comm_key ?: '0';
        $size = strlen($template);

        $soapRequest = "<SetUserTemplate><ArgComKey xsi:type=\"xsd:integer\">{$commKey}</ArgComKey><Arg><PIN xsi:type=\"xsd:integer\">{$pin}</PIN><FingerID xsi:type=\"xsd:integer\">{$fingerId}</FingerID><Size>{$size}</Size><Valid>1</Valid><Template>{$template}</Template></Arg></SetUserTemplate>";
        $response = $this->sendSoapRequest($device->ip, $port, $soapRequest, 5.0);

        if (! $response) {
            return [
                'success' => false,
                'message' => "Mesin target {$device->name} tidak merespons saat pengiriman sidik jari.",
            ];
        }

        // Segarkan database mesin target
        $refreshRequest = "<RefreshDB><ArgComKey xsi:type=\"xsd:integer\">{$commKey}</ArgComKey></RefreshDB>";
        $this->sendSoapRequest($device->ip, $port, $refreshRequest, 2.0);

        $info = $this->parseTag($response, '<Information>', '</Information>');
        $successMsg = "Sidik jari PIN {$pin} (Jari #{$fingerId}) berhasil didistribusikan ke mesin {$device->name}".($info ? " ({$info})" : '').'.';

        DeviceSyncLog::create([
            'device_id' => $device->id,
            'sync_type' => 'push_template',
            'status' => 'success',
            'records_count' => 1,
            'message' => $successMsg,
            'details' => ['driver' => 'soap', 'pin' => $pin, 'finger_id' => $fingerId, 'size' => $size],
            'operator_id' => $operatorId,
        ]);

        return [
            'success' => true,
            'message' => $successMsg,
        ];
    }
}
