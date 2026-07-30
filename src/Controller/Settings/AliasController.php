<?php

declare(strict_types=1);

namespace App\Controller\Settings;

use App\Domain\Enum\Account\EmailAliasSource;
use App\Domain\Enum\Account\EmailAliasStatus;
use App\Entity\Mail\Account;
use App\Entity\Mail\EmailAlias;
use App\Form\EmailAliasType;
use App\Form\Factory\AliasAddFormFactory;
use App\Repository\Mail\AccountRepository;
use App\Repository\Mail\EmailAliasRepository;
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
        $this->denyUnlessOwner($account);

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
        $this->assertToken($request, 'alias-primary' . $aliasId);
        $this->denyUnlessOwner($account);
        $target = $this->ownedAlias($account, $aliasId);

        foreach ($account->getAliases() as $alias) {
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
        $this->assertToken($request, 'alias-status' . $aliasId);
        $this->denyUnlessOwner($account);
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
        $this->assertToken($request, 'alias-delete' . $aliasId);
        $this->denyUnlessOwner($account);
        $target = $this->ownedAlias($account, $aliasId);

        if (EmailAliasStatus::Primary === $target->status) {
            return $this->streamResponse($request, $account, 'alias.status_blocked');
        }

        $account->removeAlias($target);
        $this->em->remove($target);
        $this->em->flush();

        return $this->streamResponse($request, $account, 'alias.deleted');
    }

    #[Route('/refresh', name: 'refresh', methods: ['POST'])]
    public function refresh(Request $request, Account $account): Response
    {
        $this->assertToken($request, 'alias-refresh' . $account->getId());
        $this->denyUnlessOwner($account);

        $this->aliasSeeder->seed($account);

        return $this->streamResponse($request, $account, 'alias.refreshed');
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private function ownedAlias(Account $account, int $aliasId): EmailAlias
    {
        foreach ($account->getAliases() as $alias) {
            if ($alias->id === $aliasId) {
                return $alias;
            }
        }

        throw $this->createNotFoundException('No such alias on this account.');
    }

    /**
     * The add form gets its token from EmailAliasType; these four are single
     * buttons, so they carry one by hand the way the label and account rows do.
     */
    private function assertToken(Request $request, string $id): void
    {
        if (false === $this->isCsrfTokenValid($id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
    }

    private function denyUnlessOwner(Account $account): void
    {
        if ($account->getUsr() !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }
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
