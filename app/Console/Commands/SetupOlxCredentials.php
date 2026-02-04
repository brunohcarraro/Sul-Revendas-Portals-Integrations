<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class SetupOlxCredentials extends Command
{
    protected $signature = 'olx:setup
                            {--client-id= : The Client ID from OLX}
                            {--client-secret= : The Client Secret from OLX}
                            {--redirect-uri= : The redirect URI for OAuth}';

    protected $description = 'Show OLX .env configuration';

    public function handle(): int
    {
        $this->info('OLX Configuration Setup');
        $this->newLine();

        $clientId = $this->option('client-id') ?: $this->ask('Enter Client ID');
        $clientSecret = $this->option('client-secret') ?: $this->secret('Enter Client Secret');
        $redirectUri = $this->option('redirect-uri') ?: $this->ask('Enter Redirect URI', 'https://yourdomain.com/oauth/olx/callback');

        if (!$clientId || !$clientSecret) {
            $this->error('Client ID and Client Secret are required.');
            return Command::FAILURE;
        }

        $this->newLine();
        $this->warn('Add these lines to your .env file:');
        $this->newLine();
        $this->line("OLX_CLIENT_ID={$clientId}");
        $this->line("OLX_CLIENT_SECRET={$clientSecret}");
        $this->line("OLX_REDIRECT_URI={$redirectUri}");
        $this->newLine();

        $this->info('After adding to .env, run: php artisan config:clear');
        $this->newLine();

        $this->warn('OLX requires OAuth authorization:');
        $this->line('1. Visit /oauth/olx/authorize to start OAuth flow');
        $this->line('2. After authorization, the access token will be shown');
        $this->line('3. Add OLX_ACCESS_TOKEN to your .env file');

        return Command::SUCCESS;
    }
}
