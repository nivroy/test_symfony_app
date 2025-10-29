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

    public function createUserDocument(string $uid, array $fields): void
    {
        $this->createDocument('users', $uid, $fields);
    }

    public function createCompanyDocument(string $uid, array $fields): void
    {
        $this->createDocument('companies', $uid, $fields);
    }

    public function updateDocument(string $collection, string $uid, array $fields): void
    {
        if (!$this->creds->isConfigured()) {
            throw new \RuntimeException('Credenciales de Firebase Admin no configuradas.');
        }
        $projectId = $this->creds->projectId();
        if (!$projectId) {
            throw new \RuntimeException('Project ID no disponible en las credenciales.');
        }

        $token = $this->oauth->getAccessToken('https://www.googleapis.com/auth/datastore');
        $mask = implode(',', array_map(static fn($k) => rawurlencode((string)$k), array_keys($fields)));
        $url = sprintf(
            'https://firestore.googleapis.com/v1/projects/%s/databases/(default)/documents/%s/%s?updateMask.fieldPaths=%s',
            rawurlencode($projectId), rawurlencode($collection), rawurlencode($uid), $mask
        );

        $doc = ['fields' => $this->encodeFields($fields)];
        $payload = json_encode($doc, JSON_UNESCAPED_SLASHES);
        $opts = [
            'http' => [
                'method' => 'PATCH',
                'header' => "Authorization: Bearer $token\r\nContent-Type: application/json\r\n",
                'content' => $payload,
                'ignore_errors' => true,
                'timeout' => 10,
            ],
        ];
        $ctx = stream_context_create($opts);
        $res = @file_get_contents($url, false, $ctx);
        if ($res === false) {
            throw new \RuntimeException('No se pudo actualizar el documento en Firestore.');
        }
        $json = json_decode($res, true);
        if (isset($json['error']['status'])) {
            $msg = (string) ($json['error']['message'] ?? 'Error desconocido');
            throw new \RuntimeException('Firestore: ' . $msg);
        }
    }

    public function createDocument(string $collection, string $uid, array $fields): void
    {
        if (!$this->creds->isConfigured()) {
            throw new \RuntimeException('Credenciales de Firebase Admin no configuradas.');
        }

        $projectId = $this->creds->projectId();
        if (!$projectId) {
            throw new \RuntimeException('Project ID no disponible en las credenciales.');
        }

        $token = $this->oauth->getAccessToken('https://www.googleapis.com/auth/datastore');
        $url = sprintf(
            'https://firestore.googleapis.com/v1/projects/%s/databases/(default)/documents/%s?documentId=%s',
            rawurlencode($projectId), rawurlencode($collection), rawurlencode($uid)
        );

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
            if ((string) $json['error']['status'] === 'ALREADY_EXISTS') {
                return;
            }
            $msg = (string) ($json['error']['message'] ?? 'Error desconocido');
            throw new \RuntimeException('Firestore: ' . $msg);
        }
    }

    private function encodeFields(array $fields): array
    {
        $out = [];
        foreach ($fields as $key => $value) {
            if ($value === null) continue;
            $out[$key] = $this->encodeValue($value);
        }
        return $out;
    }

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

    public function getDocument(string $collection, string $uid): ?array
    {
        if (!$this->creds->isConfigured()) {
            throw new \RuntimeException('Credenciales de Firebase Admin no configuradas.');
        }
        $projectId = $this->creds->projectId();
        if (!$projectId) {
            throw new \RuntimeException('Project ID no disponible en las credenciales.');
        }

        $token = $this->oauth->getAccessToken('https://www.googleapis.com/auth/datastore');
        $url = sprintf(
            'https://firestore.googleapis.com/v1/projects/%s/databases/(default)/documents/%s/%s',
            rawurlencode($projectId), rawurlencode($collection), rawurlencode($uid)
        );

        $opts = [
            'http' => [
                'method' => 'GET',
                'header' => "Authorization: Bearer $token\r\nAccept: application/json\r\n",
                'ignore_errors' => true,
                'timeout' => 10,
            ],
        ];
        $ctx = stream_context_create($opts);
        $res = @file_get_contents($url, false, $ctx);
        if ($res === false) {
            throw new \RuntimeException('No se pudo leer el documento en Firestore.');
        }
        $json = json_decode($res, true);
        if (!is_array($json)) {
            throw new \RuntimeException('Respuesta inválida de Firestore.');
        }
        if (isset($json['error']['status'])) {
            $status = (string) $json['error']['status'];
            if ($status === 'NOT_FOUND') {
                return null;
            }
            $msg = (string) ($json['error']['message'] ?? 'Error desconocido');
            throw new \RuntimeException('Firestore: ' . $msg);
        }

        /** @var array<string,mixed> $fields */
        $fields = (array) ($json['fields'] ?? []);
        return $this->decodeFields($fields);
    }

    private function decodeFields(array $fields): array
    {
        $out = [];
        foreach ($fields as $k => $v) {
            $out[$k] = $this->decodeValue($v);
        }
        return $out;
    }

    private function decodeValue(array $value): mixed
    {
        if (isset($value['stringValue'])) return (string) $value['stringValue'];
        if (isset($value['integerValue'])) return (int) $value['integerValue'];
        if (isset($value['doubleValue'])) return (float) $value['doubleValue'];
        if (isset($value['booleanValue'])) return (bool) $value['booleanValue'];
        if (isset($value['timestampValue'])) return (string) $value['timestampValue'];
        if (isset($value['mapValue']['fields']) && is_array($value['mapValue']['fields'])) {
            return $this->decodeFields($value['mapValue']['fields']);
        }
        return null;
    }

    public function getDocumentByPath(string $path): ?array
    {
        if (!$this->creds->isConfigured()) {
            throw new \RuntimeException('Credenciales de Firebase Admin no configuradas.');
        }
        $projectId = $this->creds->projectId();
        if (!$projectId) {
            throw new \RuntimeException('Project ID no disponible en las credenciales.');
        }
        $segments = array_map(static fn($s) => rawurlencode($s), explode('/', $path));
        $encodedPath = implode('/', $segments);
        $token = $this->oauth->getAccessToken('https://www.googleapis.com/auth/datastore');
        $url = sprintf(
            'https://firestore.googleapis.com/v1/projects/%s/databases/(default)/documents/%s',
            rawurlencode($projectId), $encodedPath
        );
        $opts = [
            'http' => [
                'method' => 'GET',
                'header' => "Authorization: Bearer $token\r\nAccept: application/json\r\n",
                'ignore_errors' => true,
                'timeout' => 10,
            ],
        ];
        $ctx = stream_context_create($opts);
        $res = @file_get_contents($url, false, $ctx);
        if ($res === false) {
            throw new \RuntimeException('No se pudo leer el documento en Firestore.');
        }
        $json = json_decode($res, true);
        if (!is_array($json)) {
            throw new \RuntimeException('Respuesta inválida de Firestore.');
        }
        if (isset($json['error']['status'])) {
            $status = (string) $json['error']['status'];
            if ($status === 'NOT_FOUND') {
                return null;
            }
            $msg = (string) ($json['error']['message'] ?? 'Error desconocido');
            throw new \RuntimeException('Firestore: ' . $msg);
        }
        $fields = (array) ($json['fields'] ?? []);
        return $this->decodeFields($fields);
    }

    public function getCompanyLatestApiKey(string $uid): ?array
    {
        $doc = $this->getDocumentByPath('companies/' . $uid . '/apikey/latest');
        if ($doc === null) return null;
        $out = [];
        if (isset($doc['value']) && is_string($doc['value'])) {
            $out['value'] = $doc['value'];
        }
        if (isset($doc['keyId']) && is_string($doc['keyId'])) {
            $out['keyId'] = $doc['keyId'];
        }
        return $out;
    }
}
