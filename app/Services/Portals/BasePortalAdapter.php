<?php

namespace App\Services\Portals;

use App\Models\PortalSyncLog;
use App\Services\Portals\Contracts\PortalAdapterInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

abstract class BasePortalAdapter implements PortalAdapterInterface
{
    protected array $config = [];
    protected ?string $accessToken = null;

    abstract public function getPortalName(): string;
    abstract public function getBaseUrl(): string;

    /**
     * Set credentials from config array
     * Credentials come from config('portals.{portal}')
     */
    public function setCredentials($credentials): self
    {
        if (is_array($credentials)) {
            $this->config = $credentials;
        }
        return $this;
    }

    public function setAccessToken(string $token): self
    {
        $this->accessToken = $token;
        return $this;
    }

    public function isAuthenticated(): bool
    {
        return $this->accessToken !== null;
    }

    /**
     * Make an HTTP request to the portal API with logging
     */
    protected function request(
        string $method,
        string $endpoint,
        array $data = [],
        array $headers = []
    ): array {
        $startTime = microtime(true);
        $url = $this->getBaseUrl() . $endpoint;

        $defaultHeaders = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];

        if ($this->accessToken) {
            $defaultHeaders['Authorization'] = 'Bearer ' . $this->accessToken;
        }

        $headers = array_merge($defaultHeaders, $headers);

        try {
            $request = Http::withHeaders($headers);

            $response = match (strtoupper($method)) {
                'GET' => $request->get($url, $data),
                'POST' => $request->post($url, $data),
                'PUT' => $request->put($url, $data),
                'PATCH' => $request->patch($url, $data),
                'DELETE' => $request->delete($url, $data),
                default => throw new \InvalidArgumentException("Invalid HTTP method: {$method}"),
            };

            $durationMs = (int) ((microtime(true) - $startTime) * 1000);

            $result = [
                'success' => $response->successful(),
                'status' => $response->status(),
                'body' => $response->json() ?? $response->body(),
            ];

            // Log the request
            $this->logRequest(
                $method,
                $endpoint,
                $response->status(),
                $data,
                $result['body'],
                $response->successful() ? PortalSyncLog::RESULT_SUCCESS : PortalSyncLog::RESULT_ERROR,
                $durationMs,
                $response->successful() ? null : ($result['body']['message'] ?? 'Request failed')
            );

            return $result;

        } catch (\Exception $e) {
            $durationMs = (int) ((microtime(true) - $startTime) * 1000);

            Log::error("Portal API Error [{$this->getPortalName()}]", [
                'endpoint' => $endpoint,
                'error' => $e->getMessage(),
            ]);

            $this->logRequest(
                $method,
                $endpoint,
                null,
                $data,
                null,
                PortalSyncLog::RESULT_ERROR,
                $durationMs,
                $e->getMessage()
            );

            return [
                'success' => false,
                'status' => null,
                'body' => null,
                'error' => $e->getMessage(),
            ];
        }
    }

    protected function logRequest(
        string $method,
        string $endpoint,
        ?int $status,
        array $payload,
        mixed $response,
        string $result,
        int $durationMs,
        ?string $error = null
    ): void {
        PortalSyncLog::log(
            $this->getPortalName(),
            $method . ' ' . $endpoint,
            $result,
            [
                'http_method' => $method,
                'endpoint' => $endpoint,
                'http_status' => $status,
                'request_payload' => $payload,
                'response_body' => is_array($response) ? $response : ['raw' => $response],
                'error_message' => $error,
                'duration_ms' => $durationMs,
            ]
        );
    }

    /**
     * Generate a hash for vehicle content to detect changes
     */
    protected function generateContentHash(array $vehicleData): string
    {
        // Sort keys for consistent hashing
        ksort($vehicleData);
        return md5(json_encode($vehicleData));
    }
}
