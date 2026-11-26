<?php

declare(strict_types=1);

namespace App\Controller\Settings;

use App\Controller\ChecksCsrf;
use App\Domain\Enum\Mail\ReadReceiptMode;
use App\Entity\Mail\Account;
use App\Repository\Mail\Account\AccountRepository;
use App\Security\Voter\OwnershipVoter;
use App\Service\Mail\DefaultBccProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\UX\Turbo\TurboBundle;

/**
 * The Compose defaults card: read receipts and Always Bcc, per account, with
 * the addresses that differ from their account listed as exceptions.
 *
 * Shaped after AliasController deliberately — same per-action CSRF, same
 * ownership check, same stream-or-redirect response — because a settings
 * panel that behaves differently from the one beside it is a panel users
 * learn twice.
 *
 * TWO SETTINGS, TWO LEVELS
 * ────────────────────────
 * Each setting has an account value and a per-alias override, and an override
 * is read by its key's PRESENCE: inherit REMOVES the key, and the negative
 * answer — "never send", "Bcc nobody" — STORES it. An address with either key
 * is an exception, which is the only way an address appears on the card
 * (#692: one row per alias did not scale past a few accounts).
 *
 * TWO KINDS OF RESPONSE
 * ─────────────────────
 * A select, a button, an exception added or removed: the whole panel is
 * replaced, because alias captions quote the account value ("Account default
 * (never send)") and a partial swap could leave them disagreeing. It comes
 * back with the account (and exception) that was being edited still open.
 *
 * A Bcc chip added or removed: only the summary lines and captions are
 * replaced. Replacing the panel would rebuild the chip field under the cursor
 * and throw the person out of it between two addresses. See bccResponse().
 *
 * NO MIGRATION. Every setting here lives in Account::$settings, the existing
 * jsonb bag — keyed per alias id for the overrides and on a fixed key for the
 * account default. See Account::readReceiptAliasSetting().
 */
#[Route('/account/{id}/compose-defaults', name: 'app_compose_defaults_')]
#[IsGranted('ROLE_USER')]
final class ComposeDefaultsController extends AbstractController
{
    use ChecksCsrf;
    use FindsOwnedAlias;

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
        $this->assertCsrf($request, 'compose-defaults-receipt' . $account->id);
        $this->denyAccessUnlessGranted(OwnershipVoter::OWN, $account);

        $mode = ReadReceiptMode::tryFrom((string) $request->request->get('mode', ''));

        if (null === $mode) {
            return $this->streamResponse($request, $account, null, 'settings.compose_defaults.invalid', 'error');
        }

        $account->setSetting(Account::SETTING_READ_RECEIPT_DEFAULT, $mode->value);
        $this->em->flush();

        return $this->streamResponse($request, $account, null, 'settings.compose_defaults.saved');
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
        $this->assertCsrf($request, 'compose-defaults-receipt-alias' . $aliasId);
        $this->denyAccessUnlessGranted(OwnershipVoter::OWN, $account);

        $alias = $this->ownedAlias($account, $aliasId);
        $raw   = (string) $request->request->get('mode', '');
        $key   = Account::readReceiptAliasSetting((int) $alias->id);

        if ('' === $raw) {
            $account->unsetSetting($key);
            $this->em->flush();

            return $this->streamResponse($request, $account, $alias->id, 'settings.compose_defaults.saved');
        }

        $mode = ReadReceiptMode::tryFrom($raw);

        if (null === $mode) {
            return $this->streamResponse($request, $account, $alias->id, 'settings.compose_defaults.invalid', 'error');
        }

        $account->setSetting($key, $mode->value);
        $this->em->flush();

