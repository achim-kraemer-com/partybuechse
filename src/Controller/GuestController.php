<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\PartyRepository;
use App\Service\GuestResolver;
use App\Service\PartyStateBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The page guests land on after scanning the QR code.
 */
class GuestController extends AbstractController
{
    #[Route('/p/{code}', name: 'guest_party', methods: ['GET'], requirements: ['code' => '[A-Za-z0-9]{3,10}'])]
    public function party(string $code, Request $request, PartyRepository $parties, GuestResolver $guests, PartyStateBuilder $stateBuilder): Response
    {
        $party = $parties->findOneByCode($code);
        if (null === $party) {
            return $this->render('guest/not_found.html.twig', ['code' => strtoupper($code)], new Response(status: Response::HTTP_NOT_FOUND));
        }

        [$guest, $cookie] = $guests->resolve($request, $party);

        $response = $this->render('guest/party.html.twig', [
            'party' => $party,
            'guest' => $guest,
            'state' => $stateBuilder->build($party, $guest),
        ]);

        if (null !== $cookie) {
            $response->headers->setCookie($cookie);
        }

        return $response;
    }
}
