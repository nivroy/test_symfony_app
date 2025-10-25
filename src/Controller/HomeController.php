<?php

namespace App\Controller;

use App\Service\AppVariables;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Attribute\Route;

final class HomeController extends AbstractController
{
    public function __construct(private readonly \App\Service\ServiceAPI $api)
    {
    }
    #[Route('/', name: 'app_home', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $session = $request->getSession();

        return $this->render('views/home.html.twig', [
            'authUrl' => AppVariables::URL_AUTH,
            'name' => $session?->get('app.auth.name'),
            'hasToken' => $session?->has('app.auth.token'),
        ]);
    }

    // Legacy flow (kept for compatibility, not used by new UI)
    #[Route('/autenticacion/iniciar', name: 'app_auth_start', methods: ['POST'])]
    public function start(Request $request): Response
    {
        $name = trim((string) $request->request->get('name'));
        $code = trim((string) $request->request->get('code'));

        if ($name === '') {
            $this->addFlash('error', 'Debes ingresar tu nombre para continuar.');
            return $this->redirectToRoute('app_home');
        }

        if (!preg_match('/^\d{6}$/', $code)) {
            $this->addFlash('error', 'El codigo debe contener exactamente 6 digitos.');
            return $this->redirectToRoute('app_home');
        }

        $session = $this->getSession($request);
        if (!$session) {
            throw new \RuntimeException('No hay una sesion activa disponible. Habilitaste framework.session?');
        }
        $session->set('app.auth.name', $name);
        $session->set('app.auth.code', $code);
        $session->remove('app.auth.token');

        return $this->redirectToRoute('app_auth_wait');
    }

    #[Route('/autenticacion/espera', name: 'app_auth_wait', methods: ['GET'])]
    public function wait(Request $request): Response
    {
        $session = $this->getSession($request);
        if (!$session || !$session->has('app.auth.token')) {
            $this->addFlash('error', 'Debes iniciar sesion para continuar.');
            return $this->redirectToRoute('app_home');
        }
        if (($session->get('app.auth.type') ?? null) === 'company') {
            $this->addFlash('error', 'Esta sección es solo para personas.');
            return $this->redirectToRoute('app_company_home');
        }

        $idToken = (string) ($session->get('app.auth.id_token') ?? $session->get('app.auth.token') ?? '');
        $digitalToken = null;
        try {
            $digitalToken = $this->api->fetchDigitalToken($idToken);
        } catch (\Throwable $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->render('views/auth_wait.html.twig', [
            'authUrl' => AppVariables::URL_AUTH,
            'name' => $session->get('app.auth.email') ?? $session->get('app.auth.name') ?? 'Usuario',
            'code' => $session->get('app.auth.code') ?? null,
            'token' => $session->get('app.auth.token'),
            'digitalToken' => $digitalToken,
        ]);
    }

    #[Route('/autenticacion/confirmar', name: 'app_auth_confirm', methods: ['POST'])]
    public function confirm(Request $request): Response
    {
        $session = $this->getSession($request);
        if (!$session) {
            $this->addFlash('error', 'La sesion de autenticacion no esta disponible.');
            return $this->redirectToRoute('app_home');
        }

        if (!$session->has('app.auth.name') || !$session->has('app.auth.code')) {
            $this->addFlash('error', 'La sesion de autenticacion ha expirado.');
            return $this->redirectToRoute('app_home');
        }

        $token = trim((string) $request->request->get('token'));

        if ($token === '') {
            $this->addFlash('error', 'Debes ingresar el token recibido para continuar.');
            return $this->redirectToRoute('app_auth_wait');
        }

        $session->set('app.auth.token', $token);

        $this->addFlash('success', 'Autenticacion completada correctamente. Bienvenido!');

        return $this->redirectToRoute('app_auth_wait');
    }

    #[Route('/autenticacion/cancelar', name: 'app_auth_cancel', methods: ['POST'])]
    public function cancel(Request $request): Response
    {
        $session = $this->getSession($request);
        if ($session) {
            $session->remove('app.auth.name');
            $session->remove('app.auth.code');
            $session->remove('app.auth.token');
            $session->remove('app.auth.id_token');
            $session->remove('app.auth.uid');
            $session->remove('app.auth.email');
        }

        $this->addFlash('warning', 'La autenticacion fue cancelada.');

        return $this->redirectToRoute('app_home');
    }

    private function getSession(Request $request): ?SessionInterface
    {
        return $request->getSession();
    }

    #[Route('/api/token/digital', name: 'app_api_token_digital', methods: ['GET'])]
    public function apiDigitalToken(Request $request): JsonResponse
    {
        $session = $this->getSession($request);
        if (!$session || !$session->has('app.auth.token')) {
            return new JsonResponse(['error' => 'unauthorized'], 401);
        }

        $idToken = (string) ($session->get('app.auth.id_token') ?? $session->get('app.auth.token') ?? '');
        try {
            $digitalToken = $this->api->fetchDigitalToken($idToken);
            return new JsonResponse(['token' => $digitalToken]);
        } catch (\Throwable $e) {
            return new JsonResponse(['error' => $e->getMessage()], 502);
        }
    }
}
