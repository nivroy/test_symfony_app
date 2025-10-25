<?php

namespace App\Service;

final class Firestore
{
    public function __construct(
        private readonly FirebaseAdminCredentials $creds,
        private readonly GoogleOAuth $oauth,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->creds->isConfigured();
    }

    /**
     * @param array<string,mixed> $fields
     */
    public function createUserDocument(string $uid, array $fields): void
    {
        if (!$this->creds->isConfigured()) {
            throw new \RuntimeException('Credenciales de Firebase Admin no configuradas.');
        }

        $projectId = $this->creds->projectId();
        if (!$projectId) {
            throw new \RuntimeException('Project ID no disponible en las credenciales.');
        }

        $token = $this->oauth->getAccessToken('https://www.googleapis.com/auth/datastore');
        $url = sprintf('https://firestore.googleapis.com/v1/projects/%s/databases/(default)/documents/users?documentId=%s', rawurlencode($projectId), rawurlencode($uid));

        $doc = ['fields' => $this->encodeFields($fields)];
        $payload = json_encode($doc, JSON_UNESCAPED_SLASHES);

        $opts = [
            'http' => [
                'method' => 'POST',
                'header' => "Authorization: Bearer $token\r\nContent-Type: application/json\r\n",
                'content' => $payload,
                'ignore_errors' => true,
                'timeout' => 10,
            ],
        ];
        $ctx = stream_context_create($opts);
        $res = @file_get_contents($url, false, $ctx);
        if ($res === false) {
            throw new \RuntimeException('No se pudo crear el documento en Firestore.');
        }

        $json = json_decode($res, true);
        if (isset($json['error']['status'])) {
            // Ignore already exists
            if ((string) $json['error']['status'] === 'ALREADY_EXISTS') {
                return;
            }
            $msg = (string) ($json['error']['message'] ?? 'Error desconocido');
            throw new \RuntimeException('Firestore: ' . $msg);
        }
    }

    /**
     * @param array<string,mixed> $fields
     * @return array<string,mixed>
     */
    private function encodeFields(array $fields): array
    {
        $out = [];
        foreach ($fields as $key => $value) {
            if ($value === null) continue;
            $out[$key] = $this->encodeValue($value);
        }
        return $out;
    }

    /**
     * @return array<string,mixed>
     */
    private function encodeValue(mixed $value): array
    {
        return match (true) {
            is_string($value) => ['stringValue' => $value],
            is_int($value) => ['integerValue' => (string) $value],
            is_float($value) => ['doubleValue' => $value],
            is_bool($value) => ['booleanValue' => $value],
            $value instanceof \DateTimeInterface => ['timestampValue' => $value->format('Y-m-d\TH:i:s\Z')],
            is_array($value) => ['mapValue' => ['fields' => $this->encodeFields($value)]],
            default => ['stringValue' => (string) $value],
        };
    }
}

