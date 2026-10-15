<?php

declare(strict_types=1);

namespace App\Controller\Settings;

use App\Controller\ChecksCsrf;
use App\Domain\Enum\Account\EmailAliasSource;
use App\Domain\Enum\Account\EmailAliasStatus;
use App\Entity\Mail\Account;
use App\Entity\Mail\EmailAlias;
use App\Form\EmailAliasType;
use App\Form\Factory\AliasAddFormFactory;
use App\Repository\Mail\AccountRepository;
use App\Repository\Mail\EmailAliasRepository;
use App\Security\Voter\OwnershipVoter;
use App\Service\Mail\AliasSeeder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\UX\Turbo\TurboBundle;

#[Route('/account/{id}/alias', name: 'app_alias_')]
#[IsGranted('ROLE_USER')]
final class AliasController extends AbstractController
{
    use ChecksCsrf;
    use FindsOwnedAlias;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AccountRepository      $accountRepository,
        private readonly EmailAliasRepository   $aliasRepository,
        private readonly AliasSeeder            $aliasSeeder,
        private readonly AliasAddFormFactory    $aliasAddForms,
    ) {}

    #[Route('/add', name: 'add', methods: ['POST'])]
    public function add(Request $request, Account $account): Response
    {
        $this->denyAccessUnlessGranted(OwnershipVoter::OWN, $account);

        $form = $this->createForm(EmailAliasType::class);
        $form->handleRequest($request);

        // Covers a missing or stale CSRF token as well as a malformed address:
        // both mean "do not write", and the toast says the same thing either way.
        if (false === $form->isSubmitted() || false === $form->isValid()) {
            return $this->streamResponse($request, $account, 'alias.invalid');
        }

        $address = EmailAlias::normalize((string) $form->get('address')->getData());

        if ('' === $address) {
            return $this->streamResponse($request, $account, 'alias.invalid');
        }

        if (null !== $this->aliasRepository->findOneByAccountAndAddress($account, $address)) {
            return $this->streamResponse($request, $account, 'alias.duplicate');
        }

        $alias = new EmailAlias(
            account: $account,
            address: $address,
            source: EmailAliasSource::Manual,
            status: EmailAliasStatus::Active,
        );

        $account->addAlias($alias);
        $this->em->persist($alias);
        $this->em->flush();

        return $this->streamResponse($request, $account, 'alias.added');
    }

    #[Route('/{aliasId}/primary', name: 'primary', methods: ['POST'])]
    public function makePrimary(Request $request, Account $account, int $aliasId): Response
    {
        $this->assertCsrf($request, 'alias-primary' . $aliasId);
        $this->denyAccessUnlessGranted(OwnershipVoter::OWN, $account);
        $target = $this->ownedAlias($account, $aliasId);

        foreach ($account->aliases as $alias) {
            if (EmailAliasStatus::Primary === $alias->status) {
                $alias->status = EmailAliasStatus::Active;
            }
        }

        $target->status = EmailAliasStatus::Primary;
        $this->em->flush();

        return $this->streamResponse($request, $account, 'alias.primary_set');
    }

    #[Route('/{aliasId}/status', name: 'status', methods: ['POST'])]
    public function setStatus(Request $request, Account $account, int $aliasId): Response
    {
        $this->assertCsrf($request, 'alias-status' . $aliasId);
        $this->denyAccessUnlessGranted(OwnershipVoter::OWN, $account);
        $target = $this->ownedAlias($account, $aliasId);

        $status = EmailAliasStatus::tryFrom((string) $request->request->get('status', ''));

        // A primary can't be disabled — demote it only via makePrimary on another.
        if (null === $status || EmailAliasStatus::Primary === $target->status) {
            return $this->streamResponse($request, $account, 'alias.status_blocked');
        }

        $target->status = $status;
        $this->em->flush();

        return $this->streamResponse($request, $account, 'alias.status_set');
    }

    #[Route('/{aliasId}/delete', name: 'delete', methods: ['POST'])]
    public function delete(Request $request, Account $account, int $aliasId): Response
    {
        $this->assertCsrf($request, 'alias-delete' . $aliasId);
        $this->denyAccessUnlessGranted(OwnershipVoter::OWN, $account);
        $target = $this->ownedAlias($account, $aliasId);

        if (EmailAliasStatus::Primary === $target->status) {
            return $this->streamResponse($request, $account, 'alias.status_blocked');
        }

        $account->removeAlias($target);
        $this->em->remove($target);
        $this->em->flush();

        return $this->streamResponse($request, $account, 'alias.deleted');
    }

    /**
     * The name mail from this address goes out under.
     *
     * Empty clears it, and that is an answer rather than an error: the address
     * goes back to sending under the account's name, which is what every alias
     * does until somebody says otherwise (Account::senderNameFor()).
     *
     * Control characters are dropped on the way in. The name ends up in a
     * header, and although the mailer encodes it, a line break stored here
     * would also be shown in the From selector and published to JMAP clients,
     * none of which expect one.
     */
    #[Route('/{aliasId}/name', name: 'name', methods: ['POST'])]
    public function setName(Request $request, Account $account, int $aliasId): Response
    {
        $this->assertCsrf($request, 'alias-name' . $aliasId);
        $this->denyAccessUnlessGranted(OwnershipVoter::OWN, $account);
        $target = $this->ownedAlias($account, $aliasId);

        $name = (string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) $request->request->get('name', ''));

        $target->displayName = mb_substr(trim($name), 0, 255);
        $this->em->flush();

        return $this->streamResponse(
            $request,
            $account,
            null === $target->displayName ? 'alias.name_cleared' : 'alias.name_set',
        );
    }

    /**
     * Declare a domain a catch-all: every address at it reaches this mailbox.
     *
     * What that buys is answering as the address a mail was sent to
     * (ReplyFromResolver) without adding each one as an alias first. Refused
     * for Gmail and Microsoft accounts, where it could not work: both send as
     * the addresses the provider knows about and rewrite or reject any other
     * From, so the setting would promise something the first reply would break.
     */
    #[Route('/catch-all/add', name: 'catch_all_add', methods: ['POST'])]
    public function addCatchAll(Request $request, Account $account): Response
    {
        $this->assertCsrf($request, 'alias-catch-all' . $account->id);
        $this->denyAccessUnlessGranted(OwnershipVoter::OWN, $account);

        if (true === $account->isGmail() || true === $account->isMicrosoft()) {
            return $this->streamResponse($request, $account, 'alias.catch_all_unsupported');
        }

        $domain = $this->domainFrom((string) $request->request->get('domain', ''));

        if (null === $domain) {
            return $this->streamResponse($request, $account, 'alias.catch_all_invalid');
        }

        $domains = $account->catchAllDomains;

        if (true === in_array($domain, $domains, true)) {
            return $this->streamResponse($request, $account, 'alias.catch_all_duplicate');
        }

        $domains[]                = $domain;
        $account->catchAllDomains = $domains;
        $this->em->flush();

        return $this->streamResponse($request, $account, 'alias.catch_all_added');
    }

    #[Route('/catch-all/remove', name: 'catch_all_remove', methods: ['POST'])]
    public function removeCatchAll(Request $request, Account $account): Response
    {
        $this->assertCsrf($request, 'alias-catch-all' . $account->id);
        $this->denyAccessUnlessGranted(OwnershipVoter::OWN, $account);

        $domain = strtolower(trim((string) $request->request->get('domain', '')));

        $account->catchAllDomains = array_values(array_filter(
            $account->catchAllDomains,
            static fn (string $existing): bool => $existing !== $domain,
        ));
        $this->em->flush();

        return $this->streamResponse($request, $account, 'alias.catch_all_removed');
    }

    #[Route('/refresh', name: 'refresh', methods: ['POST'])]
    public function refresh(Request $request, Account $account): Response
    {
        $this->assertCsrf($request, 'alias-refresh' . $account->id);
        $this->denyAccessUnlessGranted(OwnershipVoter::OWN, $account);

        $this->aliasSeeder->seed($account);

        return $this->streamResponse($request, $account, 'alias.refreshed');
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * A domain name out of whatever was typed, or null when it is not one.
     *
     * Forgiving about the spellings people reach for — `@example.com`,
     * `*@example.com`, a pasted address — because all of them mean the same
     * domain, and strict about the result: letters, digits and hyphens in
     * dot-separated labels with a top-level one, which is what a domain mail
     * is delivered to looks like. An internationalised name goes in as its
     * punycode form, the way it appears in an address on the wire.
     */
    private function domainFrom(string $typed): ?string
    {
        $domain = strtolower(trim($typed));
        $at     = strrpos($domain, '@');

        if (false !== $at) {
            $domain = substr($domain, $at + 1);
        }

        $domain = trim($domain, " .\t");

        if (strlen($domain) > 253 || 1 !== preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{0,61}[a-z0-9]$/', $domain)) {
            return null;
        }

        return $domain;
    }

    private function streamResponse(Request $request, Account $account, string $toastMessage): Response
    {
        $manageableAccounts = $this->accountRepository->findForUserOrdered($this->getUser());

        if (TurboBundle::STREAM_FORMAT === $request->getPreferredFormat()) {
            $request->setRequestFormat(TurboBundle::STREAM_FORMAT);

            return $this->render('alias/_mutation.stream.html.twig', [
                'account'            => $account,
                'manageableAccounts' => $manageableAccounts,
                'toastMessage'       => $toastMessage,
                // Fresh, unsubmitted forms: the stream replaces the whole list,
                // and the add field should come back empty.
                'aliasForms'         => $this->aliasAddForms->forAccounts($manageableAccounts),
            ]);
        }

        return $this->redirectToRoute('app_settings_index');
    }
}
