<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class WhatsAppTemplate extends Model
{
    use HasFactory;

    protected $table = 'whatsapp_templates';

    protected $fillable = [
        'event_type',
        'name',
        'content',
        'description',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saved(function (WhatsAppTemplate $template) {
            Cache::forget("wa_template_{$template->event_type}");
            Cache::forget('wa_templates_all');
        });

        static::deleted(function (WhatsAppTemplate $template) {
            Cache::forget("wa_template_{$template->event_type}");
            Cache::forget('wa_templates_all');
        });
    }

    /**
     * Dapatkan teks template aktif berdasarkan event_type.
     * Jika tidak ada di database, gunakan fallback dari config/whatsapp.php.
     */
    public static function getTemplate(string $eventType): string
    {
        $cached = Cache::rememberForever("wa_template_{$eventType}", function () use ($eventType) {
            $tpl = static::where('event_type', $eventType)
                ->where('is_active', true)
                ->first();

            return $tpl?->content;
        });

        if (! empty($cached)) {
            return $cached;
        }

        return (string) config("whatsapp.templates.{$eventType}", config('whatsapp.templates.default', ''));
    }
}
