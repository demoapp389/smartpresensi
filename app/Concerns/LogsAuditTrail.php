<?php

namespace App\Concerns;

use App\Models\AuditLog;
use Illuminate\Support\Facades\Auth;

/**
 * Helper pencatatan jejak audit agar bentuk payload seragam di seluruh aplikasi
 * dan tidak mengulang boilerplate actor/IP di setiap lokasi.
 */
trait LogsAuditTrail
{
    /**
     * Catat satu baris audit log.
     *
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    protected function logAudit(
        string $action,
        string $entityType,
        int|string|null $entityId = null,
        ?array $before = null,
        ?array $after = null,
        ?int $actorId = null,
        ?string $ipAddress = null,
    ): AuditLog {
        return AuditLog::create([
            'actor_id' => $actorId ?? Auth::id(),
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'before' => $before,
            'after' => $after,
            'ip_address' => $ipAddress ?? request()->ip(),
            'created_at' => now(),
        ]);
    }
}
