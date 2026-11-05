<?php

declare(strict_types=1);

namespace App\Services\Emergency;

interface EmergencyAlertAdapterInterface
{
    /**
     * Machine-readable identifier of the emergency dispatch provider.
     */
    public function getProviderName(): string;

    /**
     * Dispatch an emergency alert to the external dispatch service.
     *
     * @param array<string, mixed> $incidentData Incident data (elderly_id, latitude, longitude, address, reason, etc.)
     * @return array<string, mixed> Standardized dispatch response structure
     */
    public function dispatchEmergency(array $incidentData): array;

    /**
     * Check current status of an ongoing emergency dispatch ticket.
     *
     * @param string $ticketId Ticket code (e.g. EMERCOM-112-BY-2026-XXXX)
     * @return array<string, mixed>
     */
    public function checkIncidentStatus(string $ticketId): array;

    /**
     * Cancel or recall an active emergency dispatch.
     *
     * @param string $ticketId
     * @param string $reason
     * @return array<string, mixed>
     */
    public function cancelIncident(string $ticketId, string $reason = ''): array;
}
