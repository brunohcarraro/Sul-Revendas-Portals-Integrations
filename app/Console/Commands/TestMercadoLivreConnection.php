<?php

namespace App\Console\Commands;

use App\Services\Portals\MercadoLivre\MercadoLivreAdapter;
use Illuminate\Console\Command;

class TestMercadoLivreConnection extends Command
{
    protected $signature = 'mercadolivre:test';
    protected $description = 'Test Mercado Livre API configuration and connection';

    public function handle(): int
    {
        $this->info('Testing Mercado Livre Configuration...');
        $this->newLine();

        // Check config
        $config = config('portals.mercadolivre');

        $this->info('Configuration from .env:');
        $this->line('  Enabled: ' . ($config['enabled'] ? 'Yes' : 'No'));
        $this->line('  App ID: ' . ($config['app_id'] ? substr($config['app_id'], 0, 20) . '...' : 'NOT SET'));
        $this->line('  Client Secret: ' . ($config['client_secret'] ? '***SET***' : 'NOT SET'));
        $this->line('  Redirect URI: ' . ($config['redirect_uri'] ?? 'NOT SET'));
        $this->line('  Access Token: ' . ($config['access_token'] ? '***SET***' : 'NOT SET'));
        $this->line('  User ID: ' . ($config['user_id'] ?? 'NOT SET'));
        $this->line('  Site ID: ' . ($config['site_id'] ?? 'NOT SET'));
        $this->newLine();

        if (!$config['enabled']) {
            $this->warn('Mercado Livre is not enabled in .env (MERCADOLIVRE_ENABLED=false)');
            $this->newLine();
        }

        if (!$config['app_id'] || !$config['client_secret']) {
            $this->error('Missing MERCADOLIVRE_APP_ID or MERCADOLIVRE_CLIENT_SECRET in .env');
            $this->newLine();
            $this->info('To set up Mercado Livre:');
            $this->line('1. Create an app at https://developers.mercadolivre.com.br/devcenter');
            $this->line('2. Get your App ID and Client Secret');
            $this->line('3. Add them to .env:');
            $this->line('   MERCADOLIVRE_ENABLED=true');
            $this->line('   MERCADOLIVRE_APP_ID=your_app_id');
            $this->line('   MERCADOLIVRE_CLIENT_SECRET=your_client_secret');
            $this->line('   MERCADOLIVRE_REDIRECT_URI=your_redirect_uri');
            return Command::FAILURE;
        }

        // Create adapter
        $adapter = new MercadoLivreAdapter();

        // Check if we have a token
        if (!$config['access_token']) {
            $this->warn('No access token configured. OAuth authorization required.');
            $this->newLine();

            if (!$config['redirect_uri']) {
                $this->error('MERCADOLIVRE_REDIRECT_URI must be set before generating authorization URL');
                return Command::FAILURE;
            }

            $authUrl = $adapter->getAuthorizationUrl('sulrevendas');
            $this->info('Authorization URL:');
            $this->line($authUrl);
            $this->newLine();
            $this->info('After authorization, add these to .env:');
            $this->line('  MERCADOLIVRE_ACCESS_TOKEN=...');
            $this->line('  MERCADOLIVRE_REFRESH_TOKEN=...');
            $this->line('  MERCADOLIVRE_USER_ID=...');

            return Command::SUCCESS;
        }

        // Test API with token
        $adapter->setAccessToken($config['access_token']);
        if ($config['user_id']) {
            $adapter->setUserId($config['user_id']);
        }

        $this->info('Testing API connection...');

        // Test user info
        $result = $adapter->getUserInfo();

        if ($result['success']) {
            $user = $result['data'];
            $this->info('SUCCESS: Connected to Mercado Livre');
            $this->newLine();
            $this->line('  User ID: ' . ($user['id'] ?? 'N/A'));
            $this->line('  Nickname: ' . ($user['nickname'] ?? 'N/A'));
            $this->line('  Email: ' . ($user['email'] ?? 'N/A'));
            $this->line('  Site: ' . ($user['site_id'] ?? 'N/A'));
            $this->line('  Seller Experience: ' . ($user['seller_experience'] ?? 'N/A'));

            // Test fetching categories (no auth required)
            $this->newLine();
            $this->info('Testing category API...');
            $brandsResult = $adapter->getCarBrands();
            if ($brandsResult['success']) {
                $count = count($brandsResult['data']);
                $this->info("Found {$count} car subcategories");

                if ($count > 0 && $this->option('verbose')) {
                    $brands = array_slice($brandsResult['data'], 0, 5);
                    $this->table(['Name', 'ID'], array_map(fn($b) => [$b['name'], $b['id']], $brands));
                }
            }

            return Command::SUCCESS;
        }

        $this->error('API test failed: ' . ($result['error'] ?? 'Unknown error'));

        if (str_contains($result['error'] ?? '', 'expired') || str_contains($result['error'] ?? '', 'invalid')) {
            $this->newLine();
            $this->warn('Token may be expired. Try refreshing with:');
            $this->line('  php artisan mercadolivre:refresh-token');
        }

        return Command::FAILURE;
    }
}
