<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class AuthController extends AbstractController
{
    #[Route('/login', name: 'app_login', methods: ['GET'])]
    public function loginPage(): Response
    {
        return $this->render('auth/login.html.twig');
    }

    #[Route('/register', name: 'app_register', methods: ['GET'])]
    public function registerPage(): Response
    {
        return $this->render('auth/register.html.twig');
    }

    #[Route('/api/login_check', name: 'api_login_check', methods: ['POST'])]
    public function login(): JsonResponse
    {
        // Cette méthode ne sera jamais appelée car le firewall gère l'authentification
        return $this->json(['message' => 'Cette route ne devrait jamais être appelée directement']);
    }
} 