<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class SetupWebMotorsCredentials extends Command
{
    protected $signature = 'webmotors:setup
                            {--client-id= : The Client ID from WebMotors developer portal}
                            {--client-secret= : The Client Secret from WebMotors developer portal}
                            {--username= : SOAP API username}
                            {--password= : SOAP API password}
                            {--cnpj= : Dealer CNPJ}';

    protected $description = 'Show WebMotors .env configuration';

    public function handle(): int
    {
        $this->info('WebMotors Configuration Setup');
        $this->newLine();

        $this->info('WebMotors has two APIs:');
        $this->line('1. REST API (for channels) - uses Client ID/Secret');
        $this->line('2. SOAP API (for dealers) - uses Username/Password/CNPJ');
        $this->newLine();

        $apiType = $this->choice('Which API are you configuring?', ['REST (channels)', 'SOAP (dealers)'], 1);

        $this->newLine();
        $this->warn('Add these lines to your .env file:');
        $this->newLine();

        if ($apiType === 'REST (channels)') {
            $clientId = $this->option('client-id') ?: $this->ask('Enter Client ID');
            $clientSecret = $this->option('client-secret') ?: $this->secret('Enter Client Secret');

            $this->line("WEBMOTORS_CLIENT_ID={$clientId}");
            $this->line("WEBMOTORS_CLIENT_SECRET={$clientSecret}");
        } else {
            $username = $this->option('username') ?: $this->ask('Enter SOAP Username');
            $password = $this->option('password') ?: $this->secret('Enter SOAP Password');
            $cnpj = $this->option('cnpj') ?: $this->ask('Enter Dealer CNPJ');

            $this->line("WEBMOTORS_SOAP_USERNAME={$username}");
            $this->line("WEBMOTORS_SOAP_PASSWORD={$password}");
            $this->line("WEBMOTORS_CNPJ={$cnpj}");
        }

        $this->newLine();
        $this->info('After adding to .env, run: php artisan config:clear');
        $this->newLine();
        $this->info('Run: php artisan webmotors:test to verify the connection.');

        return Command::SUCCESS;
    }
}
