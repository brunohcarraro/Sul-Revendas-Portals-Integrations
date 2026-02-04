<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VehiclePortalSync extends Model
{
    protected $table = 'vehicle_portal_sync';

    protected $fillable = [
        'veiculo_id',
        'portal',
        'external_id',
        'external_url',
        'status',
        'last_action',
        'last_error',
        'last_payload',
        'last_response',
        'content_hash',
        'last_sync_at',
        'published_at',
    ];

    protected $casts = [
        'last_payload' => 'array',
        'last_response' => 'array',
        'last_sync_at' => 'datetime',
        'published_at' => 'datetime',
    ];

    const STATUS_PENDING = 'pending';
    const STATUS_PUBLISHING = 'publishing';
    const STATUS_PUBLISHED = 'published';
    const STATUS_UPDATING = 'updating';
    const STATUS_REMOVING = 'removing';
    const STATUS_REMOVED = 'removed';
    const STATUS_ERROR = 'error';
    const STATUS_PAUSED = 'paused';

    const ACTION_CREATE = 'create';
    const ACTION_UPDATE = 'update';
    const ACTION_REMOVE = 'remove';
    const ACTION_PAUSE = 'pause';
    const ACTION_ACTIVATE = 'activate';

    public function logs(): HasMany
    {
        return $this->hasMany(PortalSyncLog::class, 'sync_id');
    }

    public function scopeForPortal($query, string $portal)
    {
        return $query->where('portal', $portal);
    }

    public function scopeWithStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopePublished($query)
    {
        return $query->where('status', self::STATUS_PUBLISHED);
    }

    public function scopeWithErrors($query)
    {
        return $query->where('status', self::STATUS_ERROR);
    }

    public function markAsPublishing(): void
    {
        $this->update([
            'status' => self::STATUS_PUBLISHING,
            'last_action' => self::ACTION_CREATE,
        ]);
    }

    public function markAsPublished(string $externalId, ?string $url = null): void
    {
        $this->update([
            'status' => self::STATUS_PUBLISHED,
            'external_id' => $externalId,
            'external_url' => $url,
            'last_error' => null,
            'last_sync_at' => now(),
            'published_at' => now(),
        ]);
    }

    public function markAsError(string $error, ?array $response = null): void
    {
        $this->update([
            'status' => self::STATUS_ERROR,
            'last_error' => $error,
            'last_response' => $response,
            'last_sync_at' => now(),
        ]);
    }

    public function markAsRemoved(): void
    {
        $this->update([
            'status' => self::STATUS_REMOVED,
            'last_action' => self::ACTION_REMOVE,
            'last_sync_at' => now(),
        ]);
    }
}
