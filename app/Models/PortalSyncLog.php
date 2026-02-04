<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PortalSyncLog extends Model
{
    protected $table = 'portal_sync_logs';

    public $timestamps = false;

    protected $fillable = [
        'sync_id',
        'veiculo_id',
        'portal',
        'action',
        'result',
        'http_method',
        'endpoint',
        'http_status',
        'request_payload',
        'response_body',
        'error_message',
        'duration_ms',
        'created_at',
    ];

    protected $casts = [
        'request_payload' => 'array',
        'response_body' => 'array',
        'created_at' => 'datetime',
    ];

    const RESULT_SUCCESS = 'success';
    const RESULT_ERROR = 'error';
    const RESULT_SKIPPED = 'skipped';

    public function sync(): BelongsTo
    {
        return $this->belongsTo(VehiclePortalSync::class, 'sync_id');
    }

    public static function log(
        string $portal,
        string $action,
        string $result,
        array $data = []
    ): self {
        return self::create(array_merge([
            'portal' => $portal,
            'action' => $action,
            'result' => $result,
            'created_at' => now(),
        ], $data));
    }

    public function scopeForPortal($query, string $portal)
    {
        return $query->where('portal', $portal);
    }

    public function scopeErrors($query)
    {
        return $query->where('result', self::RESULT_ERROR);
    }

    public function scopeRecent($query, int $hours = 24)
    {
        return $query->where('created_at', '>=', now()->subHours($hours));
    }
}
