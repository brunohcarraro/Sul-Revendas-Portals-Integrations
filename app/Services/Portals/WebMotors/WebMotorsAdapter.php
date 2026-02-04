<?php

namespace App\Services\Portals\WebMotors;

use App\Models\PortalSyncLog;
use App\Services\Portals\Contracts\PortalAdapterInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * WebMotors REST API Adapter (Estoquecanais API)
 *
 * This adapter uses the REST API for channel stock management.
 * Documentation: https://portal-webmotors.sensedia.com
 */
class WebMotorsAdapter implements PortalAdapterInterface
{
    protected array $config;
    protected ?string $accessToken = null;

    public function __construct()
    {
        $this->config = config('portals.webmotors');
    }

    public function getPortalName(): string
    {
        return 'webmotors';
    }

    public function getBaseUrl(): string
    {
        $env = $this->config['environment'] ?? 'homologation';
        return $this->config['urls'][$env] ?? $this->config['urls']['homologation'];
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
        if (!$this->config['client_id'] || !$this->config['client_secret']) {
            Log::error('WebMotors REST: No credentials configured');
            return false;
        }

        try {
            $response = Http::asForm()->post($this->getBaseUrl() . '/oauth/v1/access-token', [
                'grant_type' => 'client_credentials',
                'client_id' => $this->config['client_id'],
                'client_secret' => $this->config['client_secret'],
            ]);

            if ($response->successful()) {
                $data = $response->json();
                $this->accessToken = $data['access_token'];

                Log::info('WebMotors REST: Authentication successful', [
                    'expires_in' => $data['expires_in'] ?? null,
                ]);

                return true;
            }

            Log::error('WebMotors REST: Authentication failed', [
                'status' => $response->status(),
                'body' => $response->json(),
            ]);

            return false;

        } catch (\Exception $e) {
            Log::error('WebMotors REST: Authentication exception', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    public function isAuthenticated(): bool
    {
        return $this->accessToken !== null;
    }

    // ========================================
    // API Request Helper
    // ========================================

    protected function apiRequest(string $method, string $endpoint, array $data = []): array
    {
        if (!$this->accessToken) {
            return ['success' => false, 'data' => null, 'error' => 'No access token'];
        }

        $startTime = microtime(true);
        $url = $this->getBaseUrl() . $endpoint;

        try {
            $request = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->accessToken,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ]);

            $response = match (strtoupper($method)) {
                'GET' => $request->get($url, $data),
                'POST' => $request->post($url, $data),
                'PUT' => $request->put($url, $data),
                'PATCH' => $request->patch($url, $data),
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
                'error' => $body['message'] ?? $body['error'] ?? 'Request failed',
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
    // Channel Stock API Methods
    // ========================================

    /**
     * Get list of interactions (publication requests from dealers)
     */
    public function getInteractions(): array
    {
        $result = $this->apiRequest('GET', '/estoquecanais/v1/interacoes');

        return [
            'success' => $result['success'],
            'interactions' => $result['data'] ?? [],
            'error' => $result['error'],
        ];
    }

    /**
     * Get list of published vehicles
     */
    public function getPublishedVehicles(): array
    {
        $result = $this->apiRequest('GET', '/estoquecanais/v1/itens');

        return [
            'success' => $result['success'],
            'vehicles' => $result['data'] ?? [],
            'error' => $result['error'],
        ];
    }

    /**
     * Get specific item details
     */
    public function getItem(string $itemId): array
    {
        $result = $this->apiRequest('GET', "/estoquecanais/v1/itens/{$itemId}");

        return [
            'success' => $result['success'],
            'item' => $result['data'],
            'error' => $result['error'],
        ];
    }

    /**
     * Update item status on WebMotors (report publication result)
     */
    public function updateItemStatus(string $itemId, string $status, ?string $url = null): array
    {
        $data = ['status' => $status];

        if ($url) {
            $data['url'] = $url;
        }

        $result = $this->apiRequest('PATCH', "/estoquecanais/v1/itens/{$itemId}/status", $data);

        return [
            'success' => $result['success'],
            'error' => $result['error'],
        ];
    }

    // ========================================
    // PortalAdapterInterface Implementation
    // ========================================

    public function publishVehicle(array $vehicleData): array
    {
        // WebMotors REST API uses a passive model - dealers publish from Cockpit,
        // and we receive the vehicles via getInteractions() and confirm publication
        return [
            'success' => false,
            'external_id' => null,
            'url' => null,
            'error' => 'WebMotors REST uses passive integration. Use getInteractions() to receive vehicles.',
        ];
    }

    public function updateVehicle(string $externalId, array $vehicleData): array
    {
        // WebMotors passive model - updates come from Cockpit
        return [
            'success' => false,
            'error' => 'WebMotors REST uses passive integration. Updates are pushed from Cockpit.',
        ];
    }

    public function removeVehicle(string $externalId): array
    {
        return $this->updateItemStatus($externalId, 'removed');
    }

    public function updateVehicleStatus(string $externalId, string $status): array
    {
        $wmStatus = match ($status) {
            'active' => 'published',
            'paused' => 'paused',
            'sold' => 'sold',
            'inactive' => 'removed',
            default => $status,
        };

        return $this->updateItemStatus($externalId, $wmStatus);
    }

    public function fetchLeads(array $filters = []): array
    {
        $result = $this->apiRequest('GET', '/leads/v1/leads', $filters);

        return [
            'success' => $result['success'],
            'leads' => $result['data'] ?? [],
            'error' => $result['error'],
        ];
    }

    public function transformVehicleData(array $vehicle): array
    {
        return [
            'marca' => $vehicle['fipe_marca_nome'] ?? $vehicle['kbb_marca_nome'] ?? null,
            'modelo' => $vehicle['fipe_modelo_nome'] ?? $vehicle['kbb_modelo_nome'] ?? null,
            'versao' => $vehicle['fipe_versao_nome'] ?? $vehicle['kbb_versao_nome'] ?? null,
            'ano_fabricacao' => $vehicle['veiculo_ano_fabricacao'] ?? null,
            'ano_modelo' => $vehicle['veiculo_ano_modelo'] ?? null,
            'km' => $vehicle['veiculo_km'] ?? 0,
            'preco' => $vehicle['veiculo_valor'] ?? null,
            'cor' => $vehicle['cor']['nome'] ?? null,
            'combustivel' => $vehicle['combustivel']['nome'] ?? null,
            'cambio' => $vehicle['cambio']['nome'] ?? null,
            'portas' => $vehicle['veiculo_portas'] ?? null,
            'placa' => $vehicle['veiculo_placa'] ?? null,
            'observacao' => $vehicle['veiculo_obs'] ?? null,
            'zero_km' => (bool) ($vehicle['zero_km'] ?? false),
        ];
    }
}