        return $this->streamResponse($request, $account, $alias->id, 'settings.compose_defaults.saved');
    }

    /**
     * The account's default Bcc list. An empty list removes it.
     */
    #[Route('/bcc', name: 'bcc_default', methods: ['POST'])]
    public function setBccDefault(Request $request, Account $account): Response
    {
        $this->assertCsrf($request, 'compose-defaults-bcc' . $account->id);
        $this->denyAccessUnlessGranted(OwnershipVoter::OWN, $account);

        $parsed = DefaultBccProvider::parse($this->submittedAddresses($request));
        $error  = $this->bccError($parsed);

        if (null !== $error) {
            return $this->streamResponse($request, $account, null, $error, 'error');
        }

        // Nothing above the account to inherit from, so an empty list and no
        // key say the same thing — and no key is the one that keeps the bag
        // free of a setting nobody has.
        if ([] === $parsed['addresses']) {
            $account->unsetSetting(Account::SETTING_DEFAULT_BCC);
        } else {
            $account->setSetting(Account::SETTING_DEFAULT_BCC, $parsed['addresses']);
        }

        $this->em->flush();

        return $this->bccResponse($request, $account);
    }

    /**
     * Start, change or end one address's own Bcc list.
     *
     * Three posts on one route, as SignatureController::setForAlias() has:
     * `inherit` removes the key (the account's list applies again),
     * `override` starts one seeded with the account's list so the field opens
     * on what was in force, and `addresses` stores the list — empty included,
     * which is the deliberate "this address Bccs nobody".
     */
    #[Route('/{aliasId}/bcc', name: 'bcc_alias', requirements: ['aliasId' => '\\d+'], methods: ['POST'])]
    public function setBccForAlias(Request $request, Account $account, int $aliasId): Response
    {
        $this->assertCsrf($request, 'compose-defaults-bcc-alias' . $aliasId);
        $this->denyAccessUnlessGranted(OwnershipVoter::OWN, $account);

        $alias = $this->ownedAlias($account, $aliasId);
        $key   = Account::defaultBccAliasSetting((int) $alias->id);

        if (true === $request->request->getBoolean('inherit')) {
            $account->unsetSetting($key);
            $this->em->flush();

            return $this->streamResponse($request, $account, $alias->id, 'settings.compose_defaults.saved');
        }

        if (true === $request->request->getBoolean('override')) {
            // A stale panel posting this twice must not overwrite a list that
            // has been edited since with the account's.
            if (null === $account->getSetting($key)) {
                $account->setSetting($key, DefaultBccProvider::normalize($account->getSetting(Account::SETTING_DEFAULT_BCC)));
                $this->em->flush();
            }

            return $this->streamResponse($request, $account, $alias->id, 'settings.compose_defaults.saved');
        }

        $parsed = DefaultBccProvider::parse($this->submittedAddresses($request));
        $error  = $this->bccError($parsed);

        if (null !== $error) {
            return $this->streamResponse($request, $account, $alias->id, $error, 'error');
        }

        $account->setSetting($key, $parsed['addresses']);
        $this->em->flush();

        return $this->bccResponse($request, $account);
    }

    /**
     * Make an address follow its account in everything again: both overrides
     * removed at once, which is what taking it off the list of exceptions
     * means.
     */
    #[Route('/{aliasId}/exception/remove', name: 'exception_remove', requirements: ['aliasId' => '\\d+'], methods: ['POST'])]
    public function removeException(Request $request, Account $account, int $aliasId): Response
    {
        $this->assertCsrf($request, 'compose-defaults-exception' . $aliasId);
        $this->denyAccessUnlessGranted(OwnershipVoter::OWN, $account);

        $alias = $this->ownedAlias($account, $aliasId);

        $account->unsetSetting(Account::readReceiptAliasSetting((int) $alias->id));
        $account->unsetSetting(Account::defaultBccAliasSetting((int) $alias->id));
        $this->em->flush();

        return $this->streamResponse($request, $account, null, 'settings.compose_defaults.saved');
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * The chip field posts `addresses[]`, one per chip; a form without
     * JavaScript, or a test, may post one comma-separated string. Both are a
     * list.
     */
    private function submittedAddresses(Request $request): string
    {
        $raw = $request->request->all()['addresses'] ?? '';

        if (true === is_array($raw)) {
            return implode(',', array_map(static fn (mixed $value): string => is_scalar($value) ? (string) $value : '', $raw));
        }

        return is_scalar($raw) ? (string) $raw : '';
    }

    /**
     * The toast for a list that cannot be stored, or null when it can.
     *
     * Refused whole rather than stored without the bad entry: a typo dropped
     * quietly is a backup copy that silently never arrives, and the person
     * relying on it finds out when they need it.
     *
     * @param array{addresses: list<string>, invalid: ?string, tooMany: bool} $parsed
     *
     * @return array{0: string, 1: array<string, string|int>}|null
     */
    private function bccError(array $parsed): ?array
    {
        if (null !== $parsed['invalid']) {
            return ['settings.compose_defaults.bcc.invalid', ['%address%' => $parsed['invalid']]];
        }

        if (true === $parsed['tooMany']) {
            return ['settings.compose_defaults.bcc.too_many', ['%max%' => DefaultBccProvider::MAX_ADDRESSES]];
        }

        return null;
    }

    /**
     * After a Bcc chip: the account's summary line, its exceptions' summaries
     * and the captions that quote the account list — and nothing else, so the
     * chip field being typed in stays where it is. See the class docblock.
     */
    private function bccResponse(Request $request, Account $account): Response
    {
        if (TurboBundle::STREAM_FORMAT === $request->getPreferredFormat()) {
            $request->setRequestFormat(TurboBundle::STREAM_FORMAT);

            return $this->render('settings/_compose_defaults_bcc.stream.html.twig', [
                'account' => $account,
            ]);
        }

        return $this->redirectToRoute('app_settings_page', ['page' => 'compose']);
    }

    /**
     * Replaces the whole panel, with the account (and exception) that was
     * being edited still open.
     *
     * @param string|array{0: string, 1: array<string, string|int>} $toastMessage a key, or a key and its parameters
     */
    private function streamResponse(
        Request      $request,
        Account      $account,
        ?int         $aliasId,
        string|array $toastMessage,
        string       $toastType = 'success',
    ): Response {
        [$message, $parameters] = is_array($toastMessage) ? $toastMessage : [$toastMessage, []];

        if (TurboBundle::STREAM_FORMAT === $request->getPreferredFormat()) {
            $request->setRequestFormat(TurboBundle::STREAM_FORMAT);

            return $this->render('settings/_compose_defaults.stream.html.twig', [
                'manageableAccounts' => $this->accountRepository->findForUserOrdered($this->getUser()),
                'openAccountId'      => $account->id,
                'openAliasId'        => $aliasId,
                'toastMessage'       => $message,
                'toastParameters'    => $parameters,
                'toastType'          => $toastType,
            ]);
        }

        // The Compose page, where the card lives. This used to be bare
        // /settings, which opened Profile — the no-script fallback landed
        // somebody on a page without the setting they had just changed.
        return $this->redirectToRoute('app_settings_page', ['page' => 'compose']);
    }
}
