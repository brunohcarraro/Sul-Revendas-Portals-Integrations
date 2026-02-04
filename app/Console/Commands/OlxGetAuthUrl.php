<?php

namespace App\Console\Commands;

use App\Services\Portals\OLX\OlxAdapter;
use Illuminate\Console\Command;

class OlxGetAuthUrl extends Command
{
    protected $signature = 'olx:auth-url';
    protected $description = 'Generate OLX OAuth authorization URL';

    public function handle(): int
    {
        $adapter = new OlxAdapter();

        $this->info('OLX OAuth Authorization URL:');
        $this->newLine();
        $this->line($adapter->getAuthorizationUrl('sulrevendas'));
        $this->newLine();

        $this->warn('Steps:');
        $this->line('1. Log in to OLX account (portalsulrevendas@gmail.com)');
        $this->line('2. Open the URL above in your browser');
        $this->line('3. Authorize the application');
        $this->line('4. Copy the "code" parameter from the redirect URL');
        $this->line('5. Run: php artisan olx:exchange-code YOUR_CODE');

        return Command::SUCCESS;
    }
}
