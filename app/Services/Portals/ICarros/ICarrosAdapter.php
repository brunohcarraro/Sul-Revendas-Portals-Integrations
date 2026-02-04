<?php

namespace App\Services\Portals\ICarros;

use App\Models\PortalSyncLog;
use App\Services\Portals\Contracts\PortalAdapterInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ICarrosAdapter implements PortalAdapterInterface
{
    protected array $config;
    protected ?string $accessToken = null;
    protected ?string $refreshToken = null;
    protected ?int $dealerId = null;

    // Cached database mappings
    protected ?array $cachedMakes = null;
    protected ?array $cachedColors = null;
    protected ?array $cachedFuels = null;
    protected ?array $cachedTransmissions = null;
    protected ?array $cachedEquipments = null;

    public function __construct()
    {
        $this->config = config('portals.icarros');
    }

    public function getPortalName(): string
    {
        return 'icarros';
    }

    public function getBaseUrl(): string
    {
        return $this->config['urls']['api'];
    }

    public function getAuthUrl(): string
    {
        return $this->config['urls']['auth'];
    }

    public function setCredentials($credentials): self
    {
        return $this;
    }

    public function setAccessToken(string $token): self
    {
        $this->accessToken = $token;
        return $this;
    }

    public function setRefreshToken(string $token): self
    {
        $this->refreshToken = $token;
        return $this;
    }

    public function setDealerId(int $dealerId): self
    {
        $this->dealerId = $dealerId;
        return $this;
    }

    public function authenticate(): bool
    {
        $this->accessToken = $this->accessToken ?? $this->config['access_token'] ?? null;
        $this->refreshToken = $this->refreshToken ?? $this->config['refresh_token'] ?? null;
        $this->dealerId = $this->dealerId ?? ($this->config['dealer_id'] ? (int) $this->config['dealer_id'] : null);
        return $this->accessToken !== null;
    }

    public function isAuthenticated(): bool
    {
        return $this->accessToken !== null;
    }

    // ========================================
    // OAuth Authentication (Keycloak)
    // ========================================

    /**
     * Get initial token using login credentials (backend auth)
     * This is the primary authentication method for iCarros
     */
    public function getToken(string $username, string $password): array
    {
        $startTime = microtime(true);

        try {
            $response = Http::asForm()
                ->withHeaders([
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ])
                ->post($this->getAuthUrl() . '/token', [
                    'grant_type' => 'password',
                    'client_id' => $this->config['client_id'],
                    'client_secret' => $this->config['client_secret'],
                    'username' => $username,
                    'password' => $password,
                ]);

            $durationMs = (int) ((microtime(true) - $startTime) * 1000);
            $data = $response->json();

            $this->logRequest('POST', '/token', $response->status(), ['grant_type' => 'password'], $data, $response->successful(), $durationMs);

            if ($response->successful()) {
                $this->accessToken = $data['access_token'];
                $this->refreshToken = $data['refresh_token'] ?? null;

                return [
                    'success' => true,
                    'access_token' => $data['access_token'],
                    'refresh_token' => $data['refresh_token'] ?? null,
                    'expires_in' => $data['expires_in'] ?? 300,
                    'refresh_expires_in' => $data['refresh_expires_in'] ?? 5184000,
                ];
            }

            return [
                'success' => false,
                'error' => $data['error_description'] ?? $data['error'] ?? 'Authentication failed',
            ];

        } catch (\Exception $e) {
            Log::error('iCarros: Authentication exception', ['error' => $e->getMessage()]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Refresh access token using refresh_token
     */
    public function refreshTokenAuth(?string $refreshToken = null): array
    {
        $token = $refreshToken ?? $this->refreshToken;

        if (!$token) {
            return ['success' => false, 'error' => 'No refresh token available'];
        }

        $startTime = microtime(true);

        try {
            $response = Http::asForm()
                ->withHeaders([
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ])
                ->post($this->getAuthUrl() . '/token', [
                    'grant_type' => 'refresh_token',
                    'client_id' => $this->config['client_id'],
                    'client_secret' => $this->config['client_secret'],
                    'refresh_token' => $token,
                ]);

            $durationMs = (int) ((microtime(true) - $startTime) * 1000);
            $data = $response->json();

            $this->logRequest('POST', '/token (refresh)', $response->status(), ['grant_type' => 'refresh_token'], $data, $response->successful(), $durationMs);

            if ($response->successful()) {
                $this->accessToken = $data['access_token'];
                $this->refreshToken = $data['refresh_token'] ?? $token;

                return [
                    'success' => true,
                    'access_token' => $data['access_token'],
                    'refresh_token' => $data['refresh_token'] ?? $token,
                    'expires_in' => $data['expires_in'] ?? 300,
                ];
            }

            return [
                'success' => false,
                'error' => $data['error_description'] ?? $data['error'] ?? 'Token refresh failed',
            ];

        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    // ========================================
    // API Request Helper
    // ========================================

    protected function apiRequest(string $method, string $endpoint, array $data = [], array $query = []): array
    {
        if (!$this->accessToken) {
            return ['success' => false, 'data' => null, 'error' => 'No access token'];
        }

        $startTime = microtime(true);
        $url = $this->getBaseUrl() . '/pj/v1/core' . $endpoint;

        try {
            $request = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->accessToken,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ]);

            $response = match (strtoupper($method)) {
                'GET' => $request->get($url, $query ?: $data),
                'POST' => $request->post($url, $data),
                'PUT' => $request->put($url, $data),
                'DELETE' => $request->delete($url),
                default => throw new \InvalidArgumentException("Invalid method: {$method}"),
            };

            $durationMs = (int) ((microtime(true) - $startTime) * 1000);
            $body = $response->json();

            $this->logRequest($method, $endpoint, $response->status(), $data, $body, $response->successful(), $durationMs);

            if ($response->successful()) {
                return [
                    'success' => true,
                    'data' => $body,
                    'error' => null,
                ];
            }

            return [
                'success' => false,
                'data' => $body,
                'error' => $this->parseError($body),
            ];

        } catch (\Exception $e) {
            $durationMs = (int) ((microtime(true) - $startTime) * 1000);
            $this->logRequest($method, $endpoint, null, $data, null, false, $durationMs, $e->getMessage());

            return ['success' => false, 'data' => null, 'error' => $e->getMessage()];
        }
    }

    protected function logRequest(
        string $method,
        string $endpoint,
        ?int $status,
        array $payload,
        ?array $response,
        bool $success,
        int $durationMs,
        ?string $error = null
    ): void {
        PortalSyncLog::log(
            $this->getPortalName(),
            $method . ' ' . $endpoint,
            $success ? 'success' : 'error',
            [
                'http_method' => $method,
                'endpoint' => $endpoint,
                'http_status' => $status,
                'request_payload' => $payload,
                'response_body' => $response,
                'error_message' => $error,
                'duration_ms' => $durationMs,
            ]
        );
    }

    // ========================================
    // Database/Reference Data Methods
    // ========================================

    /**
     * Get categories (only CARROS supported)
     */
    public function getCategories(): array
    {
        $result = $this->apiRequest('GET', '/database/categories');

        if (!$result['success']) {
            return ['success' => false, 'data' => [], 'error' => $result['error']];
        }

        return ['success' => true, 'data' => $result['data'] ?? []];
    }

    /**
     * Get available colors
     */
    public function getColors(): array
    {
        $result = $this->apiRequest('GET', '/database/colors');

        if (!$result['success']) {
            return ['success' => false, 'data' => [], 'error' => $result['error']];
        }

        $this->cachedColors = $result['data'] ?? [];
        return ['success' => true, 'data' => $this->cachedColors];
    }

    /**
     * Get available equipment/optionals
     */
    public function getEquipments(): array
    {
        $result = $this->apiRequest('GET', '/database/equipments');

        if (!$result['success']) {
            return ['success' => false, 'data' => [], 'error' => $result['error']];
        }

        $this->cachedEquipments = $result['data'] ?? [];
        return ['success' => true, 'data' => $this->cachedEquipments];
    }

    /**
     * Get fuel types
     */
    public function getFuels(): array
    {
        $result = $this->apiRequest('GET', '/database/fuels');

        if (!$result['success']) {
            return ['success' => false, 'data' => [], 'error' => $result['error']];
        }

        $this->cachedFuels = $result['data'] ?? [];
        return ['success' => true, 'data' => $this->cachedFuels];
    }

    /**
     * Get transmissions
     */
    public function getTransmissions(): array
    {
        $result = $this->apiRequest('GET', '/database/transmissions');

        if (!$result['success']) {
            return ['success' => false, 'data' => [], 'error' => $result['error']];
        }

        $this->cachedTransmissions = $result['data'] ?? [];
        return ['success' => true, 'data' => $this->cachedTransmissions];
    }

    /**
     * Get brands/makes
     */
    public function getMakes(): array
    {
        $result = $this->apiRequest('GET', '/database/makes');

        if (!$result['success']) {
            return ['success' => false, 'data' => [], 'error' => $result['error']];
        }

        $this->cachedMakes = $result['data'] ?? [];
        return ['success' => true, 'data' => $this->cachedMakes];
    }

    /**
     * Get models for a brand
     */
    public function getModels(int $makeId): array
    {
        $result = $this->apiRequest('GET', '/database/models', [], ['makeId' => $makeId]);

        if (!$result['success']) {
            return ['success' => false, 'data' => [], 'error' => $result['error']];
        }

        return ['success' => true, 'data' => $result['data'] ?? []];
    }

    /**
     * Get trims/versions for a model and year
     */
    public function getTrims(int $makeId, int $modelId, int $modelYear): array
    {
        $result = $this->apiRequest('GET', '/database/trims', [], [
            'makeId' => $makeId,
            'modelId' => $modelId,
            'modelYear' => $modelYear,
        ]);

        if (!$result['success']) {
            return ['success' => false, 'data' => [], 'error' => $result['error']];
        }

        return ['success' => true, 'data' => $result['data'] ?? []];
    }

    // ========================================
    // Dealer Methods
    // ========================================

    /**
     * Get all dealers accessible by current token
     */
    public function getDealers(): array
    {
        $result = $this->apiRequest('GET', '/dealers');

        if (!$result['success']) {
            return ['success' => false, 'data' => [], 'error' => $result['error']];
        }

        return ['success' => true, 'data' => $result['data'] ?? []];
    }

    /**
     * Get dealer info
     */
    public function getDealer(?int $dealerId = null): array
    {
        $id = $dealerId ?? $this->dealerId;

        if (!$id) {
            return ['success' => false, 'data' => null, 'error' => 'Dealer ID not set'];
        }

        $result = $this->apiRequest('GET', "/dealers/{$id}");

        return [
            'success' => $result['success'],
            'data' => $result['data'] ?? null,
            'error' => $result['error'],
        ];
    }

    /**
     * Get dealer's available plans
     */
    public function getPlans(?int $dealerId = null, bool $zeroKm = false): array
    {
        $id = $dealerId ?? $this->dealerId;

        if (!$id) {
            return ['success' => false, 'data' => [], 'error' => 'Dealer ID not set'];
        }

        $result = $this->apiRequest('GET', "/dealers/{$id}/plans", [], [
            'segmento' => 'Carro',
            'zeroKm' => $zeroKm ? 'true' : 'false',
        ]);

        if (!$result['success']) {
            return ['success' => false, 'data' => [], 'error' => $result['error']];
        }

        return ['success' => true, 'data' => $result['data'] ?? []];
    }

    /**
     * Get dealer's inventory
     */
    public function getInventory(?int $dealerId = null): array
    {
        $id = $dealerId ?? $this->dealerId;

        if (!$id) {
            return ['success' => false, 'data' => [], 'error' => 'Dealer ID not set'];
        }

        $result = $this->apiRequest('GET', "/dealers/{$id}/inventory");

        if (!$result['success']) {
            return ['success' => false, 'data' => [], 'error' => $result['error']];
        }

        return ['success' => true, 'data' => $result['data'] ?? []];
    }

    /**
     * Get dealer's leads
     */
    public function getLeads(?int $dealerId = null, ?string $startDate = null, ?string $endDate = null): array
    {
        $id = $dealerId ?? $this->dealerId;

        if (!$id) {
            return ['success' => false, 'data' => [], 'error' => 'Dealer ID not set'];
        }

        $query = [];
        if ($startDate) {
            $query['startDate'] = $startDate;
        }
        if ($endDate) {
            $query['endDate'] = $endDate;
        }

        $result = $this->apiRequest('GET', "/dealers/{$id}/leads", [], $query);

        if (!$result['success']) {
            return ['success' => false, 'data' => [], 'error' => $result['error']];
        }

        return ['success' => true, 'data' => $result['data'] ?? []];
    }

    // ========================================
    // Vehicle CRUD Operations
    // ========================================

    public function publishVehicle(array $vehicleData): array
    {
        if (!$this->dealerId) {
            return [
                'success' => false,
                'external_id' => null,
                'url' => null,
                'error' => 'Dealer ID not set',
            ];
        }

        $payload = $this->transformVehicleData($vehicleData);
        $payload['dealerId'] = $this->dealerId;

        $result = $this->apiRequest('POST', '/deals', $payload);

        if (!$result['success']) {
            return [
                'success' => false,
                'external_id' => null,
                'url' => null,
                'error' => $result['error'],
            ];
        }

        $dealId = $result['data']['id'] ?? null;

        // Upload images if present
        if ($dealId && !empty($vehicleData['imagens'])) {
            $this->uploadImages($dealId, $vehicleData['imagens']);
        }

        return [
            'success' => true,
            'external_id' => $dealId ? (string) $dealId : null,
            'url' => $dealId ? "https://www.icarros.com.br/anuncio/{$dealId}" : null,
            'error' => null,
        ];
    }

    public function updateVehicle(string $externalId, array $vehicleData): array
    {
        $payload = $this->transformVehicleData($vehicleData);

        $result = $this->apiRequest('PUT', "/deals/{$externalId}", $payload);

        return [
            'success' => $result['success'],
            'error' => $result['error'],
        ];
    }

    public function removeVehicle(string $externalId): array
    {
        $result = $this->apiRequest('DELETE', "/deals/{$externalId}");

        return [
            'success' => $result['success'],
            'error' => $result['error'],
        ];
    }

    public function updateVehicleStatus(string $externalId, string $status): array
    {
        // iCarros doesn't have a direct status update - vehicles are either active or deleted
        // For "sold" or "paused", we would remove the vehicle
        if (in_array($status, ['sold', 'paused', 'inactive'])) {
            return $this->removeVehicle($externalId);
        }

        return ['success' => true, 'error' => null];
    }

    /**
     * Get a specific deal/ad
     */
    public function getDeal(string $dealId): array
    {
        $result = $this->apiRequest('GET', "/deals/{$dealId}");

        return [
            'success' => $result['success'],
            'data' => $result['data'] ?? null,
            'error' => $result['error'],
        ];
    }

    /**
     * Update deal price
     */
    public function updatePrice(string $dealId, float $price): array
    {
        $result = $this->apiRequest('PUT', "/deals/{$dealId}/price", [
            'id' => (int) $dealId,
            'price' => $price,
        ]);

        return [
            'success' => $result['success'],
            'error' => $result['error'],
        ];
    }

    /**
     * Toggle spotlight/super oferta
     */
    public function updateSpotlight(string $dealId, bool $spotlight): array
    {
        $result = $this->apiRequest('PUT', "/deals/{$dealId}/spotlight", [
            'id' => (int) $dealId,
            'spotlight' => $spotlight,
        ]);

        return [
            'success' => $result['success'],
            'error' => $result['error'],
        ];
    }

    // ========================================
    // Image Management
    // ========================================

    /**
     * Upload images to a deal
     */
    public function uploadImages(string $dealId, array $images): array
    {
        $uploaded = [];
        $errors = [];

        $imageBaseUrl = config('portals.images.base_url') . config('portals.images.path_prefix');

        foreach ($images as $img) {
            $imgName = $img->imagem_nome ?? $img['imagem_nome'] ?? null;
            if (!$imgName) continue;

            $imageUrl = $imageBaseUrl . $imgName;

            $result = $this->apiRequest('POST', "/deals/{$dealId}/images", [
                'photo' => $imageUrl,
            ]);

            if ($result['success']) {
                $uploaded[] = $result['data']['id'] ?? $imgName;
            } else {
                $errors[] = "Failed to upload {$imgName}: " . $result['error'];
            }
        }

        return [
            'success' => empty($errors),
            'uploaded' => $uploaded,
            'errors' => $errors,
        ];
    }

    /**
     * Delete all images from a deal
     */
    public function deleteAllImages(string $dealId): array
    {
        $result = $this->apiRequest('DELETE', "/deals/{$dealId}/images");

        return [
            'success' => $result['success'],
            'error' => $result['error'],
        ];
    }

    /**
     * Delete specific image
     */
    public function deleteImage(string $dealId, string $imageId): array
    {
        $result = $this->apiRequest('DELETE', "/deals/{$dealId}/images/{$imageId}");

        return [
            'success' => $result['success'],
            'error' => $result['error'],
        ];
    }

    /**
     * Order images
     */
    public function orderImages(string $dealId, array $imageIds): array
    {
        $result = $this->apiRequest('PUT', "/deals/{$dealId}/orderImages", [
            'array_photos' => implode('_', $imageIds),
            'dealId' => (int) $dealId,
            'dealerId' => $this->dealerId,
        ]);

        return [
            'success' => $result['success'],
            'error' => $result['error'],
        ];
    }

    // ========================================
    // Interface Methods
    // ========================================

    public function fetchLeads(array $filters = []): array
    {
        $startDate = $filters['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
        $endDate = $filters['end_date'] ?? date('Y-m-d');

        $result = $this->getLeads(null, $startDate, $endDate);

        if (!$result['success']) {
            return [
                'success' => false,
                'leads' => [],
                'error' => $result['error'],
            ];
        }

        $leads = [];
        foreach ($result['data'] as $lead) {
            $leads[] = [
                'id' => $lead['id'] ?? null,
                'deal_id' => $lead['dealId'] ?? $lead['deal_id'] ?? null,
                'name' => $lead['name'] ?? $lead['nome'] ?? null,
                'email' => $lead['email'] ?? null,
                'phone' => $lead['phone'] ?? $lead['telefone'] ?? null,
                'message' => $lead['message'] ?? $lead['mensagem'] ?? null,
                'date' => $lead['date'] ?? $lead['data'] ?? null,
            ];
        }

        return [
            'success' => true,
            'leads' => $leads,
            'error' => null,
        ];
    }

    public function getPublishedVehicles(): array
    {
        $result = $this->getInventory();

        if (!$result['success']) {
            return [
                'success' => false,
                'vehicles' => [],
                'error' => $result['error'],
            ];
        }

        return [
            'success' => true,
            'vehicles' => $result['data'] ?? [],
            'error' => null,
        ];
    }

    // ========================================
    // Data Transformation
    // ========================================

    public function transformVehicleData(array $vehicle): array
    {
        // Get IDs by name matching
        $makeId = $this->findMakeByName($vehicle['fipe_marca_nome'] ?? $vehicle['kbb_marca_nome'] ?? '');
        $modelId = $makeId ? $this->findModelByName($makeId, $vehicle['fipe_modelo_nome'] ?? $vehicle['kbb_modelo_nome'] ?? '') : null;
        $modelYear = (int) ($vehicle['veiculo_ano_modelo'] ?? date('Y'));
        $trimId = ($makeId && $modelId) ? $this->findTrimByName($makeId, $modelId, $modelYear, $vehicle['fipe_versao_nome'] ?? $vehicle['kbb_versao_nome'] ?? '') : null;

        $colorId = $this->findColorByName($vehicle['cor']['nome'] ?? '');
        $fuelId = $this->findFuelByName($vehicle['combustivel']['nome'] ?? '');
        $transmissionId = $this->findTransmissionByName($vehicle['cambio']['nome'] ?? '');

        // Build equipment IDs
        $equipmentIds = [];
        if (!empty($vehicle['opcionais'])) {
            foreach ($vehicle['opcionais'] as $opcional) {
                $name = $opcional['nome'] ?? $opcional->nome ?? null;
                if ($name) {
                    $equipmentId = $this->findEquipmentByName($name);
                    if ($equipmentId) {
                        $equipmentIds[] = $equipmentId;
                    }
                }
            }
        }

        // Determine priority from plans (default to 3 = comum)
        $priority = $vehicle['priority'] ?? 3;

        return [
            'text' => $vehicle['veiculo_descricao'] ?? '',
            'productionYear' => (int) ($vehicle['veiculo_ano_fabricacao'] ?? $vehicle['veiculo_ano_modelo'] ?? date('Y')),
            'modelYear' => $modelYear,
            'makeId' => $makeId,
            'makeName' => $vehicle['fipe_marca_nome'] ?? $vehicle['kbb_marca_nome'] ?? '',
            'modelId' => $modelId,
            'modelName' => $vehicle['fipe_modelo_nome'] ?? $vehicle['kbb_modelo_nome'] ?? '',
            'trimId' => $trimId,
            'trimName' => $vehicle['fipe_versao_nome'] ?? $vehicle['kbb_versao_nome'] ?? '',
            'plate' => preg_replace('/[^A-Za-z0-9]/', '', $vehicle['veiculo_placa'] ?? ''),
            'km' => (int) ($vehicle['veiculo_km'] ?? 0),
            'colorId' => $colorId,
            'colorName' => $vehicle['cor']['nome'] ?? '',
            'doors' => (int) ($vehicle['veiculo_portas'] ?? 4),
            'fuelId' => $fuelId,
            'transmissionId' => $transmissionId,
            'fuelName' => $vehicle['combustivel']['nome'] ?? '',
            'video' => $this->extractYoutubeId($vehicle['video_url'] ?? ''),
            'price' => (float) ($vehicle['veiculo_valor'] ?? 0),
            'priority' => $priority,
            'spotlight' => (bool) ($vehicle['destaque'] ?? false),
            'usePhotosFromIcarrosGallery' => false,
            'equipments' => $equipmentIds,
        ];
    }

    // ========================================
    // Name-Based Mapping Methods
    // ========================================

    protected function findMakeByName(string $name): ?int
    {
        if (empty($name)) return null;

        if ($this->cachedMakes === null) {
            $result = $this->getMakes();
            if (!$result['success']) return null;
        }

        $normalizedSearch = $this->normalizeString($name);

        foreach ($this->cachedMakes as $make) {
            $makeName = $make['name'] ?? $make['nome'] ?? '';
            if ($this->normalizeString($makeName) === $normalizedSearch) {
                return $make['id'] ?? null;
            }
        }

        // Partial match
        foreach ($this->cachedMakes as $make) {
            $makeName = $make['name'] ?? $make['nome'] ?? '';
            if (str_contains($this->normalizeString($makeName), $normalizedSearch) ||
                str_contains($normalizedSearch, $this->normalizeString($makeName))) {
                return $make['id'] ?? null;
            }
        }

        return null;
    }

    protected function findModelByName(int $makeId, string $name): ?int
    {
        if (empty($name)) return null;

        $result = $this->getModels($makeId);
        if (!$result['success']) return null;

        $normalizedSearch = $this->normalizeString($name);

        foreach ($result['data'] as $model) {
            $modelName = $model['name'] ?? $model['nome'] ?? '';
            if ($this->normalizeString($modelName) === $normalizedSearch) {
                return $model['id'] ?? null;
            }
        }

        // Partial match
        foreach ($result['data'] as $model) {
            $modelName = $model['name'] ?? $model['nome'] ?? '';
            if (str_contains($this->normalizeString($modelName), $normalizedSearch) ||
                str_contains($normalizedSearch, $this->normalizeString($modelName))) {
                return $model['id'] ?? null;
            }
        }

        return null;
    }

    protected function findTrimByName(int $makeId, int $modelId, int $modelYear, string $name): ?int
    {
        if (empty($name)) return null;

        $result = $this->getTrims($makeId, $modelId, $modelYear);
        if (!$result['success']) return null;

        $normalizedSearch = $this->normalizeString($name);

        foreach ($result['data'] as $trim) {
            $trimName = $trim['name'] ?? $trim['nome'] ?? '';
            if ($this->normalizeString($trimName) === $normalizedSearch) {
                return $trim['id'] ?? null;
            }
        }

        // Partial match
        foreach ($result['data'] as $trim) {
            $trimName = $trim['name'] ?? $trim['nome'] ?? '';
            if (str_contains($this->normalizeString($trimName), $normalizedSearch) ||
                str_contains($normalizedSearch, $this->normalizeString($trimName))) {
                return $trim['id'] ?? null;
            }
        }

        return null;
    }

    protected function findColorByName(string $name): ?int
    {
        if (empty($name)) return null;

        if ($this->cachedColors === null) {
            $result = $this->getColors();
            if (!$result['success']) return null;
        }

        $normalizedSearch = $this->normalizeString($name);

        foreach ($this->cachedColors as $color) {
            $colorName = $color['name'] ?? $color['nome'] ?? '';
            if ($this->normalizeString($colorName) === $normalizedSearch) {
                return $color['id'] ?? null;
            }
        }

        // Common color mappings
        $colorMap = [
            'branco' => ['branca', 'white', 'branco perolizado'],
            'preto' => ['preta', 'black', 'preto fosco'],
            'prata' => ['cinza prata', 'silver'],
            'cinza' => ['grafite', 'gray', 'grey'],
            'vermelho' => ['vermelha', 'red', 'bordo'],
            'azul' => ['blue', 'azul marinho'],
            'verde' => ['green'],
            'bege' => ['champagne', 'dourado'],
        ];

        foreach ($this->cachedColors as $color) {
            $colorName = $this->normalizeString($color['name'] ?? $color['nome'] ?? '');
            foreach ($colorMap as $base => $variants) {
                if ($colorName === $base && in_array($normalizedSearch, array_map([$this, 'normalizeString'], $variants))) {
                    return $color['id'] ?? null;
                }
            }
        }

        return null;
    }

    protected function findFuelByName(string $name): ?int
    {
        if (empty($name)) return null;

        if ($this->cachedFuels === null) {
            $result = $this->getFuels();
            if (!$result['success']) return null;
        }

        $normalizedSearch = $this->normalizeString($name);

        foreach ($this->cachedFuels as $fuel) {
            $fuelName = $fuel['name'] ?? $fuel['nome'] ?? '';
            if ($this->normalizeString($fuelName) === $normalizedSearch) {
                return $fuel['id'] ?? null;
            }
        }

        // Common fuel mappings
        $fuelMap = [
            'flex' => ['etanol/gasolina', 'gasolina/etanol', 'bicombustivel'],
            'gasolina' => ['gasoline'],
            'diesel' => ['oleo diesel'],
            'eletrico' => ['elétrico', 'electric'],
            'hibrido' => ['híbrido', 'hybrid'],
        ];

        foreach ($this->cachedFuels as $fuel) {
            $fuelName = $this->normalizeString($fuel['name'] ?? $fuel['nome'] ?? '');
            foreach ($fuelMap as $base => $variants) {
                if ($fuelName === $base && in_array($normalizedSearch, array_map([$this, 'normalizeString'], $variants))) {
                    return $fuel['id'] ?? null;
                }
                if ($normalizedSearch === $base && in_array($fuelName, array_map([$this, 'normalizeString'], $variants))) {
                    return $fuel['id'] ?? null;
                }
            }
        }

        return null;
    }

    protected function findTransmissionByName(string $name): ?int
    {
        if (empty($name)) return null;

        if ($this->cachedTransmissions === null) {
            $result = $this->getTransmissions();
            if (!$result['success']) return null;
        }

        $normalizedSearch = $this->normalizeString($name);

        foreach ($this->cachedTransmissions as $trans) {
            $transName = $trans['name'] ?? $trans['nome'] ?? '';
            if ($this->normalizeString($transName) === $normalizedSearch) {
                return $trans['id'] ?? null;
            }
        }

        // Common transmission mappings
        $transMap = [
            'automatico' => ['automática', 'automatic', 'automatica'],
            'manual' => ['mecanico', 'mecânico'],
            'automatizado' => ['semi-automatico', 'cvt'],
        ];

        foreach ($this->cachedTransmissions as $trans) {
            $transName = $this->normalizeString($trans['name'] ?? $trans['nome'] ?? '');
            foreach ($transMap as $base => $variants) {
                if ($transName === $base && in_array($normalizedSearch, array_map([$this, 'normalizeString'], $variants))) {
                    return $trans['id'] ?? null;
                }
            }
        }

        return null;
    }

    protected function findEquipmentByName(string $name): ?int
    {
        if (empty($name)) return null;

        if ($this->cachedEquipments === null) {
            $result = $this->getEquipments();
            if (!$result['success']) return null;
        }

        $normalizedSearch = $this->normalizeString($name);

        foreach ($this->cachedEquipments as $equip) {
            $equipName = $equip['name'] ?? $equip['nome'] ?? '';
            if ($this->normalizeString($equipName) === $normalizedSearch) {
                return $equip['id'] ?? null;
            }
        }

        // Partial match for equipment
        foreach ($this->cachedEquipments as $equip) {
            $equipName = $this->normalizeString($equip['name'] ?? $equip['nome'] ?? '');
            if (str_contains($equipName, $normalizedSearch) || str_contains($normalizedSearch, $equipName)) {
                return $equip['id'] ?? null;
            }
        }

        return null;
    }

    // ========================================
    // Helper Methods
    // ========================================

    protected function normalizeString(string $str): string
    {
        $str = mb_strtolower($str);
        $str = preg_replace('/[áàãâä]/u', 'a', $str);
        $str = preg_replace('/[éèêë]/u', 'e', $str);
        $str = preg_replace('/[íìîï]/u', 'i', $str);
        $str = preg_replace('/[óòõôö]/u', 'o', $str);
        $str = preg_replace('/[úùûü]/u', 'u', $str);
        $str = preg_replace('/[ç]/u', 'c', $str);
        $str = preg_replace('/[^a-z0-9\s]/', '', $str);
        $str = preg_replace('/\s+/', ' ', $str);
        return trim($str);
    }

    protected function extractYoutubeId(string $url): string
    {
        if (empty($url)) return '';

        // Already just an ID
        if (preg_match('/^[a-zA-Z0-9_-]{11}$/', $url)) {
            return $url;
        }

        // Full YouTube URL
        if (preg_match('/(?:youtube\.com\/(?:watch\?v=|embed\/)|youtu\.be\/)([a-zA-Z0-9_-]{11})/', $url, $matches)) {
            return $matches[1];
        }

        return '';
    }

    protected function parseError($body): string
    {
        if (!is_array($body)) {
            return 'Unknown error';
        }

        if (isset($body['message'])) {
            return $body['message'];
        }

        if (isset($body['error'])) {
            return is_string($body['error']) ? $body['error'] : json_encode($body['error']);
        }

        if (isset($body['error_description'])) {
            return $body['error_description'];
        }

        return json_encode($body);
    }
}
