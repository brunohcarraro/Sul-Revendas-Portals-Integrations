<?php

namespace App\Services\Portals\WebMotors;

use App\Models\PortalSyncLog;
use App\Services\Portals\Contracts\PortalAdapterInterface;
use Illuminate\Support\Facades\Log;
use SoapClient;
use SoapFault;

/**
 * WebMotors SOAP API Adapter
 *
 * This adapter uses the SOAP API for direct vehicle management.
 * Documentation: https://integracao.webmotors.com.br/manualintegracao/index.html
 *
 * Requires: PHP SOAP extension enabled
 */
class WebMotorsSoapAdapter implements PortalAdapterInterface
{
    protected array $config;
    protected ?string $hashAutenticacao = null;
    protected ?SoapClient $client = null;
    protected ?SoapClient $clientMotos = null;

    public function __construct()
    {
        $this->config = config('portals.webmotors');
    }

    public function getPortalName(): string
    {
        return 'webmotors';
    }

    public function getBaseUrl(): string
    {
        return $this->config['urls']['soap_endpoint'];
    }

    public function getWsdlUrl(): string
    {
        return $this->config['urls']['soap_wsdl'];
    }

    public function setCredentials($credentials): self
    {
        // For compatibility - credentials come from config
        return $this;
    }

    /**
     * Set the authentication hash directly (for testing)
     */
    public function setHashAutenticacao(string $hash): self
    {
        $this->hashAutenticacao = $hash;
        return $this;
    }

    public function authenticate(): bool
    {
        // Load hash from config if already set
        $this->hashAutenticacao = $this->hashAutenticacao ?? $this->config['hash_autenticacao'] ?? null;

        // If no hash, try to get it using login credentials
        if (!$this->hashAutenticacao) {
            $login = $this->config['login'] ?? [];
            $email = $login['email'] ?? null;
            $password = $login['password'] ?? null;
            $cnpj = $login['cnpj'] ?? null;

            if (!$email || !$password || !$cnpj) {
                Log::error('WebMotors SOAP: Missing login credentials. Set WEBMOTORS_LOGIN_EMAIL, WEBMOTORS_LOGIN_PASSWORD, WEBMOTORS_CNPJ in .env');
                return false;
            }

            try {
                $this->initClient();

                // Authenticate with login credentials to get hash
                $result = $this->client->AutenticarUsuario([
                    'pEmail' => $email,
                    'pSenha' => $password,
                    'pCNPJ' => preg_replace('/\D/', '', $cnpj), // Remove non-digits
                ]);

                if (isset($result->AutenticarUsuarioResult) && !empty($result->AutenticarUsuarioResult)) {
                    $this->hashAutenticacao = $result->AutenticarUsuarioResult;
                    Log::info('WebMotors SOAP: Authentication successful, hash obtained');
                    return true;
                }

                Log::error('WebMotors SOAP: AutenticarUsuario returned empty result');
                return false;

            } catch (SoapFault $e) {
                Log::error('WebMotors SOAP: Authentication failed', ['error' => $e->getMessage()]);
                return false;
            } catch (\RuntimeException $e) {
                Log::error('WebMotors SOAP: ' . $e->getMessage());
                return false;
            }
        }

        // Validate existing hash by testing connection
        try {
            $this->initClient();

            $result = $this->client->ObterMarca([
                'pHashAutenticacao' => $this->hashAutenticacao
            ]);

            return isset($result->ObterMarcaResult);

        } catch (SoapFault $e) {
            Log::error('WebMotors SOAP: Authentication test failed', ['error' => $e->getMessage()]);
            return false;
        } catch (\RuntimeException $e) {
            Log::error('WebMotors SOAP: ' . $e->getMessage());
            return false;
        }
    }

    public function isAuthenticated(): bool
    {
        return $this->hashAutenticacao !== null;
    }

    /**
     * Initialize SOAP client for cars
     */
    protected function initClient(): void
    {
        if ($this->client) {
            return;
        }

        if (!extension_loaded('soap')) {
            throw new \RuntimeException('PHP SOAP extension is not enabled. Enable it in php.ini');
        }

        $this->client = new SoapClient($this->getWsdlUrl(), [
            'trace' => true,
            'exceptions' => true,
            'cache_wsdl' => WSDL_CACHE_NONE,
            'soap_version' => SOAP_1_2,
            'connection_timeout' => 30,
        ]);
    }

