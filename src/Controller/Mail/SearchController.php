<?php

declare(strict_types=1);

namespace App\Controller\Mail;

use App\Service\Ai\EmbeddingCatchUp;
use App\Service\Ai\SemanticCoverage;
use App\Service\Ai\SemanticQuery;
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
        private readonly SemanticQuery           $semantic,
        private readonly SemanticCoverage        $coverage,
        private readonly EmbeddingCatchUp        $catchUp,
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
                'semantic' => null,
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
                'semantic' => null,
            ]);
        }

        $user = $this->getUser();

        // One call for the rows AND the total: the pager's "1–50 of N" used to
        // be a second query re-running the whole search, and on a large mailbox
        // it was a third of the wait for a number nobody navigates by. See
        // MessageThreadRepository::searchRows().
        // Embedded HERE, once, and handed down. buildSearchSql() runs up to
        // four times for one search — the cheap pass, the body rescue, and
        // twice more when a page past the end recovers its total — and one of
        // those is inside a statement-timeout transaction. A round trip to
        // another machine in any of them would be a search that sometimes takes
        // four times as long for no reason a user could see, and inside the
        // timeout a slow model would be reported as a database fault.
        //
        // A REASON RATHER THAN A NULL. Every way this can come back without a
        // vector — off, unconfigured, host down, model gone, a query too short
        // to mean anything — used to be the same null and the same silence, and
        // four of those five happen on an installation where somebody has
        // switched the feature ON. The search still runs exactly as it always
        // has; what changes is that the page can now say why it is the search
        // it always was. See SemanticQuery.
        $semantic = $this->semantic->forQuery($parsed->freeText);

        $results = $this->threadRepository->searchPage($user, $parsed, $page, sort: $sort, semantic: $semantic);
        $threads = $results->threads;

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
        //
        // The report counts what THIS PAGE owes to the vector, which is all the
        // rows in hand can answer for: attributing the whole result set would
        // need a second pass over every matching row, and one statement per
        // search is the design this route is built on.
        //
        // The coverage is per MAILBOX, so it needs the id and not the token —
        // and IS_AUTHENTICATED guarantees a user without saying which class it
        // is. A search is not worth failing over the difference, so an
        // unrecognised principal reports an empty mailbox, which is a report
        // with nothing to say rather than an error page. Same shape as
        // resolveSort() below.
        $userId = $user instanceof User ? (int) $user->id : 0;

        $report = $this->coverage->report($userId, $semantic, count($results->semanticOnly));

        // THE SEARCH JUST PAID FOR A WARM MODEL; SPEND THE REST OF IT.
        //
        // New mail is not embedded as it arrives any more — the nightly
        // app:ai:index-new-mail is the backstop, and this is the trigger that
        // makes the backstop rarely matter. The embedding model is the SMALL
        // one, well under a gigabyte, and the query above has just loaded it;
        // indexing a handful of messages now costs a few seconds of a model
        // that is already resident, where the same work at three in the morning
        // pays the cold load again.
        //
        // It only ever DISPATCHES — a few ids onto the ingest transport, whose
        // worker is a different process — so nothing here waits on the model,
        // and it is throttled to one batch per mailbox per five minutes so that
        // paging through results does not queue one per page. Both guards live
        // in EmbeddingCatchUp, which is also what the nightly sweep calls.
        //
        // After the coverage report rather than before it: the report is what
        // the person actually sees, and it should describe the mailbox as it
        // was searched rather than as it is about to be.
        $this->catchUp->afterSearch($userId, $semantic);

        return $this->listRenderer->render($request, 'search/search.html.twig', $threads, [
            'q'             => $raw,
            'total'         => $results->total,
            'page'          => $page,
            'per_page'      => 50,
            'parsed'        => $parsed,
            'sort'          => $sort,
            'semantic'      => $report,
            'semantic_only' => $results->semanticOnly,
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
