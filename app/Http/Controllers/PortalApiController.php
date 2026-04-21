<?php

namespace App\Http\Controllers;

use App\Services\Portals\OLX\OlxAdapter;
use App\Services\Portals\MercadoLivre\MercadoLivreAdapter;
use App\Services\Portals\ICarros\ICarrosAdapter;
use App\Services\Portals\Contracts\PortalAdapterInterface;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class PortalApiController extends Controller
{
    /**
     * Get the adapter for a portal
     */
    protected function getAdapter(string $portal): ?PortalAdapterInterface
    {
        return match ($portal) {
            'olx'          => new OlxAdapter(config('portals.olx')), // ✅ portals, não services
            'mercadolivre' => new MercadoLivreAdapter(),
            'icarros'      => new ICarrosAdapter(),
            default        => null,
        };
    }

    /**
     * Authenticate adapter and return error response if failed
     */
    protected function authenticateAdapter(PortalAdapterInterface $adapter, string $portal): ?JsonResponse
    {
        if (!$adapter->authenticate()) {
            return response()->json([
                'success' => false,
                'portal'  => $portal,
                'error'   => 'Failed to authenticate with portal. Check .env tokens.',
            ], 401);
        }
        return null;
    }

    /**
     * GET /api/health
     */
    public function health(): JsonResponse
    {
        return response()->json([
            'success'   => true,
            'service'   => 'Sul Revendas Portal Integration API',
            'version'   => '1.0.0',
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    /**
     * GET /api/portals
     */
    public function listPortals(): JsonResponse
    {
        $portals = [
            'olx' => [
                'name'      => 'OLX',
                'enabled'   => config('portals.olx.enabled', false),
                'has_token' => !empty(config('portals.olx.access_token')),
            ],
            'mercadolivre' => [
                'name'      => 'Mercado Livre',
                'enabled'   => config('portals.mercadolivre.enabled', false),
                'has_token' => !empty(config('portals.mercadolivre.access_token')),
            ],
            'icarros' => [
                'name'      => 'iCarros',
                'enabled'   => config('portals.icarros.enabled', false),
                'has_token' => !empty(config('portals.icarros.access_token')),
            ],
        ];

        return response()->json([
            'success' => true,
            'portals' => $portals,
        ]);
    }

    /**
     * GET /api/portals/{portal}/test
     */
    public function testConnection(string $portal): JsonResponse
    {
        $adapter = $this->getAdapter($portal);

        if (!$adapter) {
            return response()->json([
                'success' => false,
                'error'   => "Portal '$portal' not found. Available: olx, mercadolivre, icarros",
            ], 404);
        }

        $authError = $this->authenticateAdapter($adapter, $portal);
        if ($authError) {
            return $authError;
        }

        return response()->json([
            'success'        => true,
            'portal'         => $portal,
            'message'        => 'Connection successful',
            'authenticated'  => $adapter->isAuthenticated(),
        ]);
    }

    /**
     * POST /api/portals/{portal}/vehicles/publish
     */
    public function publishVehicle(Request $request, string $portal): JsonResponse
    {
        $adapter = $this->getAdapter($portal);

        if (!$adapter) {
            return response()->json([
                'success' => false,
                'error'   => "Portal '$portal' not found.",
            ], 404);
        }

        $data = $request->validate([
            'ad_list' => 'required|array',
        ]);

        $authError = $this->authenticateAdapter($adapter, $portal);
        if ($authError) {
            return $authError;
        }

        try {
            $result = $adapter->publishVehicle($data['ad_list']); // ✅ retorna array

            return response()->json([
                'success'      => $result['success'] ?? false,
                'portal'       => $portal,
                'external_id'  => $result['external_id'] ?? null,
                'url'          => $result['url'] ?? null,
                'error'        => $result['error'] ?? null,
                'raw_response' => $result['response'] ?? null,
            ]);
        } catch (\Exception $e) {
            Log::error("Portal [$portal] publishVehicle error", ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'portal'  => $portal,
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * PUT /api/portals/{portal}/vehicles/{externalId}
     */
    public function updateVehicle(Request $request, string $portal, string $externalId): JsonResponse
    {
        $adapter = $this->getAdapter($portal);

        if (!$adapter) {
            return response()->json([
                'success' => false,
                'error'   => "Portal '$portal' not found.",
            ], 404);
        }

        $data = $request->validate([
            'ad_list' => 'required|array',
        ]);

        $authError = $this->authenticateAdapter($adapter, $portal);
        if ($authError) {
            return $authError;
        }

        try {
            $result = $adapter->updateVehicle($externalId, $data['ad_list']);

            return response()->json([
                'success'      => $result['success'] ?? false,
                'portal'       => $portal,
                'external_id'  => $externalId,
                'error'        => $result['error'] ?? null,
                'raw_response' => $result,
            ]);
        } catch (\Exception $e) {
            Log::error("Portal [$portal] updateVehicle error", ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'portal'  => $portal,
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * DELETE /api/portals/{portal}/vehicles/{externalId}
     */
    public function removeVehicle(string $portal, string $externalId): JsonResponse
    {
        $adapter = $this->getAdapter($portal);

        if (!$adapter) {
            return response()->json([
                'success' => false,
                'error'   => "Portal '$portal' not found.",
            ], 404);
        }

        $authError = $this->authenticateAdapter($adapter, $portal);
        if ($authError) {
            return $authError;
        }

        try {
            $result = $adapter->removeVehicle($externalId);

            return response()->json([
                'success'      => $result['success'] ?? false,
                'portal'       => $portal,
                'external_id'  => $externalId,
                'error'        => $result['error'] ?? null,
                'raw_response' => $result,
            ]);
        } catch (\Exception $e) {
            Log::error("Portal [$portal] removeVehicle error", ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'portal'  => $portal,
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * PATCH /api/portals/{portal}/vehicles/{externalId}/status
     */
    public function updateVehicleStatus(Request $request, string $portal, string $externalId): JsonResponse
    {
        $adapter = $this->getAdapter($portal);

        if (!$adapter) {
            return response()->json([
                'success' => false,
                'error'   => "Portal '$portal' not found.",
            ], 404);
        }

        $data = $request->validate([
            'status' => 'required|in:active,paused,sold',
        ]);

        $authError = $this->authenticateAdapter($adapter, $portal);
        if ($authError) {
            return $authError;
        }

        try {
            $result = $adapter->updateVehicleStatus($externalId, $data['status']);

            return response()->json([
                'success'      => $result['success'] ?? false,
                'portal'       => $portal,
                'external_id'  => $externalId,
                'status'       => $data['status'],
                'error'        => $result['error'] ?? null,
                'raw_response' => $result,
            ]);
        } catch (\Exception $e) {
            Log::error("Portal [$portal] updateVehicleStatus error", ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'portal'  => $portal,
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /api/portals/{portal}/vehicles
     */
    public function getPublishedVehicles(string $portal): JsonResponse
    {
        $adapter = $this->getAdapter($portal);

        if (!$adapter) {
            return response()->json([
                'success' => false,
                'error'   => "Portal '$portal' not found.",
            ], 404);
        }

        $authError = $this->authenticateAdapter($adapter, $portal);
        if ($authError) {
            return $authError;
        }

        try {
            $result = $adapter->getPublishedVehicles();

            return response()->json([
                'success'  => $result['success'] ?? false,
                'portal'   => $portal,
                'vehicles' => $result['vehicles'] ?? [],
                'error'    => $result['error'] ?? null,
            ]);
        } catch (\Exception $e) {
            Log::error("Portal [$portal] getPublishedVehicles error", ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'portal'  => $portal,
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /api/portals/{portal}/leads
     */
    public function getLeads(Request $request, string $portal): JsonResponse
    {
        $adapter = $this->getAdapter($portal);

        if (!$adapter) {
            return response()->json([
                'success' => false,
                'error'   => "Portal '$portal' not found.",
            ], 404);
        }

        $authError = $this->authenticateAdapter($adapter, $portal);
        if ($authError) {
            return $authError;
        }

        $filters = $request->only(['from_date', 'to_date', 'status']);

        try {
            $result = $adapter->fetchLeads($filters);

            return response()->json([
                'success' => $result['success'] ?? false,
                'portal'  => $portal,
                'leads'   => $result['leads'] ?? [],
                'error'   => $result['error'] ?? null,
            ]);
        } catch (\Exception $e) {
            Log::error("Portal [$portal] getLeads error", ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'portal'  => $portal,
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /api/portals/publish-all
     */
    public function publishToAll(Request $request): JsonResponse
    {
        $data = $request->validate([
            'portals'    => 'required|array',
            'portals.*'  => 'in:olx,mercadolivre,icarros',
            'ad_list'    => 'required|array',
            'ad_list.id' => 'required',
        ]);

        $results = [];

        foreach ($data['portals'] as $portal) {
            $adapter = $this->getAdapter($portal);

            if (!$adapter) {
                $results[$portal] = ['success' => false, 'error' => 'Portal not found'];
                continue;
            }

            if (!$adapter->authenticate()) {
                $results[$portal] = ['success' => false, 'error' => 'Authentication failed'];
                continue;
            }

            try {
                $results[$portal] = $adapter->publishVehicle($data['ad_list']);
            } catch (\Exception $e) {
                $results[$portal] = ['success' => false, 'error' => $e->getMessage()];
            }
        }

        $allSuccess = collect($results)->every(fn($r) => $r['success'] ?? false);

        return response()->json([
            'success' => $allSuccess,
            'results' => $results,
        ]);
    }
}