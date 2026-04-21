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

    public function __construct(array $config)
    {
        if (empty($config['access_token'])) {
            throw new \RuntimeException('OLX access token missing');
        }

        $this->config = $config;
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
        return $this;
    }

    public function setAccessToken(string $token): self
    {
        $this->accessToken = $token;
        return $this;
    }

    public function authenticate(): bool
    {
        $this->accessToken = $this->accessToken ?? $this->config['access_token'] ?? null;
        return $this->accessToken !== null;
    }

    public function isAuthenticated(): bool
    {
        return $this->accessToken !== null;
    }

    // ========================================
    // OAuth
    // ========================================

    public function getAuthorizationUrl(string $state = ''): string
    {
        $params = http_build_query([
            'response_type' => 'code',
            'client_id'     => $this->config['client_id'],
            'redirect_uri'  => $this->config['redirect_uri'],
            'scope'         => 'autoupload basic_user_info',
            'state'         => $state ?: bin2hex(random_bytes(16)),
        ]);

        return $this->getAuthUrl() . '/oauth/authorize?' . $params;
    }

    public function exchangeCodeForToken(string $code): array
    {
        try {
            $response = Http::withHeaders([
                'User-Agent'      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'Accept'          => 'application/json, text/plain, */*',
                'Accept-Language' => 'pt-BR,pt;q=0.9,en-US;q=0.8,en;q=0.7',
                'Origin'          => 'https://www.olx.com.br',
                'Referer'         => 'https://www.olx.com.br/',
            ])->asForm()->post($this->getAuthUrl() . '/oauth/token', [
                'grant_type'    => 'authorization_code',
                'code'          => $code,
                'redirect_uri'  => $this->config['redirect_uri'],
                'client_id'     => $this->config['client_id'],
                'client_secret' => $this->config['client_secret'],
            ]);

            if ($response->successful()) {
                $data = $response->json();
                $this->accessToken = $data['access_token'];

                return [
                    'success'       => true,
                    'access_token'  => $data['access_token'],
                    'refresh_token' => $data['refresh_token'] ?? null,
                    'expires_in'    => $data['expires_in'] ?? 3600,
                ];
            }

            $body     = $response->json();
            $errorMsg = $body['error_description'] ?? $body['error'] ?? 'Token exchange failed';

            Log::error('OLX: Token exchange failed', [
                'status'    => $response->status(),
                'response'  => $body,
                'body_text' => $response->body(),
            ]);

            return [
                'success' => false,
                'error'   => $errorMsg . ' (HTTP ' . $response->status() . ')',
            ];

        } catch (\Exception $e) {
            Log::error('OLX: Token exchange exception', ['error' => $e->getMessage()]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function refreshToken(string $refreshToken): array
    {
        try {
            $response = Http::withHeaders([
                'User-Agent'      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'Accept'          => 'application/json, text/plain, */*',
                'Accept-Language' => 'pt-BR,pt;q=0.9,en-US;q=0.8,en;q=0.7',
            ])->asForm()->post($this->getAuthUrl() . '/oauth/token', [
                'grant_type'    => 'refresh_token',
                'refresh_token' => $refreshToken,
                'client_id'     => $this->config['client_id'],
                'client_secret' => $this->config['client_secret'],
            ]);

            if ($response->successful()) {
                $data = $response->json();
                $this->accessToken = $data['access_token'];

                return [
                    'success'       => true,
                    'access_token'  => $data['access_token'],
                    'refresh_token' => $data['refresh_token'] ?? null,
                    'expires_in'    => $data['expires_in'] ?? 3600,
                ];
            }

            return [
                'success' => false,
                'error'   => $response->json()['error_description'] ?? 'Token refresh failed',
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
    // API Request Helpers
    // ========================================

    protected function apiRequest(string $method, string $endpoint, array $data = []): array
    {
        if (!$this->accessToken) {
            return ['success' => false, 'data' => [], 'error' => 'No access token'];
        }

        $startTime = microtime(true);
        $url       = $this->getBaseUrl() . $endpoint;

        try {
            $payload = array_merge(['access_token' => $this->accessToken], $data);

            $http = Http::withHeaders([
                'Authorization'  => 'Bearer ' . $this->accessToken,
                'Content-Type'   => 'application/json',
                'User-Agent'     => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'Accept'         => 'application/json, text/plain, */*',
                'Accept-Language'=> 'pt-BR,pt;q=0.9,en-US;q=0.8,en;q=0.7',
                'Origin'         => 'https://www.olx.com.br',
                'Referer'        => 'https://www.olx.com.br/',
            ]);

            $response = match (strtoupper($method)) {
                'POST' => $http->post($url, $payload),
                'PUT'  => $http->put($url, $payload),
                'GET'  => $http->get($url, $payload),
                default => throw new \InvalidArgumentException("Invalid method: {$method}"),
            };

            $durationMs = (int) ((microtime(true) - $startTime) * 1000);
            $body       = $response->json();

            $this->logRequest($method, $endpoint, $response->status(), $payload, $body, $response->successful(), $durationMs);

            if ($response->successful() && ($body['status'] ?? '') === 'ok') {
                return [
                    'success' => true,
                    'data'    => $body['data'] ?? $body,
                    'error'   => null,
                ];
            }

            return [
                'success' => false,
                'data'    => [],
                'error'   => $body['error'] ?? $body['message'] ?? 'Request failed',
            ];

        } catch (\Exception $e) {
            $durationMs = (int) ((microtime(true) - $startTime) * 1000);
            $this->logRequest($method, $endpoint, null, $data, null, false, $durationMs, $e->getMessage());

            return ['success' => false, 'data' => [], 'error' => $e->getMessage()];
        }
    }

    /**
     * API request para o endpoint de import (PUT, sem verificação de status 'ok')
     */
    protected function apiRequestV1(string $method, string $endpoint, array $data = []): array
    {
        if (!$this->accessToken) {
            return ['success' => false, 'data' => [], 'error' => 'No access token'];
        }

        $startTime = microtime(true);
        $url       = 'https://apps.olx.com.br' . $endpoint;

        try {
            $payload = array_merge(['access_token' => $this->accessToken], $data);

            $http = Http::timeout(60)->withHeaders([
                'Authorization'   => 'Bearer ' . $this->accessToken,
                'Content-Type'    => 'application/json',
                'User-Agent'      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'Accept'          => 'application/json, text/plain, */*',
                'Accept-Language' => 'pt-BR,pt;q=0.9,en-US;q=0.8,en;q=0.7',
                'Origin'          => 'https://www.olx.com.br',
                'Referer'         => 'https://www.olx.com.br/',
            ]);

            $response = match (strtoupper($method)) {
                'GET'  => $http->get($url, $payload),
                'POST' => $http->post($url, $payload),
                'PUT'  => $http->put($url, $payload),
                default => throw new \InvalidArgumentException("Invalid method: {$method}"),
            };

            $durationMs = (int) ((microtime(true) - $startTime) * 1000);
            $body       = $response->json();

            $logData = $data;
            unset($logData['access_token']);
            $this->logRequest($method, $endpoint, $response->status(), $logData, $body, $response->successful(), $durationMs);

            if ($response->successful()) {
                return [
                    'success' => true,
                    'data'    => $body,
                    'error'   => null,
                ];
            }

            return [
                'success' => false,
                'data'    => $body ?? [],
                'error'   => $body['message'] ?? $body['error'] ?? $body['reason'] ?? json_encode($body),
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
        $logPayload = $payload;
        unset($logPayload['access_token']);

        PortalSyncLog::log(
            $this->getPortalName(),
            $method . ' ' . $endpoint,
            $success ? 'success' : 'error',
            [
                'http_method'     => $method,
                'endpoint'        => $endpoint,
                'http_status'     => $status,
                'request_payload' => $logPayload,
                'response_body'   => $response,
                'error_message'   => $error,
                'duration_ms'     => $durationMs,
            ]
        );
    }

    // ========================================
    // Ad Import
    // ========================================

    public function importAds(array $ads): array
    {
        if (!$this->accessToken) {
            return ['success' => false, 'error' => 'No access token'];
        }

        // OLX exige PUT para o endpoint de import
        $result = $this->apiRequestV1('PUT', '/autoupload/import', [
            'ad_list' => $ads,
        ]);

        if (!$result['success']) {
            return [
                'success'     => false,
                'external_id' => null,
                'url'         => null,
                'error'       => $result['error'] ?: json_encode($result['data']),
                'raw'         => $result['data'],
            ];
        }

        return [
            'success'  => true,
            'response' => $result['data'],
            'error'    => null,
        ];
    }

    protected function parseImportError(?array $response): string
    {
        if (!$response) {
            return 'Empty response from OLX';
        }

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
        $vehicle             = $vehicleData[0];
        $ad                  = $this->transformVehicleData($vehicle);
        $ad['operation']     = 'insert';

        $result = $this->importAds([$ad]);

        return [
            'success'     => $result['success'],
            'external_id' => $result['success'] ? $ad['id'] : null,
            'url'         => null,
            'error'       => $result['error'] ?? null,
        ];
    }

    public function updateVehicle(string $externalId, array $vehicleData): array
    {
        $vehicle         = $vehicleData[0];
        $ad              = $this->transformVehicleData($vehicle);
        $ad['id']        = $externalId;
        $ad['operation'] = 'insert';

        $result = $this->importAds([$ad]);

        return [
            'success' => $result['success'],
            'error'   => $result['error'] ?? null,
        ];
    }

    public function removeVehicle(string $externalId): array
    {
        $result = $this->importAds([
            ['id' => $externalId, 'operation' => 'delete'],
        ]);

        return [
            'success' => $result['success'],
            'error'   => $result['error'] ?? null,
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
        $nextToken   = null;

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
            $ads  = $data['data'] ?? [];

            foreach ($ads as $ad) {
                $allVehicles[] = [
                    'id'      => $ad['id'] ?? null,
                    'list_id' => $ad['list_id'] ?? null,
                    'status'  => $ad['status'] ?? null,
                ];
            }

            $nextToken = $data['next_token'] ?? null;

        } while ($nextToken);

        return ['success' => true, 'vehicles' => $allVehicles, 'error' => null];
    }

    public function fetchLeads(array $filters = []): array
    {
        return [
            'success' => false,
            'leads'   => [],
            'error'   => 'OLX leads are delivered via webhook, not API polling. Configure webhook URL in OLX developer panel.',
        ];
    }

    // ========================================
    // Data Transformation
    // ========================================

    public function transformVehicleData(array $vehicle): array
    {
        $zipcode = preg_replace('/\D/', '', $vehicle['zipcode'] ?? '');
        $phone   = (int) preg_replace('/\D/', '', $vehicle['phone'] ?? '');

        return [
            'id'       => (string) ($vehicle['id'] ?? uniqid()),
            'category' => 2020,
            'subject'  => (string) ($vehicle['subject'] ?? ''),
            'body'     => (string) ($vehicle['body'] ?? ''),
            'price'    => (int) ($vehicle['price'] ?? 0),
            'zipcode'  => $zipcode,
            'phone'    => $phone,
            'type'     => 's',
            'images'   => $this->buildImages($vehicle),
            'params'   => $this->buildAttributes($vehicle['params']),
        ];
    }

    protected function buildAttributes(array $vehicle): array
    {
        return [
            'vehicle_brand'   => (string) $vehicle['vehicle_brand'],
            'vehicle_model'   => (string) $vehicle['vehicle_model'],
            'vehicle_version' => (string) ($vehicle['vehicle_version'] ?? '1'),
            'regdate'         => (string) $vehicle['regdate'],
            'mileage'         => (int) $vehicle['mileage'],
            'fuel'            => (string) ($vehicle['fuel'] ?? '3'),
            'gearbox'         => (string) ($vehicle['gearbox'] ?? '1'),
            'doors'           => (string) ($vehicle['doors'] ?? '2'),
            'carcolor'        => (string) ($vehicle['carcolor'] ?? '2'),
            'cartype'         => (string) ($vehicle['cartype'] ?? '9'),
            'vehicle_tag'     => strtoupper($vehicle['vehicle_tag'] ?? 'ABC1234'),
            'motorpower'      => (string) ($vehicle['motorpower'] ?? '10'),
            'car_steering'    => (string) ($vehicle['car_steering'] ?? '1'),
            'car_features'    => $vehicle['car_features'] ?? ['1', '3', '4'],
        ];
    }

    protected function buildImages(array $vehicle): array
    {
        if (empty($vehicle['images'])) {
            return [];
        }

        return collect($vehicle['images'])
            ->map(function ($img) {
                if (is_string($img)) {
                    return $img;
                }
                if (is_array($img) && isset($img['url'])) {
                    return $img['url'];
                }
                return null;
            })
            ->filter()
            ->values()
            ->toArray();
    }

    // ========================================
    // Legacy mapping helpers (mantidos para compatibilidade)
    // ========================================

    protected function buildParams(array $vehicle): array
    {
        return [
            'cartype'  => $this->mapCarType($vehicle),
            'gearbox'  => $this->mapGearbox($vehicle),
            'fuel'     => $this->mapFuel($vehicle),
            'mileage'  => $vehicle['veiculo_km'] ?? 0,
            'regdate'  => $vehicle['veiculo_ano_modelo'] ?? date('Y'),
            'doors'    => $this->mapDoors($vehicle),
            'carcolor' => $this->mapColor($vehicle),
        ];
    }

    protected function mapCarType(array $v): string
    {
        $c = strtolower($v['carroceria']['nome'] ?? '');
        return match (true) {
            str_contains($c, 'sed')                                   => '1',
            str_contains($c, 'hatch')                                 => '2',
            str_contains($c, 'perua') || str_contains($c, 'sw')      => '3',
            str_contains($c, 'picape')                                => '4',
            str_contains($c, 'suv')                                   => '6',
            str_contains($c, 'van')                                   => '7',
            default                                                   => '1',
        };
    }

    protected function mapGearbox(array $v): string
    {
        $c = strtolower($v['cambio']['nome'] ?? '');
        return match (true) {
            str_contains($c, 'manual') => '1',
            str_contains($c, 'autom')  => '2',
            default                    => '1',
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
            str_contains($c, 'gnv')    => '5',
            str_contains($c, 'el')     => '6',
            default                    => '3',
        };
    }

    protected function mapDoors(array $v): string
    {
        return (string) ($v['veiculo_portas'] ?? 4);
    }

    protected function mapColor(array $v): string
    {
        $c      = strtolower($v['cor']['nome'] ?? '');
        $colors = [
            'preto'    => '1',  'branco'   => '2',  'prata'   => '3',
            'cinza'    => '4',  'vermelho' => '5',  'azul'    => '6',
            'amarelo'  => '7',  'verde'    => '8',  'laranja' => '9',
            'bege'     => '10', 'marrom'   => '11', 'dourado' => '12',
        ];
        foreach ($colors as $name => $id) {
            if (str_contains($c, $name)) return $id;
        }
        return '16';
    }
}
