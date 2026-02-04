<?php

namespace App\Console\Commands;

use App\Services\Portals\MercadoLivre\MercadoLivreAdapter;
use App\Services\Portals\OLX\OlxAdapter;
use Illuminate\Console\Command;

class RefreshPortalToken extends Command
{
    protected $signature = 'portal:refresh-token
                            {portal : Portal name (olx, mercadolivre)}
                            {--token= : Refresh token (uses .env if not provided)}';

    protected $description = 'Refresh access token using refresh token';

    public function handle(): int
    {
        $portal = strtolower($this->argument('portal'));

        $refreshToken = $this->option('token');

        if (!$refreshToken) {
            $config = config("portals.{$portal}") ?? config("portals." . ($portal === 'ml' ? 'mercadolivre' : $portal));

            if (!$config) {
                $this->error("Unknown portal: {$portal}");
                return Command::FAILURE;
            }

            $refreshToken = $config['refresh_token'] ?? null;
        }

        if (!$refreshToken) {
            $this->error('No refresh token available. Provide one with --token= or set it in .env');
            return Command::FAILURE;
        }

        $this->info("Refreshing token for {$portal}...");

        $result = match ($portal) {
            'olx' => (new OlxAdapter())->refreshToken($refreshToken),
            'mercadolivre', 'ml' => (new MercadoLivreAdapter())->refreshToken($refreshToken),
            default => ['success' => false, 'error' => "Unknown portal: {$portal}"],
        };

        if (!$result['success']) {
            $this->error('Refresh failed: ' . ($result['error'] ?? 'Unknown error'));
            return Command::FAILURE;
        }

        $this->info('SUCCESS! Update these in your .env file:');
        $this->newLine();

        $prefix = match ($portal) {
            'olx' => 'OLX',
            'mercadolivre', 'ml' => 'MERCADOLIVRE',
            default => strtoupper($portal),
        };

        $this->line("{$prefix}_ACCESS_TOKEN={$result['access_token']}");

        if (!empty($result['refresh_token'])) {
            $this->line("{$prefix}_REFRESH_TOKEN={$result['refresh_token']}");
        }

        if (!empty($result['user_id'])) {
            $this->line("{$prefix}_USER_ID={$result['user_id']}");
        }

        $this->newLine();
        $this->info("Token expires in: " . ($result['expires_in'] ?? 'unknown') . " seconds");

        return Command::SUCCESS;
    }
}
