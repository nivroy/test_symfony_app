<?php
declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

interface ServiceInterface {
    public function buildUrl(string $path, array $query = []): string;
    public function createApiKey(string $idToken): array;
    public function getApiKeys(string $idToken): array;
    public function getActiveUserConnections(string $idToken): array;
    public function fetchDigitalToken(string $idToken, ?string $companyId = null): string;
    public function rotateApiKey(string $idToken, string $oldKeyId): array;
    public function deleteApiKey(string $idToken, string $keyId): array;
    public function validateTicketCode(string $ticketCode, string $code);
    public function acceptInvitation(string $invitationId, string $idToken);
    public function rejectInvitation(string $invitationId, string $idToken);
}

class ServiceAPI implements ServiceInterface
{
    public function __construct(
        #[Autowire(env: 'BASE_URL_API')] private readonly string $baseUrl,
        private readonly HttpClientInterface $http,
    ) {}

    public function buildUrl(string $path, array $query = []): string
    {
        $base = $this->baseUrl !== '' ? rtrim($this->baseUrl, '/') : 'http://localhost:8000';
        if (!\preg_match('#^https?://#i', $base)) {
            throw new \RuntimeException('BASE_URL_API debe incluir esquema http/https.');
        }
        $url = $base . $path;
        if ($query) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }
        return $url;
    }

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

        $apiKey = $this->extractApiKey(is_array($data) ? $data : (array) $data);
        $keyId = isset($data['keyId']) && is_scalar($data['keyId']) ? (string)$data['keyId'] : null;

        if ($apiKey === null && $keyId === null) {
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
        $company = $companyId !== null ? trim($companyId) : '';
        if ($company === '') {
            throw new \RuntimeException('Missing companyId for digital token request.');
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

    public function getActiveUserConnections(string $idToken): array
    {
        if ($idToken === '') {
            throw new \RuntimeException('Missing ID token for authorization.');
        }

        $query = [];
        $connections = [];

        do {
            $url = $this->buildUrl('/api/v1/user/connections/intents', $query);
            $response = $this->http->request('GET', $url, [
                'headers' => [
                    'Accept' => 'application/json',
                    'Authorization' => 'Bearer ' . $idToken,
                ],
                'timeout' => 10,
            ]);

            $data = $this->decodeJsonOrFail($response, 'No se pudieron obtener las conexiones del usuario.');

            $items = isset($data['data']) && is_array($data['data']) ? $data['data'] : [];
            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $normalized = $this->normalizeConnectionIntent($item);
                if ($normalized['status'] === 'ACTIVE') {
                    $connections[] = $normalized;
                }
            }

            $nextCursor = isset($data['next_cursor']) && is_scalar($data['next_cursor'])
                ? trim((string) $data['next_cursor'])
                : '';
            $query = $nextCursor !== '' ? ['cursor' => $nextCursor] : [];
        } while (!empty($query));

        return $connections;
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

        $url = $this->buildUrl('/api/v1/api-keys/' . rawurlencode($keyId));

        $response = $this->http->request('DELETE', $url, [
            'headers' => [
                'Accept'        => 'application/json',
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $idToken,
            ],
            'timeout' => 12,
        ]);

        $status = $response->getStatusCode();
        $headers = $response->getHeaders(false);
        $traceId = $headers['x-trace-id'][0] ?? ($headers['trace-id'][0] ?? null);

        if ($status === 204) {
            return [
                'success' => true,
                'keyId'   => $keyId,
                'traceId' => is_scalar($traceId) ? (string)$traceId : null,
                'raw'     => null,
            ];
        }

        $data = $this->decodeJsonOrFail($response, 'No se pudo eliminar la API Key.');

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
        $ticketCode = trim($ticketCode);
        $code = trim($code);

        if ($ticketCode === '' || $code === '') {
            throw new \RuntimeException('Debe indicar ticketCode y code (token).');
        }
        // Opcional: forzar token de 6 dígitos numéricos
        if (\preg_match('/^\d{6}$/', $code) !== 1) {
            throw new \RuntimeException('El código (token) debe ser un número de 6 dígitos.');
        }

        $url = $this->buildUrl('/api/v1/tokens/validate');

        $response = $this->http->request('POST', $url, [
            'headers' => [
                'Accept'       => 'application/json',
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'token'    => $code,
                'ticketId' => $ticketCode,
            ],
            'timeout' => 10,
        ]);

        // Lanza excepción si HTTP != 2xx o si el cuerpo no es JSON válido
        $data = $this->decodeJsonOrFail($response, 'No se pudo validar el token del ticket.');

        // Campos típicos de la respuesta del ejemplo
        $success = (bool)($data['success'] ?? false);
        $reason  = isset($data['reason']) && is_scalar($data['reason']) ? (string)$data['reason'] : null;
        $status  = isset($data['status']) && is_scalar($data['status']) ? (string)$data['status'] : null;
        $traceId = isset($data['traceId']) && is_scalar($data['traceId']) ? (string)$data['traceId'] : null;

        // Fallback: intentar obtener traceId desde headers si no vino en el body
        if ($traceId === null) {
            $headers = $response->getHeaders(false);
            $hdrTrace = $headers['x-trace-id'][0] ?? ($headers['trace-id'][0] ?? null);
            if (is_scalar($hdrTrace)) {
                $traceId = (string)$hdrTrace;
            }
        }

        // Si el backend devuelve un objeto ticket, lo normalizamos (opcional)
        $ticket = null;
        if (isset($data['ticket']) && \is_array($data['ticket'])) {
            $ticket = $this->normalizeTicket($data['ticket']);
        }

        return [
            'success' => $success,
            'reason'  => $reason,
            'status'  => $status,
            'traceId' => $traceId,
            'ticket'  => $ticket,
            'raw'     => $data,
        ];
    }

     public function acceptInvitation(string $invitationId, string $idToken)
    {
        $invitationId = trim($invitationId);
        if ($invitationId === '') {
            throw new \RuntimeException('Debe indicar el invitationId a aceptar.');
        }

        $url = $this->buildUrl('/api/v1/user/connections/intents/' . rawurlencode($invitationId) . '/accept');

        $response = $this->http->request('POST', $url, [
            'headers' => [
                'Accept'        => 'application/json',
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $idToken,
            ],
            'timeout' => 12,
        ]);

        $data = $this->decodeJsonOrFail($response, 'No se pudo aceptar la invitación.');
        return $data;
    }

    public function rejectInvitation(string $invitationId, string $idToken)
    {
        $invitationId = trim($invitationId);
        if ($invitationId === '') {
            throw new \RuntimeException('Debe indicar el invitationId a rechazar.');
        }

        $url = $this->buildUrl('/api/v1/user/connections/intents/' . rawurlencode($invitationId) . '/reject');

        $response = $this->http->request('POST', $url, [
            'headers' => [
                'Accept'        => 'application/json',
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $idToken,
            ],
            'timeout' => 12,
        ]);

        $data = $this->decodeJsonOrFail($response, 'No se pudo rechazar la invitación.');
        return $data;
    }

    /** ---------- helpers internos ---------- */

    private function normalizeTicket(array $data): array
    {
        $id         = isset($data['id']) && is_scalar($data['id']) ? (string)$data['id'] : null;
        $status     = isset($data['status']) && is_scalar($data['status']) ? (string)$data['status'] : null;
        $attempts   = isset($data['attempts']) && is_numeric($data['attempts']) ? (int)$data['attempts'] : null;
        $createdAt  = isset($data['createdAt']) && is_scalar($data['createdAt']) ? (string)$data['createdAt'] : null;
        $updatedAt  = isset($data['updatedAt']) && is_scalar($data['updatedAt']) ? (string)$data['updatedAt'] : null;
        $expiresAt  = isset($data['expiresAt']) && is_scalar($data['expiresAt']) ? (string)$data['expiresAt'] : null;
        $meta       = isset($data['meta']) && is_array($data['meta']) ? $data['meta'] : [];

        if ($id === null || $status === null) {
            throw new \RuntimeException('Respuesta de ticket inválida: '.json_encode($data));
        }

        return [
            'id'        => $id,
            'status'    => $status,
            'attempts'  => $attempts,
            'createdAt' => $createdAt,
            'updatedAt' => $updatedAt,
            'expiresAt' => $expiresAt,
            'meta'      => $meta,
            'raw'       => $data,
        ];
    }

    private function normalizeConnectionIntent(array $data): array
    {
        $id = isset($data['id']) && is_scalar($data['id']) ? (string) $data['id'] : null;
        $companyId = isset($data['company_id']) && is_scalar($data['company_id']) ? (string) $data['company_id'] : null;
        $status = isset($data['status']) && is_scalar($data['status']) ? strtoupper((string) $data['status']) : null;
        $intentType = isset($data['intent_type']) && is_scalar($data['intent_type']) ? (string) $data['intent_type'] : null;
        $externalUserRef = isset($data['external_user_ref']) && is_scalar($data['external_user_ref'])
            ? (string) $data['external_user_ref']
            : null;
        $scopes = isset($data['scopes']) && is_array($data['scopes']) ? array_values(array_filter($data['scopes'], 'is_string')) : [];
        $createdAt = isset($data['created_at']) && is_scalar($data['created_at']) ? (string) $data['created_at'] : null;
        $updatedAt = isset($data['last_updated_at']) && is_scalar($data['last_updated_at']) ? (string) $data['last_updated_at'] : null;

        if ($id === null || $companyId === null || $status === null) {
            throw new \RuntimeException('Respuesta inválida de conexión: ' . json_encode($data));
        }

        return [
            'id' => $id,
            'companyId' => $companyId,
            'status' => $status,
            'intentType' => $intentType,
            'externalUserRef' => $externalUserRef,
            'scopes' => $scopes,
            'createdAt' => $createdAt,
            'lastUpdatedAt' => $updatedAt,
            'raw' => $data,
        ];
    }

    private function decodeJsonOrFail(\Symfony\Contracts\HttpClient\ResponseInterface $response, string $fallbackMsg): array
    {
        $status = $response->getStatusCode();
        $raw = $response->getContent(false);
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
