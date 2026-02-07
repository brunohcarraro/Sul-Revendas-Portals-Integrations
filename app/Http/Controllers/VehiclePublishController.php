<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Portals\OLX\OlxAdapter;
use App\Services\Portals\MercadoLivre\MercadoLivreAdapter;
use App\Services\Portals\ICarros\ICarrosAdapter;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class VehiclePublishController extends Controller
{
    protected function mapApiVehicleToInternal(array $v): array
    {
        return [
            'veiculo_id' => $v['id'],
            'veiculo_valor' => $v['price'] ?? 0,
            'veiculo_ano_modelo' => $v['year'] ?? date('Y'),
            'fipe_marca_nome' => $v['brand'] ?? '',
            'fipe_modelo_nome' => $v['model'] ?? '',
            'veiculo_obs' => $v['title'] ?? '',
            'veiculo_km' => $v['km'] ?? 0,
            'veiculo_portas' => $v['doors'] ?? 4,
            'imagens' => $v['images'] ?? [],
        ];
    }

    public function publish(Request $request): JsonResponse
    {
        $data = $request->validate([
            'portal' => 'required|in:olx,mercadolivre,icarros',
            'vehicle' => 'required|array',
            'vehicle.id'    => 'required',
            'vehicle.title' => 'required|string',
            'vehicle.price' => 'nullable|numeric',
            'vehicle.year'  => 'nullable|integer',
            'vehicle.brand' => 'nullable|string',
            'vehicle.model' => 'nullable|string',
            'vehicle.images'=> 'nullable|array',
        ]);

        $vehicle = $this->mapApiVehicleToInternal($data['vehicle']);

        $adapter = match ($data['portal']) {
            'olx'          => new OlxAdapter(),
            'mercadolivre' => new MercadoLivreAdapter(),
            'icarros'      => new ICarrosAdapter(),
        };

        // CRITICAL: Load token from config before using adapter
        if (!$adapter->authenticate()) {
            return response()->json([
                'success' => false,
                'portal'  => $data['portal'],
                'error'   => 'Failed to authenticate with portal. Check .env tokens.',
            ], 401);
        }

        try {
            $result = $adapter->publishVehicle($vehicle);

            return response()->json([
                'success' => $result['success'] ?? true,
                'portal'  => $data['portal'],
                'result'  => $result,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'portal'  => $data['portal'],
                'error'   => $e->getMessage(),
            ], 500);
        }
    }
}