    /**
     * Initialize SOAP client for motorcycles
     */
    protected function initClientMotos(): void
    {
        if ($this->clientMotos) {
            return;
        }

        if (!extension_loaded('soap')) {
            throw new \RuntimeException('PHP SOAP extension is not enabled. Enable it in php.ini');
        }

        $wsdlMotos = str_replace(
            'wsEstoqueRevendedorWebMotors.asmx',
            'Motos/wsEstoqueRevendedorWebMotorsMotos.asmx',
            $this->getWsdlUrl()
        );

        $this->clientMotos = new SoapClient($wsdlMotos, [
            'trace' => true,
            'exceptions' => true,
            'cache_wsdl' => WSDL_CACHE_NONE,
            'soap_version' => SOAP_1_2,
            'connection_timeout' => 30,
        ]);
    }

    /**
     * Make a SOAP call with logging
     */
    protected function soapCall(string $method, array $params, bool $isMotos = false): array
    {
        $startTime = microtime(true);

        // Add authentication hash to all calls
        $params['pHashAutenticacao'] = $this->hashAutenticacao;

        try {
            if ($isMotos) {
                $this->initClientMotos();
                $client = $this->clientMotos;
            } else {
                $this->initClient();
                $client = $this->client;
            }

            $result = $client->$method($params);
            $durationMs = (int) ((microtime(true) - $startTime) * 1000);

            // Convert result to array
            $resultArray = json_decode(json_encode($result), true);

            $this->logRequest($method, $params, $resultArray, true, $durationMs);

            return [
                'success' => true,
                'data' => $resultArray,
                'error' => null,
            ];

        } catch (SoapFault $e) {
            $durationMs = (int) ((microtime(true) - $startTime) * 1000);

            Log::error("WebMotors SOAP Error: {$method}", [
                'error' => $e->getMessage(),
                'code' => $e->getCode(),
            ]);

            $this->logRequest($method, $params, null, false, $durationMs, $e->getMessage());

            return [
                'success' => false,
                'data' => null,
                'error' => $e->getMessage(),
            ];
        }
    }

    protected function logRequest(
        string $method,
        array $params,
        ?array $response,
        bool $success,
        int $durationMs,
        ?string $error = null
    ): void {
        // Remove hash from logged params for security
        $logParams = $params;
        if (isset($logParams['pHashAutenticacao'])) {
            $logParams['pHashAutenticacao'] = '***REDACTED***';
        }

        PortalSyncLog::log(
            $this->getPortalName(),
            'SOAP:' . $method,
            $success ? 'success' : 'error',
            [
                'http_method' => 'SOAP',
                'endpoint' => $method,
                'request_payload' => $logParams,
                'response_body' => $response,
                'error_message' => $error,
                'duration_ms' => $durationMs,
            ]
        );
    }

    // ========================================
    // Reference Data Methods
    // ========================================

    public function getMarcas(): array
    {
        $result = $this->soapCall('ObterMarca', []);

        if (!$result['success']) {
            return ['success' => false, 'data' => [], 'error' => $result['error']];
        }

        $marcas = $result['data']['ObterMarcaResult']['MarcaWM'] ?? [];

        // Ensure always an array
        if (isset($marcas['CodigoMarca'])) {
            $marcas = [$marcas];
        }

        return ['success' => true, 'data' => $marcas, 'error' => null];
    }

    public function getModelos(int $codigoMarca): array
    {
        $result = $this->soapCall('ObterModelo', [
            'pCodigoMarca' => $codigoMarca
        ]);

        if (!$result['success']) {
            return ['success' => false, 'data' => [], 'error' => $result['error']];
        }

        $modelos = $result['data']['ObterModeloResult']['ModeloWM'] ?? [];

        if (isset($modelos['CodigoModelo'])) {
            $modelos = [$modelos];
        }

        return ['success' => true, 'data' => $modelos, 'error' => null];
    }

