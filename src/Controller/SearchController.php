<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\MessageThreadRepository;
use App\Service\Search\SearchQueryParser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/mail/search', name: 'app_mail_search')]
#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class SearchController extends AbstractController
{
    public function __construct(
        private readonly SearchQueryParser       $parser,
        private readonly MessageThreadRepository $threadRepository,
    ) {}

    #[Route('', name: '', methods: ['GET'])]
    public function search(Request $request): Response
    {
        $raw  = trim($request->query->getString('q'));
        $page = max(1, $request->query->getInt('page', 1));

        if ($raw === '') {
            return $this->render('search/search.html.twig', [
                'q'        => '',
                'threads'  => [],
                'total'    => 0,
                'page'     => 1,
                'per_page' => 50,
                'parsed'   => null,
            ]);
        }

        $parsed  = $this->parser->parse($raw);
        $user    = $this->getUser();
        $threads = $this->threadRepository->search($user, $parsed, $page);
        $total   = $this->threadRepository->countSearch($user, $parsed);

        return $this->render('search/search.html.twig', [
            'q'        => $raw,
            'threads'  => $threads,
            'total'    => $total,
            'page'     => $page,
            'per_page' => 50,
            'parsed'   => $parsed,
        ]);
    }
}
