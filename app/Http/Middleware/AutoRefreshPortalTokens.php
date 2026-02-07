<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class AutoRefreshPortalTokens
{
    /**
     * Handle an incoming request.
     *
     * Check if portal tokens are expired and refresh them automatically.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $this->checkAndRefreshTokens();

        return $next($request);
    }

    /**
     * Check if tokens need refreshing and refresh them
     */
    protected function checkAndRefreshTokens(): void
    {
        // Check Mercado Livre token
        $mlExpiresAt = config('portals.mercadolivre.token_expires_at');
        if ($mlExpiresAt && $mlExpiresAt <= now()->addMinutes(30)->timestamp) {
            $this->refreshToken('mercadolivre');
        }

        // Check iCarros token (short-lived, 5 minutes)
        $icarrosExpiresAt = config('portals.icarros.token_expires_at');
        if ($icarrosExpiresAt && $icarrosExpiresAt <= now()->addMinute()->timestamp) {
            $this->refreshToken('icarros');
        }
    }

    /**
     * Refresh a specific portal token
     */
    protected function refreshToken(string $portal): void
    {
        try {
            Log::info("Auto-refreshing {$portal} token via middleware");

            Artisan::call('portal:auto-refresh', [
                '--portal' => $portal,
            ]);

            Log::info("Successfully refreshed {$portal} token");
        } catch (\Exception $e) {
            Log::error("Failed to auto-refresh {$portal} token: " . $e->getMessage());
        }
    }
}
