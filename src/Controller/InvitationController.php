<?php

namespace App\Controller;

use App\Service\FirebaseIdentity;
use App\Service\ServiceAPI;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class InvitationController extends AbstractController
{
    public function __construct(
        private readonly FirebaseIdentity $firebase,
        private readonly ServiceAPI $api,
    ) {}

    private function isLoggedIn(Request $request): bool
    {
        $session = $request->getSession();
        return $session?->has('app.auth.uid') && $session->get('app.auth.uid') !== '';
    }

    private function redirectToLogin(Request $request): Response
    {
        $request->getSession()->set('return_to', $request->getRequestUri());
        return $this->redirectToRoute('app_home'); // tu ruta de login
    }

    #[Route('/invitation/{invitationId}', name: 'app_invitation', methods: ['GET'])]
    public function invitation(string $invitationId, Request $request): Response
    {
        if (!$this->isLoggedIn($request)) {
            return $this->redirectToLogin($request);
        }

        $session = $request->getSession();
        $uid = $session?->get('app.auth.uid', '');

        return $this->render('views/invitation.html.twig', [
            'invitationId' => $invitationId,
            'uid' => $uid,
        ]);
    }

    #[Route('/invitation/{invitationId}/accept', name: 'app_invitation_accept', methods: ['POST'])]
    public function accept(string $invitationId, Request $request): Response
    {
        $session = $request->getSession();
        if (!$this->isLoggedIn($request)) {
            return $this->redirectToLogin($request);
        }
        $idToken = (string) ($session->get('app.auth.id_token') ?? '');
        $this->api->acceptInvitation($invitationId, $idToken);

        $this->addFlash('success', 'Invitación aceptada (pendiente de implementación).');
        return $this->redirectToRoute('app_invitation', ['invitationId' => $invitationId]);
    }

    #[Route('/invitation/{invitationId}/reject', name: 'app_invitation_reject', methods: ['POST'])]
    public function reject(string $invitationId, Request $request): Response
    {
        $session = $request->getSession();
        if (!$this->isLoggedIn($request)) {
            return $this->redirectToLogin($request);
        }
        
        $idToken = (string) ($session->get('app.auth.id_token') ?? '');
        
        $this->api->rejectInvitation($invitationId, $idToken);

        $this->addFlash('success', 'Invitación rechazada (pendiente de implementación).');
        return $this->redirectToRoute('app_invitation', ['invitationId' => $invitationId]);
    }
}
