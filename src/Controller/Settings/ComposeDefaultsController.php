<?php

declare(strict_types=1);

namespace App\Controller\Settings;

use App\Domain\Enum\Mail\ReadReceiptMode;
use App\Entity\Mail\Account;
use App\Entity\Mail\EmailAlias;
use App\Repository\Mail\AccountRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\UX\Turbo\TurboBundle;

/**
 * The per-alias compose defaults panel.
 *
 * Shaped after AliasController deliberately — same per-action CSRF, same
 * ownership check, same stream-or-redirect response — because it renders in the
 * same section, against the same account/alias list, and a settings panel that
 * behaves differently from the one above it is a panel users learn twice.
 *
 * Only read receipts today. It is called compose *defaults* rather than
 * read-receipt settings because a signature control is landing in the same
 * panel from a sibling change: the route prefix, the account/alias iteration
 * and the stream template are all shared, and the only thing a second setting
 * has to add is one action here and one control in the partial.
 *
 * NO MIGRATION. Both settings live in Account::$settings, the existing jsonb
 * bag — keyed per alias id for the override and on a fixed key for the account
 * default. See Account::readReceiptAliasSetting().
 */
#[Route('/account/{id}/compose-defaults', name: 'app_compose_defaults_')]
#[IsGranted('ROLE_USER')]
final class ComposeDefaultsController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AccountRepository      $accountRepository,
    ) {
    }

    /**
     * Set the account-wide answer, used by every alias that has none of its own.
     */
    #[Route('/read-receipt', name: 'read_receipt_default', methods: ['POST'])]
    public function setDefault(Request $request, Account $account): Response
    {
        $this->assertToken($request, 'compose-defaults-receipt' . $account->id);
        $this->denyUnlessOwner($account);

        $mode = ReadReceiptMode::tryFrom((string) $request->request->get('mode', ''));

        if (null === $mode) {
            return $this->streamResponse($request, 'settings.compose_defaults.invalid');
        }

        $account->setSetting(Account::SETTING_READ_RECEIPT_DEFAULT, $mode->value);
        $this->em->flush();

        return $this->streamResponse($request, 'settings.compose_defaults.saved');
    }

    /**
     * Set — or clear — one alias's override.
     *
     * An empty mode means "follow the account default", and it REMOVES the key
     * rather than storing a sentinel. ReadReceiptPolicy distinguishes "this
     * alias has no answer" from "this alias says never" by the key's presence,
     * so writing an empty string here would silently pin the alias to Never and
     * the account default would stop reaching it.
     */
    #[Route('/{aliasId}/read-receipt', name: 'read_receipt_alias', methods: ['POST'])]
    public function setForAlias(Request $request, Account $account, int $aliasId): Response
    {
        $this->assertToken($request, 'compose-defaults-receipt-alias' . $aliasId);
        $this->denyUnlessOwner($account);

        $alias = $this->ownedAlias($account, $aliasId);
        $raw   = (string) $request->request->get('mode', '');
        $key   = Account::readReceiptAliasSetting((int) $alias->id);

        if ('' === $raw) {
            $account->unsetSetting($key);
            $this->em->flush();

            return $this->streamResponse($request, 'settings.compose_defaults.saved');
        }

        $mode = ReadReceiptMode::tryFrom($raw);

        if (null === $mode) {
            return $this->streamResponse($request, 'settings.compose_defaults.invalid');
        }

        $account->setSetting($key, $mode->value);
        $this->em->flush();

        return $this->streamResponse($request, 'settings.compose_defaults.saved');
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private function ownedAlias(Account $account, int $aliasId): EmailAlias
    {
        foreach ($account->aliases as $alias) {
            if ($alias->id === $aliasId) {
                return $alias;
            }
        }

        throw $this->createNotFoundException('No such alias on this account.');
    }

    private function assertToken(Request $request, string $id): void
    {
        if (false === $this->isCsrfTokenValid($id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
    }

    private function denyUnlessOwner(Account $account): void
    {
        if ($account->usr !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }
    }

    /**
     * Replaces the whole panel, not the one control that changed. Alias
     * overrides and the account default are shown together and one of them is
     * captioned by the other ("following the account default: never"), so a
     * partial swap could leave the caption disagreeing with the value above it.
     */
    private function streamResponse(Request $request, string $toastMessage): Response
    {
        if (TurboBundle::STREAM_FORMAT === $request->getPreferredFormat()) {
            $request->setRequestFormat(TurboBundle::STREAM_FORMAT);

            return $this->render('settings/_compose_defaults.stream.html.twig', [
                'manageableAccounts' => $this->accountRepository->findForUserOrdered($this->getUser()),
                'toastMessage'       => $toastMessage,
            ]);
        }

        return $this->redirectToRoute('app_settings_index');
    }
}
