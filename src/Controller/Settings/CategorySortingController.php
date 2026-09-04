<?php

declare(strict_types=1);

namespace App\Controller\Settings;

use App\Domain\Enum\Mail\CategorySource;
use App\Entity\User\User;
use App\Infrastructure\Messaging\Message\ReclassifyRecentMessage;
use App\Infrastructure\Messaging\Message\ResortMailboxMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * What sorts this person's mail into tabs, and whether it outranks the
 * provider's own answer.
 *
 * Two independent decisions and one form, posted together because they are read
 * together — see {@see \App\Entity\Embeddable\CategorySorting}. Its own
 * controller rather than a branch of the AI one for the reason the embeddable
 * is its own: half of this works on an installation with no model at all, and
 * a route under `/settings/ai` would say otherwise.
 *
 * THE MAILBOX IS RE-FILED WHEN EITHER ANSWER CHANGES, on a worker.
 *
 * This said the opposite at first — that a mailbox keeps its categories until
 * an administrator runs `app:backfill category` — out of caution about doing a
 * hundred thousand things inside an HTTP request. That caution is right and the
 * conclusion drawn from it was not: the answer to work too big for a request is
 * a worker, and this application has four.
 *
 * It is affordable because MessageCategorizer reads only PERSISTED data — the
 * stored headers, the provider's labels, the sender, the model's stored verdict
 * — so a re-sort asks no other machine anything. No IMAP, no model, no network.
 * It is a scan of rows the database already has and a column write.
 *
 * Leaving it undone was the worse option rather than the safe one: mail
 * arriving after the change would be sorted the new way and everything already
 * there the old way, so one inbox would be filed two ways depending on when
 * each message landed. Nobody chooses that, and the setting they did choose
 * produces it.
 *
 * The command stays. An operator re-sorting every mailbox on the installation
 * after a rules change still wants one, and it is the same work.
 *
 * See {@see \App\Infrastructure\Messaging\Message\ResortMailboxMessage}.
 */
#[Route('/settings/sorting', name: 'app_settings_sorting_')]
#[IsGranted('ROLE_USER')]
final class CategorySortingController extends AbstractController
{
    /**
     * The most mail one press may re-ask about.
     *
     * Five hundred model calls is the better part of an hour on a warm host and
     * rather more on a cold one, and it is one person's press on a host
     * everybody shares. Above this the honest answer is the backfill command,
     * which an operator runs deliberately and can watch.
     */
    private const int MAX_RECLASSIFY = 500;

    public function __construct(private readonly MessageBusInterface $bus)
    {
    }

    #[Route('', name: 'update', methods: ['POST'])]
    public function update(Request $request, EntityManagerInterface $em): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        if (false === $this->isCsrfTokenValid('settings-sorting', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        // Both fields are read only when they are POSTED, matching
        // ComposeBehaviorController: the panel may grow a second form later,
        // and a partial post must not silently reset the field it left out.
        //
        // Read first, so the dispatch below can tell a change from a press.
        // The card submits on change and the browser will happily post the
        // option that is already selected — re-filing a mailbox for that would
        // be minutes of a worker spent writing the answers back unchanged.
        $before = [$user->categorySorting->source, $user->categorySorting->overrideProvider];

        if (true === $request->request->has('source')) {
            // from_() rather than from(), so a hand-edited request sorts mail
            // the ordinary way instead of 500ing.
            $user->categorySorting->source = CategorySource::from_($request->request->get('source'))->value;
        }

        if (true === $request->request->has('overrideProvider')) {
            $user->categorySorting->overrideProvider = '1' === (string) $request->request->get('overrideProvider');
        }

        $em->flush();

        if ($before !== [$user->categorySorting->source, $user->categorySorting->overrideProvider]) {
            // RE-FILED HERE, RATHER THAN LEFT FOR AN ADMINISTRATOR TO RE-FILE.
            //
            // This card used to say "changing this decides how mail arriving
            // from now on is sorted", which leaves a mailbox sorted two
            // different ways depending on when each message landed — an
            // incoherent state nobody chose, produced by a setting they did.
            //
            // It is affordable because MessageCategorizer reads only persisted
            // data: no IMAP, no model, no network at all. A re-sort is a scan
            // and a column write, which is worker-shaped work rather than
            // request-shaped — hence a dispatch and not a loop.
            //
            // AFTER the flush, so the worker cannot read the row before the new
            // setting is on it.
            $this->bus->dispatch(new ResortMailboxMessage((int) $user->id));
        }

        return $this->redirectToRoute('app_settings_index', ['section' => 'general']);
    }

    /**
     * Ask the model again about the newest few hundred messages.
     *
     * WHY THIS IS A BUTTON AND NOT AUTOMATIC. A message is asked about once, on
     * arrival, and the answer is kept — right, because the mail does not
     * change. What changes is the QUESTION: the prompt is editable, the model
     * is a setting, and what plMail sends alongside a message has changed too.
     * Verdicts stored before the bulk-header line went in were reached without
     * evidence the model now gets, and demonstrably differ because of it.
     *
     * Nothing re-asks on its own and nothing should. A model call per message
     * is the most expensive thing here, and spending an afternoon of somebody
     * else's GPU because an administrator edited a prompt is not a decision to
     * take on their behalf.
     *
     * BOUNDED to what somebody is actually looking at. Recent mail is where a
     * wrong tab gets noticed; the whole mailbox is hours, and
     * `app:backfill category` already exists for anybody who wants that.
     */
    #[Route('/again', name: 'again', methods: ['POST'])]
    public function again(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        if (false === $this->isCsrfTokenValid('settings-sorting', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        // Clamped rather than trusted: the form offers three sizes and this is
        // a POST, so anything else arrived by hand. The ceiling is what one
        // press may cost the shared model host.
        $limit = max(50, min(self::MAX_RECLASSIFY, $request->request->getInt('limit', 200)));

        $this->bus->dispatch(new ReclassifyRecentMessage((int) $user->id, $limit));

        return $this->redirectToRoute('app_settings_index', ['section' => 'general']);
    }
}
