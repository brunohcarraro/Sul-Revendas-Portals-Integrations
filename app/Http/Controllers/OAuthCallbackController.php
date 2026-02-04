<?php

namespace App\Http\Controllers;

use App\Services\Portals\OLX\OlxAdapter;
use App\Services\Portals\MercadoLivre\MercadoLivreAdapter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class OAuthCallbackController extends Controller
{
    /**
     * Handle OLX OAuth callback
     */
    public function olxCallback(Request $request)
    {
        $code = $request->get('code');
        $error = $request->get('error');

        if ($error) {
            Log::error('OLX OAuth Error', ['error' => $error]);
            return response()->json([
                'success' => false,
                'error' => $error,
                'message' => $request->get('error_description', 'Authorization denied'),
            ], 400);
        }

        if (!$code) {
            return response()->json([
                'success' => false,
                'error' => 'missing_code',
                'message' => 'Authorization code not provided',
            ], 400);
        }

        $adapter = new OlxAdapter();
        $result = $adapter->exchangeCodeForToken($code);

        if ($result['success']) {
            return response()->json([
                'success' => true,
                'message' => 'OLX authorization successful!',
                'instructions' => 'Add these to your .env file:',
                'access_token' => $result['access_token'],
                'refresh_token' => $result['refresh_token'] ?? null,
            ]);
        }

        return response()->json([
            'success' => false,
            'error' => 'token_exchange_failed',
            'message' => $result['error'] ?? 'Failed to exchange authorization code for token',
        ], 500);
    }

    /**
     * Handle Mercado Livre OAuth callback
     */
    public function mercadoLivreCallback(Request $request)
    {
        $code = $request->get('code');
        $error = $request->get('error');

        if ($error) {
            Log::error('MercadoLivre OAuth Error', ['error' => $error]);
            return response()->json([
                'success' => false,
                'error' => $error,
                'message' => $request->get('error_description', 'Authorization denied'),
            ], 400);
        }

        if (!$code) {
            return response()->json([
                'success' => false,
                'error' => 'missing_code',
                'message' => 'Authorization code not provided',
            ], 400);
        }

        $adapter = new MercadoLivreAdapter();
        $result = $adapter->exchangeCodeForToken($code);

        if ($result['success']) {
            return response()->json([
                'success' => true,
                'message' => 'Mercado Livre authorization successful!',
                'instructions' => 'Add these to your .env file:',
                'access_token' => $result['access_token'],
                'refresh_token' => $result['refresh_token'] ?? null,
                'user_id' => $result['user_id'] ?? null,
            ]);
        }

        return response()->json([
            'success' => false,
            'error' => 'token_exchange_failed',
            'message' => $result['error'] ?? 'Failed to exchange authorization code for token',
        ], 500);
    }

    /**
     * Redirect to OLX authorization
     */
    public function olxAuthorize()
    {
        $config = config('portals.olx');

        if (!$config['client_id']) {
            return response()->json([
                'success' => false,
                'error' => 'OLX credentials not configured in .env',
            ], 500);
        }

        $adapter = new OlxAdapter();
        $authUrl = $adapter->getAuthorizationUrl('oauth_state');

        return redirect($authUrl);
    }

    /**
     * Redirect to Mercado Livre authorization
     */
    public function mercadoLivreAuthorize()
    {
        $config = config('portals.mercadolivre');

        if (!$config['app_id']) {
            return response()->json([
                'success' => false,
                'error' => 'Mercado Livre credentials not configured in .env',
            ], 500);
        }

        $adapter = new MercadoLivreAdapter();
        $authUrl = $adapter->getAuthorizationUrl('oauth_state');

        return redirect($authUrl);
    }
}
