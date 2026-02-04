<?php

namespace App\Services\Portals\MercadoLivre;

use App\Models\PortalSyncLog;
use App\Services\Portals\Contracts\PortalAdapterInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MercadoLivreAdapter implements PortalAdapterInterface
{
    protected array $config;
    protected ?string $accessToken = null;
    protected ?string $userId = null;

    public function __construct()
    {
        $this->config = config('portals.mercadolivre');
    }

    public function getPortalName(): string
    {
        return 'mercadolivre';
    }

    public function getBaseUrl(): string
    {
        return $this->config['urls']['api'];
    }

    public function getAuthUrl(): string
    {
        return $this->config['urls']['auth'];
    }

    public function getSiteId(): string
    {
        return $this->config['site_id'];
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

    public function setUserId(string $userId): self
    {
        $this->userId = $userId;
        return $this;
    }

    public function authenticate(): bool
    {
        $this->accessToken = $this->accessToken ?? $this->config['access_token'] ?? null;
        $this->userId = $this->userId ?? $this->config['user_id'] ?? null;
        return $this->accessToken !== null;
    }

    public function isAuthenticated(): bool
    {
        return $this->accessToken !== null;
    }

    // ========================================
    // PKCE Support Methods
    // ========================================

    /**
     * Generate a cryptographically secure code verifier for PKCE
     * Must be between 43-128 characters, using unreserved URI characters
     */
    public function generateCodeVerifier(int $length = 64): string
    {
        $length = max(43, min(128, $length));
        $bytes = random_bytes($length);

        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /**
     * Generate code challenge from code verifier using SHA256
     */
    public function generateCodeChallenge(string $codeVerifier): string
    {
        $hash = hash('sha256', $codeVerifier, true);

        return rtrim(strtr(base64_encode($hash), '+/', '-_'), '=');
    }

    /**
     * Get OAuth authorization URL for user consent with PKCE support
     *
     * @param string $state Optional state parameter for CSRF protection
     * @param string|null $codeVerifier If provided, uses this verifier. If null, generates one.
     * @return array Returns ['url' => string, 'code_verifier' => string, 'state' => string]
     */
    public function getAuthorizationUrlWithPKCE(string $state = '', ?string $codeVerifier = null): array
    {
        $codeVerifier = $codeVerifier ?? $this->generateCodeVerifier();
        $codeChallenge = $this->generateCodeChallenge($codeVerifier);
        $state = $state ?: bin2hex(random_bytes(16));

        $params = http_build_query([
            'response_type' => 'code',
            'client_id' => $this->config['app_id'],
            'redirect_uri' => $this->config['redirect_uri'],
            'state' => $state,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
        ]);

        return [
            'url' => $this->getAuthUrl() . '/authorization?' . $params,
            'code_verifier' => $codeVerifier,
            'code_challenge' => $codeChallenge,
            'state' => $state,
        ];
    }

    /**
     * Get OAuth authorization URL for user consent (legacy, without PKCE)
     * @deprecated Use getAuthorizationUrlWithPKCE() instead
     */
    public function getAuthorizationUrl(string $state = ''): string
    {
        $params = http_build_query([
            'response_type' => 'code',
            'client_id' => $this->config['app_id'],
            'redirect_uri' => $this->config['redirect_uri'],
            'state' => $state ?: bin2hex(random_bytes(16)),
        ]);

        return $this->getAuthUrl() . '/authorization?' . $params;
    }

    /**
     * Exchange authorization code for tokens
     *
     * @param string $code The authorization code received from the redirect
     * @param string|null $codeVerifier The PKCE code_verifier (required if PKCE was used in authorization)
     */
    public function exchangeCodeForToken(string $code, ?string $codeVerifier = null): array
    {
        try {
            $payload = [
                'grant_type' => 'authorization_code',
                'client_id' => $this->config['app_id'],
                'client_secret' => $this->config['client_secret'],
                'code' => $code,
                'redirect_uri' => $this->config['redirect_uri'],
            ];

            // Add code_verifier for PKCE flow
            if ($codeVerifier !== null) {
                $payload['code_verifier'] = $codeVerifier;
            }

            $response = Http::asForm()->post($this->getBaseUrl() . '/oauth/token', $payload);

            if ($response->successful()) {
                $data = $response->json();
                $this->accessToken = $data['access_token'];
                $this->userId = $data['user_id'] ?? null;

                return [
                    'success' => true,
                    'access_token' => $data['access_token'],
                    'refresh_token' => $data['refresh_token'] ?? null,
                    'expires_in' => $data['expires_in'] ?? 21600,
                    'user_id' => $data['user_id'] ?? null,
                ];
            }

            Log::error('MercadoLivre: Token exchange failed', ['response' => $response->json()]);
            return [
                'success' => false,
                'error' => $response->json()['message'] ?? $response->json()['error'] ?? 'Token exchange failed',
            ];

        } catch (\Exception $e) {
            Log::error('MercadoLivre: Token exchange exception', ['error' => $e->getMessage()]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Refresh access token
     */
    public function refreshToken(string $refreshToken): array
    {
        try {
            $response = Http::asForm()->post($this->getBaseUrl() . '/oauth/token', [
                'grant_type' => 'refresh_token',
                'client_id' => $this->config['app_id'],
                'client_secret' => $this->config['client_secret'],
                'refresh_token' => $refreshToken,
            ]);

            if ($response->successful()) {
                $data = $response->json();
                $this->accessToken = $data['access_token'];

                return [
                    'success' => true,
                    'access_token' => $data['access_token'],
                    'refresh_token' => $data['refresh_token'] ?? null,
                    'expires_in' => $data['expires_in'] ?? 21600,
                    'user_id' => $data['user_id'] ?? null,
                ];
            }

            return [
                'success' => false,
                'error' => $response->json()['message'] ?? 'Token refresh failed',
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
        $url = $this->getBaseUrl() . $endpoint;

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
    // Reference Data Methods
    // ========================================

    /**
     * Get user info (test connection)
     */
    public function getUserInfo(): array
    {
        return $this->apiRequest('GET', '/users/me');
    }

    /**
     * Predict category for a vehicle
     */
    public function predictCategory(string $title): array
    {
        $result = $this->apiRequest('GET', '/sites/' . $this->getSiteId() . '/category_predictor/predict', [], [
            'title' => $title,
        ]);

        if (!$result['success']) {
            return ['success' => false, 'category_id' => null, 'error' => $result['error']];
        }

        return [
            'success' => true,
            'category_id' => $result['data']['id'] ?? null,
            'category_name' => $result['data']['name'] ?? null,
        ];
    }

    /**
     * Get category attributes (required fields)
     */
    public function getCategoryAttributes(string $categoryId): array
    {
        // This endpoint doesn't require authentication
        try {
            $response = Http::get($this->getBaseUrl() . "/categories/{$categoryId}/attributes");

            if ($response->successful()) {
                return [
                    'success' => true,
                    'attributes' => $response->json(),
                ];
            }

            return ['success' => false, 'attributes' => [], 'error' => 'Failed to get attributes'];
        } catch (\Exception $e) {
            return ['success' => false, 'attributes' => [], 'error' => $e->getMessage()];
        }
    }

    /**
     * Get car brands for Brazil
     */
    public function getCarBrands(): array
    {
        $categoryId = $this->config['categories']['cars'];

        try {
            $response = Http::get($this->getBaseUrl() . "/categories/{$categoryId}");

            if ($response->successful()) {
                $data = $response->json();
                return [
                    'success' => true,
                    'data' => $data['children_categories'] ?? [],
                ];
            }

            return ['success' => false, 'data' => [], 'error' => 'Failed to get brands'];
        } catch (\Exception $e) {
            return ['success' => false, 'data' => [], 'error' => $e->getMessage()];
        }
    }

    // ========================================
    // Vehicle CRUD Operations
    // ========================================

    public function publishVehicle(array $vehicleData): array
    {
        $payload = $this->transformVehicleData($vehicleData);

        $result = $this->apiRequest('POST', '/items', $payload);

        if (!$result['success']) {
            return [
                'success' => false,
                'external_id' => null,
                'url' => null,
                'error' => $result['error'],
            ];
        }

        return [
            'success' => true,
            'external_id' => $result['data']['id'] ?? null,
            'url' => $result['data']['permalink'] ?? null,
            'error' => null,
        ];
    }

    public function updateVehicle(string $externalId, array $vehicleData): array
    {
        // ML has restrictions on what can be updated
        $payload = $this->transformVehicleDataForUpdate($vehicleData);

        $result = $this->apiRequest('PUT', "/items/{$externalId}", $payload);

        return [
            'success' => $result['success'],
            'error' => $result['error'],
        ];
    }

    public function removeVehicle(string $externalId): array
    {
        // ML uses status change to close listing
        $result = $this->apiRequest('PUT', "/items/{$externalId}", [
            'status' => 'closed',
        ]);

        return [
            'success' => $result['success'],
            'error' => $result['error'],
        ];
    }

    public function updateVehicleStatus(string $externalId, string $status): array
    {
        $mlStatus = match ($status) {
            'active' => 'active',
            'paused' => 'paused',
            'sold', 'inactive' => 'closed',
            default => $status,
        };

        $result = $this->apiRequest('PUT', "/items/{$externalId}", [
            'status' => $mlStatus,
        ]);

        return [
            'success' => $result['success'],
            'error' => $result['error'],
        ];
    }

    /**
     * Add/update item description
     */
    public function setDescription(string $itemId, string $description): array
    {
        $result = $this->apiRequest('PUT', "/items/{$itemId}/description", [
            'plain_text' => $description,
        ]);

        // If update fails (no description exists), create it
        if (!$result['success']) {
            $result = $this->apiRequest('POST', "/items/{$itemId}/description", [
                'plain_text' => $description,
            ]);
        }

        return [
            'success' => $result['success'],
            'error' => $result['error'],
        ];
    }

    // ========================================
    // Leads (Questions)
    // ========================================

    public function fetchLeads(array $filters = []): array
    {
        if (!$this->userId) {
            return ['success' => false, 'leads' => [], 'error' => 'User ID not set'];
        }

        $query = [
            'seller_id' => $this->userId,
            'status' => $filters['status'] ?? 'unanswered',
        ];

        if (!empty($filters['item_id'])) {
            $query['item'] = $filters['item_id'];
        }

        $result = $this->apiRequest('GET', '/questions/search', [], $query);

        if (!$result['success']) {
            return [
                'success' => false,
                'leads' => [],
                'error' => $result['error'],
            ];
        }

        $questions = $result['data']['questions'] ?? [];
        $leads = [];

        foreach ($questions as $q) {
            $leads[] = [
                'id' => $q['id'],
                'item_id' => $q['item_id'] ?? null,
                'message' => $q['text'] ?? '',
                'date' => $q['date_created'] ?? null,
                'from_id' => $q['from']['id'] ?? null,
                'status' => $q['status'] ?? 'unanswered',
            ];
        }

        return [
            'success' => true,
            'leads' => $leads,
            'error' => null,
        ];
    }

    /**
     * Answer a question (lead)
     */
    public function answerQuestion(int $questionId, string $answer): array
    {
        $result = $this->apiRequest('POST', "/answers", [
            'question_id' => $questionId,
            'text' => $answer,
        ]);

        return [
            'success' => $result['success'],
            'error' => $result['error'],
        ];
    }

    public function getPublishedVehicles(): array
    {
        if (!$this->userId) {
            return ['success' => false, 'vehicles' => [], 'error' => 'User ID not set'];
        }

        $result = $this->apiRequest('GET', "/users/{$this->userId}/items/search", [], [
            'status' => 'active',
        ]);

        if (!$result['success']) {
            return [
                'success' => false,
                'vehicles' => [],
                'error' => $result['error'],
            ];
        }

        $itemIds = $result['data']['results'] ?? [];

        if (empty($itemIds)) {
            return ['success' => true, 'vehicles' => [], 'error' => null];
        }

        // Get details for each item (batch up to 20)
        $vehicles = [];
        foreach (array_chunk($itemIds, 20) as $chunk) {
            $detailsResult = $this->apiRequest('GET', '/items', [], [
                'ids' => implode(',', $chunk),
            ]);

            if ($detailsResult['success']) {
                foreach ($detailsResult['data'] as $item) {
                    if (($item['code'] ?? 200) === 200) {
                        $vehicles[] = $item['body'] ?? $item;
                    }
                }
            }
        }

        return [
            'success' => true,
            'vehicles' => $vehicles,
            'error' => null,
        ];
    }

    // ========================================
    // Data Transformation
    // ========================================

    public function transformVehicleData(array $vehicle): array
    {
        $title = trim(sprintf(
            '%s %s %s %s',
            $vehicle['fipe_marca_nome'] ?? $vehicle['kbb_marca_nome'] ?? '',
            $vehicle['fipe_modelo_nome'] ?? $vehicle['kbb_modelo_nome'] ?? '',
            $vehicle['fipe_versao_nome'] ?? $vehicle['kbb_versao_nome'] ?? '',
            $vehicle['veiculo_ano_modelo'] ?? ''
        ));
        $title = substr($title, 0, 60);

        // Determine category
        $categoryId = $this->config['categories']['cars'];
        if (isset($vehicle['categoria_id'])) {
            $categoryId = match ((int) $vehicle['categoria_id']) {
                3 => $this->config['categories']['motorcycles'],
                2 => $this->config['categories']['trucks'],
                default => $this->config['categories']['cars'],
            };
        }

        // Build pictures array
        $pictures = [];
        $imageBaseUrl = config('portals.images.base_url') . config('portals.images.path_prefix');
        if (!empty($vehicle['imagens'])) {
            foreach (array_slice($vehicle['imagens'], 0, 12) as $img) {
                $imgName = $img->imagem_nome ?? $img['imagem_nome'] ?? null;
                if ($imgName) {
                    $pictures[] = ['source' => $imageBaseUrl . $imgName];
                }
            }
        }

        // Build attributes
        $attributes = $this->buildAttributes($vehicle);

        return [
            'title' => $title,
            'category_id' => $categoryId,
            'price' => (float) ($vehicle['veiculo_valor'] ?? 0),
            'currency_id' => 'BRL',
            'available_quantity' => 1,
            'buying_mode' => 'classified',
            'listing_type_id' => 'free',
            'condition' => ($vehicle['zero_km'] ?? false) ? 'new' : 'used',
            'pictures' => $pictures,
            'attributes' => $attributes,
            'channels' => ['marketplace'],
        ];
    }

    protected function transformVehicleDataForUpdate(array $vehicle): array
    {
        $data = [];

        if (isset($vehicle['veiculo_valor'])) {
            $data['price'] = (float) $vehicle['veiculo_valor'];
        }

        if (!empty($vehicle['imagens'])) {
            $pictures = [];
            $imageBaseUrl = config('portals.images.base_url') . config('portals.images.path_prefix');
            foreach (array_slice($vehicle['imagens'], 0, 12) as $img) {
                $imgName = $img->imagem_nome ?? $img['imagem_nome'] ?? null;
                if ($imgName) {
                    $pictures[] = ['source' => $imageBaseUrl . $imgName];
                }
            }
            $data['pictures'] = $pictures;
        }

        return $data;
    }

    protected function buildAttributes(array $vehicle): array
    {
        $attributes = [];

        // License Plate
        if (!empty($vehicle['veiculo_placa'])) {
            $plate = preg_replace('/[^A-Za-z0-9]/', '', $vehicle['veiculo_placa']);
            $attributes[] = [
                'id' => 'LICENSE_PLATE',
                'value_name' => strtoupper($plate),
            ];
        }

        // VIN last 6 digits
        if (!empty($vehicle['veiculo_renavam'])) {
            $vin = substr($vehicle['veiculo_renavam'], -6);
            $attributes[] = [
                'id' => 'VIN_LAST_DIGITS',
                'value_name' => $vin,
            ];
        }

        // Year
        if (!empty($vehicle['veiculo_ano_modelo'])) {
            $attributes[] = [
                'id' => 'VEHICLE_YEAR',
                'value_name' => (string) $vehicle['veiculo_ano_modelo'],
            ];
        }

        // Mileage
        $attributes[] = [
            'id' => 'KILOMETERS',
            'value_name' => (string) ($vehicle['veiculo_km'] ?? 0),
        ];

        // Fuel type
        if (!empty($vehicle['combustivel']['nome'])) {
            $attributes[] = [
                'id' => 'FUEL_TYPE',
                'value_name' => $this->mapFuelType($vehicle['combustivel']['nome']),
            ];
        }

        // Transmission
        if (!empty($vehicle['cambio']['nome'])) {
            $attributes[] = [
                'id' => 'TRANSMISSION',
                'value_name' => $this->mapTransmission($vehicle['cambio']['nome']),
            ];
        }

        // Color
        if (!empty($vehicle['cor']['nome'])) {
            $attributes[] = [
                'id' => 'COLOR',
                'value_name' => $vehicle['cor']['nome'],
            ];
        }

        // Doors
        if (!empty($vehicle['veiculo_portas'])) {
            $attributes[] = [
                'id' => 'DOORS',
                'value_name' => (string) $vehicle['veiculo_portas'],
            ];
        }

        // Item condition
        $attributes[] = [
            'id' => 'ITEM_CONDITION',
            'value_name' => ($vehicle['zero_km'] ?? false) ? 'Novo' : 'Usado',
        ];

        return $attributes;
    }

    protected function mapFuelType(string $fuel): string
    {
        return match (strtolower($fuel)) {
            'gasolina' => 'Gasolina',
            'etanol' => 'Álcool',
            'etanol/gasolina', 'flex' => 'Flex',
            'diesel' => 'Diesel',
            'gnv' => 'GNV',
            'elétrico', 'eletrico' => 'Elétrico',
            'híbrido', 'hibrido' => 'Híbrido',
            default => 'Flex',
        };
    }

    protected function mapTransmission(string $transmission): string
    {
        return match (strtolower($transmission)) {
            'manual' => 'Manual',
            'automático', 'automatico' => 'Automática',
            'automatizado' => 'Automatizada',
            default => 'Manual',
        };
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
            return $body['error'] . ': ' . ($body['message'] ?? '');
        }

        if (isset($body['cause'])) {
            $causes = is_array($body['cause']) ? $body['cause'] : [$body['cause']];
            return implode('; ', array_map(fn($c) => is_array($c) ? ($c['message'] ?? json_encode($c)) : $c, $causes));
        }

        return 'Unknown error';
    }
}
