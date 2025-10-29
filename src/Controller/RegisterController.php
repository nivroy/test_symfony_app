<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class RegisterController extends AbstractController
{
    #[Route('/register', name: 'app_register_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('views/register/index.html.twig');
    }

    #[Route('/register/person', name: 'app_register_person', methods: ['GET'])]
    public function person(): Response
    {
        return $this->render('views/register/person.html.twig');
    }

    #[Route('/register/company', name: 'app_register_company', methods: ['GET'])]
    public function company(): Response
    {
        return $this->render('views/register/company.html.twig');
    }
}

