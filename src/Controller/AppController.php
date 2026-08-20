<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AppController extends AbstractController
{
    #[Route('/', name: 'app_home', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('app/index.html.twig');
    }

    #[Route('/chat', name: 'app_chat', methods: ['GET'])]
    public function chat(): Response
    {
        return $this->render('app/chat.html.twig');
    }
}
