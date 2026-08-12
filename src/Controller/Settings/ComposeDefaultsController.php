<?php

declare(strict_types=1);

namespace App\Controller\Settings;

use App\Domain\Enum\Mail\ReadReceiptMode;
use App\Entity\Mail\Account;
use App\Entity\Mail\EmailAlias;
use App\Repository\Mail\AccountRepository;
use App\Service\Mail\SignatureProvider;
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
 * Read receipts and signatures. The panel was called compose *defaults*
 * rather than read-receipt settings precisely so the signature could land in
 * it: the route prefix, the account/alias iteration and the stream template
 * are shared, and adding it came to one action here and one control in the
 * partial, as predicted.
 *
 * Both settings have the same three-state shape per alias — inherit, or an
 * answer of this alias's own, where the answer may be the negative one
 * ("never" / an empty signature). Inherit REMOVES the key; the negative
 * answer STORES it. See setForAlias() and setSignatureForAlias().
 *
 * NO MIGRATION. Every setting here lives in Account::$settings, the existing
 * jsonb bag — keyed per alias id for the overrides and on fixed keys for the
 * account defaults. See Account::readReceiptAliasSetting() and
 * Account::signatureAliasSetting().
 */
#[Route('/account/{id}/compose-defaults', name: 'app_compose_defaults_')]
#[IsGranted('ROLE_USER')]
final class ComposeDefaultsController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AccountRepository      $accountRepository,
        private readonly SignatureProvider      $signatures,
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

    /**
     * The account-wide signature, used by every alias that has none of its own.
     *
     * SANITISED, ALWAYS. What arrives here is the innerHTML of a
     * contenteditable, which is to say whatever the clipboard held when the
     * user last pasted into it, and it is about to be injected verbatim into
     * every message this account sends. SignatureProvider::sanitize() runs it
     * through the same allow-list as inbound mail, so a script, an iframe or a
     * form cannot be stored — never mind that this endpoint is CSRF-protected
     * and owner-checked, because the threat is not only someone else's post.
     */
    #[Route('/signature', name: 'signature_default', methods: ['POST'])]
    public function setSignature(Request $request, Account $account): Response
    {
        $this->assertToken($request, 'compose-defaults-signature' . $account->id);
        $this->denyUnlessOwner($account);

        $account->setSetting(
            Account::SETTING_SIGNATURE,
            $this->signatures->sanitize((string) $request->request->get('signature', '')),
        );
        $this->em->flush();

        return $this->streamResponse($request, 'settings.compose_defaults.saved');
    }

    /**
     * Set — or clear — one alias's signature.
     *
     * Three states, exactly as the read-receipt control has three, and for the
     * same reason: "inherit" REMOVES the key while an empty signature STORES
     * the empty string. Those are different answers. An alias that stores ''
     * signs with nothing even though the account has a signature — which is
     * what a personal address on a work mailbox wants — and an alias with no
     * key at all follows the account. Writing '' for both would make the first
     * unreachable.
     *
     * The `inherit` checkbox is what says which of the two was meant; the
     * signature field is ignored when it is ticked.
     */
    #[Route('/{aliasId}/signature', name: 'signature_alias', methods: ['POST'])]
    public function setSignatureForAlias(Request $request, Account $account, int $aliasId): Response
    {
        $this->assertToken($request, 'compose-defaults-signature-alias' . $aliasId);
        $this->denyUnlessOwner($account);

        $alias = $this->ownedAlias($account, $aliasId);
        $key   = Account::signatureAliasSetting((int) $alias->id);

        if (true === $request->request->getBoolean('inherit')) {
            $account->unsetSetting($key);
            $this->em->flush();

            return $this->streamResponse($request, 'settings.compose_defaults.saved');
        }

        $account->setSetting(
            $key,
            $this->signatures->sanitize((string) $request->request->get('signature', '')),
        );
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
