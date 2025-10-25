<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class FirebaseAdminCredentials
{
    /** @var array<string,mixed>|null */
    private ?array $json;

    public function __construct(
        #[Autowire(env: 'FIREBASE_ADMIN_CREDENTIALS_JSON_BASE64')] private readonly ?string $b64,
    ) {
        $this->json = null;
        if ($this->b64) {
            $raw = base64_decode($this->b64, true);
            if ($raw !== false && $raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $this->json = $decoded;
                }
            }
        }
    }

    public function isConfigured(): bool
    {
        return is_array($this->json)
            && ($this->json['client_email'] ?? null)
            && ($this->json['private_key'] ?? null)
            && ($this->json['project_id'] ?? null);
    }

    public function projectId(): ?string
    {
        return $this->json['project_id'] ?? null;
    }

    public function clientEmail(): ?string
    {
        return $this->json['client_email'] ?? null;
    }

    public function privateKey(): ?string
    {
        $key = $this->json['private_key'] ?? null;
        return is_string($key) ? $key : null;
    }
}

