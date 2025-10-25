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
        if (!$session || !$session->has('app.auth.token')) {
            $this->addFlash('error', 'Debes iniciar sesión para continuar.');
            return $this->redirectToRoute('app_home');
        }
        if (($session->get('app.auth.type') ?? null) !== 'company') {
            $this->addFlash('error', 'Esta sección es solo para empresas.');
            return $this->redirectToRoute('app_auth_wait');
        }

        $uid = (string) ($session->get('app.auth.uid') ?? '');
        $company = null;
        $apiKey = null;
        $keyId = null;
        if ($uid !== '' && $firestore->isConfigured()) {
            try {
                $company = $firestore->getDocument('companies', $uid);
                $latest = $firestore->getCompanyLatestApiKey($uid);
                $apiKey = $latest['value'] ?? null;
                $keyId = $latest['keyId'] ?? null;
            } catch (\Throwable $e) {
                $this->addFlash('warning', 'No se pudo obtener datos de la empresa: ' . $e->getMessage());
            }
        }

        return $this->render('views/company.html.twig', [
            'companyName' => $company['companyName'] ?? ($session->get('app.auth.company_name') ?? null),
            'ruc' => $company['ruc'] ?? ($session->get('app.auth.ruc') ?? null),
            'apiKey' => $apiKey,
            'keyId' => $keyId,
        ]);
    }

    #[Route('/api/company/apikey/refresh', name: 'app_api_company_apikey_refresh', methods: ['POST'])]
    public function refreshApiKey(Request $request, Firestore $firestore): JsonResponse
    {
        $session = $request->getSession();
        if (!$session || ($session->get('app.auth.type') ?? null) !== 'company') {
            return new JsonResponse(['error' => 'forbidden'], 403);
        }
        $idToken = (string) ($session->get('app.auth.id_token') ?? '');
        if ($idToken === '') {
            return new JsonResponse(['error' => 'unauthorized'], 401);
        }
        $uid = (string) ($session->get('app.auth.uid') ?? '');
        if ($uid === '') {
            return new JsonResponse(['error' => 'unauthorized'], 401);
        }
        try {
            $latest = $firestore->getCompanyLatestApiKey($uid);
            $oldKeyId = (string) ($latest['keyId'] ?? '');
            if ($oldKeyId === '') {
                return new JsonResponse(['error' => 'No hay una clave previa para rotar.'], 400);
            }
            $res = $this->api->rotateApiKey($idToken, [
                'accountId' => $uid,
                'oldKeyId' => $oldKeyId,
            ]);
            return new JsonResponse(['apiKey' => $res['apiKey'], 'keyId' => $res['keyId']]);
        } catch (\Throwable $e) {
            return new JsonResponse(['error' => $e->getMessage()], 502);
        }
    }
}
