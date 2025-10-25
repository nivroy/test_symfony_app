<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class ServiceAPI
{
    public function __construct(
        #[Autowire(env: 'API_BASE_URL')] private readonly string $baseUrl,
        #[Autowire(env: 'API_COMPANY_ID')] private readonly ?string $defaultCompanyId = null,
    ) {}

    /**
     * Fetch 6-digit token from local API using a Firebase ID token for auth.
     */
    public function fetchDigitalToken(string $idToken, ?string $companyId = null): string
    {
        $company = $companyId ?: ($this->defaultCompanyId ?? '');
        if ($company === '') {
            throw new \RuntimeException('Missing companyId for digital token request. Configure API_COMPANY_ID.');
        }
        if ($idToken === '') {
            throw new \RuntimeException('Missing ID token for authorization.');
        }

        $url = rtrim($this->baseUrl ?: 'http://localhost:8000', '/') . '/api/v1/tokens/digital?companyId=' . rawurlencode($company);

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
            throw new \RuntimeException('Respuesta del servidor no es JSON v?lido.');
        }

        $token = $this->extractSixDigitToken($data);
        if ($token === null) {
            throw new \RuntimeException('No se encontr? un token de 6 d?gitos en la respuesta.');
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

    /**
     * Request backend to generate and persist an API key for the authenticated company.
     * Requires Authorization Bearer of the logged-in user. Returns the generated apiKey.
     */
    public function generateApiKey(string $idToken): string
    {
        $url = rtrim($this->baseUrl ?: 'http://localhost:8000', '/') . '/api/v1/apikeys/generate';

        $opts = [
            'http' => [
                'method' => 'POST',
                'header' => "Accept: application/json\r\nContent-Type: application/json\r\nAuthorization: Bearer $idToken\r\n",
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
            throw new \RuntimeException('Respuesta inv?lida al generar API Key.');
        }
        $key = $this->extractApiKey($data);
        if ($key === null || $key === '') {
            throw new \RuntimeException('No se encontr? API Key en la respuesta.');
        }
        return $key;
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




<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class ServiceAPI
{
    public function __construct(
        #[Autowire(env: 'API_BASE_URL')] private readonly string $baseUrl,
        #[Autowire(env: 'API_COMPANY_ID')] private readonly ?string $defaultCompanyId = null,
    ) {}

    /**
     * Obtiene el token de 6 dígitos desde el backend usando Bearer del usuario.
     */
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

    /**
     * Solicita al backend generar y persistir la API Key para la empresa autenticada.
     * Requiere Authorization Bearer del usuario; retorna el valor de apiKey generado.
     */
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

