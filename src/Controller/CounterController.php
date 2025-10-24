<?php
namespace App\Controller;

use App\Service\Counter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class CounterController extends AbstractController
{
    #[Route('/', name: 'app_counter_index', methods: ['GET'])]
    public function index(Counter $counter): Response
    {
        return $this->render('views/counter.html.twig', [
            'count' => $counter->get(),
        ]);
    }

    #[Route('/incrementar', name: 'app_counter_increment', methods: ['POST'])]
    public function increment(Request $request, Counter $counter): Response
    {
        $counter->increment();
        return $this->redirectToRoute('app_counter_index');
    }

    #[Route('/reducir', name: 'app_counter_decrement', methods: ['POST'])]
    public function decrement(Request $request, Counter $counter): Response
    {
        $counter->decrement();
        return $this->redirectToRoute('app_counter_index');
    }

    #[Route('/reset', name: 'app_counter_reset', methods: ['POST'])]
    public function reset(Request $request, Counter $counter): Response
    {
        $counter->reset();
        return $this->redirectToRoute('app_counter_index');
    }
}
