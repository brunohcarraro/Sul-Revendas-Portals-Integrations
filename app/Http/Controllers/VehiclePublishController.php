<?php 

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Portals\OLX\OlxAdapter;
use App\Services\Portals\MercadoLivre\MercadoLivreAdapter;
use App\Services\Portals\ICarros\ICarrosAdapter;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;



class VehiclePublishController extends Controller
{
    public function publish(Request $request): JsonResponse
    {
        $token = config('services.integration.token');
        
        $payload = [
            'brand' => 'Volkswagen',
            'model' => 'Fusca',
            'year'  => 1974,
            'price' => 45000,
        ];

        $response = Http::withToken($token)
            ->acceptJson()
            ->post('https://integrations.sulrevendas.com.br/api/vehicles/publish', $payload);

        dd([
            'status' => $response->status(),
            'body'   => $response->body(),
            'json'   => $response->json(),
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