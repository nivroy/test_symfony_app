<?php

namespace App\Service;

final class GoogleOAuth
{
    public function __construct(private readonly FirebaseAdminCredentials $creds) {}

    public function isConfigured(): bool
    {
        return $this->creds->isConfigured();
    }

    public function getAccessToken(string $scope = 'https://www.googleapis.com/auth/datastore'): string
    {
        if (!$this->creds->isConfigured()) {
            throw new \RuntimeException('FIREBASE_ADMIN_CREDENTIALS_JSON_BASE64 no está configurado.');
        }

        $now = time();
        $payload = [
            'iss' => $this->creds->clientEmail(),
            'scope' => $scope,
            'aud' => 'https://oauth2.googleapis.com/token',
            'exp' => $now + 3600,
            'iat' => $now,
        ];

        $jwt = $this->signJwt($payload);

        $data = http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $jwt,
        ], '', '&');

        $opts = [
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
                'content' => $data,
                'ignore_errors' => true,
                'timeout' => 10,
            ],
        ];
        $ctx = stream_context_create($opts);
        $res = @file_get_contents('https://oauth2.googleapis.com/token', false, $ctx);
        if ($res === false) {
            throw new \RuntimeException('No se pudo obtener el token de acceso de Google.');
        }
        /** @var array{access_token?:string,error?:string}|array<string,mixed> $json */
        $json = json_decode($res, true);
        if (!is_array($json) || !isset($json['access_token'])) {
            $err = is_array($json) && isset($json['error']) ? (string)$json['error'] : 'respuesta inválida';
            throw new \RuntimeException('Error OAuth: ' . $err);
        }
        return (string) $json['access_token'];
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function signJwt(array $payload): string
    {
        $header = ['alg' => 'RS256', 'typ' => 'JWT'];

        $segments = [
            $this->b64url(json_encode($header, JSON_UNESCAPED_SLASHES)),
            $this->b64url(json_encode($payload, JSON_UNESCAPED_SLASHES)),
        ];

        $signingInput = implode('.', $segments);
        $privateKey = $this->creds->privateKey();
        if (!$privateKey) {
            throw new \RuntimeException('Clave privada no disponible.');
        }
        $signature = '';
        $ok = \openssl_sign($signingInput, $signature, $privateKey, OPENSSL_ALGO_SHA256);
        if (!$ok) {
            throw new \RuntimeException('No se pudo firmar el JWT con la clave privada.');
        }
        $segments[] = $this->b64url($signature);
        return implode('.', $segments);
    }

    private function b64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}

