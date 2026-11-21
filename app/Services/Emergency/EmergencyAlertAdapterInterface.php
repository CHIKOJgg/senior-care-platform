<?php

declare(strict_types=1);

namespace App\Services\Emergency;

interface EmergencyAlertAdapterInterface
{



    public function getProviderName(): string;







    public function dispatchEmergency(array $incidentData): array;







    public function checkIncidentStatus(string $ticketId): array;








    public function cancelIncident(string $ticketId, string $reason = ''): array;
}
