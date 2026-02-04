<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PortalLead extends Model
{
    protected $table = 'portal_leads';

    protected $fillable = [
        'veiculo_id',
        'anunciante_id',
        'portal',
        'external_lead_id',
        'external_ad_id',
        'name',
        'email',
        'phone',
        'message',
        'extra_data',
        'status',
        'received_at',
    ];

    protected $casts = [
        'extra_data' => 'array',
        'received_at' => 'datetime',
    ];

    const STATUS_NEW = 'new';
    const STATUS_CONTACTED = 'contacted';
    const STATUS_NEGOTIATING = 'negotiating';
    const STATUS_CONVERTED = 'converted';
    const STATUS_LOST = 'lost';
    const STATUS_SPAM = 'spam';

    public function scopeForPortal($query, string $portal)
    {
        return $query->where('portal', $portal);
    }

    public function scopeNew($query)
    {
        return $query->where('status', self::STATUS_NEW);
    }

    public function scopeForVehicle($query, int $veiculoId)
    {
        return $query->where('veiculo_id', $veiculoId);
    }

    public function scopeForAnunciante($query, int $anuncianteId)
    {
        return $query->where('anunciante_id', $anuncianteId);
    }

    /**
     * Check if this lead already exists (to avoid duplicates)
     */
    public static function isDuplicate(string $portal, string $externalLeadId): bool
    {
        return self::where('portal', $portal)
            ->where('external_lead_id', $externalLeadId)
            ->exists();
    }
}
