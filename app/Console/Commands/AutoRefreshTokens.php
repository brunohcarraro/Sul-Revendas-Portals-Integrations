<?php

namespace App\Console\Commands;

use App\Services\Portals\MercadoLivre\MercadoLivreAdapter;
use App\Services\Portals\ICarros\ICarrosAdapter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class AutoRefreshTokens extends Command
{
    protected $signature = 'portal:auto-refresh
                            {--force : Force refresh even if token is not expired}
                            {--portal= : Specific portal to refresh (mercadolivre, icarros)}';

    protected $description = 'Automatically refresh portal tokens and update .env file';

    /**
     * Path to the .env file
     */
    protected string $envPath;

    public function __construct()
    {
        parent::__construct();
        $this->envPath = base_path('.env');
    }

    public function handle(): int
    {
        $specificPortal = $this->option('portal');
        $force = $this->option('force');

        $portals = $specificPortal
            ? [$specificPortal]
            : ['mercadolivre', 'icarros'];

        $hasError = false;

        foreach ($portals as $portal) {
            $this->info("Checking {$portal}...");

            $result = match ($portal) {
                'mercadolivre' => $this->refreshMercadoLivre($force),
                'icarros' => $this->refreshICarros($force),
                default => ['success' => false, 'error' => "Unknown portal: {$portal}", 'skipped' => false],
            };

            if ($result['skipped'] ?? false) {
                $this->info("  Token still valid, skipped.");
                continue;
            }

            if (!$result['success']) {
                $this->error("  Failed: " . ($result['error'] ?? 'Unknown error'));
                Log::error("Auto-refresh failed for {$portal}: " . ($result['error'] ?? 'Unknown error'));
                $hasError = true;
                continue;
            }

            $this->info("  Token refreshed successfully!");
            Log::info("Auto-refresh successful for {$portal}");
        }

        return $hasError ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * Refresh Mercado Livre token
     */
    protected function refreshMercadoLivre(bool $force): array
    {
        $config = config('portals.mercadolivre');

        if (!$config || empty($config['refresh_token'])) {
            return ['success' => false, 'error' => 'No refresh token configured', 'skipped' => false];
        }

        // Check if we need to refresh (ML tokens expire in 6 hours = 21600 seconds)
        // We refresh 1 hour before expiry to be safe
        if (!$force && !$this->shouldRefreshMercadoLivre()) {
            return ['success' => true, 'skipped' => true];
        }

        $adapter = new MercadoLivreAdapter();
        $result = $adapter->refreshToken($config['refresh_token']);

        if (!$result['success']) {
            return $result;
        }

        // Update .env file
        $this->updateEnvValue('MERCADOLIVRE_ACCESS_TOKEN', $result['access_token']);

        if (!empty($result['refresh_token'])) {
            $this->updateEnvValue('MERCADOLIVRE_REFRESH_TOKEN', $result['refresh_token']);
        }

        if (!empty($result['user_id'])) {
            $this->updateEnvValue('MERCADOLIVRE_USER_ID', $result['user_id']);
        }

        // Store token expiry time
        $expiresAt = now()->addSeconds($result['expires_in'] ?? 21600)->timestamp;
        $this->updateEnvValue('MERCADOLIVRE_TOKEN_EXPIRES_AT', $expiresAt);

        // Clear config cache so new values are loaded
        $this->call('config:clear', [], $this->output);

        return $result;
    }

    /**
     * Refresh iCarros token
     */
    protected function refreshICarros(bool $force): array
    {
        $config = config('portals.icarros');

        if (!$config || empty($config['refresh_token'])) {
            return ['success' => false, 'error' => 'No refresh token configured', 'skipped' => false];
        }

        // iCarros access tokens expire in 5 minutes, refresh tokens in 60 days
        // We should refresh proactively
        if (!$force && !$this->shouldRefreshICarros()) {
            return ['success' => true, 'skipped' => true];
        }

        $adapter = new ICarrosAdapter();
        $result = $adapter->refreshTokenAuth($config['refresh_token']);

        if (!$result['success']) {
            return $result;
        }

        // Update .env file
        $this->updateEnvValue('ICARROS_ACCESS_TOKEN', $result['access_token']);

        if (!empty($result['refresh_token'])) {
            $this->updateEnvValue('ICARROS_REFRESH_TOKEN', $result['refresh_token']);
        }

        // Store token expiry time
        $expiresAt = now()->addSeconds($result['expires_in'] ?? 300)->timestamp;
        $this->updateEnvValue('ICARROS_TOKEN_EXPIRES_AT', $expiresAt);

        // Clear config cache
        $this->call('config:clear', [], $this->output);

        return $result;
    }

    /**
     * Check if Mercado Livre token should be refreshed
     */
    protected function shouldRefreshMercadoLivre(): bool
    {
        $expiresAt = config('portals.mercadolivre.token_expires_at');

        if (!$expiresAt) {
            // No expiry stored, assume we should refresh
            return true;
        }

        // Refresh 1 hour before expiry
        $refreshThreshold = now()->addHour()->timestamp;

        return $expiresAt <= $refreshThreshold;
    }

    /**
     * Check if iCarros token should be refreshed
     */
    protected function shouldRefreshICarros(): bool
    {
        $expiresAt = config('portals.icarros.token_expires_at');

        if (!$expiresAt) {
            // No expiry stored, assume we should refresh
            return true;
        }

        // Refresh 1 minute before expiry (iCarros tokens are short-lived)
        $refreshThreshold = now()->addMinute()->timestamp;

        return $expiresAt <= $refreshThreshold;
    }

    /**
     * Update a value in the .env file
     */
    protected function updateEnvValue(string $key, string $value): void
    {
        $envContent = file_get_contents($this->envPath);

        // Check if key exists
        if (preg_match("/^{$key}=.*/m", $envContent)) {
            // Update existing key
            $envContent = preg_replace(
                "/^{$key}=.*/m",
                "{$key}={$value}",
                $envContent
            );
        } else {
            // Add new key at the end
            $envContent .= "\n{$key}={$value}";
        }

        file_put_contents($this->envPath, $envContent);

        $this->line("  Updated {$key}");
    }
}
