<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\PartyRepository;
use App\Service\PublicUrlResolver;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Color\Color;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\SvgWriter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class QrController extends AbstractController
{
    #[Route('/p/{code}/qr.svg', name: 'guest_qr', methods: ['GET'], requirements: ['code' => '[A-Za-z0-9]{3,10}'])]
    public function qr(string $code, PartyRepository $parties, PublicUrlResolver $urls): Response
    {
        $party = $parties->findOneByCode($code);
        if (null === $party) {
            throw $this->createNotFoundException();
        }

        $builder = new Builder(
            writer: new SvgWriter(),
            writerOptions: [SvgWriter::WRITER_OPTION_EXCLUDE_XML_DECLARATION => true],
            data: $urls->joinUrl($party),
            encoding: new Encoding('UTF-8'),
            errorCorrectionLevel: ErrorCorrectionLevel::Medium,
            size: 640,
            margin: 24,
            roundBlockSizeMode: RoundBlockSizeMode::Margin,
            foregroundColor: new Color(8, 11, 17),
            backgroundColor: new Color(255, 255, 255),
        );

        $result = $builder->build();

        $response = new Response($result->getString(), Response::HTTP_OK, [
            'Content-Type' => $result->getMimeType(),
            'Cache-Control' => 'private, max-age=300',
        ]);

        return $response;
    }
}
