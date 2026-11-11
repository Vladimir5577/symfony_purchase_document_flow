<?php

declare(strict_types=1);

namespace App\Security;

use Lexik\Bundle\JWTAuthenticationBundle\Security\User\JWTUserInterface;

/**
 * Пользователь из JWT монолита. Claim `id` монолит кладёт в JWTCreatedListener,
 * `username` (логин) и `roles` — сам lexik. Имя, организацию и прочее берём
 * из справочника по id.
 */
final class JwtUser implements JWTUserInterface
{
    /**
     * @param list<string> $roles
     */
    public function __construct(
        private readonly int $id,
        private readonly string $login,
        private readonly array $roles,
    ) {
    }

    public static function createFromPayload($username, array $payload): self
    {
        return new self((int) ($payload['id'] ?? 0), (string) $username, array_values($payload['roles'] ?? []));
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getUserIdentifier(): string
    {
        return $this->login;
    }

    public function getRoles(): array
    {
        return $this->roles;
    }
}
