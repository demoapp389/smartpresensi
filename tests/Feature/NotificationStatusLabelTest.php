<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class NotificationStatusLabelTest extends TestCase
{
    public function test_notification_status_component_uses_consistent_indonesian_labels(): void
    {
        $labels = [
            'queued' => 'Antrean',
            'sent' => 'Terkirim',
            'delivered' => 'Diterima',
            'read' => 'Dibaca',
            'held' => 'Ditahan',
            'cancelled' => 'Dibatalkan',
            'failed' => 'Gagal',
        ];

        foreach ($labels as $status => $label) {
            $html = Blade::render('<x-notification-status :status="$status" />', [
                'status' => $status,
            ]);

            $this->assertStringContainsString($label, $html);
        }
    }
}
