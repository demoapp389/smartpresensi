<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Pengaturan WhatsApp global (singleton).
 *
 * Hanya berisi pengaturan yang boleh diubah operator dari UI, bukan kredensial
 * gateway. Kredensial tetap dibaca dari file konfigurasi / `.env`.
 */
class WhatsAppSetting extends Model
{
    public const SCOPE_ALL = 'all';

    public const SCOPE_LATE = 'late';

    /**
     * @var list<string>
     */
    public const SCOPES = [self::SCOPE_ALL, self::SCOPE_LATE];

    protected $table = 'whatsapp_settings';

    protected $fillable = [
        'auto_notification_scope',
    ];

    protected $attributes = [
        'auto_notification_scope' => self::SCOPE_ALL,
    ];

    /**
     * Ambil baris pengaturan tunggal, buat bila belum ada.
     */
    public static function current(): self
    {
        return self::query()->orderBy('id')->first() ?? self::query()->create([]);
    }

    /**
     * Penerima notifikasi absensi otomatis tanpa membuat baris baru saat dibaca.
     */
    public static function autoNotificationScope(): string
    {
        $scope = self::query()->orderBy('id')->value('auto_notification_scope');

        return in_array($scope, self::SCOPES, true) ? $scope : self::SCOPE_ALL;
    }

    /**
     * Apakah notifikasi otomatis hanya ditujukan ke wali siswa terlambat.
     */
    public static function notifiesLateStudentsOnly(): bool
    {
        return self::autoNotificationScope() === self::SCOPE_LATE;
    }
}
