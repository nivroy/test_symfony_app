<?php

namespace App\Service;

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class FirebaseIdentity
{
    private const SESSION_KEY = 'firebase_user';

    public function __construct(
        #[Autowire(env: 'FIREBASE_WEB_API_KEY')] private readonly string $apiKey,
        private readonly RequestStack $requestStack,
    ) {}

    /**
     * Sign in an existing user using email/password.
     * @return array{ idToken: string, localId: string, email: string }
     */
    public function signIn(string $email, string $password): array
    {
        $url = 'https://identitytoolkit.googleapis.com/v1/accounts:signInWithPassword?key=' . urlencode($this->apiKey);
        $data = $this->request($url, [
            'email' => $email,
            'password' => $password,
            'returnSecureToken' => true,
        ]);

        // Guardar en sesión
        $this->storeSession($data);

        return $data;
    }

    /**
     * Register a new user using email/password.
     * @return array{ idToken: string, localId: string, email: string }
     */
    public function signUp(string $email, string $password): array
    {
        $url = 'https://identitytoolkit.googleapis.com/v1/accounts:signUp?key=' . urlencode($this->apiKey);
        $data = $this->request($url, [
            'email' => $email,
            'password' => $password,
            'returnSecureToken' => true,
        ]);

        // Guardar en sesión
        $this->storeSession($data);

        return $data;
    }

    /**
     * Devuelve true si hay usuario autenticado en sesión.
     */
    public function isAuthenticated(): bool
    {
        $session = $this->requestStack->getSession();
        return $session->has(self::SESSION_KEY);
    }

    /**
     * Devuelve los datos del usuario actual (idToken, localId, email)
     * o null si no hay sesión.
     *
     * @return array{idToken:string, localId:string, email:string}|null
     */
    public function getCurrentUser(): ?array
    {
        $session = $this->requestStack->getSession();
        /** @var array<string,mixed>|null $data */
        $data = $session->get(self::SESSION_KEY);
        return $data ?: null;
    }

    /**
     * Cierra la sesión del usuario autenticado.
     */
    public function logout(): void
    {
        $session = $this->requestStack->getSession();
        $session->remove(self::SESSION_KEY);
    }

    /**
     * Guarda los datos de usuario en sesión.
     *
     * @param array{idToken:string, localId:string, email:string} $data
     */
    private function storeSession(array $data): void
    {
        $session = $this->requestStack->getSession();
        $session->set(self::SESSION_KEY, [
            'idToken'  => $data['idToken'] ?? '',
            'localId'  => $data['localId'] ?? '',
            'email'    => $data['email'] ?? '',
        ]);
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function request(string $url, array $payload): array
    {
        if ($this->apiKey === '' || $this->apiKey === null) {
            throw new \RuntimeException('FIREBASE_WEB_API_KEY is not configured. Add it to your .env or environment.');
        }

        $opts = [
            'http' => [
                'method'  => 'POST',
                'header'  => "Content-Type: application/json\r\n",
                'content' => json_encode($payload, JSON_THROW_ON_ERROR),
                'ignore_errors' => true,
                'timeout' => 10,
            ],
        ];

        $ctx = stream_context_create($opts);
        $result = @file_get_contents($url, false, $ctx);
        if ($result === false) {
            throw new \RuntimeException('Could not reach Firebase. Check network and FIREBASE_WEB_API_KEY.');
        }

        /** @var array<string,mixed> $data */
        $data = json_decode($result, true, 512, JSON_THROW_ON_ERROR);

        if (isset($data['error']['message'])) {
            $message = (string) $data['error']['message'];
            throw new \RuntimeException($this->translateError($message));
        }

        return $data;
    }

    private function translateError(string $firebaseMessage): string
    {
        return match ($firebaseMessage) {
            'EMAIL_EXISTS' => 'Email already registered.',
            'INVALID_PASSWORD' => 'Invalid password.',
            'EMAIL_NOT_FOUND' => 'Email not found.',
            'USER_DISABLED' => 'Account is disabled.',
            default => 'Auth error: ' . $firebaseMessage,
        };
    }
}
