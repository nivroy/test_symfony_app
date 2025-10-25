<?php

namespace App\Controller;

use App\Service\FirebaseIdentity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AuthController extends AbstractController
{
    public function __construct(private readonly FirebaseIdentity $firebase) {}

    #[Route('/auth/login', name: 'app_auth_login', methods: ['POST'])]
    public function login(Request $request): Response
    {
        $email = trim((string) $request->request->get('email'));
        $password = (string) $request->request->get('password');

        if ($email === '' || $password === '') {
            $this->addFlash('error', 'Debes ingresar correo y contraseña.');
            return $this->redirectToRoute('app_home');
        }

        try {
            $data = $this->firebase->signIn($email, $password);
        } catch (\Throwable $e) {
            $this->addFlash('error', $e->getMessage());
            return $this->redirectToRoute('app_home');
        }

        $session = $request->getSession();
        $session?->set('app.auth.id_token', $data['idToken'] ?? '');
        $session?->set('app.auth.uid', $data['localId'] ?? '');
        $session?->set('app.auth.email', $data['email'] ?? $email);
        // keep compatibility with existing guard
        $session?->set('app.auth.token', $data['idToken'] ?? '');

        // Print token to server console
        if (!empty($data['idToken'])) {
            @error_log('bearer ' . $data['idToken']);
        }

        return $this->redirectToRoute('app_auth_wait');
    }

    #[Route('/auth/register', name: 'app_auth_register', methods: ['POST'])]
    public function register(Request $request, \App\Service\Firestore $firestore): Response
    {
        $name = trim((string) $request->request->get('name'));
        $email = trim((string) $request->request->get('email'));
        $password = (string) $request->request->get('password');
        $passwordConfirm = (string) $request->request->get('password_confirm');

        if ($name === '' || $email === '' || $password === '') {
            $this->addFlash('error', 'Debes ingresar nombre, correo y contraseña.');
            return $this->redirectToRoute('app_home');
        }
        if ($password !== $passwordConfirm) {
            $this->addFlash('error', 'Las contraseñas no coinciden.');
            return $this->redirectToRoute('app_home');
        }

        try {
            $data = $this->firebase->signUp($email, $password);
        } catch (\Throwable $e) {
            $this->addFlash('error', $e->getMessage());
            return $this->redirectToRoute('app_home');
        }

        $session = $request->getSession();
        $session?->set('app.auth.id_token', $data['idToken'] ?? '');
        $session?->set('app.auth.uid', $data['localId'] ?? '');
        $session?->set('app.auth.email', $data['email'] ?? $email);
        $session?->set('app.auth.name', $name);
        $session?->set('app.auth.token', $data['idToken'] ?? '');

        // Print token to server console
        if (!empty($data['idToken'])) {
            @error_log('bearer ' . $data['idToken']);
        }

        // Create Firestore document users/[uid]
        $uid = (string) ($data['localId'] ?? '');
        if ($uid !== '' && $firestore->isConfigured()) {
            try {
                $firestore->createUserDocument($uid, [
                    'name' => $name,
                    'email' => $email,
                    'createdAt' => new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
                ]);
            } catch (\Throwable $e) {
                // Keep session, but inform non-blocking
                $this->addFlash('warning', 'Registrado, pero no se pudo crear el perfil en Firestore: ' . $e->getMessage());
            }
        } elseif ($uid !== '') {
            $this->addFlash('warning', 'Registrado, pero falta configurar FIREBASE_ADMIN_CREDENTIALS_JSON_BASE64 para crear el perfil.');
        }

        return $this->redirectToRoute('app_auth_wait');
    }
}
