<?php

declare(strict_types=1);

namespace App\Controller\Settings;

use App\Domain\Enum\Mail\CategorySource;
use App\Entity\User\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
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
 * NOTHING IS RE-SORTED HERE, and the settings page says so. The category is
 * recomputed from stored data — that is the whole design of MessageCategorizer
 * — so a mailbox keeps the categories it has until `app:backfill category`
 * runs. Re-sorting a hundred thousand messages inside an HTTP request is the
 * one thing this must not do quietly, and a button that did it in the
 * background would still be a background job somebody did not ask for.
 */
#[Route('/settings/sorting', name: 'app_settings_sorting_')]
#[IsGranted('ROLE_USER')]
final class CategorySortingController extends AbstractController
{
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
        if (true === $request->request->has('source')) {
            // from_() rather than from(), so a hand-edited request sorts mail
            // the ordinary way instead of 500ing.
            $user->categorySorting->source = CategorySource::from_($request->request->get('source'))->value;
        }

        if (true === $request->request->has('overrideProvider')) {
            $user->categorySorting->overrideProvider = '1' === (string) $request->request->get('overrideProvider');
        }

        $em->flush();

        return $this->redirectToRoute('app_settings_index', ['section' => 'general']);
    }
}
