<?php

namespace App\Services\Portals\Contracts;

interface PortalAdapterInterface
{
    /**
     * Get the portal identifier
     */
    public function getPortalName(): string;

    /**
     * Set credentials for API calls (from config array)
     * Credentials come from config('portals.{portal}')
     */
    public function setCredentials($credentials): self;

    /**
     * Authenticate and get access token
     */
    public function authenticate(): bool;

    /**
     * Check if current token is valid
     */
    public function isAuthenticated(): bool;

    /**
     * Publish a vehicle to the portal
     *
     * @param array $vehicleData Normalized vehicle data
     * @return array ['success' => bool, 'external_id' => string|null, 'url' => string|null, 'error' => string|null]
     */
    public function publishVehicle(array $vehicleData): array;

    /**
     * Update an existing vehicle on the portal
     *
     * @param string $externalId The ID on the portal
     * @param array $vehicleData Normalized vehicle data
     * @return array ['success' => bool, 'error' => string|null]
     */
    public function updateVehicle(string $externalId, array $vehicleData): array;

    /**
     * Remove a vehicle from the portal
     *
     * @param string $externalId The ID on the portal
     * @return array ['success' => bool, 'error' => string|null]
     */
    public function removeVehicle(string $externalId): array;

    /**
     * Update vehicle status (pause/activate)
     *
     * @param string $externalId The ID on the portal
     * @param string $status 'active', 'paused', 'sold'
     * @return array ['success' => bool, 'error' => string|null]
     */
    public function updateVehicleStatus(string $externalId, string $status): array;

    /**
     * Fetch leads from the portal
     *
     * @param array $filters Optional filters (date range, etc.)
     * @return array ['success' => bool, 'leads' => array, 'error' => string|null]
     */
    public function fetchLeads(array $filters = []): array;

    /**
     * Transform internal vehicle data to portal format
     *
     * @param array $vehicle Vehicle data from tb_veiculos with relations
     * @return array Portal-specific payload
     */
    public function transformVehicleData(array $vehicle): array;

    /**
     * Get list of vehicles from portal (for sync verification)
     *
     * @return array ['success' => bool, 'vehicles' => array, 'error' => string|null]
     */
    public function getPublishedVehicles(): array;
}