    public function getVersoes(int $codigoModelo): array
    {
        $result = $this->soapCall('ObterVersao', [
            'pCodigoModelo' => $codigoModelo,
            'pDataInicioAtualizacao' => '2000-01-01T00:00:00',
            'pDataFimAtualizacao' => date('Y-m-d\TH:i:s'),
        ]);

        if (!$result['success']) {
            return ['success' => false, 'data' => [], 'error' => $result['error']];
        }

        $versoes = $result['data']['ObterVersaoResult']['Versao'] ?? [];

        if (isset($versoes['CodigoVersao'])) {
            $versoes = [$versoes];
        }

        return ['success' => true, 'data' => $versoes, 'error' => null];
    }

    public function getCores(): array
    {
        $result = $this->soapCall('ObterCores', []);

        if (!$result['success']) {
            return ['success' => false, 'data' => [], 'error' => $result['error']];
        }

        $cores = $result['data']['ObterCoresResult']['CorWM'] ?? [];

        if (isset($cores['CodigoCor'])) {
            $cores = [$cores];
        }

        return ['success' => true, 'data' => $cores, 'error' => null];
    }

    public function getCombustiveis(): array
    {
        $result = $this->soapCall('ObterCombustivel', []);

        if (!$result['success']) {
            return ['success' => false, 'data' => [], 'error' => $result['error']];
        }

        $combustiveis = $result['data']['ObterCombustivelResult']['CombustivelWM'] ?? [];

        if (isset($combustiveis['CodigoCombustivel'])) {
            $combustiveis = [$combustiveis];
        }

        return ['success' => true, 'data' => $combustiveis, 'error' => null];
    }

    public function getCambios(): array
    {
        $result = $this->soapCall('ObterCambio', []);

        if (!$result['success']) {
            return ['success' => false, 'data' => [], 'error' => $result['error']];
        }

        $cambios = $result['data']['ObterCambioResult']['TipoCambioWM'] ?? [];

        if (isset($cambios['CodigoCambio'])) {
            $cambios = [$cambios];
        }

        return ['success' => true, 'data' => $cambios, 'error' => null];
    }

    public function getOpcionais(): array
    {
        $result = $this->soapCall('ObterOpcionais', []);

        if (!$result['success']) {
            return ['success' => false, 'data' => [], 'error' => $result['error']];
        }

        $opcionais = $result['data']['ObterOpcionaisResult']['OpcionalWM'] ?? [];

        if (isset($opcionais['CodigoOpcional'])) {
            $opcionais = [$opcionais];
        }

        return ['success' => true, 'data' => $opcionais, 'error' => null];
    }

    public function getModalidades(): array
    {
        $result = $this->soapCall('ObterModalidade', []);

        if (!$result['success']) {
            return ['success' => false, 'data' => [], 'error' => $result['error']];
        }

        $modalidades = $result['data']['ObterModalidadeResult']['ModalidadeWM'] ?? [];

        if (isset($modalidades['CodigoModalidade'])) {
            $modalidades = [$modalidades];
        }

        return ['success' => true, 'data' => $modalidades, 'error' => null];
    }

    // ========================================
    // Inventory Methods
    // ========================================

    public function getPublishedVehicles(): array
    {
        $result = $this->soapCall('ObterEstoqueAtual', []);

        if (!$result['success']) {
            return ['success' => false, 'vehicles' => [], 'error' => $result['error']];
        }

        $vehicles = $result['data']['ObterEstoqueAtualResult']['Anuncio'] ?? [];

        // Ensure it's always an array
        if (!is_array($vehicles) || isset($vehicles['CodigoAnuncio'])) {
            $vehicles = [$vehicles];
        }

        return ['success' => true, 'vehicles' => $vehicles, 'error' => null];
    }

    public function getPublishedVehiclesPaginated(int $page = 1, int $pageSize = 50): array
    {
        $result = $this->soapCall('ObterEstoqueAtualPaginado', [
            'pPagina' => $page,
            'pTamanho' => $pageSize,
        ]);

        if (!$result['success']) {
            return ['success' => false, 'vehicles' => [], 'pagination' => null, 'error' => $result['error']];
        }

        $data = $result['data']['ObterEstoqueAtualPaginadoResult'] ?? [];
        $vehicles = $data['Anuncios']['Anuncio'] ?? [];

        if (!is_array($vehicles) || isset($vehicles['CodigoAnuncio'])) {
            $vehicles = [$vehicles];
        }

        return [
            'success' => true,
            'vehicles' => $vehicles,
            'pagination' => [
                'total' => $data['TotalRegistros'] ?? 0,
                'page' => $data['PaginaAtual'] ?? $page,
                'pages' => $data['TotalPaginas'] ?? 1,
            ],
            'error' => null,
        ];
    }

