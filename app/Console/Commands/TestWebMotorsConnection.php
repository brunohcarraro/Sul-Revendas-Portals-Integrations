<?php

namespace App\Console\Commands;

use App\Services\Portals\WebMotors\WebMotorsAdapter;
use App\Services\Portals\WebMotors\WebMotorsSoapAdapter;
use Illuminate\Console\Command;

class TestWebMotorsConnection extends Command
{
    protected $signature = 'webmotors:test
                            {--soap : Test SOAP API instead of REST}
                            {--rest : Test REST API (default)}';

    protected $description = 'Test WebMotors API configuration and connection';

    public function handle(): int
    {
        $this->info('Testing WebMotors Configuration...');
        $this->newLine();

        $config = config('portals.webmotors');

        $this->info('Configuration from .env:');
        $this->line('  Enabled: ' . ($config['enabled'] ? 'Yes' : 'No'));
        $this->line('  Environment: ' . ($config['environment'] ?? 'homologation'));
        $this->line('  Client ID: ' . ($config['client_id'] ? substr($config['client_id'], 0, 20) . '...' : 'NOT SET'));
        $this->line('  Client Secret: ' . ($config['client_secret'] ? '***SET***' : 'NOT SET'));
        $this->line('  Hash Autenticacao: ' . ($config['hash_autenticacao'] ? '***SET***' : 'NOT SET'));
        $this->line('  Login Email: ' . ($config['login']['email'] ?? 'NOT SET'));
        $this->line('  CNPJ: ' . ($config['login']['cnpj'] ?? 'NOT SET'));
        $this->newLine();

        $this->info('URLs:');
        $this->line('  Homologation: ' . $config['urls']['homologation']);
        $this->line('  Production: ' . $config['urls']['production']);
        $this->line('  SOAP WSDL: ' . $config['urls']['soap_wsdl']);
        $this->newLine();

        if (!$config['enabled']) {
            $this->warn('WebMotors is not enabled in .env (WEBMOTORS_ENABLED=false)');
        }

        if ($this->option('soap')) {
            return $this->testSoap($config);
        }

        return $this->testRest($config);
    }

    protected function testRest(array $config): int
    {
        $this->info('Testing REST API (Estoquecanais)...');
        $this->newLine();

        if (!$config['client_id'] || !$config['client_secret']) {
            $this->error('Missing WEBMOTORS_CLIENT_ID or WEBMOTORS_CLIENT_SECRET in .env');
            return Command::FAILURE;
        }

        $adapter = new WebMotorsAdapter();

        $this->line('Authenticating with OAuth...');
        $authenticated = $adapter->authenticate();

        if (!$authenticated) {
            $this->error('REST API authentication failed');
            $this->newLine();
            $this->warn('Possible causes:');
            $this->line('  - Invalid client_id or client_secret');
            $this->line('  - Homologation server may be unavailable (Mon-Fri 8am-8pm Brazil time)');
            $this->line('  - Network issues');
            return Command::FAILURE;
        }

        $this->info('Authentication successful!');
        $this->newLine();

        // Try to fetch interactions
        $this->line('Fetching interactions...');
        $result = $adapter->getInteractions();

        if ($result['success']) {
            $count = count($result['interactions']);
            $this->info("Found {$count} interaction(s)");
        } else {
            $this->warn('Could not fetch interactions: ' . ($result['error'] ?? 'Unknown error'));
        }

        // Try to fetch published vehicles
        $this->line('Fetching published vehicles...');
        $result = $adapter->getPublishedVehicles();

        if ($result['success']) {
            $count = count($result['vehicles']);
            $this->info("Found {$count} published vehicle(s)");
        } else {
            $this->warn('Could not fetch vehicles: ' . ($result['error'] ?? 'Unknown error'));
        }

        $this->newLine();
        $this->info('REST API test completed successfully!');

        return Command::SUCCESS;
    }

    protected function testSoap(array $config): int
    {
        $this->info('Testing SOAP API...');
        $this->newLine();

        // Check SOAP extension
        if (!extension_loaded('soap')) {
            $this->error('PHP SOAP extension is not enabled!');
            $this->newLine();
            $this->info('To enable SOAP:');
            $this->line('  1. Open php.ini');
            $this->line('  2. Uncomment: extension=soap');
            $this->line('  3. Restart web server/PHP');
            return Command::FAILURE;
        }

        $this->line('SOAP extension: Enabled');

        if (!$config['hash_autenticacao']) {
            $this->error('Missing WEBMOTORS_HASH_AUTENTICACAO in .env');
            $this->newLine();
            $this->info('The hash is obtained from WebMotors after homologation approval.');
            $this->info('Contact WebMotors support or use the homologation portal.');
            return Command::FAILURE;
        }

        $adapter = new WebMotorsSoapAdapter();

        $this->line('Testing SOAP connection...');

        try {
            $authenticated = $adapter->authenticate();

            if (!$authenticated) {
                $this->error('SOAP API authentication failed');
                $this->newLine();
                $this->warn('Possible causes:');
                $this->line('  - Invalid hash_autenticacao');
                $this->line('  - Hash may have expired');
                $this->line('  - SOAP service may be unavailable');
                return Command::FAILURE;
            }

            $this->info('SOAP Authentication successful!');
            $this->newLine();

            // Test fetching reference data
            $this->line('Fetching car brands...');
            $result = $adapter->getMarcas();

            if ($result['success']) {
                $count = count($result['data']);
                $this->info("Found {$count} brand(s)");

                if ($count > 0 && $this->option('verbose')) {
                    $brands = array_slice($result['data'], 0, 5);
                    $this->table(
                        ['Code', 'Name'],
                        array_map(fn($b) => [$b['CodigoMarca'] ?? 'N/A', $b['NomeMarca'] ?? 'N/A'], $brands)
                    );
                }
            } else {
                $this->warn('Could not fetch brands: ' . ($result['error'] ?? 'Unknown error'));
            }

            // Test fetching current stock
            $this->line('Fetching current stock...');
            $result = $adapter->getPublishedVehicles();

            if ($result['success']) {
                $count = count($result['vehicles']);
                $this->info("Found {$count} vehicle(s) in stock");
            } else {
                $this->warn('Could not fetch stock: ' . ($result['error'] ?? 'Unknown error'));
            }

            $this->newLine();
            $this->info('SOAP API test completed successfully!');

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error('SOAP Error: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
