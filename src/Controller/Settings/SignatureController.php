<?php

declare(strict_types=1);

namespace App\Controller\Settings;

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
 * The Signatures section.
 *
 * Its own section and its own controller, split out of ComposeDefaultsController
 * — which keeps the read-receipt defaults. A signature is a paragraph of HTML
 * that needs an editor and a Save button; a read-receipt default is a
 * three-item dropdown that submits itself. Sharing a panel meant the paragraph
 * was pushed below a list of aliases and the dropdown was pushed below four
 * editors, and neither control was where anyone looked for it.
 *
 * Shaped after AliasController: same per-action CSRF, same ownership check,
 * same stream-or-redirect response, so the panel still works with JavaScript
 * off.
 *
 * THREE STATES PER ADDRESS, TWO OF THEM WRITES
 * ────────────────────────────────────────────
 * An alias either has no key (inherits the account signature), or has a key
 * holding HTML, or has a key holding the empty string (signs with nothing,
 * deliberately, on an account that does have one). SignatureProvider reads the
 * difference by the key's PRESENCE — see its class docblock — so "inherit"
 * must unsetSetting() and must never store ''.
 *
 * One alias route carries all three, keyed on which button posted:
 *
 *   inherit=1   → unsetSetting(): back to the account signature.
 *   override=1  → seed the key with the account's current signature, so the
 *                 editor appears with what this address is already sending
 *                 rather than empty. Nothing about the outgoing mail changes
 *                 at that moment, which is what makes the button safe to
 *                 press out of curiosity.
 *   otherwise   → store the posted signature, sanitised. An empty post here
 *                 is the deliberate "signs with nothing".
 *
 * One route rather than three because all three are the same sentence about
 * the same key, and splitting them would give the panel three CSRF ids for
 * one row.
 *
 * NO MIGRATION. Everything lives in Account::$settings, the existing jsonb bag.
 *
 * SANITISED, ALWAYS. What arrives is the innerHTML of a contenteditable, which
 * is to say whatever the clipboard held when the user last pasted into it, and
 * it is about to be injected verbatim into every message this account sends.
 * SignatureProvider::sanitize() runs it through the same allow-list as inbound
 * mail — never mind that this endpoint is CSRF-protected and owner-checked,
 * because the threat is not only someone else's post.
 */
#[Route('/account/{id}/signature', name: 'app_signature_')]
#[IsGranted('ROLE_USER')]
final class SignatureController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AccountRepository      $accountRepository,
        private readonly SignatureProvider      $signatures,
    ) {
    }

    /**
     * The account-wide signature, used by every address that has none of its own.
     */
    #[Route('', name: 'default', methods: ['POST'])]
    public function setDefault(Request $request, Account $account): Response
    {
        $this->assertToken($request, 'settings-signature' . $account->id);
        $this->denyUnlessOwner($account);

        $account->setSetting(
            Account::SETTING_SIGNATURE,
            $this->signatures->sanitize((string) $request->request->get('signature', '')),
        );
        $this->em->flush();

        return $this->streamResponse($request, 'settings.signature.saved');
    }

    /**
     * Set, seed or clear one address's own signature. See the class docblock
     * for why all three share a route.
     */
    #[Route('/{aliasId}', name: 'alias', methods: ['POST'])]
    public function setForAlias(Request $request, Account $account, int $aliasId): Response
    {
        $this->assertToken($request, 'settings-signature-alias' . $aliasId);
        $this->denyUnlessOwner($account);

        $alias = $this->ownedAlias($account, $aliasId);
        $key   = Account::signatureAliasSetting((int) $alias->id);

        if (true === $request->request->getBoolean('inherit')) {
            $account->unsetSetting($key);
            $this->em->flush();

            return $this->streamResponse($request, 'settings.signature.reverted');
        }

        if (true === $request->request->getBoolean('override')) {
            // Already overriding: pressing it again must not overwrite what is
            // stored with the account's copy. The button is not drawn in that
            // state, but a second POST of a stale panel would arrive here.
            if (null === $account->getSetting($key)) {
                $account->setSetting(
                    $key,
                    $this->signatures->sanitize((string) $account->getSetting(Account::SETTING_SIGNATURE, '')),
                );
                $this->em->flush();
            }

            return $this->streamResponse($request, 'settings.signature.overriding');
        }

        $account->setSetting(
            $key,
            $this->signatures->sanitize((string) $request->request->get('signature', '')),
        );
        $this->em->flush();

        return $this->streamResponse($request, 'settings.signature.saved');
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
     * Replaces the whole panel, not the row that changed.
     *
     * Every alias row is captioned by the account signature above it ("uses
     * the account signature"), and the disclosure's own open/closed state and
     * its count of overrides both follow from the set of rows — so a partial
     * swap could leave a badge saying "1" over a list with none.
     */
    private function streamResponse(Request $request, string $toastMessage): Response
    {
        if (TurboBundle::STREAM_FORMAT === $request->getPreferredFormat()) {
            $request->setRequestFormat(TurboBundle::STREAM_FORMAT);

            return $this->render('settings/_signature.stream.html.twig', [
                'manageableAccounts' => $this->accountRepository->findForUserOrdered($this->getUser()),
                'toastMessage'       => $toastMessage,
            ]);
        }

        return $this->redirectToRoute('app_settings_index', ['section' => 'signature']);
    }
}
