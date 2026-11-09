<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\PartyRepository;
use App\Service\PartyStateBuilder;
use App\Service\PublicUrlResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The big screen: now playing, ranking and the QR code. Protected by the party's display key,
 * so it can run on a TV or tablet without a host login.
 */
class DisplayController extends AbstractController
{
    #[Route('/display/{code}/{key}', name: 'display', methods: ['GET'], requirements: ['code' => '[A-Za-z0-9]{3,10}', 'key' => '[a-f0-9]{16,64}'])]
    public function display(string $code, string $key, PartyRepository $parties, PartyStateBuilder $stateBuilder, PublicUrlResolver $urls): Response
    {
        $party = $parties->findOneByCode($code);
        if (null === $party || !hash_equals($party->getDisplayKey(), $key)) {
            throw $this->createNotFoundException();
        }

        return $this->render('host/display.html.twig', [
            'party' => $party,
            'state' => $stateBuilder->build($party),
            'join_url' => $urls->joinUrl($party),
        ]);
    }
}
