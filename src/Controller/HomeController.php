<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\PartyRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class HomeController extends AbstractController
{
    #[Route('/', name: 'home', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('home/index.html.twig');
    }

    #[Route('/join', name: 'join', methods: ['POST'])]
    public function join(Request $request, PartyRepository $parties): Response
    {
        $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $request->request->get('code', '')) ?? '');
        $party = '' === $code ? null : $parties->findOneByCode($code);

        if (null === $party) {
            $this->addFlash('error', 'Diesen Party-Code gibt es nicht. Schau noch mal auf den QR-Code oder frag den Host.');

            return $this->redirectToRoute('home');
        }

        return $this->redirectToRoute('guest_party', ['code' => $party->getCode()]);
    }
}
