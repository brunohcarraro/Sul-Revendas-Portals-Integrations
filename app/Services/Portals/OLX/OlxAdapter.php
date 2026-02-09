<?php

namespace App\Services\Portals\OLX;

use App\Models\PortalSyncLog;
use App\Services\Portals\Contracts\PortalAdapterInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OlxAdapter implements PortalAdapterInterface
{
    protected array $config;
    protected ?string $accessToken = null;

    public function __construct()
    {
        $this->config = config('portals.olx');

        if (!is_array($this->config)) {
            throw new \RuntimeException(
                'OLX config not found. Did you forget config/portals.php or config:clear?'
            );
        }
    }

    public function getPortalName(): string
    {
        return 'olx';
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
        // For compatibility - credentials come from config
        return $this;
    }

    public function setAccessToken(string $token): self
    {
        $this->accessToken = $token;
        return $this;
    }

    public function authenticate(): bool
    {
        // Check if we have a token in config or set manually
        $this->accessToken = $this->accessToken ?? $this->config['access_token'] ?? null;
        return $this->accessToken !== null;
    }

    public function isAuthenticated(): bool
    {
        return $this->accessToken !== null;
    }

    /**
     * Get OAuth authorization URL for user consent
     */
    public function getAuthorizationUrl(string $state = ''): string
    {
        $params = http_build_query([
            'response_type' => 'code',
            'client_id' => $this->config['client_id'],
            'redirect_uri' => $this->config['redirect_uri'],
            'scope' => 'autoupload basic_user_info',
            'state' => $state ?: bin2hex(random_bytes(16)),
        ]);

        return $this->getAuthUrl() . '/oauth/authorize?' . $params;
    }

    /**
     * Exchange authorization code for tokens
     */
    public function exchangeCodeForToken(string $code): array
    {
        try {
            $response = Http::withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'Accept' => 'application/json, text/plain, */*',
                'Accept-Language' => 'pt-BR,pt;q=0.9,en-US;q=0.8,en;q=0.7',
                'Origin' => 'https://www.olx.com.br',
                'Referer' => 'https://www.olx.com.br/',
            ])->asForm()->post($this->getAuthUrl() . '/oauth/token', [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $this->config['redirect_uri'],
                'client_id' => $this->config['client_id'],
                'client_secret' => $this->config['client_secret'],
            ]);

            if ($response->successful()) {
                $data = $response->json();
                $this->accessToken = $data['access_token'];

                return [
                    'success' => true,
                    'access_token' => $data['access_token'],
                    'refresh_token' => $data['refresh_token'] ?? null,
                    'expires_in' => $data['expires_in'] ?? 3600,
                ];
            }

            $body = $response->json();
            $errorMsg = $body['error_description'] ?? $body['error'] ?? 'Token exchange failed';

            Log::error('OLX: Token exchange failed', [
                'status' => $response->status(),
                'response' => $body,
                'body_text' => $response->body()
            ]);

            return [
                'success' => false,
                'error' => $errorMsg . ' (HTTP ' . $response->status() . ')',
            ];

        } catch (\Exception $e) {
            Log::error('OLX: Token exchange exception', ['error' => $e->getMessage()]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Refresh access token
     */
    public function refreshToken(string $refreshToken): array
    {
        try {
            $response = Http::withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'Accept' => 'application/json, text/plain, */*',
                'Accept-Language' => 'pt-BR,pt;q=0.9,en-US;q=0.8,en;q=0.7',
            ])->asForm()->post($this->getAuthUrl() . '/oauth/token', [
                'grant_type' => 'refresh_token',
                'refresh_token' => $refreshToken,
                'client_id' => $this->config['client_id'],
                'client_secret' => $this->config['client_secret'],
            ]);

            if ($response->successful()) {
                $data = $response->json();
                $this->accessToken = $data['access_token'];

                return [
                    'success' => true,
                    'access_token' => $data['access_token'],
                    'refresh_token' => $data['refresh_token'] ?? null,
                    'expires_in' => $data['expires_in'] ?? 3600,
                ];
            }

            return [
                'success' => false,
                'error' => $response->json()['error_description'] ?? 'Token refresh failed',
            ];

        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    // ========================================
    // Reference Data Methods
    // ========================================

    public function getCarBrands(): array
    {
        return $this->apiRequest('POST', '/autoupload/car_info');
    }

    public function getCarModels(int $brandId): array
    {
        return $this->apiRequest('POST', "/autoupload/car_info/{$brandId}");
    }

    public function getCarVersions(int $brandId, int $modelId): array
    {
        return $this->apiRequest('POST', "/autoupload/car_info/{$brandId}/{$modelId}");
    }

    public function getMotoBrands(): array
    {
        return $this->apiRequest('POST', '/autoupload/moto_info');
    }

    // ========================================
    // API Request Helper
    // ========================================

    protected function apiRequest(string $method, string $endpoint, array $data = []): array
    {
        if (!$this->accessToken) {
            return ['success' => false, 'data' => [], 'error' => 'No access token'];
        }

        $startTime = microtime(true);
        $url = $this->getBaseUrl() . $endpoint;

        try {
            // OLX requires access_token in BOTH header AND body
            $payload = array_merge(['access_token' => $this->accessToken], $data);

            $http = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->accessToken,
                'Content-Type' => 'application/json',
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'Accept' => 'application/json, text/plain, */*',
                'Accept-Language' => 'pt-BR,pt;q=0.9,en-US;q=0.8,en;q=0.7',
                'Origin' => 'https://www.olx.com.br',
                'Referer' => 'https://www.olx.com.br/',
            ]);

            $response = match (strtoupper($method)) {
                'POST' => $http->post($url, $payload),
                'PUT' => $http->put($url, $payload),
                'GET' => $http->get($url, $payload),
                default => throw new \InvalidArgumentException("Invalid method: {$method}"),
            };

            $durationMs = (int) ((microtime(true) - $startTime) * 1000);
            $body = $response->json();

            $this->logRequest($method, $endpoint, $response->status(), $payload, $body, $response->successful(), $durationMs);

            if ($response->successful() && ($body['status'] ?? '') === 'ok') {
                return [
                    'success' => true,
                    'data' => $body['data'] ?? $body,
                    'error' => null,
                ];
            }

            return [
                'success' => false,
                'data' => [],
                'error' => $body['error'] ?? $body['message'] ?? 'Request failed',
            ];

        } catch (\Exception $e) {
            $durationMs = (int) ((microtime(true) - $startTime) * 1000);
            $this->logRequest($method, $endpoint, null, $data, null, false, $durationMs, $e->getMessage());

            return ['success' => false, 'data' => [], 'error' => $e->getMessage()];
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
        // Remove token from logged payload
        $logPayload = $payload;
        unset($logPayload['access_token']);

        PortalSyncLog::log(
            $this->getPortalName(),
            $method . ' ' . $endpoint,
            $success ? 'success' : 'error',
            [
                'http_method' => $method,
                'endpoint' => $endpoint,
                'http_status' => $status,
                'request_payload' => $logPayload,
                'response_body' => $response,
                'error_message' => $error,
                'duration_ms' => $durationMs,
            ]
        );
    }

    // ========================================
    // Ad Import (batch operations)
    // ========================================

    public function importAds(array $ads): array
    {
        if (!$this->accessToken) {
            return ['success' => false, 'error' => 'No access token'];
        }

        $payload = [
            'access_token' => $this->accessToken,
            'ad_list' => $ads,
        ];

        $startTime = microtime(true);

        try {
            // OLX requires access_token in BOTH header AND body
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->accessToken,
                'Content-Type' => 'application/json',
            ])->put($this->getBaseUrl() . '/autoupload/import', $payload);

            $durationMs = (int) ((microtime(true) - $startTime) * 1000);
            $body = $response->json();

            $this->logRequest('PUT', '/autoupload/import', $response->status(),
                ['ad_count' => count($ads)], $body, $response->successful(), $durationMs);

            if ($response->successful() && ($body['statusCode'] ?? -1) === 0) {
                return [
                    'success' => true,
                    'token' => $body['token'] ?? null,
                    'message' => $body['statusMessage'] ?? 'Ads imported',
                    'error' => null,
                ];
            }

            return [
                'success' => false,
                'error' => $this->parseImportError($body),
                'errors' => $body['errors'] ?? [],
            ];

        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    protected function parseImportError(array $response): string
    {
        $code = $response['statusCode'] ?? -1;
        return match ($code) {
            -1 => 'Unexpected error',
            -2 => 'User blocked for excessive requests',
            -3 => 'No ads to import',
            -4 => 'Ad validation failed: ' . json_encode($response['errors'] ?? []),
            -5 => 'Import service disabled',
            -6 => 'Insufficient permissions (requires professional plan)',
            -7 => 'Insufficient ad slots remaining',
            -8 => 'Partial import due to time limits',
            default => $response['statusMessage'] ?? 'Unknown error',
        };
    }

    // ========================================
    // PortalAdapterInterface Implementation
    // ========================================

    public function publishVehicle(array $vehicleData): array
    {
        $ad = $this->transformVehicleData($vehicleData);
        $ad['operation'] = 'insert';

        $result = $this->importAds([$ad]);

        return [
            'success' => $result['success'],
            'external_id' => $result['success'] ? $ad['id'] : null,
            'url' => null,
            'error' => $result['error'] ?? null,
        ];
    }

    public function updateVehicle(string $externalId, array $vehicleData): array
    {
        $ad = $this->transformVehicleData($vehicleData);
        $ad['id'] = $externalId;
        $ad['operation'] = 'insert';

        $result = $this->importAds([$ad]);

        return [
            'success' => $result['success'],
            'error' => $result['error'] ?? null,
        ];
    }

    public function removeVehicle(string $externalId): array
    {
        $result = $this->importAds([
            ['id' => $externalId, 'operation' => 'delete']
        ]);

        return [
            'success' => $result['success'],
            'error' => $result['error'] ?? null,
        ];
    }

    public function updateVehicleStatus(string $externalId, string $status): array
    {
        if (in_array($status, ['sold', 'inactive', 'paused'])) {
            return $this->removeVehicle($externalId);
        }
        return ['success' => true, 'error' => null];
    }

    public function getPublishedVehicles(): array
    {
        if (!$this->accessToken) {
            return ['success' => false, 'vehicles' => [], 'error' => 'No access token'];
        }

        $allVehicles = [];
        $nextToken = null;

        do {
            $params = ['access_token' => $this->accessToken];
            if ($nextToken) {
                $params['page_token'] = $nextToken;
            }

            $result = $this->apiRequestV1('GET', '/autoupload/v1/published', $params);

            if (!$result['success']) {
                return ['success' => false, 'vehicles' => $allVehicles, 'error' => $result['error']];
            }

            $data = $result['data'];
            $ads = $data['data'] ?? [];

            foreach ($ads as $ad) {
                $allVehicles[] = [
                    'id' => $ad['id'] ?? null,
                    'list_id' => $ad['list_id'] ?? null,
                    'status' => $ad['status'] ?? null,
                ];
            }

            $nextToken = $data['next_token'] ?? null;

        } while ($nextToken);

        return ['success' => true, 'vehicles' => $allVehicles, 'error' => null];
    }

    /**
     * API request for v1 endpoints (different response format)
     */
    protected function apiRequestV1(string $method, string $endpoint, array $data = []): array
    {
        if (!$this->accessToken) {
            return ['success' => false, 'data' => [], 'error' => 'No access token'];
        }

        $startTime = microtime(true);
        $url = $this->getBaseUrl() . $endpoint;

        try {
            $http = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->accessToken,
                'Content-Type' => 'application/json',
            ]);

            $response = match (strtoupper($method)) {
                'GET' => $http->get($url, $data),
                'POST' => $http->post($url, $data),
                default => throw new \InvalidArgumentException("Invalid method: {$method}"),
            };

            $durationMs = (int) ((microtime(true) - $startTime) * 1000);
            $body = $response->json();

            // Remove token from logged data
            $logData = $data;
            unset($logData['access_token']);

            $this->logRequest($method, $endpoint, $response->status(), $logData, $body, $response->successful(), $durationMs);

            if ($response->successful()) {
                return [
                    'success' => true,
                    'data' => $body,
                    'error' => null,
                ];
            }

            return [
                'success' => false,
                'data' => [],
                'error' => $body['message'] ?? $body['reason'] ?? 'Request failed',
            ];

        } catch (\Exception $e) {
            $durationMs = (int) ((microtime(true) - $startTime) * 1000);
            $this->logRequest($method, $endpoint, null, $data, null, false, $durationMs, $e->getMessage());

            return ['success' => false, 'data' => [], 'error' => $e->getMessage()];
        }
    }

    public function fetchLeads(array $filters = []): array
    {
        // OLX autoupload API does not provide leads endpoint
        // Leads are delivered via webhook to configured URL
        // Configure webhook at: https://developers.olx.com.br
        return [
            'success' => false,
            'leads' => [],
            'error' => 'OLX leads are delivered via webhook, not API polling. Configure webhook URL in OLX developer panel.',
        ];
    }

    public function transformVehicleData(array $vehicle): array
    {
        $id = $vehicle['olx_ad_id'] ?? ('v' . $vehicle['veiculo_id']);

        $category = $this->config['categories']['cars'];
        if (isset($vehicle['categoria_id'])) {
            $category = match ((int) $vehicle['categoria_id']) {
                3 => $this->config['categories']['motorcycles'],
                2 => $this->config['categories']['trucks'],
                default => $this->config['categories']['cars'],
            };
        }

        $title = trim(sprintf('%s %s %s',
            $vehicle['fipe_marca_nome'] ?? $vehicle['kbb_marca_nome'] ?? '',
            $vehicle['fipe_modelo_nome'] ?? $vehicle['kbb_modelo_nome'] ?? '',
            $vehicle['veiculo_ano_modelo'] ?? ''
        ));

        $description = $vehicle['veiculo_obs'] ?? $title;

        $images = [];
        $imageBaseUrl = config('portals.images.base_url') . config('portals.images.path_prefix');
        if (!empty($vehicle['imagens'])) {
            foreach ($vehicle['imagens'] as $img) {
                $imgName = $img->imagem_nome ?? $img['imagem_nome'] ?? null;
                if ($imgName) {
                    $images[] = $imageBaseUrl . $imgName;
                }
            }
        }

        return [
            'id' => $id,
            'category' => $category,
            'subject' => substr($title, 0, 90),
            'body' => substr($description, 0, 6000),
            'phone' => $vehicle['anunciante_telefone'] ?? '',
            'type' => 's',
            'price' => (int) ($vehicle['veiculo_valor'] ?? 0),
            'zipcode' => $vehicle['anunciante_cep'] ?? '',
            'images' => array_slice($images, 0, 20),
            'params' => $this->buildParams($vehicle),
        ];
    }

    protected function buildParams(array $vehicle): array
    {
        return [
            'cartype' => $this->mapCarType($vehicle),
            'gearbox' => $this->mapGearbox($vehicle),
            'fuel' => $this->mapFuel($vehicle),
            'mileage' => $vehicle['veiculo_km'] ?? 0,
            'regdate' => $vehicle['veiculo_ano_modelo'] ?? date('Y'),
            'doors' => $this->mapDoors($vehicle),
            'carcolor' => $this->mapColor($vehicle),
        ];
    }

    protected function mapCarType(array $v): string
    {
        $c = strtolower($v['carroceria']['nome'] ?? '');
        return match (true) {
            str_contains($c, 'sed') => '1',
            str_contains($c, 'hatch') => '2',
            str_contains($c, 'perua') || str_contains($c, 'sw') => '3',
            str_contains($c, 'picape') => '4',
            str_contains($c, 'suv') => '6',
            str_contains($c, 'van') => '7',
            default => '1',
        };
    }

    protected function mapGearbox(array $v): string
    {
        $c = strtolower($v['cambio']['nome'] ?? '');
        return match (true) {
            str_contains($c, 'manual') => '1',
            str_contains($c, 'autom') => '2',
            default => '1',
        };
    }

    protected function mapFuel(array $v): string
    {
        $c = strtolower($v['combustivel']['nome'] ?? '');
        return match (true) {
            str_contains($c, 'gasolina') && !str_contains($c, 'etanol') => '1',
            str_contains($c, 'etanol') && !str_contains($c, 'gasolina') => '2',
            str_contains($c, 'flex') || str_contains($c, 'etanol/gasolina') => '3',
            str_contains($c, 'diesel') => '4',
            str_contains($c, 'gnv') => '5',
            str_contains($c, 'el') => '6',
            default => '3',
        };
    }

    protected function mapDoors(array $v): string
    {
        return (string) ($v['veiculo_portas'] ?? 4);
    }

    protected function mapColor(array $v): string
    {
        $c = strtolower($v['cor']['nome'] ?? '');
        $colors = [
            'preto' => '1', 'branco' => '2', 'prata' => '3', 'cinza' => '4',
            'vermelho' => '5', 'azul' => '6', 'amarelo' => '7', 'verde' => '8',
            'laranja' => '9', 'bege' => '10', 'marrom' => '11', 'dourado' => '12',
        ];
        foreach ($colors as $name => $id) {
            if (str_contains($c, $name)) return $id;
        }
        return '16';
    }
}
