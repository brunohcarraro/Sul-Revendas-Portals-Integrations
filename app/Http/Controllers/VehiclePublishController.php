<?php 

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Portals\OLX\OlxAdapter;
use App\Services\Portals\MercadoLivre\MercadoLivreAdapter;
use App\Services\Portals\ICarros\ICarrosAdapter;
use Illuminate\Http\Request;

class VehiclePublishController extends Controller
{
    public function publish(Request $request)
    {
        if ($request->bearerToken() !== config('services.integration.token')) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }
        
        $data = $request->validate([
            'portal' => 'required|in:olx,mercadolivre,icarros',
            'vehicle' => 'required|array',
        ]);

        $adapter = match ($data['portal']) {
            'olx' => new OlxAdapter(),
            'mercadolivre' => new MercadoLivreAdapter(),
            'icarros' => new ICarrosAdapter(),
        };

        $result = $adapter->publishVehicle($data['vehicle']);

        return response()->json($result);
    }
}