    // ========================================
    // Vehicle CRUD
    // ========================================

    public function publishVehicle(array $vehicleData): array
    {
        $anuncio = $this->transformVehicleData($vehicleData);

        $result = $this->soapCall('IncluirCarro', [
            'pAnuncio' => $anuncio
        ]);

        if (!$result['success']) {
            return [
                'success' => false,
                'external_id' => null,
                'url' => null,
                'error' => $result['error'],
            ];
        }

        $response = $result['data']['IncluirCarroResult'] ?? [];

        // Check for errors in response
        if (!empty($response['MensagemErro'])) {
            return [
                'success' => false,
                'external_id' => null,
                'url' => null,
                'error' => $response['MensagemErro'],
            ];
        }

        return [
            'success' => true,
            'external_id' => $response['CodigoAnuncio'] ?? null,
            'url' => $response['UrlAnuncio'] ?? null,
            'error' => null,
        ];
    }

    public function updateVehicle(string $externalId, array $vehicleData): array
    {
        $anuncio = $this->transformVehicleData($vehicleData);
        $anuncio['CodigoAnuncio'] = $externalId;

        $result = $this->soapCall('AlterarCarro', [
            'pAnuncio' => $anuncio
        ]);

        if (!$result['success']) {
            return ['success' => false, 'error' => $result['error']];
        }

        $response = $result['data']['AlterarCarroResult'] ?? [];

        if (!empty($response['MensagemErro'])) {
            return ['success' => false, 'error' => $response['MensagemErro']];
        }

        return ['success' => true, 'error' => null];
    }

    public function removeVehicle(string $externalId): array
    {
        $result = $this->soapCall('ExcluirCarro', [
            'pCodigoAnuncio' => $externalId,
            'pMotivoExclusao' => 1, // 1 = Sold, 2 = Other
        ]);

        if (!$result['success']) {
            return ['success' => false, 'error' => $result['error']];
        }

        $response = $result['data']['ExcluirCarroResult'] ?? [];

        if (!empty($response['MensagemErro'])) {
            return ['success' => false, 'error' => $response['MensagemErro']];
        }

        return ['success' => true, 'error' => null];
    }

    public function updateVehicleStatus(string $externalId, string $status): array
    {
        // WebMotors SOAP API doesn't have a direct status update
        // For "sold" status, we remove the vehicle
        if (in_array($status, ['sold', 'inactive'])) {
            return $this->removeVehicle($externalId);
        }

        return ['success' => false, 'error' => 'Status update not supported via SOAP API'];
    }

    // ========================================
    // Photo Methods
    // ========================================

    public function addPhotoByUrl(string $codigoAnuncio, string $imageUrl): array
    {
        $result = $this->soapCall('IncluirFotoUrl', [
            'oUrlImagem' => $imageUrl,
            'pCodigoAnuncio' => $codigoAnuncio,
        ]);

        if (!$result['success']) {
            return ['success' => false, 'photo_id' => null, 'error' => $result['error']];
        }

        $response = $result['data']['IncluirFotoUrlResult'] ?? [];

        if (!empty($response['MensagemErro'])) {
            return ['success' => false, 'photo_id' => null, 'error' => $response['MensagemErro']];
        }

        return [
            'success' => true,
            'photo_id' => $response['CodigoFoto'] ?? null,
            'error' => null,
        ];
    }

    public function removePhoto(string $codigoFoto, string $codigoAnuncio): array
    {
        $result = $this->soapCall('ExcluirFoto', [
            'pCodigoFoto' => $codigoFoto,
            'pCodigoAnuncio' => $codigoAnuncio,
        ]);

        if (!$result['success']) {
            return ['success' => false, 'error' => $result['error']];
        }

        return ['success' => true, 'error' => null];
    }

    public function getVehiclePhotos(string $codigoAnuncio): array
    {
        $result = $this->soapCall('ObterFotosCarro', [
            'pCodigoAnuncio' => $codigoAnuncio,
        ]);

        if (!$result['success']) {
            return ['success' => false, 'photos' => [], 'error' => $result['error']];
        }

        $photos = $result['data']['ObterFotosCarroResult']['FotosWM'] ?? [];

        if (isset($photos['CodigoFoto'])) {
            $photos = [$photos];
        }

        return ['success' => true, 'photos' => $photos, 'error' => null];
    }

