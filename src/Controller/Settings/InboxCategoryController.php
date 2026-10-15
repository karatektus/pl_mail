<?php

declare(strict_types=1);

namespace App\Controller\Settings;

use App\Controller\ChecksCsrf;
use App\Domain\Enum\Mail\MessageCategory;
use App\Domain\Exception\InvalidInboxCategoryException;
use App\Entity\Mail\InboxCategory;
use App\Entity\User\User;
use App\Infrastructure\Messaging\Message\ResortMailboxMessage;
use App\Repository\Mail\MessageThreadRepository;
use App\Security\Voter\OwnershipVoter;
use App\Service\Mail\InboxCategoryManager;
use App\Service\Mail\InboxTabs;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The inbox tabs — Settings → Categories: the reader's own, which built-in ones
 * are switched on, and the order of all of them.
 *
 * Plain form posts that redirect back to the page, like the sorting card beside
 * it: three small writes on a short list do not need a frame of their own, and
 * a refused name comes back as a toast saying why rather than as a half-redrawn
 * card. Every write is the reader's own — the id in the URL is checked against
 * the owner before anything is changed.
 *
 * What fills a tab is not here. A category is only a name; mail goes into it
 * through a filter's "File in category" action (Settings → Filters).
 */
#[Route('/settings/categories', name: 'app_settings_categories_')]
#[IsGranted('ROLE_USER')]
final class InboxCategoryController extends AbstractController
{
    use ChecksCsrf;

    private const string CSRF = 'settings-categories';

    public function __construct(
        private readonly InboxCategoryManager $manager,
        private readonly TranslatorInterface $translator,
        private readonly InboxTabs $tabs,
        private readonly MessageThreadRepository $threads,
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface $bus,
        private readonly \App\Service\Mail\InboxTabChanges $tabChanges,
    ) {
    }

    /**
     * Switch one built-in tab on or off.
     *
     * Off is not a matter of display: nothing sorts into the category any more
     * and its mail moves to Primary (CategorySorting::$disabled), so the change
     * queues the same re-sort the other sorting choices do. On queues it too,
     * and the mail comes back, because every message's category is recomputed
     * from what is stored.
     *
     * A conversation somebody dragged into the category is released first:
     * the re-sort skips pinned conversations, and one pinned to a tab that is
     * not there would stay in no tab for ever — the case InboxCategoryManager
     * handles for a deleted category of the reader's own.
     */
    #[Route('/switch', name: 'switch', methods: ['POST'])]
    public function switch(Request $request): Response
    {
        $this->assertCsrf($request, self::CSRF);

        /** @var User $user */
        $user     = $this->getUser();
        $category = MessageCategory::tryFrom((string) $request->request->get('key', ''));

        // Primary is refused rather than ignored silently: the page never
        // offers it, so a request for it is not one the page made.
        if (null === $category || MessageCategory::Primary === $category) {
            return $this->back();
        }

        $on  = '1' === (string) $request->request->get('on', '');
        $off = array_values(array_filter(
            $user->categorySorting->switchedOff(),
            static fn (MessageCategory $switched): bool => $switched !== $category,
        ));

        if (false === $on) {
            $off[] = $category;
        }

        if ($off === $user->categorySorting->switchedOff()) {
            return $this->back();
        }

        if (false === $on) {
            $this->threads->unpinFromCategory($user, $category);
        }

        $user->categorySorting->switchOff($off);
        $this->tabChanges->updated($user, [$category->value]);
        $this->em->flush();

        // After the flush, so the worker reads the new setting.
        $this->bus->dispatch(new ResortMailboxMessage((int) $user->id));

        return $this->back();
    }

    /**
     * The order of the tabs after Primary, as the whole list of keys.
     *
     * Kept to the keys the reader has — a built-in name or one of their own
     * categories — so a request cannot plant a key that names somebody else's.
     * A tab the list leaves out keeps its place after the ones it names
     * (InboxTabs::managedFor()), which makes a partial or duplicated request
     * harmless rather than destructive.
     */
    #[Route('/order', name: 'order', methods: ['POST'])]
    public function order(Request $request): JsonResponse
    {
        $this->assertCsrf($request, self::CSRF);

        $keys = $request->toArray()['keys'] ?? null;

        if (false === is_array($keys)) {
            return $this->json(['ok' => false], Response::HTTP_BAD_REQUEST);
        }

        /** @var User $user */
        $user  = $this->getUser();
        $known = array_map(static fn ($tab): string => $tab->value, $this->tabs->managedFor($user));
        $was   = $user->categorySorting->tabOrder;

        $user->categorySorting->arrange(array_values(array_filter(
            array_map(static fn (mixed $key): string => is_string($key) ? $key : '', $keys),
            static fn (string $key): bool => true === in_array($key, $known, true),
        )));

        // Every tab, because every sortOrder may have moved. Not for a request
        // that changed nothing: the page writes after each move, including a
        // move straight back.
        if ($was !== $user->categorySorting->tabOrder) {
            $this->tabChanges->updated($user, $known);
        }

        $this->em->flush();

        return $this->json(['ok' => true]);
    }

    #[Route('', name: 'create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $this->assertCsrf($request, self::CSRF);

        /** @var User $user */
        $user = $this->getUser();

        try {
            $category = $this->manager->create($user, (string) $request->request->get('name', ''));
        } catch (InvalidInboxCategoryException $e) {
            $this->addFlash('error', $this->translator->trans($e->translationKey));

            return $this->back();
        }

        $this->addFlash('success', $this->translator->trans('settings.categories.created', ['%name%' => $category->name]));

        return $this->back();
    }

    #[Route('/{id}/rename', name: 'rename', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function rename(Request $request, InboxCategory $category): Response
    {
        $this->denyAccessUnlessGranted(OwnershipVoter::OWN, $category);
        $this->assertCsrf($request, self::CSRF);

        try {
            $this->manager->rename($category, (string) $request->request->get('name', ''));
        } catch (InvalidInboxCategoryException $e) {
            $this->addFlash('error', $this->translator->trans($e->translationKey));

            return $this->back();
        }

        $this->addFlash('success', $this->translator->trans('settings.categories.renamed', ['%name%' => $category->name]));

        return $this->back();
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(Request $request, InboxCategory $category): Response
    {
        $this->denyAccessUnlessGranted(OwnershipVoter::OWN, $category);
        $this->assertCsrf($request, self::CSRF);

        $name = $category->name;

        $this->manager->remove($category);

        $this->addFlash('success', $this->translator->trans('settings.categories.deleted', ['%name%' => $name]));

        return $this->back();
    }

    private function back(): Response
    {
        return $this->redirectToRoute('app_settings_index', ['section' => 'categories']);
    }
}
