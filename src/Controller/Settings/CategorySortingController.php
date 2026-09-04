<?php

declare(strict_types=1);

namespace App\Controller\Settings;

use App\Domain\Enum\Mail\CategorySource;
use App\Entity\User\User;
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
}