    // ========================================
    // Leads (not available via SOAP)
    // ========================================

    public function fetchLeads(array $filters = []): array
    {
        return [
            'success' => false,
            'leads' => [],
            'error' => 'Leads not available via SOAP API. Use REST API or webhooks.',
        ];
    }

    // ========================================
    // Data Transformation
    // ========================================

    public function transformVehicleData(array $vehicle): array
    {
        // WebMotors requires their own brand/model/version codes
        // Use pre-mapped IDs if available, otherwise find by name matching

        // Get brand code
        $codigoMarca = $vehicle['webmotors_marca_id'] ?? null;
        if (!$codigoMarca) {
            $brandName = $vehicle['fipe_marca_nome'] ?? $vehicle['kbb_marca_nome'] ?? null;
            $codigoMarca = $this->findMarcaByName($brandName);
        }

        // Get model code
        $codigoModelo = $vehicle['webmotors_modelo_id'] ?? null;
        if (!$codigoModelo && $codigoMarca) {
            $modelName = $vehicle['fipe_modelo_nome'] ?? $vehicle['kbb_modelo_nome'] ?? null;
            $codigoModelo = $this->findModeloByName($codigoMarca, $modelName);
        }

        // Get version code
        $codigoVersao = $vehicle['webmotors_versao_id'] ?? null;
        if (!$codigoVersao && $codigoModelo) {
            $versionName = $vehicle['fipe_versao_nome'] ?? $vehicle['kbb_versao_nome'] ?? null;
            $year = $vehicle['veiculo_ano_modelo'] ?? null;
            $codigoVersao = $this->findVersaoByName($codigoModelo, $versionName, $year);
        }

        $codigoCor = $vehicle['webmotors_cor_id']
            ?? $this->mapColorToWebMotors($vehicle['cor']['nome'] ?? null)
            ?? null;

        $codigoCombustivel = $vehicle['webmotors_combustivel_id']
            ?? $this->mapFuelToWebMotors($vehicle['combustivel']['nome'] ?? null)
            ?? null;

        $codigoCambio = $vehicle['webmotors_cambio_id']
            ?? $this->mapTransmissionToWebMotors($vehicle['cambio']['nome'] ?? null)
            ?? null;

        return [
            'CodigoMarca' => $codigoMarca,
            'CodigoModelo' => $codigoModelo,
            'CodigoVersao' => $codigoVersao,
            'AnoFabricacao' => $vehicle['veiculo_ano_fabricacao'] ?? null,
            'AnoModelo' => $vehicle['veiculo_ano_modelo'] ?? null,
            'Quilometragem' => $vehicle['veiculo_km'] ?? 0,
            'Placa' => $vehicle['veiculo_placa'] ?? '',
            'Preco' => $vehicle['veiculo_valor'] ?? 0,
            'CodigoCor' => $codigoCor,
            'CodigoCombustivel' => $codigoCombustivel,
            'CodigoCambio' => $codigoCambio,
            'NumeroPortas' => $vehicle['veiculo_portas'] ?? 4,
            'Observacao' => substr($vehicle['veiculo_obs'] ?? '', 0, 2000),
            'Blindado' => (bool) ($vehicle['blindado'] ?? false),
            'ZeroKm' => (bool) ($vehicle['zero_km'] ?? false),
            'CodigoModalidade' => $vehicle['webmotors_modalidade_id'] ?? 1,
            'Opcionais' => $vehicle['webmotors_opcionais'] ?? [],
        ];
    }

    /**
     * Find WebMotors brand code by name
     */
    public function findMarcaByName(?string $brandName): ?int
    {
        if (!$brandName) {
            return null;
        }

        $result = $this->getMarcas();
        if (!$result['success']) {
            return null;
        }

        $brandName = $this->normalizeString($brandName);

        foreach ($result['data'] as $marca) {
            $wmName = $this->normalizeString($marca['NomeMarca'] ?? '');
            if ($wmName === $brandName || str_contains($wmName, $brandName) || str_contains($brandName, $wmName)) {
                return (int) $marca['CodigoMarca'];
            }
        }

        return null;
    }

