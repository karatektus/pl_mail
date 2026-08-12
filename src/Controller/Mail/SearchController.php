<?php

declare(strict_types=1);

namespace App\Controller\Mail;

use App\Domain\Enum\Mail\SearchSortOrder;
use App\Entity\User\User;
use App\Repository\Mail\MessageThreadRepository;
use App\Service\Mail\ThreadListRenderer;
use App\Service\Search\SearchQueryParser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/mail/search', name: 'app_mail_search')]
#[IsGranted('IS_AUTHENTICATED')]
final class SearchController extends AbstractController
{
    public function __construct(
        private readonly SearchQueryParser       $parser,
        private readonly MessageThreadRepository $threadRepository,
        private readonly EntityManagerInterface  $em,
        private readonly ThreadListRenderer      $listRenderer,
    ) {}

    #[Route('', name: '', methods: ['GET'])]
    public function search(Request $request): Response
    {
        $raw  = trim($request->query->getString('q'));
        $page = max(1, $request->query->getInt('page', 1));
        $sort = $this->resolveSort($request);

        if ($raw === '') {
            return $this->render('search/search.html.twig', [
                'q'        => '',
                'threads'  => [],
                'total'    => 0,
                'page'     => 1,
                'per_page' => 50,
                'parsed'   => null,
                'sort'     => $sort,
            ]);
        }

        $parsed = $this->parser->parse($raw);

        // A query that is all half-typed operator — "from:" with nothing after
        // it — parses to no filters at all. Searching on that would answer
        // with the whole mailbox, which reads as the search having been
        // ignored; the empty result says "nothing to go on yet" instead.
        if (true === $parsed->isEmpty()) {
            return $this->render('search/search.html.twig', [
                'q'        => $raw,
                'threads'  => [],
                'total'    => 0,
                'page'     => 1,
                'per_page' => 50,
                'parsed'   => $parsed,
                'sort'     => $sort,
            ]);
        }

        $user    = $this->getUser();
        $threads = $this->threadRepository->search($user, $parsed, $page, sort: $sort);
        $total   = $this->threadRepository->countSearch($user, $parsed);

        // The list views have always preloaded and search never did, which is
        // why a full page of results measured 167 queries against the inbox's
        // 120 for the same fifty rows: search paid for the label chips one row
        // at a time on top of everything the inbox was already paying.
        $this->threadRepository->preloadForRows($threads);

        // Through ThreadListRenderer, not $this->render(): a row whose subject
        // and sender the user has just read in a result list has been SHOWN,
        // and leaving it badged would mean finding your own search results
        // announced as news the next time you opened the inbox. Same
        // collect-render-then-mark order, same prefetch guard, one
        // implementation — see the service.
        return $this->listRenderer->render($request, 'search/search.html.twig', $threads, [
            'q'        => $raw,
            'total'    => $total,
            'page'     => $page,
            'per_page' => 50,
            'parsed'   => $parsed,
            'sort'     => $sort,
        ]);
    }

    /**
     * The order to answer in, and — when the request asked for one — the order
     * to remember.
     *
     * A GET parameter rather than a POST to a preferences endpoint, because the
     * control has to re-run the search anyway: the menu's entries are links
     * into this route, so switching order is one navigation inside the Turbo
     * frame instead of a write followed by a reload. The write is a side effect
     * of asking, which is what makes the choice stick for the next search —
     * that one arrives with no `sort` at all, from the search box in the
     * topbar, and reads the setting back.
     */
    private function resolveSort(Request $request): SearchSortOrder
    {
        $user = $this->getUser();

        if (false === $user instanceof User) {
            return SearchSortOrder::fromSetting($request->query->get('sort'));
        }

        if (false === $request->query->has('sort')) {
            return $user->searchSortOrder;
        }

        // Anything unrecognised keeps the order the user is already in rather
        // than resetting it to the default — a mistyped URL is not a choice.
        $sort = SearchSortOrder::fromSetting(
            $request->query->get('sort'),
            $user->searchSortOrder,
        );

        if ($sort !== $user->searchSortOrder) {
            $user->searchSortOrder = $sort;
            $this->em->flush();
        }

        return $sort;
    }
}
