<?php

namespace App\Controller;

use App\Service\FirebaseIdentity;
use App\Service\Firestore;
use App\Service\ServiceAPI;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AuthController extends AbstractController
{
    public function __construct(
        private readonly FirebaseIdentity $firebase,
        private readonly ServiceAPI $api,
    ) {}

    #[Route('/auth/login', name: 'app_auth_login', methods: ['POST'])]
    public function login(Request $request, Firestore $firestore): Response
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
        $session?->set('app.auth.token', $data['idToken'] ?? '');

        if (!empty($data['idToken'])) {
            @error_log('bearer ' . $data['idToken']);
        }

        $type = 'person';
        try {
            $uid = (string) ($session?->get('app.auth.uid') ?? '');
            if ($uid !== '') {
                $company = $firestore->getDocument('companies', $uid);
                if ($company !== null) {
                    $type = 'company';
                } else {
                    $person = $firestore->getDocument('persons', $uid);
                    if ($person !== null) { $type = 'person'; }
                }
            }
        } catch (\Throwable $e) {
            $this->addFlash('error', $e->getMessage());
        }
        $session?->set('app.auth.type', $type);

        return $this->redirectToRoute($type === 'company' ? 'app_company_home' : 'app_auth_wait');
    }

    #[Route('/auth/register', name: 'app_auth_register', methods: ['POST'])]
    public function register(Request $request, \App\Service\Firestore $firestore): Response
    {
        $type = strtolower((string) $request->request->get('type', 'person'));
        $name = trim((string) $request->request->get('name'));
        $email = trim((string) $request->request->get('email'));
        $password = (string) $request->request->get('password');
        $passwordConfirm = (string) $request->request->get('password_confirm');
        $companyName = trim((string) $request->request->get('company_name'));
        $ruc = trim((string) $request->request->get('ruc'));

        if ($email === '' || $password === '') {
            $this->addFlash('error', 'Debes ingresar correo y contraseña.');
            return $this->redirectToRoute('app_register_index');
        }
        if ($type === 'person' && $name === '') {
            $this->addFlash('error', 'Debes ingresar tu nombre.');
            return $this->redirectToRoute('app_register_person');
        }
        if ($type === 'company' && ($companyName === '' || $ruc === '')) {
            $this->addFlash('error', 'Debes ingresar nombre de empresa y RUC.');
            return $this->redirectToRoute('app_register_company');
        }
        if ($password !== $passwordConfirm) {
            $this->addFlash('error', 'Las contraseñas no coinciden.');
            return $this->redirectToRoute($type === 'company' ? 'app_register_company' : 'app_register_person');
        }

        try {
            $data = $this->firebase->signUp($email, $password);
        } catch (\Throwable $e) {
            $this->addFlash('error', $e->getMessage());
            return $this->redirectToRoute($type === 'company' ? 'app_register_company' : 'app_register_person');
        }

        $session = $request->getSession();
        $session?->set('app.auth.id_token', $data['idToken'] ?? '');
        $session?->set('app.auth.uid', $data['localId'] ?? '');
        $session?->set('app.auth.email', $data['email'] ?? $email);
        if ($type === 'person') {
            $session?->set('app.auth.name', $name);
        } else {
            $session?->set('app.auth.company_name', $companyName);
            $session?->set('app.auth.ruc', $ruc);
        }
        $session?->set('app.auth.token', $data['idToken'] ?? '');

        if (!empty($data['idToken'])) {
            @error_log('bearer ' . $data['idToken']);
        }

        $uid = (string) ($data['localId'] ?? '');
        if ($uid !== '' && $firestore->isConfigured()) {
            try {
                $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
                if ($type === 'company') {
                    $firestore->createCompanyDocument($uid, [
                        'email' => $email,
                        'companyName' => $companyName,
                        'ruc' => $ruc,
                        'createdAt' => $now,
                        'updatedAt' => $now,
                        'apiKey' => null,
                    ]);
                    $session?->set('app.auth.type', 'company');
                } else {
                    $firestore->createUserDocument($uid, [
                        'name' => $name,
                        'email' => $email,
                        'createdAt' => $now,
                        'updatedAt' => $now,
                    ]);
                    $session?->set('app.auth.type', 'person');
                }
            } catch (\Throwable $e) {
                $this->addFlash('warning', 'Registrado, pero no se pudo guardar en Firestore: ' . $e->getMessage());
            }
        } elseif ($uid !== '') {
            $this->addFlash('warning', 'Registrado, pero falta configurar FIREBASE_ADMIN_CREDENTIALS_JSON_BASE64 para guardar datos.');
        }

        return $this->redirectToRoute($type === 'company' ? 'app_company_home' : 'app_auth_wait');
    }
}
