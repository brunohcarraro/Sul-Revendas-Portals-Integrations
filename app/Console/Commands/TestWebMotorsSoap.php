<?php

namespace App\Console\Commands;

use App\Services\Portals\WebMotors\WebMotorsSoapAdapter;
use Illuminate\Console\Command;

class TestWebMotorsSoap extends Command
{
    protected $signature = 'webmotors:test-soap
                            {--hash= : The authentication hash from WebMotors}';

    protected $description = 'Test WebMotors SOAP API connection';

    public function handle(): int
    {
        $this->info('Testing WebMotors SOAP API...');
        $this->newLine();

        $hash = $this->option('hash');

        if (!$hash) {
            $hash = $this->ask('Enter your WebMotors authentication hash (pHashAutenticacao)');
        }

        if (!$hash) {
            $this->error('Authentication hash is required.');
            return Command::FAILURE;
        }

        $adapter = new WebMotorsSoapAdapter();
        $adapter->setHashAutenticacao($hash);

        // Test authentication by fetching brands
        $this->info('Testing authentication by fetching brands...');

        $result = $adapter->getMarcas();

        if ($result['success']) {
            $count = count($result['data']);
            $this->info("✓ Authentication successful! Found {$count} brands.");
            $this->newLine();

            if ($count > 0 && $this->confirm('Show first 10 brands?', true)) {
                $brands = array_slice($result['data'], 0, 10);
                $this->table(
                    ['Code', 'Name'],
                    collect($brands)->map(fn($b) => [
                        $b['Codigo'] ?? '-',
                        $b['Nome'] ?? '-',
                    ])->toArray()
                );
            }

            // Test fetching colors
            $this->newLine();
            $this->info('Fetching colors...');
            $cores = $adapter->getCores();
            if ($cores['success']) {
                $this->info("✓ Found " . count($cores['data']) . " colors");
            }

            // Test fetching fuel types
            $this->info('Fetching fuel types...');
            $combustiveis = $adapter->getCombustiveis();
            if ($combustiveis['success']) {
                $this->info("✓ Found " . count($combustiveis['data']) . " fuel types");
            }

            // Test fetching transmissions
            $this->info('Fetching transmissions...');
            $cambios = $adapter->getCambios();
            if ($cambios['success']) {
                $this->info("✓ Found " . count($cambios['data']) . " transmission types");
            }

            // Test fetching current inventory
            $this->newLine();
            $this->info('Fetching current inventory...');
            $inventory = $adapter->getPublishedVehicles();
            if ($inventory['success']) {
                $count = count($inventory['vehicles']);
                $this->info("✓ Found {$count} vehicles in inventory");

                if ($count > 0 && $this->confirm('Show first 5 vehicles?', true)) {
                    $vehicles = array_slice($inventory['vehicles'], 0, 5);
                    $this->table(
                        ['Code', 'Brand', 'Model', 'Year', 'Price'],
                        collect($vehicles)->map(fn($v) => [
                            $v['CodigoAnuncio'] ?? '-',
                            $v['Marca'] ?? '-',
                            $v['Modelo'] ?? '-',
                            $v['AnoModelo'] ?? '-',
                            'R$ ' . number_format($v['Preco'] ?? 0, 2, ',', '.'),
                        ])->toArray()
                    );
                }
            } else {
                $this->warn("Could not fetch inventory: " . ($inventory['error'] ?? 'Unknown error'));
            }

            $this->newLine();
            $this->info('✓ WebMotors SOAP API connection is working!');

            return Command::SUCCESS;

        } else {
            $this->error('✗ Authentication failed!');
            $this->error('Error: ' . ($result['error'] ?? 'Unknown error'));
            $this->newLine();
            $this->warn('Check if your authentication hash is correct.');
            return Command::FAILURE;
        }
    }
}
