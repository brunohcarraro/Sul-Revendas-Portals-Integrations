<?php

namespace App\Services;

use App\Models\PortalLead;
use App\Models\VehiclePortalSync;
use App\Services\Portals\Contracts\PortalAdapterInterface;
use App\Services\Portals\WebMotors\WebMotorsAdapter;
use App\Services\Portals\WebMotors\WebMotorsSoapAdapter;
use App\Services\Portals\OLX\OlxAdapter;
use App\Services\Portals\MercadoLivre\MercadoLivreAdapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PortalSyncService
{
    protected array $adapters = [];

    public function __construct()
    {
        $this->registerAdapters();
    }

    protected function registerAdapters(): void
    {
        $this->adapters = [
            'webmotors' => WebMotorsSoapAdapter::class, // Using SOAP adapter for dealers
            'webmotors_rest' => WebMotorsAdapter::class, // REST API for channels
            'olx' => OlxAdapter::class,
            'mercadolivre' => MercadoLivreAdapter::class,
        ];
    }

    /**
     * Get adapter instance for a portal
     * Credentials come from config('portals.{portal}') - NOT from database
     */
    public function getAdapter(string $portal, ?int $anuncianteId = null): ?PortalAdapterInterface
    {
        if (!isset($this->adapters[$portal])) {
            Log::error("Unknown portal: {$portal}");
            return null;
        }

        // Get config for portal - SINGLE SOURCE OF TRUTH
        $config = config("portals.{$portal}");

        if (!$config) {
            Log::error("No config found for portal: {$portal}. Check config/portals.php and .env");
            return null;
        }

        // Create adapter - it reads config in constructor
        $adapter = new $this->adapters[$portal]();

        // Authenticate if needed
        if (!$adapter->isAuthenticated()) {
            if (!$adapter->authenticate()) {
                Log::error("Failed to authenticate with portal: {$portal}");
                return null;
            }
        }

        return $adapter;
    }

    /**
     * Sync a vehicle to a specific portal
     */
    public function syncVehicleToPortal(int $veiculoId, string $portal): array
    {
        $adapter = $this->getAdapter($portal);

        if (!$adapter) {
            return ['success' => false, 'error' => 'Could not initialize portal adapter'];
        }

        // Get vehicle data from database
        $vehicle = $this->getVehicleData($veiculoId);

        if (!$vehicle) {
            return ['success' => false, 'error' => 'Vehicle not found'];
        }

        // Get or create sync record
        $sync = VehiclePortalSync::firstOrCreate(
            ['veiculo_id' => $veiculoId, 'portal' => $portal],
            ['status' => VehiclePortalSync::STATUS_PENDING]
        );

        // Check if vehicle needs update by comparing content hash
        $transformedData = $adapter->transformVehicleData($vehicle);
        $newHash = md5(json_encode($transformedData));

        if ($sync->status === VehiclePortalSync::STATUS_PUBLISHED && $sync->content_hash === $newHash) {
            return ['success' => true, 'message' => 'Vehicle already up to date'];
        }

        // Determine action
        if ($sync->external_id && $sync->status === VehiclePortalSync::STATUS_PUBLISHED) {
            // Update existing
            $result = $adapter->updateVehicle($sync->external_id, $transformedData);
            $sync->update([
                'last_action' => VehiclePortalSync::ACTION_UPDATE,
                'last_payload' => $transformedData,
            ]);
        } else {
            // Publish new
            $sync->markAsPublishing();
            $result = $adapter->publishVehicle($transformedData);
            $sync->update(['last_payload' => $transformedData]);
        }

        if ($result['success']) {
            $sync->markAsPublished(
                $result['external_id'] ?? $sync->external_id,
                $result['url'] ?? $sync->external_url
            );
            $sync->update(['content_hash' => $newHash]);
        } else {
            $sync->markAsError($result['error'] ?? 'Unknown error');
        }

        return $result;
    }

    /**
     * Remove a vehicle from a portal
     */
    public function removeVehicleFromPortal(int $veiculoId, string $portal): array
    {
        $adapter = $this->getAdapter($portal);

        if (!$adapter) {
            return ['success' => false, 'error' => 'Could not initialize portal adapter'];
        }

        $sync = VehiclePortalSync::where('veiculo_id', $veiculoId)
            ->where('portal', $portal)
            ->first();

        if (!$sync || !$sync->external_id) {
            return ['success' => false, 'error' => 'Vehicle not found on portal'];
        }

        $sync->update([
            'status' => VehiclePortalSync::STATUS_REMOVING,
            'last_action' => VehiclePortalSync::ACTION_REMOVE,
        ]);

        $result = $adapter->removeVehicle($sync->external_id);

        if ($result['success']) {
            $sync->markAsRemoved();
        } else {
            $sync->markAsError($result['error'] ?? 'Failed to remove');
        }

        return $result;
    }

    /**
     * Fetch and store leads from a portal
     */
    public function fetchLeadsFromPortal(string $portal, array $filters = []): array
    {
        $adapter = $this->getAdapter($portal);

        if (!$adapter) {
            return ['success' => false, 'error' => 'Could not initialize portal adapter', 'count' => 0];
        }

        $result = $adapter->fetchLeads($filters);

        if (!$result['success']) {
            return ['success' => false, 'error' => $result['error'], 'count' => 0];
        }

        $count = 0;
        foreach ($result['leads'] as $leadData) {
            if ($this->storeLead($portal, $leadData)) {
                $count++;
            }
        }

        return ['success' => true, 'count' => $count, 'error' => null];
    }

    /**
     * Store a lead if it doesn't already exist
     */
    protected function storeLead(string $portal, array $leadData): bool
    {
        $externalId = $leadData['id'] ?? $leadData['lead_id'] ?? null;

        if ($externalId && PortalLead::isDuplicate($portal, $externalId)) {
            return false;
        }

        // Try to match with internal vehicle
        $veiculoId = $this->matchVehicleFromLead($portal, $leadData);

        PortalLead::create([
            'portal' => $portal,
            'external_lead_id' => $externalId,
            'external_ad_id' => $leadData['ad_id'] ?? $leadData['anuncio_id'] ?? null,
            'veiculo_id' => $veiculoId,
            'name' => $leadData['name'] ?? $leadData['nome'] ?? null,
            'email' => $leadData['email'] ?? null,
            'phone' => $leadData['phone'] ?? $leadData['telefone'] ?? null,
            'message' => $leadData['message'] ?? $leadData['mensagem'] ?? null,
            'extra_data' => $leadData,
            'received_at' => $leadData['created_at'] ?? $leadData['data'] ?? now(),
        ]);

        return true;
    }

    /**
     * Try to match a lead to an internal vehicle
     */
    protected function matchVehicleFromLead(string $portal, array $leadData): ?int
    {
        $externalAdId = $leadData['ad_id'] ?? $leadData['anuncio_id'] ?? null;

        if (!$externalAdId) {
            return null;
        }

        $sync = VehiclePortalSync::where('portal', $portal)
            ->where('external_id', $externalAdId)
            ->first();

        return $sync?->veiculo_id;
    }

    /**
     * Get vehicle data with relations from the existing database
     */
    protected function getVehicleData(int $veiculoId): ?array
    {
        $vehicle = DB::table('tb_veiculos')
            ->where('veiculo_id', $veiculoId)
            ->first();

        if (!$vehicle) {
            return null;
        }

        $data = (array) $vehicle;

        // Load relations
        if ($vehicle->cor_id) {
            $data['cor'] = (array) DB::table('tb_cores')->where('cor_id', $vehicle->cor_id)->first();
        }

        if ($vehicle->combustivel_id) {
            $data['combustivel'] = (array) DB::table('tb_combustiveis')->where('combustivel_id', $vehicle->combustivel_id)->first();
        }

        if ($vehicle->cambio_id) {
            $data['cambio'] = (array) DB::table('tb_cambios')->where('cambio_id', $vehicle->cambio_id)->first();
        }

        if ($vehicle->carroceria_id) {
            $data['carroceria'] = (array) DB::table('tb_carrocerias')->where('carroceria_id', $vehicle->carroceria_id)->first();
        }

        // Load accessories
        $data['acessorios'] = DB::table('tb_acessorios')
            ->join('tb_acessorios_veiculos', 'tb_acessorios.acessorio_id', '=', 'tb_acessorios_veiculos.acessorio_id')
            ->where('tb_acessorios_veiculos.veiculo_id', $veiculoId)
            ->pluck('acessorio_nome')
            ->toArray();

        // Load images
        $data['imagens'] = DB::table('tb_galeria')
            ->where('imagem_veiculo', $veiculoId)
            ->whereNull('deleted_at')
            ->orderBy('ordem')
            ->get()
            ->toArray();

        return $data;
    }
}
