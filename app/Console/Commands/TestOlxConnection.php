<?php

namespace App\Console\Commands;

use App\Services\Portals\OLX\OlxAdapter;
use Illuminate\Console\Command;

class TestOlxConnection extends Command
{
    protected $signature = 'olx:test';
    protected $description = 'Test OLX API configuration and connection';

    public function handle(): int
    {
        $this->info('Testing OLX Configuration...');
        $this->newLine();

        // Check config
        $config = config('portals.olx');

        $this->info('Configuration from .env:');
        $this->line('  Enabled: ' . ($config['enabled'] ? 'Yes' : 'No'));
        $this->line('  Client ID: ' . ($config['client_id'] ? substr($config['client_id'], 0, 20) . '...' : 'NOT SET'));
        $this->line('  Client Secret: ' . ($config['client_secret'] ? '***SET***' : 'NOT SET'));
        $this->line('  Redirect URI: ' . ($config['redirect_uri'] ?? 'NOT SET'));
        $this->line('  Access Token: ' . ($config['access_token'] ? '***SET***' : 'NOT SET'));
        $this->newLine();

        if (!$config['enabled']) {
            $this->error('OLX is not enabled in .env (OLX_ENABLED=false)');
            return Command::FAILURE;
        }

        if (!$config['client_id'] || !$config['client_secret']) {
            $this->error('Missing OLX_CLIENT_ID or OLX_CLIENT_SECRET in .env');
            return Command::FAILURE;
        }

        // Create adapter
        $adapter = new OlxAdapter();

        // Check if we have a token
        if (!$config['access_token']) {
            $this->warn('No access token configured. OAuth authorization required.');
            $this->newLine();

            $authUrl = $adapter->getAuthorizationUrl('test_state');
            $this->info('Authorization URL:');
            $this->line($authUrl);
            $this->newLine();
            $this->info('After authorization, add OLX_ACCESS_TOKEN to .env');

            return Command::SUCCESS;
        }

        // Test API with token
        $adapter->setAccessToken($config['access_token']);

        $this->info('Testing API with access token...');
        $result = $adapter->getCarBrands();

        if ($result['success']) {
            $count = count($result['data']);
            $this->info("SUCCESS: Found {$count} car brands");

            if ($count > 0 && $this->option('verbose')) {
                $brands = array_slice($result['data'], 0, 5, true);
                $this->table(['Brand', 'ID'], collect($brands)->map(fn($id, $name) => [$name, $id])->toArray());
            }

            return Command::SUCCESS;
        }

        $this->error('API test failed: ' . ($result['error'] ?? 'Unknown error'));

        // Check last log entry for more details
        $this->newLine();
        $this->warn('Checking recent sync logs...');
        $lastLog = \App\Models\PortalSyncLog::where('portal', 'olx')
            ->orderBy('created_at', 'desc')
            ->first();

        if ($lastLog) {
            $details = $lastLog->details;
            $this->line('  HTTP Status: ' . ($details['http_status'] ?? 'N/A'));
            $this->line('  Error: ' . ($details['error_message'] ?? 'N/A'));
            if (!empty($details['response_body'])) {
                $this->line('  Response: ' . json_encode($details['response_body']));
            }
        }

        return Command::FAILURE;
    }
}
