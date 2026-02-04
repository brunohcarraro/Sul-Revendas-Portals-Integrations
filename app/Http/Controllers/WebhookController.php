<?php

namespace App\Http\Controllers;

use App\Models\PortalLead;
use App\Models\PortalSyncLog;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    /**
     * Handle OLX webhook for leads
     * Documentation: https://developers.olx.com.br
     */
    public function olxLead(Request $request): JsonResponse
    {
        $startTime = microtime(true);
        $payload = $request->all();

        Log::info('OLX Webhook received', ['payload' => $payload]);

        try {
            // OLX sends lead data in the webhook payload
            $lead = $this->processOlxLead($payload);

            $durationMs = (int) ((microtime(true) - $startTime) * 1000);
            $this->logWebhook('olx', 'lead', $payload, true, $durationMs);

            return response()->json(['success' => true, 'lead_id' => $lead?->id]);

        } catch (\Exception $e) {
            $durationMs = (int) ((microtime(true) - $startTime) * 1000);
            $this->logWebhook('olx', 'lead', $payload, false, $durationMs, $e->getMessage());

            Log::error('OLX Webhook error', ['error' => $e->getMessage()]);

            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Handle WebMotors webhook for leads
     */
    public function webmotorsLead(Request $request): JsonResponse
    {
        $startTime = microtime(true);
        $payload = $request->all();

        Log::info('WebMotors Webhook received', ['payload' => $payload]);

        try {
            $lead = $this->processWebMotorsLead($payload);

            $durationMs = (int) ((microtime(true) - $startTime) * 1000);
            $this->logWebhook('webmotors', 'lead', $payload, true, $durationMs);

            return response()->json(['success' => true, 'lead_id' => $lead?->id]);

        } catch (\Exception $e) {
            $durationMs = (int) ((microtime(true) - $startTime) * 1000);
            $this->logWebhook('webmotors', 'lead', $payload, false, $durationMs, $e->getMessage());

            Log::error('WebMotors Webhook error', ['error' => $e->getMessage()]);

            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Handle Mercado Livre notifications webhook
     */
    public function mercadoLivreNotification(Request $request): JsonResponse
    {
        $startTime = microtime(true);
        $payload = $request->all();

        Log::info('MercadoLivre Webhook received', ['payload' => $payload]);

        try {
            // Mercado Livre sends notifications for various events
            // topic: questions, orders, items, etc.
            $topic = $payload['topic'] ?? null;
            $resource = $payload['resource'] ?? null;

            if ($topic === 'questions') {
                // A new question (lead) was received
                // The resource contains the question ID - need to fetch details via API
                $this->processMercadoLivreQuestion($payload);
            }

            $durationMs = (int) ((microtime(true) - $startTime) * 1000);
            $this->logWebhook('mercadolivre', $topic ?? 'notification', $payload, true, $durationMs);

            return response()->json(['success' => true]);

        } catch (\Exception $e) {
            $durationMs = (int) ((microtime(true) - $startTime) * 1000);
            $this->logWebhook('mercadolivre', 'notification', $payload, false, $durationMs, $e->getMessage());

            Log::error('MercadoLivre Webhook error', ['error' => $e->getMessage()]);

            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Process OLX lead from webhook payload
     */
    protected function processOlxLead(array $payload): ?PortalLead
    {
        // Extract lead data from OLX webhook format
        $externalId = $payload['lead_id'] ?? $payload['id'] ?? null;

        if (!$externalId) {
            Log::warning('OLX lead missing external_id', ['payload' => $payload]);
            return null;
        }

        // Check for duplicate
        if (PortalLead::isDuplicate('olx', $externalId)) {
            Log::info('OLX duplicate lead ignored', ['external_id' => $externalId]);
            return null;
        }

        // Create lead record
        return PortalLead::create([
            'portal' => 'olx',
            'external_lead_id' => $externalId,
            'veiculo_id' => $payload['ad_id'] ?? $payload['vehicle_id'] ?? null,
            'name' => $payload['name'] ?? $payload['customer_name'] ?? null,
            'email' => $payload['email'] ?? $payload['customer_email'] ?? null,
            'phone' => $payload['phone'] ?? $payload['customer_phone'] ?? null,
            'message' => $payload['message'] ?? $payload['text'] ?? null,
            'status' => 'new',
            'extra_data' => $payload,
        ]);
    }

    /**
     * Process WebMotors lead from webhook payload
     */
    protected function processWebMotorsLead(array $payload): ?PortalLead
    {
        $externalId = $payload['lead_id'] ?? $payload['id'] ?? $payload['CodigoLead'] ?? null;

        if (!$externalId) {
            Log::warning('WebMotors lead missing external_id', ['payload' => $payload]);
            return null;
        }

        if (PortalLead::isDuplicate('webmotors', $externalId)) {
            Log::info('WebMotors duplicate lead ignored', ['external_id' => $externalId]);
            return null;
        }

        return PortalLead::create([
            'portal' => 'webmotors',
            'external_lead_id' => $externalId,
            'veiculo_id' => $payload['CodigoAnuncio'] ?? $payload['ad_id'] ?? null,
            'name' => $payload['NomeCliente'] ?? $payload['name'] ?? null,
            'email' => $payload['EmailCliente'] ?? $payload['email'] ?? null,
            'phone' => $payload['TelefoneCliente'] ?? $payload['phone'] ?? null,
            'message' => $payload['Mensagem'] ?? $payload['message'] ?? null,
            'status' => 'new',
            'extra_data' => $payload,
        ]);
    }

    /**
     * Handle iCarros webhook for leads
     */
    public function icarrosLead(Request $request): JsonResponse
    {
        $startTime = microtime(true);
        $payload = $request->all();

        Log::info('iCarros Webhook received', ['payload' => $payload]);

        try {
            $lead = $this->processICarrosLead($payload);

            $durationMs = (int) ((microtime(true) - $startTime) * 1000);
            $this->logWebhook('icarros', 'lead', $payload, true, $durationMs);

            return response()->json(['success' => true, 'lead_id' => $lead?->id]);

        } catch (\Exception $e) {
            $durationMs = (int) ((microtime(true) - $startTime) * 1000);
            $this->logWebhook('icarros', 'lead', $payload, false, $durationMs, $e->getMessage());

            Log::error('iCarros Webhook error', ['error' => $e->getMessage()]);

            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Process iCarros lead from webhook payload
     */
    protected function processICarrosLead(array $payload): ?PortalLead
    {
        $externalId = $payload['id'] ?? $payload['lead_id'] ?? null;

        if (!$externalId) {
            Log::warning('iCarros lead missing external_id', ['payload' => $payload]);
            return null;
        }

        if (PortalLead::isDuplicate('icarros', $externalId)) {
            Log::info('iCarros duplicate lead ignored', ['external_id' => $externalId]);
            return null;
        }

        return PortalLead::create([
            'portal' => 'icarros',
            'external_lead_id' => $externalId,
            'veiculo_id' => $payload['dealId'] ?? $payload['deal_id'] ?? $payload['ad_id'] ?? null,
            'name' => $payload['name'] ?? $payload['nome'] ?? null,
            'email' => $payload['email'] ?? null,
            'phone' => $payload['phone'] ?? $payload['telefone'] ?? null,
            'message' => $payload['message'] ?? $payload['mensagem'] ?? null,
            'status' => 'new',
            'extra_data' => $payload,
        ]);
    }

    /**
     * Process Mercado Livre question notification
     * Note: This just logs the notification - actual question fetch requires API call
     */
    protected function processMercadoLivreQuestion(array $payload): void
    {
        // Mercado Livre sends a notification with resource URL
        // The actual question must be fetched via API using the resource
        // This is handled by the scheduled FetchLeadsJob

        Log::info('MercadoLivre question notification received', [
            'resource' => $payload['resource'] ?? null,
            'user_id' => $payload['user_id'] ?? null,
        ]);

        // Store notification for later processing if needed
        PortalSyncLog::log(
            'mercadolivre',
            'webhook:question',
            'success',
            [
                'resource' => $payload['resource'] ?? null,
                'topic' => $payload['topic'] ?? null,
                'application_id' => $payload['application_id'] ?? null,
            ]
        );
    }

    /**
     * Log webhook request
     */
    protected function logWebhook(
        string $portal,
        string $action,
        array $payload,
        bool $success,
        int $durationMs,
        ?string $error = null
    ): void {
        PortalSyncLog::log(
            $portal,
            'webhook:' . $action,
            $success ? 'success' : 'error',
            [
                'http_method' => 'POST',
                'endpoint' => 'webhook',
                'request_payload' => $payload,
                'error_message' => $error,
                'duration_ms' => $durationMs,
            ]
        );
    }
}
