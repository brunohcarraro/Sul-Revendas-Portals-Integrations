<?php

namespace App\Console\Commands;

use App\Services\Portals\MercadoLivre\MercadoLivreAdapter;
use Illuminate\Console\Command;

class MercadoLivreOAuth extends Command
{
    protected $signature = 'mercadolivre:oauth
                            {action : Action to perform: authorize, exchange}
                            {--code= : Authorization code for exchange action}
                            {--verifier= : Code verifier for exchange action}';

    protected $description = 'Mercado Livre OAuth flow with PKCE support';

    public function handle(): int
    {
        $action = $this->argument('action');

        return match ($action) {
            'authorize' => $this->handleAuthorize(),
            'exchange' => $this->handleExchange(),
            default => $this->showHelp(),
        };
    }

    protected function handleAuthorize(): int
    {
        $adapter = new MercadoLivreAdapter();

        $this->info('Generating Mercado Livre Authorization URL with PKCE...');
        $this->newLine();

        $pkceData = $adapter->getAuthorizationUrlWithPKCE('sulrevendas');

        $this->warn('IMPORTANT: Save this code_verifier - you will need it to exchange the code for tokens!');
        $this->newLine();

        $this->table(['Parameter', 'Value'], [
            ['code_verifier', $pkceData['code_verifier']],
            ['code_challenge', $pkceData['code_challenge']],
            ['state', $pkceData['state']],
        ]);

        $this->newLine();
        $this->info('Authorization URL:');
        $this->line($pkceData['url']);
        $this->newLine();

        $this->warn('Steps:');
        $this->line('1. Copy the code_verifier above and save it');
        $this->line('2. Open the authorization URL in a browser');
        $this->line('3. Log in and authorize the application');
        $this->line('4. Copy the "code" parameter from the redirect URL');
        $this->line('5. Run: php artisan mercadolivre:oauth exchange --code=YOUR_CODE --verifier=YOUR_VERIFIER');

        return Command::SUCCESS;
    }

    protected function handleExchange(): int
    {
        $code = $this->option('code');
        $verifier = $this->option('verifier');

        if (!$code) {
            $code = $this->ask('Enter the authorization code from the redirect URL');
        }

        if (!$verifier) {
            $verifier = $this->ask('Enter the code_verifier you saved from the authorize step');
        }

        if (!$code || !$verifier) {
            $this->error('Both code and code_verifier are required.');
            return Command::FAILURE;
        }

        $adapter = new MercadoLivreAdapter();

        $this->info('Exchanging code for tokens...');

        $result = $adapter->exchangeCodeForToken($code, $verifier);

        if (!$result['success']) {
            $this->error('Token exchange failed: ' . ($result['error'] ?? 'Unknown error'));
            return Command::FAILURE;
        }

        $this->newLine();
        $this->info('Success! Add these to your .env file:');
        $this->newLine();

        $this->line("MERCADOLIVRE_ACCESS_TOKEN={$result['access_token']}");
        $this->line("MERCADOLIVRE_REFRESH_TOKEN={$result['refresh_token']}");
        $this->line("MERCADOLIVRE_USER_ID={$result['user_id']}");

        $this->newLine();
        $this->warn("Token expires in: {$result['expires_in']} seconds");
        $this->info('Run: php artisan config:clear after updating .env');

        return Command::SUCCESS;
    }

    protected function showHelp(): int
    {
        $this->error('Invalid action. Use "authorize" or "exchange".');
        $this->newLine();

        $this->info('Usage:');
        $this->line('  php artisan mercadolivre:oauth authorize');
        $this->line('    - Generates authorization URL with PKCE');
        $this->newLine();
        $this->line('  php artisan mercadolivre:oauth exchange --code=XXX --verifier=YYY');
        $this->line('    - Exchanges authorization code for access tokens');

        return Command::FAILURE;
    }
}
