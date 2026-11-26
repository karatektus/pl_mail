<?php

declare(strict_types=1);

namespace App\Controller\Settings;

use App\Repository\Mail\Account\AccountRepository;
use App\Controller\ChecksCsrf;
use App\Domain\Exception\InvalidUnifiedInboxException;
use App\Entity\Mail\Account;
use App\Entity\Mail\UnifiedInbox;
use App\Entity\User\User;
use App\Security\Voter\OwnershipVoter;
use App\Service\Mail\UnifiedInboxes;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Settings → Unified inboxes (#265): the reader's inboxes — name, icon, order —
 * and which inbox each of their accounts is listed in.
 *
 * Plain form posts that come back to the page, like the tabs card it is
 * modelled on (InboxCategoryController); the order is one JSON list written
 * after every move, by the same settings--tab-order controller. A refused
 * change comes back as a toast saying why.
 *
 * An inbox id in the URL is resolved through UnifiedInboxes against the
 * reader's own rows, so somebody else's id is a 404 rather than a write.
 */
#[Route('/settings/inboxes', name: 'app_settings_inboxes_')]
#[IsGranted('ROLE_USER')]
final class UnifiedInboxController extends AbstractController
{
    use ChecksCsrf;

    public const string CSRF = 'settings-inboxes';

    public function __construct(
        private readonly UnifiedInboxes $inboxes,
        private readonly TranslatorInterface $translator,
        private readonly AccountRepository $accounts,
    ) {
    }

    #[Route('', name: 'create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $this->assertCsrf($request, self::CSRF);

        $user = $this->user();

        try {
            $inbox = $this->inboxes->create(
                $user,
                (string) $request->request->get('name', ''),
                (string) $request->request->get('icon', ''),
            );
        } catch (InvalidUnifiedInboxException $e) {
            return $this->refused($e);
        }

        $this->addFlash('success', $this->translator->trans('settings.unified_inboxes.created', ['%name%' => (string) $inbox->name]));

        return $this->back();
    }

    /** Name and icon in one post: the row's form carries both. */
    #[Route('/{id}', name: 'update', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function update(Request $request, int $id): Response
    {
        $this->assertCsrf($request, self::CSRF);

        $user = $this->user();

        try {
            $this->inboxes->update(
                $user,
                $this->inbox($user, $id),
                (string) $request->request->get('name', ''),
                (string) $request->request->get('icon', ''),
            );
        } catch (InvalidUnifiedInboxException $e) {
            return $this->refused($e);
        }

        $this->addFlash('success', $this->translator->trans('settings.unified_inboxes.saved'));

        return $this->back();
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(Request $request, int $id): Response
    {
        $this->assertCsrf($request, self::CSRF);

        $user = $this->user();

        try {
            $this->inboxes->remove($user, $this->inbox($user, $id));
        } catch (InvalidUnifiedInboxException $e) {
            return $this->refused($e);
        }

        $this->addFlash('success', $this->translator->trans('settings.unified_inboxes.deleted'));

        return $this->back();
    }

    /**
     * The order of the reader's inboxes, as the whole list of ids. Ids that are
     * not theirs are dropped by UnifiedInboxes::arrange(), and ones left out
     * keep their place after the rest.
     */
    #[Route('/order', name: 'order', methods: ['POST'])]
    public function order(Request $request): JsonResponse
    {
        $this->assertCsrf($request, self::CSRF);

        $keys = $request->toArray()['keys'] ?? null;

        if (false === is_array($keys)) {
            return $this->json(['ok' => false], Response::HTTP_BAD_REQUEST);
        }

        $ids = array_values(array_filter(
            array_map(static fn (mixed $key): int => is_numeric($key) ? (int) $key : 0, $keys),
            static fn (int $id): bool => $id > 0,
        ));

        $this->inboxes->arrange($this->user(), $ids);

        return $this->json(['ok' => true]);
    }

    /**
     * Put an account in a group, or take it out of one (#783): an account may
     * be in any number of groups, so a group lists its accounts and each has
     * an add and a remove. Out of its last group an account goes to Other.
     */
    #[Route('/{id}/accounts', name: 'account', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function account(Request $request, int $id): Response
    {
        $this->assertCsrf($request, self::CSRF);

        $user    = $this->user();
        $inbox   = $this->inbox($user, $id);
        $account = $this->accounts->find($request->request->getInt('account'));

        if (null === $account) {
            throw $this->createNotFoundException();
        }

        $this->denyAccessUnlessGranted(OwnershipVoter::OWN, $account);

        try {
            if ('remove' === $request->request->getString('do')) {
                $this->inboxes->removeAccount($user, $account, $inbox);
            } else {
                $this->inboxes->addAccount($user, $account, $inbox);
            }
        } catch (InvalidUnifiedInboxException $e) {
            return $this->refused($e);
        }

        return $this->back();
    }

    private function inbox(User $user, int $id): UnifiedInbox
    {
        return $this->inboxes->find($user, $id) ?? throw $this->createNotFoundException();
    }

    private function user(): User
    {
        $user = $this->getUser();

        if (false === $user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }

    private function refused(InvalidUnifiedInboxException $e): Response
    {
        $this->addFlash('error', $this->translator->trans($e->translationKey));

        return $this->back();
    }

    private function back(): Response
    {
        return $this->redirectToRoute('app_settings_page', ['page' => 'inboxes']);
    }
}
