<?php

namespace App\Console\Commands;

use App\Services\Portals\OLX\OlxAdapter;
use Illuminate\Console\Command;

class OlxExchangeCode extends Command
{
    protected $signature = 'olx:exchange-code {code : The authorization code from the redirect URL}';
    protected $description = 'Exchange OLX authorization code for access token';

    public function handle(): int
    {
        $code = $this->argument('code');

        $this->info('Exchanging authorization code for access token...');

        $adapter = new OlxAdapter();
        $result = $adapter->exchangeCodeForToken($code);

        if (!$result['success']) {
            $this->error('Token exchange failed: ' . ($result['error'] ?? 'Unknown error'));

            // Check logs for more details
            $this->newLine();
            $this->warn('Checking recent logs...');
            $lastLog = \App\Models\PortalSyncLog::where('portal', 'olx')
                ->orderBy('created_at', 'desc')
                ->first();

            if ($lastLog) {
                $details = $lastLog->details;
                $this->line('HTTP Status: ' . ($details['http_status'] ?? 'N/A'));
                if (!empty($details['response_body'])) {
                    $this->line('Response: ' . json_encode($details['response_body'], JSON_PRETTY_PRINT));
                }
            }

            return Command::FAILURE;
        }

        $this->newLine();
        $this->info('Success! Add these to your .env file:');
        $this->newLine();

        $this->line("OLX_ACCESS_TOKEN={$result['access_token']}");
        if (!empty($result['refresh_token'])) {
            $this->line("OLX_REFRESH_TOKEN={$result['refresh_token']}");
        }

        $this->newLine();
        $this->warn("Token expires in: {$result['expires_in']} seconds");
        $this->info('Run: php artisan config:clear after updating .env');

        return Command::SUCCESS;
    }
}
