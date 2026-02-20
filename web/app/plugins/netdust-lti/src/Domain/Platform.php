<?php
declare(strict_types=1);

namespace NetdustLTI\Domain;

final readonly class Platform
{
    public function __construct(
        public ?int $id,
        public string $name,
        public string $platformId,
        public string $clientId,
        public ?string $deploymentId,
        public string $authEndpoint,
        public string $tokenEndpoint,
        public string $jwksEndpoint,
        public bool $enabled,
        public ?\DateTimeImmutable $createdAt = null,
        public ?\DateTimeImmutable $updatedAt = null,
    ) {}

    public static function fromRow(array $row): self
    {
        return new self(
            id: (int) $row['id'],
            name: $row['name'],
            platformId: $row['platform_id'],
            clientId: $row['client_id'],
            deploymentId: $row['deployment_id'],
            authEndpoint: $row['auth_endpoint'],
            tokenEndpoint: $row['token_endpoint'],
            jwksEndpoint: $row['jwks_endpoint'],
            enabled: (bool) $row['enabled'],
            createdAt: new \DateTimeImmutable($row['created_at']),
            updatedAt: new \DateTimeImmutable($row['updated_at']),
        );
    }

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'platform_id' => $this->platformId,
            'client_id' => $this->clientId,
            'deployment_id' => $this->deploymentId,
            'auth_endpoint' => $this->authEndpoint,
            'token_endpoint' => $this->tokenEndpoint,
            'jwks_endpoint' => $this->jwksEndpoint,
            'enabled' => $this->enabled ? 1 : 0,
        ];
    }
}
