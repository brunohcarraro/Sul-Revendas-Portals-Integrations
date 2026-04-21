<?php

namespace App\Console\Commands;

use App\Services\Portals\OLX\OlxAdapter;
use Illuminate\Console\Command;

class TestarOlx extends Command
{
    protected $signature   = 'olx:testar';
    protected $description = 'Testa publicação na OLX com dados fixos';

    public function handle(): void
    {
        $this->info('🔌 Conectando na OLX...');

        $adapter = new OlxAdapter(config('portals.olx'));
        $adapter->authenticate();

        $vehicleData = [
            'id'      => '579652',
            'subject' => 'FIAT UNO VIVACE 1.0 4P 2014',
            'body'    => 'Veículo completo, revisado, excelente estado.',
            'phone'   => 54991610763,
            'type'    => 's',
            'price'   => 35000,
            'zipcode' => '95702264',
            'images'  => [
                'https://srstorage.nyc3.digitaloceanspaces.com/veiculos/348952/lg_17746436441828398154.jpg',
            ],
            'params' => [
                'vehicle_brand'   => '25',
                'vehicle_model'   => '27',
                'vehicle_version' => '8',
                'regdate'         => '2014',
                'mileage'         => 120000,
                'fuel'            => '3',
                'gearbox'         => '2',
                'doors'           => '2',
                'carcolor'        => '3',
                'cartype'         => '9',
                'vehicle_tag'     => 'FBV3G87',
                'motorpower'      => '1',
                'car_steering'    => '2',
                'car_features'    => ['1'],
            ],
        ];

        $this->info('📦 Payload:');
        $this->line(json_encode($vehicleData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->newLine();
        $this->info('🚀 Enviando para OLX...');

        $result = $adapter->publishVehicle([$vehicleData]);

        $this->newLine();

        if ($result['success']) {
            $this->info('✅ Sucesso!');
            $this->line(json_encode($result['response'] ?? $result, JSON_PRETTY_PRINT));
        } else {
            $this->error('❌ Erro:');
            $this->line($result['error'] ?? 'Erro desconhecido');
            $this->line(json_encode($result['raw'] ?? [], JSON_PRETTY_PRINT));
        }
    }
}