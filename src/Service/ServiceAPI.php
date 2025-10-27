<?php
declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class ServiceAPI
{
    public function __construct(
        #[Autowire(env: 'API_BASE_URL')] private readonly string $baseUrl,
        #[Autowire(env: 'API_COMPANY_ID')] private readonly ?string $defaultCompanyId = null,
        private readonly HttpClientInterface $http,
    ) {}

    private function buildUrl(string $path, array $query = []): string
    {
        $base = $this->baseUrl !== '' ? rtrim($this->baseUrl, '/') : 'http://localhost:8000';
        if (!\preg_match('#^https?://#i', $base)) {
            throw new \RuntimeException('API_BASE_URL debe incluir esquema http/https.');
        }
        $url = $base . $path;
        if ($query) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }
        return $url;
    }

    /** ---------- NUEVOS MÉTODOS ---------- */


    public function createApiKey(string $idToken): array
    {
        if ($idToken === '') {
            throw new \RuntimeException('Missing ID token for authorization.');
        }

        $url = $this->buildUrl('/api/v1/api-keys/create');

        $headers = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $idToken,
        ];

        $response = $this->http->request('POST', $url, [
            'headers' => $headers,
            'timeout' => 15,
        ]);

        $data = $this->decodeJsonOrFail($response, 'No se pudo crear la API Key.');

        // Según tu ejemplo de Postman, la respuesta contiene apiKey y keyId
        $apiKey = $this->extractApiKey(is_array($data) ? $data : (array) $data);
        $keyId = isset($data['keyId']) && is_scalar($data['keyId']) ? (string)$data['keyId'] : null;

        // Validación mínima
        if ($apiKey === null && $keyId === null) {
            // devolver toda la respuesta en el error contextual
            throw new \RuntimeException('Respuesta inválida al crear API Key. ' . json_encode($data));
        }

        return [
            'apiKey' => $apiKey,
            'keyId'  => $keyId,
            'raw'    => $data,
        ];
    }


    public function getApiKeys(string $idToken): array
    {
        if ($idToken === '') {
            throw new \RuntimeException('Missing ID token for authorization.');
        }

        $url = $this->buildUrl('/api/v1/api-keys');

        $resp = $this->http->request('GET', $url, [
            'headers' => [
                'Accept' => 'application/json',
                'Authorization' => 'Bearer ' . $idToken,
            ],
            'timeout' => 10,
        ]);

        $data = $this->decodeJsonOrFail($resp, 'No se pudo obtener las API Keys.');

        if (!isset($data['items']) || !is_array($data['items'])) {
            throw new \RuntimeException('Respuesta inválida: falta el campo "items".');
        }

        $activeItems = array_filter($data['items'], static function (array $item): bool {
            return ($item['status'] ?? null) === 'active';
        });

        // Mapeamos cada ítem a un formato más simple
        $keys = array_map(static function (array $item): array {
            return [
                'keyId' => $item['keyId'] ?? null,
                'status' => $item['status'] ?? null,
                'createdAt' => $item['createdAt'] ?? null,
                'traceId' => $item['traceId'] ?? null,
                'expiresAt' => $item['expiresAt'] ?? null,
                'lastUsedAt' => $item['lastUsedAt'] ?? null,
                'lastUsedIp' => $item['lastUsedIp'] ?? null,
                'rateLimitPerMin' => $item['rateLimitPerMin'] ?? null,
            ];
        }, $activeItems);

        return array_values($keys);
    }

    public function fetchDigitalToken(string $idToken, ?string $companyId = null): string
    {
        $company = $companyId ?: ($this->defaultCompanyId ?? '');
        if ($company === '') {
            throw new \RuntimeException('Missing companyId for digital token request. Configure API_COMPANY_ID.');
        }
        if ($idToken === '') {
            throw new \RuntimeException('Missing ID token for authorization.');
        }
        $url = $this->buildUrl('/api/v1/tokens/digital', ['companyId' => $company]);

        $response = $this->http->request('GET', $url, [
            'headers' => [
                'Accept' => 'application/json',
                'Authorization' => 'Bearer '.$idToken,
            ],
            'timeout' => 10,
        ]);

        $data = $this->decodeJsonOrFail($response, 'No se pudo obtener el token digital desde el servidor.');
        $token = $this->extractSixDigitToken($data);
        if ($token === null) {
            throw new \RuntimeException('No se encontró un token de 6 dígitos en la respuesta.');
        }
        return $token;
    }

    public function rotateApiKey(string $idToken, string $oldKeyId): array
    {
        if ($idToken === '') {
            throw new \RuntimeException('Missing ID token for authorization.');
        }
        if ($oldKeyId === '') {
            throw new \RuntimeException('Debe indicar oldKeyId para rotar la API Key.');
        }

        $url = $this->buildUrl('/api/v1/api-keys/rotate');

        $response = $this->http->request('POST', $url, [
            'headers' => [
                'Accept'        => 'application/json',
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer '.$idToken,
            ],
            'json' => [
                'oldKeyId' => $oldKeyId,
            ],
            'timeout' => 15,
        ]);

        $data = $this->decodeJsonOrFail($response, 'No se pudo rotar la API Key.');

        $apiKey = isset($data['apiKey']) && is_scalar($data['apiKey']) ? (string)$data['apiKey'] : null;
        $keyId  = isset($data['keyId'])  && is_scalar($data['keyId'])  ? (string)$data['keyId']  : null;
        $secretVersion = isset($data['secretVersion']) && is_scalar($data['secretVersion'])
            ? (int)$data['secretVersion'] : null;

        if ($apiKey === null || $keyId === null) {
            throw new \RuntimeException('Respuesta inválida al rotar API Key: '.json_encode($data));
        }

        $headers = $response->getHeaders(false);
        $traceId = $data['traceId'] ?? ($headers['x-trace-id'][0] ?? ($headers['trace-id'][0] ?? null));

        return [
            'apiKey'        => $apiKey,
            'keyId'         => $keyId,
            'secretVersion' => $secretVersion,
            'traceId'       => is_scalar($traceId) ? (string)$traceId : null,
            'raw'           => $data,
        ];
    }

    public function deleteApiKey(string $idToken, string $keyId): array
    {
        if ($idToken === '') {
            throw new \RuntimeException('Missing ID token for authorization.');
        }
        $keyId = trim($keyId);
        if ($keyId === '') {
            throw new \RuntimeException('Debe indicar el keyId a eliminar.');
        }

        // /api/v1/api-keys/{keyId}
        $url = $this->buildUrl('/api/v1/api-keys/' . rawurlencode($keyId));

        $response = $this->http->request('DELETE', $url, [
            'headers' => [
                'Accept'        => 'application/json',
                'Content-Type'  => 'application/json', // opcional; lo dejamos para seguir tu cURL
                'Authorization' => 'Bearer ' . $idToken,
            ],
            'timeout' => 12,
        ]);

        $status = $response->getStatusCode();
        $headers = $response->getHeaders(false);
        $traceId = $headers['x-trace-id'][0] ?? ($headers['trace-id'][0] ?? null);

        // Algunos backends devuelven 204 No Content en DELETE
        if ($status === 204) {
            return [
                'success' => true,
                'keyId'   => $keyId,
                'traceId' => is_scalar($traceId) ? (string)$traceId : null,
                'raw'     => null,
            ];
        }

        // Si no es 204, intentamos decodificar JSON y validar que sea 2xx
        $data = $this->decodeJsonOrFail($response, 'No se pudo eliminar la API Key.');

        // Armamos una respuesta estándar. Si el backend devuelve 'success', 'message', etc., los exponemos.
        $respKeyId = isset($data['keyId']) && is_scalar($data['keyId']) ? (string)$data['keyId'] : $keyId;

        return [
            'success' => (bool)($data['success'] ?? ($status >= 200 && $status < 300)),
            'keyId'   => $respKeyId,
            'message' => isset($data['message']) && is_scalar($data['message']) ? (string)$data['message'] : null,
            'traceId' => isset($data['traceId']) && is_scalar($data['traceId'])
                ? (string)$data['traceId']
                : (is_scalar($traceId) ? (string)$traceId : null),
            'raw'     => $data,
        ];
    }

    public function validateTicketCode(string $ticketCode, string $code)
    {
    }

    /** ---------- helpers internos ---------- */

    /** @return array<string,mixed> */
    private function decodeJsonOrFail(\Symfony\Contracts\HttpClient\ResponseInterface $response, string $fallbackMsg): array
    {
        $status = $response->getStatusCode();
        $raw = $response->getContent(false); // no lanza por status
        $data = null;
        try {
            $parsed = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            $data = is_array($parsed) ? $parsed : null;
        } catch (\JsonException) {}

        if ($status < 200 || $status >= 300) {
            $bits = [];
            foreach (['code','message','errorCode','traceId'] as $k) {
                if (isset($data[$k]) && is_scalar($data[$k])) {
                    $bits[] = "$k=".(string)$data[$k];
                }
            }
            $ctx = $bits ? ' ['.implode(' ', $bits).']' : '';
            throw new \RuntimeException($fallbackMsg." (HTTP $status)$ctx");
        }
        if ($data === null) {
            throw new \RuntimeException($fallbackMsg.' (respuesta no es JSON válido)');
        }
        return $data;
    }

    /** @param array<string,mixed> $data */
    private function extractSixDigitToken(array $data): ?string
    {
        foreach (['token','code','otp','pin'] as $k) {
            if (isset($data[$k]) && is_scalar($data[$k])) {
                $v = trim((string)$data[$k]);
                if (\preg_match('/^\d{6}$/', $v) === 1) return $v;
            }
        }
        $queue = [$data];
        while ($queue) {
            $node = array_shift($queue);
            if (!is_array($node)) continue;
            foreach ($node as $v) {
                if (is_array($v)) $queue[] = $v;
                elseif (is_scalar($v)) {
                    $s = trim((string)$v);
                    if (\strlen($s) === 6 && \preg_match('/^\d{6}$/', $s) === 1) return $s;
                }
            }
        }
        return null;
    }

    /** @param array<string,mixed> $data */
    private function extractApiKey(array $data): ?string
    {
        foreach (['apiKey','key','token'] as $k) {
            if (isset($data[$k]) && is_scalar($data[$k])) {
                $v = trim((string)$data[$k]);
                if ($v !== '') return $v;
            }
        }
        foreach ($data as $v) {
            if (\is_array($v)) {
                $res = $this->extractApiKey($v);
                if ($res) return $res;
            }
        }
        return null;
    }
}
