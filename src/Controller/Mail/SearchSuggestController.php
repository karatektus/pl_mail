<?php

declare(strict_types=1);

namespace App\Controller\Mail;

use App\Domain\DTO\ParsedSearchQuery;
use App\Entity\User\User;
use App\Service\Search\SearchQueryParser;
use App\Service\Search\TypeAheadSearch;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The ten conversations under the search box, refreshed as you type.
 *
 * Its own controller rather than a second action on SearchController, because
 * the two answer to different rules and would otherwise keep having to be told
 * apart inside one method: this one may only run passes cheap enough for a
 * keystroke, must never navigate, and is allowed to answer with less than the
 * truth. See TypeAheadSearch for what that costs and what it buys.
 *
 * It answers with HTML, not JSON. The rows carry a subject, a sender, a date
 * and an empty-subject fallback, all of which are translated and escaped — and
 * a JSON endpoint would mean building those in JavaScript, where this
 * application keeps neither its translations nor its markup.
 */
#[Route('/mail/search/suggest', name: 'app_mail_search_suggest')]
#[IsGranted('IS_AUTHENTICATED')]
final class SearchSuggestController extends AbstractController
{
    /**
     * Two characters match a startling amount of a large mailbox, and ten rows
     * drawn from "everything" are ten rows of noise under a box somebody is
     * still typing into. It is also the floor the trigram index can serve at
     * all — see FreeTextCompiler::MIN_SUBSTRING_LENGTH, which draws the same
     * line for the same reason.
     */
    private const int MIN_LENGTH = 3;

    /**
     * Ten. Enough that the answer is usually among them, few enough that the
     * list does not cover the mail behind it, and few enough that arrowing to
     * the bottom of it is quicker than finishing the word.
     */
    private const int LIMIT = 10;

    public function __construct(
        private readonly SearchQueryParser $parser,
        private readonly TypeAheadSearch   $typeAhead,
    ) {}

    #[Route('', name: '', methods: ['GET'])]
    public function suggest(Request $request): Response
    {
        $raw = trim($request->query->getString('q'));

        if (mb_strlen($raw) < self::MIN_LENGTH) {
            return $this->empty();
        }

        $parsed = $this->parser->parse($raw);

        // Operators are for the full search, not for the preview.
        //
        // A preview that quietly ignored `is:unread` would answer a question
        // nobody asked and look exactly like a preview that had honoured it —
        // the rows are indistinguishable — so the failure would be silent and
        // the reader would learn to distrust the list. Honouring them here
        // instead would mean rebuilding the whole filtered search under a
        // keystroke's budget, which is the thing this endpoint exists not to
        // do. So: as soon as the query says something this cannot promise, the
        // list steps aside and leaves the box to Enter.
        if (true === $this->carriesOperators($parsed)) {
            return $this->empty();
        }

        if ('' === $parsed->freeText || mb_strlen($parsed->freeText) < self::MIN_LENGTH) {
            return $this->empty();
        }

        $user = $this->getUser();

        if (false === $user instanceof User) {
            return $this->empty();
        }

        return $this->render('_partials/_search_suggestions.html.twig', [
            'hits' => $this->typeAhead->suggest($user, $parsed->freeText, self::LIMIT),
        ]);
    }

    /**
     * An empty document rather than a 204 or a JSON `[]`: the caller replaces
     * the list's innerHTML with whatever comes back, so "nothing to show" and
     * "here are the rows" are usefully the same code path on both sides.
     */
    private function empty(): Response
    {
        return new Response('', Response::HTTP_OK, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    /**
     * Compared field by field against a freshly parsed nothing, rather than
     * listing the operators here. The list of operators grows; a copy of it in
     * this file would go stale silently, and the failure mode of a stale copy
     * is precisely the silent wrong answer the caller guards against above.
     */
    private function carriesOperators(ParsedSearchQuery $parsed): bool
    {
        $none = new ParsedSearchQuery();

        foreach (get_object_vars($parsed) as $field => $value) {
            if ('freeText' === $field) {
                continue;
            }

            if ($value != $none->$field) {
                return true;
            }
        }

        return false;
    }
}
