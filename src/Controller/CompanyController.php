<?php

namespace App\Controller;

use App\Service\Firestore;
use App\Service\ServiceAPI;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class CompanyController extends AbstractController
{
    public function __construct(private readonly ServiceAPI $api) {}

    #[Route('/empresa', name: 'app_company_home', methods: ['GET'])]
    public function home(Request $request, Firestore $firestore): Response
    {
        $session = $request->getSession();

        // 🔒 Validaciones de sesión y tipo de usuario
        if (!$session || !$session->has('app.auth.token')) {
            $this->addFlash('error', 'Debes iniciar sesión para continuar.');
            return $this->redirectToRoute('app_home');
        }
        if (($session->get('app.auth.type') ?? null) !== 'company') {
            $this->addFlash('error', 'Esta sección es solo para empresas.');
            return $this->redirectToRoute('app_auth_wait');
        }

        $idToken = (string) ($session->get('app.auth.id_token') ?? '');
        $uid     = (string) ($session->get('app.auth.uid') ?? '');

        // 🏢 Datos de la empresa (opcional)
        $company = null;
        if ($uid !== '' && $firestore->isConfigured()) {
            try {
                $company = $firestore->getDocument('companies', $uid);
            } catch (\Throwable $e) {
                $this->addFlash('warning', 'No se pudo obtener datos de la empresa: ' . $e->getMessage());
            }
        }

        // 🔑 Obtener todas las API Keys de la empresa
        try {
            $apiKeys = $this->api->getApiKeys($idToken);
        } catch (\Throwable $e) {
            $this->addFlash('warning', 'No se pudieron obtener las API Keys: ' . $e->getMessage());
            $apiKeys = [];
        }

        // 📦 Renderizar vista
        return $this->render('views/company.html.twig', [
            'companyName'     => $company['companyName'] ?? ($session->get('app.auth.company_name') ?? null),
            'ruc'             => $company['ruc'] ?? ($session->get('app.auth.ruc') ?? null),
            'apiKeys'         => $apiKeys,
        ]);
    }

    #[Route('/api/company/apikey/create', name: 'app_api_company_apikey_create', methods: ['POST'])]
    public function createApiKey(Request $request): JsonResponse
    {
        $session = $request->getSession();

        // ✅ Validar token CSRF
        $csrf = (string) $request->request->get('_token', '');
        if (!$this->isCsrfTokenValid('company_apikey_create', $csrf)) {
            return $this->json(['error' => 'invalid_csrf_token'], 400, ['Cache-Control' => 'no-store']);
        }

        // ✅ Validar sesión y tipo de usuario
        if (!$session || ($session->get('app.auth.type') ?? null) !== 'company') {
            return $this->json(['error' => 'forbidden'], 403, ['Cache-Control' => 'no-store']);
        }

        $idToken = (string) ($session->get('app.auth.id_token') ?? '');
        $uid     = (string) ($session->get('app.auth.uid') ?? '');
        if ($idToken === '' || $uid === '') {
            return $this->json(['error' => 'unauthorized'], 401, ['Cache-Control' => 'no-store']);
        }

        // ✅ Llamar al servicio que realmente crea la API Key
        try {
            // Ejecutar creación de API Key
            $result = $this->api->createApiKey($idToken);

            // ✅ Guardar el plaintext en sesión para mostrarlo una sola vez
            if (!empty($result['apiKey'])) {
                $session->set('app.company.apikey.plaintext', $result['apiKey']);
            }

            // ✅ Devolver JSON de éxito
            return $this->json([
                'success' => true,
                'apiKey'  => $result['apiKey'] ?? null,
                'keyId'   => $result['keyId'] ?? null,
                'raw'     => $result['raw'] ?? null,
            ], 200, ['Cache-Control' => 'no-store']);
        } catch (\Throwable $e) {
            return $this->json([
                'error' => 'No se pudo crear la API Key: ' . $e->getMessage(),
            ], 502, ['Cache-Control' => 'no-store']);
        }
    }

    #[Route('/api/company/apikey/rotate', name: 'app_api_company_apikey_rotate', methods: ['POST'])]
    public function rotateApiKey(Request $request): JsonResponse
    {
        $session = $request->getSession();

        // ✅ CSRF (envía un _token desde el form/JS con este id)
        $csrf = (string) $request->request->get('_token', '');
        if (!$this->isCsrfTokenValid('company_apikey_rotate', $csrf)) {
            return $this->json(['error' => 'invalid_csrf_token'], 400, ['Cache-Control' => 'no-store']);
        }

        // ✅ Validar sesión/tipo
        if (!$session || ($session->get('app.auth.type') ?? null) !== 'company') {
            return $this->json(['error' => 'forbidden'], 403, ['Cache-Control' => 'no-store']);
        }

        $idToken = (string) ($session->get('app.auth.id_token') ?? '');
        $uid     = (string) ($session->get('app.auth.uid') ?? '');
        if ($idToken === '' || $uid === '') {
            return $this->json(['error' => 'unauthorized'], 401, ['Cache-Control' => 'no-store']);
        }

        // ✅ Leer oldKeyId del request
        $oldKeyId = (string) $request->request->get('oldKeyId', '');
        if ($oldKeyId === '') {
            return $this->json(['error' => 'missing_oldKeyId'], 400, ['Cache-Control' => 'no-store']);
        }

        try {
            // Llama a tu método simple (sin companyId) alineado al cURL
            $res = $this->api->rotateApiKey($idToken, $oldKeyId);

            // (opcional) guardarlo para mostrar una sola vez en la vista
            // $session->set('app.company.apikey.plaintext', $res['apiKey']);

            return $this->json([
                'success'       => true,
                'mode'          => 'rotated',
                'apiKey'        => $res['apiKey'],              // plaintext nueva
                'keyId'         => $res['keyId'],
                'secretVersion' => $res['secretVersion'] ?? null,
                'traceId'       => $res['traceId'] ?? null,
            ], 200, ['Cache-Control' => 'no-store']);
        } catch (\Throwable $e) {
            return $this->json([
                'error' => 'No se pudo rotar la API Key: '.$e->getMessage(),
            ], 502, ['Cache-Control' => 'no-store']);
        }
    }

    #[Route('/api/company/apikey/delete', name: 'app_api_company_apikey_delete', methods: ['POST', 'DELETE'])]
    public function deleteApiKey(Request $request): JsonResponse
    {
        $session = $request->getSession();

        // ✅ Obtener keyId del body (POST/x-www-form-urlencoded) o del JSON (DELETE/POST)
        $keyId = (string) ($request->request->get('keyId') ?? '');
        if ($keyId === '' && $request->getContent()) {
            try {
                $json = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
                if (is_array($json) && isset($json['keyId'])) {
                    $keyId = (string) $json['keyId'];
                }
            } catch (\Throwable) {
                // ignoramos parse error; validamos abajo
            }
        }

        // ✅ CSRF (usa un token distinto para delete)
        // - Para POST espera _token en el body (como tus otros endpoints)
        // - Para DELETE permite también cabecera 'X-CSRF-Token' o query param si lo necesitas
        $csrf = (string) ($request->request->get('_token') ?? $request->headers->get('X-CSRF-Token') ?? $request->query->get('_token') ?? '');
        if (!$this->isCsrfTokenValid('company_apikey_delete', $csrf)) {
            return $this->json(['error' => 'invalid_csrf_token'], 400, ['Cache-Control' => 'no-store']);
        }

        // ✅ Validar sesión/tipo
        if (!$session || ($session->get('app.auth.type') ?? null) !== 'company') {
            return $this->json(['error' => 'forbidden'], 403, ['Cache-Control' => 'no-store']);
        }

        $idToken = (string) ($session->get('app.auth.id_token') ?? '');
        $uid     = (string) ($session->get('app.auth.uid') ?? '');
        if ($idToken === '' || $uid === '') {
            return $this->json(['error' => 'unauthorized'], 401, ['Cache-Control' => 'no-store']);
        }

        if ($keyId === '') {
            return $this->json(['error' => 'missing_keyId'], 400, ['Cache-Control' => 'no-store']);
        }

        try {
            $res = $this->api->deleteApiKey($idToken, $keyId);

            return $this->json([
                'success' => (bool) ($res['success'] ?? true),
                'keyId'   => $res['keyId'] ?? $keyId,
                'message' => $res['message'] ?? null,
                'traceId' => $res['traceId'] ?? null,
            ], 200, ['Cache-Control' => 'no-store']);
        } catch (\Throwable $e) {
            return $this->json([
                'error' => 'No se pudo eliminar la API Key: ' . $e->getMessage(),
            ], 502, ['Cache-Control' => 'no-store']);
        }
    }

}
