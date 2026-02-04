<?php

namespace App\Console\Commands;

use App\Services\Portals\MercadoLivre\MercadoLivreAdapter;
use App\Services\Portals\OLX\OlxAdapter;
use Illuminate\Console\Command;

class ExchangeOAuthCode extends Command
{
    protected $signature = 'portal:exchange-code
                            {portal : Portal name (olx, mercadolivre)}
                            {code : Authorization code from OAuth callback}';

    protected $description = 'Exchange OAuth authorization code for access/refresh tokens';

    public function handle(): int
    {
        $portal = strtolower($this->argument('portal'));
        $code = $this->argument('code');

        $this->info("Exchanging code for {$portal}...");
        $this->newLine();

        $result = match ($portal) {
            'olx' => $this->exchangeOlx($code),
            'mercadolivre', 'ml' => $this->exchangeMercadoLivre($code),
            default => ['success' => false, 'error' => "Unknown portal: {$portal}"],
        };

        if (!$result['success']) {
            $this->error('Exchange failed: ' . ($result['error'] ?? 'Unknown error'));
            return Command::FAILURE;
        }

        $this->info('SUCCESS! Add these to your .env file:');
        $this->newLine();

        if ($portal === 'olx') {
            $this->line("OLX_ACCESS_TOKEN={$result['access_token']}");
            if (!empty($result['refresh_token'])) {
                $this->line("OLX_REFRESH_TOKEN={$result['refresh_token']}");
            }
        } else {
            $this->line("MERCADOLIVRE_ACCESS_TOKEN={$result['access_token']}");
            if (!empty($result['refresh_token'])) {
                $this->line("MERCADOLIVRE_REFRESH_TOKEN={$result['refresh_token']}");
            }
            if (!empty($result['user_id'])) {
                $this->line("MERCADOLIVRE_USER_ID={$result['user_id']}");
            }
        }

        $this->newLine();
        $this->info("Token expires in: " . ($result['expires_in'] ?? 'unknown') . " seconds");

        return Command::SUCCESS;
    }

    protected function exchangeOlx(string $code): array
    {
        $adapter = new OlxAdapter();
        return $adapter->exchangeCodeForToken($code);
    }

    protected function exchangeMercadoLivre(string $code): array
    {
        $adapter = new MercadoLivreAdapter();
        return $adapter->exchangeCodeForToken($code);
    }
}
