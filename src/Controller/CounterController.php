<?php
namespace App\Controller;

use App\Service\Counter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Attribute\Route;

final class CounterController extends AbstractController
{
    public function __construct(private RequestStack $requestStack)
    {
    }

    #[Route('/contador', name: 'app_counter_index', methods: ['GET'])]
    public function index(Counter $counter): Response
    {
        if ($response = $this->guard()) {
            return $response;
        }

        return $this->render('views/counter.html.twig', [
            'count' => $counter->get(),
        ]);
    }

    #[Route('/contador/incrementar', name: 'app_counter_increment', methods: ['POST'])]
    public function increment(Request $request, Counter $counter): Response
    {
        if ($response = $this->guard()) {
            return $response;
        }

        $counter->increment();
        return $this->redirectToRoute('app_counter_index');
    }

    #[Route('/contador/reducir', name: 'app_counter_decrement', methods: ['POST'])]
    public function decrement(Request $request, Counter $counter): Response
    {
        if ($response = $this->guard()) {
            return $response;
        }

        $counter->decrement();
        return $this->redirectToRoute('app_counter_index');
    }

    #[Route('/contador/reset', name: 'app_counter_reset', methods: ['POST'])]
    public function reset(Request $request, Counter $counter): Response
    {
        if ($response = $this->guard()) {
            return $response;
        }

        $counter->reset();
        return $this->redirectToRoute('app_counter_index');
    }

    private function guard(): ?Response
    {
        $session = $this->session();
        if (!$session || !$session->has('app.auth.token')) {
            $this->addFlash('error', 'Debes autenticarte para acceder al contador.');
            return $this->redirectToRoute('app_home');
        }

        return null;
    }

    private function session(): ?SessionInterface
    {
        return $this->requestStack->getSession();
    }
}