    /**
     * Find WebMotors model code by name
     */
    public function findModeloByName(int $codigoMarca, ?string $modelName): ?int
    {
        if (!$modelName) {
            return null;
        }

        $result = $this->getModelos($codigoMarca);
        if (!$result['success']) {
            return null;
        }

        $modelName = $this->normalizeString($modelName);

        foreach ($result['data'] as $modelo) {
            $wmName = $this->normalizeString($modelo['NomeModelo'] ?? '');
            if ($wmName === $modelName || str_contains($wmName, $modelName) || str_contains($modelName, $wmName)) {
                return (int) $modelo['CodigoModelo'];
            }
        }

        return null;
    }

    /**
     * Find WebMotors version code by name
     */
    public function findVersaoByName(int $codigoModelo, ?string $versionName, ?int $year = null): ?int
    {
        if (!$versionName) {
            return null;
        }

        $result = $this->getVersoes($codigoModelo);
        if (!$result['success']) {
            return null;
        }

        $versionName = $this->normalizeString($versionName);

        foreach ($result['data'] as $versao) {
            $wmName = $this->normalizeString($versao['NomeVersao'] ?? '');

            // Check year range if provided
            if ($year) {
                $anoInicio = (int) ($versao['AnoInicio'] ?? 0);
                $anoFim = (int) ($versao['AnoFim'] ?? 9999);
                if ($year < $anoInicio || $year > $anoFim) {
                    continue;
                }
            }

            if ($wmName === $versionName || str_contains($wmName, $versionName) || str_contains($versionName, $wmName)) {
                return (int) $versao['CodigoVersao'];
            }
        }

        // If no exact match, return first version that matches year
        if ($year) {
            foreach ($result['data'] as $versao) {
                $anoInicio = (int) ($versao['AnoInicio'] ?? 0);
                $anoFim = (int) ($versao['AnoFim'] ?? 9999);
                if ($year >= $anoInicio && $year <= $anoFim) {
                    return (int) $versao['CodigoVersao'];
                }
            }
        }

        return null;
    }

    /**
     * Normalize string for comparison
     */
    protected function normalizeString(string $str): string
    {
        $str = mb_strtolower($str);
        $str = preg_replace('/[^a-z0-9]/', '', $str);
        return $str;
    }

    /**
     * Map color name to WebMotors color code
     */
    protected function mapColorToWebMotors(?string $colorName): ?int
    {
        if (!$colorName) {
            return null;
        }

        $colorName = strtolower($colorName);
        $colors = [
            'preto' => 1,
            'branco' => 2,
            'prata' => 3,
            'cinza' => 4,
            'vermelho' => 5,
            'azul' => 6,
            'verde' => 7,
            'amarelo' => 8,
            'laranja' => 9,
            'marrom' => 10,
            'bege' => 11,
            'dourado' => 12,
            'vinho' => 13,
            'rosa' => 14,
        ];

        foreach ($colors as $name => $code) {
            if (str_contains($colorName, $name)) {
                return $code;
            }
        }

        return null;
    }

    /**
     * Map fuel type name to WebMotors fuel code
     */
    protected function mapFuelToWebMotors(?string $fuelName): ?int
    {
        if (!$fuelName) {
            return null;
        }

        $fuelName = strtolower($fuelName);
        return match (true) {
            str_contains($fuelName, 'flex') => 1,
            str_contains($fuelName, 'gasolina') && !str_contains($fuelName, 'etanol') => 2,
            str_contains($fuelName, 'etanol') && !str_contains($fuelName, 'gasolina') => 3,
            str_contains($fuelName, 'diesel') => 4,
            str_contains($fuelName, 'gnv') => 5,
            str_contains($fuelName, 'el') => 6,
            str_contains($fuelName, 'híbrido') || str_contains($fuelName, 'hibrido') => 7,
            default => null,
        };
    }

    /**
     * Map transmission name to WebMotors transmission code
     */
    protected function mapTransmissionToWebMotors(?string $transmissionName): ?int
    {
        if (!$transmissionName) {
            return null;
        }

        $transmissionName = strtolower($transmissionName);
        return match (true) {
            str_contains($transmissionName, 'manual') => 1,
            str_contains($transmissionName, 'autom') => 2,
            str_contains($transmissionName, 'cvt') => 3,
            str_contains($transmissionName, 'automatizado') => 4,
            default => null,
        };
    }
}
