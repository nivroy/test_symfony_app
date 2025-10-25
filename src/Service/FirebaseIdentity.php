<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class FirebaseIdentity
{
    public function __construct(
        #[Autowire(env: 'FIREBASE_WEB_API_KEY')] private readonly string $apiKey,
    ) {}

    /**
     * Sign in an existing user using email/password.
     * @return array{ idToken: string, localId: string, email: string }
     */
    public function signIn(string $email, string $password): array
    {
        $url = 'https://identitytoolkit.googleapis.com/v1/accounts:signInWithPassword?key=' . urlencode($this->apiKey);
        return $this->request($url, [
            'email' => $email,
            'password' => $password,
            'returnSecureToken' => true,
        ]);
    }

    /**
     * Register a new user using email/password.
     * @return array{ idToken: string, localId: string, email: string }
     */
    public function signUp(string $email, string $password): array
    {
        $url = 'https://identitytoolkit.googleapis.com/v1/accounts:signUp?key=' . urlencode($this->apiKey);
        return $this->request($url, [
            'email' => $email,
            'password' => $password,
            'returnSecureToken' => true,
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
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\n",
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
