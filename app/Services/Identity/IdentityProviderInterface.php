<?php

declare(strict_types=1);

namespace App\Services\Identity;

interface IdentityProviderInterface
{



    public function getProviderName(): string;







    public function authenticate(array $credentials): array;








    public function verifyIdentity(string $personalNumber, array $context = []): array;







    public function fetchProfile(string $token): array;
}
