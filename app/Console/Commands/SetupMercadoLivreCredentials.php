<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class SetupMercadoLivreCredentials extends Command
{
    protected $signature = 'mercadolivre:setup
                            {--app-id= : The App ID from Mercado Livre}
                            {--client-secret= : The Client Secret from Mercado Livre}
                            {--redirect-uri= : The redirect URI for OAuth}';

    protected $description = 'Show Mercado Livre .env configuration';

    public function handle(): int
    {
        $this->info('Mercado Livre Configuration Setup');
        $this->newLine();

        $appId = $this->option('app-id') ?: $this->ask('Enter App ID');
        $clientSecret = $this->option('client-secret') ?: $this->secret('Enter Client Secret');
        $redirectUri = $this->option('redirect-uri') ?: $this->ask('Enter Redirect URI', 'https://yourdomain.com/oauth/mercadolivre/callback');

        if (!$appId || !$clientSecret) {
            $this->error('App ID and Client Secret are required.');
            return Command::FAILURE;
        }

        $this->newLine();
        $this->warn('Add these lines to your .env file:');
        $this->newLine();
        $this->line("MERCADOLIVRE_APP_ID={$appId}");
        $this->line("MERCADOLIVRE_CLIENT_SECRET={$clientSecret}");
        $this->line("MERCADOLIVRE_REDIRECT_URI={$redirectUri}");
        $this->newLine();

        $this->info('After adding to .env, run: php artisan config:clear');
        $this->newLine();

        $this->warn('Mercado Livre requires OAuth authorization:');
        $this->line('1. Visit /oauth/mercadolivre/authorize to start OAuth flow');
        $this->line('2. After authorization, the access token will be shown');
        $this->line('3. Add MERCADOLIVRE_ACCESS_TOKEN to your .env file');

        return Command::SUCCESS;
    }
}
