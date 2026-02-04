<?php 

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Portals\OLX\OlxAdapter;
use App\Services\Portals\MercadoLivre\MercadoLivreAdapter;
use App\Services\Portals\ICarros\ICarrosAdapter;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;
use Http;



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
            'veiculo_km' => 0,
            'veiculo_portas' => 4,
            'imagens' => [],
        ];
    }

    public function publish(Request $request): JsonResponse
    {
        $data = $request->validate([
            'portal' => 'required|in:olx,mercadolivre,icarros',
            'vehicle' => 'required|array',
            'vehicle.id'    => 'required',
            'vehicle.title' => 'required|string',
        ]);

        $vehicle = $this->mapApiVehicleToInternal($data['vehicle']);

        $adapter = match ($data['portal']) {
            'olx'          => new OlxAdapter(),
            'mercadolivre' => new MercadoLivreAdapter(),
            'icarros'      => new ICarrosAdapter(),
        };

        $result = $adapter->publishVehicle($vehicle);

        return response()->json([
            'success' => true,
            'portal'  => $data['portal'],
            'result'  => $result,
        ]);

        /**
         * 1️⃣ Validação da requisição
         */
        $data = $request->validate([
            'portal' => 'required|in:olx,mercadolivre,icarros',
            'vehicle' => 'required|array',

            // campos mínimos do veículo (para teste)
            'vehicle.id'    => 'required',
            'vehicle.title' => 'required|string',
            'vehicle.price' => 'nullable|numeric',
            'vehicle.year'  => 'nullable|integer',
            'vehicle.brand' => 'nullable|string',
            'vehicle.model' => 'nullable|string',
            'vehicle.images'=> 'nullable|array',
        ]);

        $vehicle = $data['vehicle'];

        /**
         * 2️⃣ Seleciona o adapter
         */
        try {
            $adapter = match ($data['portal']) {
                'olx'          => new OlxAdapter(),
                'mercadolivre' => new MercadoLivreAdapter(),
                'icarros'      => new ICarrosAdapter(),
            };
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Portal adapter not found',
            ], 422);
        }

        /**
         * 3️⃣ Publicação (aqui pode ser mock no início)
         */
        try {
            $result = $adapter->publishVehicle($vehicle);

            return response()->json([
                'success' => true,
                'portal'  => $data['portal'],
                'result'  => $result,
            ]);
        } catch (\Throwable $e) {
            // erro controlado (não 500 genérico)
            return response()->json([
                'success' => false,
                'message' => 'Failed to publish vehicle',
                'error'   => $e->getMessage(),
            ], 422);
        }
    }
}