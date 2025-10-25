<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class ServiceAPI
{
    public function __construct(
        #[Autowire(env: 'API_BASE_URL')] private readonly string $baseUrl,
        #[Autowire(env: 'API_COMPANY_ID')] private readonly ?string $defaultCompanyId = null,
    ) {}

    public function fetchDigitalToken(string $idToken, ?string $companyId = null): string
    {
        $company = $companyId ?: ($this->defaultCompanyId ?? '');
        if ($company === '') {
            throw new \RuntimeException('Missing companyId for digital token request. Configure API_COMPANY_ID.');
        }
        if ($idToken === '') {
            throw new \RuntimeException('Missing ID token for authorization.');
        }

        $base = $this->baseUrl !== '' ? $this->baseUrl : 'http://localhost:8000';
        $url = rtrim($base, '/') . '/api/v1/tokens/digital?companyId=' . rawurlencode($company);

        $opts = [
            'http' => [
                'method' => 'GET',
                'header' =>
                    "Accept: application/json\r\n" .
                    'Authorization: Bearer ' . $idToken . "\r\n",
                'ignore_errors' => true,
                'timeout' => 10,
            ],
        ];

        $context = stream_context_create($opts);
        $result = @file_get_contents($url, false, $context);
        if ($result === false) {
            throw new \RuntimeException('No se pudo obtener el token digital desde el servidor.');
        }

        /** @var array<string,mixed> $data */
        $data = json_decode($result, true);
        if (!is_array($data)) {
            throw new \RuntimeException('Respuesta del servidor no es JSON válido.');
        }

        $token = $this->extractSixDigitToken($data);
        if ($token === null) {
            throw new \RuntimeException('No se encontró un token de 6 dígitos en la respuesta.');
        }

        return $token;
    }

    /**
     * @param array<string,mixed> $data
     */
    private function extractSixDigitToken(array $data): ?string
    {
        $candidates = ['token', 'code', 'otp', 'pin'];
        foreach ($candidates as $key) {
            if (isset($data[$key]) && is_scalar($data[$key])) {
                $val = (string) $data[$key];
                if (preg_match('/^\\d{6}$/', $val)) {
                    return $val;
                }
            }
        }

        $queue = [$data];
        while ($queue) {
            $node = array_shift($queue);
            foreach ($node as $v) {
                if (is_array($v)) {
                    $queue[] = $v;
                } elseif (is_scalar($v)) {
                    $val = (string) $v;
                    if (preg_match('/^\\d{6}$/', $val)) {
                        return $val;
                    }
                }
            }
        }

        return null;
    }

    public function generateApiKey(string $idToken): string
    {
        if ($idToken === '') {
            throw new \RuntimeException('Missing ID token for authorization.');
        }
        $base = $this->baseUrl !== '' ? $this->baseUrl : 'http://localhost:8000';
        $url = rtrim($base, '/') . '/api/v1/apikeys/generate';

        $opts = [
            'http' => [
                'method' => 'POST',
                'header' =>
                    "Accept: application/json\r\n" .
                    "Content-Type: application/json\r\n" .
                    'Authorization: Bearer ' . $idToken . "\r\n",
                'content' => '{}',
                'ignore_errors' => true,
                'timeout' => 10,
            ],
        ];
        $ctx = stream_context_create($opts);
        $res = @file_get_contents($url, false, $ctx);
        if ($res === false) {
            throw new \RuntimeException('No se pudo generar la API Key.');
        }
        /** @var array<string,mixed> $data */
        $data = json_decode($res, true);
        if (!is_array($data)) {
            throw new \RuntimeException('Respuesta inválida al generar API Key.');
        }
        $key = $this->extractApiKey($data);
        if ($key === null || $key === '') {
            throw new \RuntimeException('No se encontró API Key en la respuesta.');
        }
        return $key;
    }

    /**
     * Rotate API key using backend endpoint. Returns array with apiKey and keyId.
     * @param array<string,mixed> $payload Minimal required: accountId, oldKeyId
     * @return array{apiKey:string,keyId:string}
     */
    public function rotateApiKey(string $idToken, array $payload): array
    {
        if ($idToken === '') {
            throw new \RuntimeException('Missing ID token for authorization.');
        }
        $base = $this->baseUrl !== '' ? $this->baseUrl : 'http://localhost:8000';
        $url = rtrim($base, '/') . '/api/v1/api-keys/rotate';

        $opts = [
            'http' => [
                'method' => 'POST',
                'header' =>
                    "Accept: application/json\r\n" .
                    "Content-Type: application/json\r\n" .
                    'Authorization: Bearer ' . $idToken . "\r\n",
                'content' => json_encode($payload, JSON_UNESCAPED_SLASHES),
                'ignore_errors' => true,
                'timeout' => 15,
            ],
        ];
        $ctx = stream_context_create($opts);
        $res = @file_get_contents($url, false, $ctx);
        if ($res === false) {
            throw new \RuntimeException('No se pudo rotar la API Key.');
        }
        $data = json_decode($res, true);
        if (!is_array($data) || empty($data['apiKey']) || empty($data['keyId'])) {
            throw new \RuntimeException('Respuesta inválida al rotar API Key.');
        }
        return ['apiKey' => (string)$data['apiKey'], 'keyId' => (string)$data['keyId']];
    }

    /**
     * @param array<string,mixed> $data
     */
    private function extractApiKey(array $data): ?string
    {
        foreach (['apiKey', 'key', 'token'] as $k) {
            if (isset($data[$k]) && is_scalar($data[$k])) {
                $v = (string) $data[$k];
                if ($v !== '') return $v;
            }
        }
        foreach ($data as $v) {
            if (is_array($v)) {
                $res = $this->extractApiKey($v);
                if ($res) return $res;
            }
        }
        return null;
    }
}
