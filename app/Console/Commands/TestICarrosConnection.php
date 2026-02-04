<?php

namespace App\Console\Commands;

use App\Services\Portals\ICarros\ICarrosAdapter;
use Illuminate\Console\Command;

class TestICarrosConnection extends Command
{
    protected $signature = 'icarros:test
                            {--login= : Login/username for authentication}
                            {--password= : Password for authentication}
                            {--dealer= : Dealer ID to test with}';

    protected $description = 'Test iCarros API connection and basic operations';

    public function handle(): int
    {
        $this->info('===========================================');
        $this->info('     iCarros Integration Test');
        $this->info('===========================================');
        $this->newLine();

        // Check config
        $config = config('portals.icarros');
        $this->info('Configuration:');
        $this->line('  Enabled: ' . ($config['enabled'] ? 'Yes' : 'No'));
        $this->line('  Client ID: ' . ($config['client_id'] ? $config['client_id'] : 'NOT SET'));
        $this->line('  Client Secret: ' . ($config['client_secret'] ? '***' . substr($config['client_secret'], -4) : 'NOT SET'));
        $this->line('  Access Token: ' . ($config['access_token'] ? substr($config['access_token'], 0, 20) . '...' : 'NOT SET'));
        $this->line('  Dealer ID: ' . ($config['dealer_id'] ?: 'NOT SET'));
        $this->newLine();

        if (!$config['client_id'] || !$config['client_secret']) {
            $this->error('Client ID and Secret are required!');
            return Command::FAILURE;
        }

        $adapter = new ICarrosAdapter();

        // Check if we need to authenticate
        $accessToken = $config['access_token'];
        $login = $this->option('login');
        $password = $this->option('password');

        if (!$accessToken && (!$login || !$password)) {
            $this->warn('No access token configured. Please provide login credentials.');
            $login = $login ?: $this->ask('Enter iCarros login (email)');
            $password = $password ?: $this->secret('Enter iCarros password');
        }

        // Authenticate
        if (!$accessToken) {
            $this->info('Authenticating with credentials...');
            $authResult = $adapter->getToken($login, $password);

            if (!$authResult['success']) {
                $this->error('Authentication failed: ' . $authResult['error']);
                return Command::FAILURE;
            }

            $this->info('Authentication successful!');
            $this->line('  Access Token: ' . substr($authResult['access_token'], 0, 30) . '...');
            $this->line('  Expires in: ' . ($authResult['expires_in'] ?? 300) . ' seconds');

            if (!empty($authResult['refresh_token'])) {
                $this->line('  Refresh Token: ' . substr($authResult['refresh_token'], 0, 30) . '...');
            }

            $this->newLine();
            $this->warn('Add these to your .env:');
            $this->line("ICARROS_ACCESS_TOKEN={$authResult['access_token']}");
            if (!empty($authResult['refresh_token'])) {
                $this->line("ICARROS_REFRESH_TOKEN={$authResult['refresh_token']}");
            }
            $this->newLine();

        } else {
            $this->info('Using configured access token...');
            $adapter->setAccessToken($accessToken);
        }

        // Test: Get Dealers
        $this->info('Testing: Get Dealers...');
        $dealersResult = $adapter->getDealers();

        if (!$dealersResult['success']) {
            $this->error('Failed to get dealers: ' . $dealersResult['error']);
            return Command::FAILURE;
        }

        $dealers = $dealersResult['data'];
        $this->info('Found ' . count($dealers) . ' dealer(s):');

        foreach ($dealers as $dealer) {
            $dealerId = $dealer['id'] ?? 'N/A';
            $dealerName = $dealer['name'] ?? $dealer['nome'] ?? 'N/A';
            $this->line("  - ID: {$dealerId} | Name: {$dealerName}");
        }

        $this->newLine();

        // Get dealer ID
        $dealerId = $this->option('dealer') ?: $config['dealer_id'];

        if (!$dealerId && !empty($dealers)) {
            $dealerId = $dealers[0]['id'] ?? null;
            $this->info("Using first dealer: {$dealerId}");
        }

        if ($dealerId) {
            $adapter->setDealerId((int) $dealerId);

            // Test: Get Dealer Info
            $this->info("Testing: Get Dealer Info (ID: {$dealerId})...");
            $dealerResult = $adapter->getDealer();

            if ($dealerResult['success']) {
                $dealerData = $dealerResult['data'];
                $this->line('  Name: ' . ($dealerData['name'] ?? $dealerData['nome'] ?? 'N/A'));
                $this->line('  CNPJ: ' . ($dealerData['cnpj'] ?? 'N/A'));
                $this->line('  City: ' . ($dealerData['cityName'] ?? $dealerData['city'] ?? 'N/A'));
                $this->line('  Status: ' . ($dealerData['status'] ?? 'N/A'));

                if (!empty($config['dealer_id']) && $config['dealer_id'] != $dealerId) {
                    $this->newLine();
                    $this->warn("Add this to your .env:");
                    $this->line("ICARROS_DEALER_ID={$dealerId}");
                }
            } else {
                $this->warn('Could not get dealer info: ' . $dealerResult['error']);
            }

            $this->newLine();

            // Test: Get Plans
            $this->info('Testing: Get Available Plans...');
            $plansResult = $adapter->getPlans();

            if ($plansResult['success']) {
                $plansData = $plansResult['data'];
                $this->line('  Plan Name: ' . ($plansData['planName'] ?? 'Legacy'));
                $this->line('  Plan ID: ' . ($plansData['planId'] ?? 'N/A'));

                $plans = $plansData['plans'] ?? $plansData['planos'] ?? [];
                foreach ($plans as $plan) {
                    $nome = $plan['nome'] ?? 'N/A';
                    $quantidade = $plan['quantidade'] ?? 0;
                    $publicados = $plan['publicados'] ?? 0;
                    $livres = $plan['livres'] ?? 0;
                    $priority = $plan['priority'] ?? 'N/A';
                    $this->line("    - {$nome}: {$livres}/{$quantidade} available (priority: {$priority})");
                }
            } else {
                $this->warn('Could not get plans: ' . $plansResult['error']);
            }

            $this->newLine();

            // Test: Get Inventory
            $this->info('Testing: Get Inventory...');
            $inventoryResult = $adapter->getInventory();

            if ($inventoryResult['success']) {
                $inventory = $inventoryResult['data'];
                $this->info('Found ' . count($inventory) . ' vehicle(s) in inventory');

                foreach (array_slice($inventory, 0, 3) as $vehicle) {
                    $id = $vehicle['id'] ?? 'N/A';
                    $make = $vehicle['makeDescription'] ?? 'N/A';
                    $model = $vehicle['modelDescription'] ?? 'N/A';
                    $price = number_format($vehicle['price'] ?? 0, 2, ',', '.');
                    $this->line("  - ID: {$id} | {$make} {$model} | R\$ {$price}");
                }

                if (count($inventory) > 3) {
                    $this->line('  ... and ' . (count($inventory) - 3) . ' more');
                }
            } else {
                $this->warn('Could not get inventory: ' . $inventoryResult['error']);
            }

            $this->newLine();

            // Test: Get Leads
            $this->info('Testing: Get Leads (last 7 days)...');
            $leadsResult = $adapter->fetchLeads([
                'start_date' => date('Y-m-d', strtotime('-7 days')),
                'end_date' => date('Y-m-d'),
            ]);

            if ($leadsResult['success']) {
                $leads = $leadsResult['leads'];
                $this->info('Found ' . count($leads) . ' lead(s)');

                foreach (array_slice($leads, 0, 3) as $lead) {
                    $name = $lead['name'] ?? 'N/A';
                    $email = $lead['email'] ?? 'N/A';
                    $phone = $lead['phone'] ?? 'N/A';
                    $this->line("  - {$name} | {$email} | {$phone}");
                }
            } else {
                $this->warn('Could not get leads: ' . $leadsResult['error']);
            }
        }

        $this->newLine();

        // Test: Get Reference Data
        $this->info('Testing: Get Reference Data...');

        // Makes
        $makesResult = $adapter->getMakes();
        if ($makesResult['success']) {
            $this->line('  Makes: ' . count($makesResult['data']) . ' brands loaded');
        } else {
            $this->warn('  Makes: FAILED - ' . $makesResult['error']);
        }

        // Colors
        $colorsResult = $adapter->getColors();
        if ($colorsResult['success']) {
            $this->line('  Colors: ' . count($colorsResult['data']) . ' colors loaded');
        } else {
            $this->warn('  Colors: FAILED - ' . $colorsResult['error']);
        }

        // Fuels
        $fuelsResult = $adapter->getFuels();
        if ($fuelsResult['success']) {
            $this->line('  Fuels: ' . count($fuelsResult['data']) . ' fuel types loaded');
        } else {
            $this->warn('  Fuels: FAILED - ' . $fuelsResult['error']);
        }

        // Transmissions
        $transResult = $adapter->getTransmissions();
        if ($transResult['success']) {
            $this->line('  Transmissions: ' . count($transResult['data']) . ' types loaded');
        } else {
            $this->warn('  Transmissions: FAILED - ' . $transResult['error']);
        }

        // Equipments
        $equipResult = $adapter->getEquipments();
        if ($equipResult['success']) {
            $this->line('  Equipments: ' . count($equipResult['data']) . ' items loaded');
        } else {
            $this->warn('  Equipments: FAILED - ' . $equipResult['error']);
        }

        $this->newLine();
        $this->info('===========================================');
        $this->info('     Test Complete!');
        $this->info('===========================================');

        return Command::SUCCESS;
    }
}
