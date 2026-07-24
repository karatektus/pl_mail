<?php

namespace App\Controller;

use App\Domain\Trait\ParsesAddressFields;
use App\Domain\Enum\LabelRole;
use App\Domain\Enum\MessageFlag;
use App\Entity\Account;
use App\Entity\Message;
use App\Form\ComposeType;
use App\Message\SendMessageMessage;
use App\Repository\AccountRepository;
use App\Repository\ContactRepository;
use App\Repository\MailboxRepository;
use App\Repository\MessageRepository;
use App\Service\Imap\MessageThreader;
use App\Service\Label\LabelResolver;
use App\Service\Label\ThreadLabelSynchronizer;
use DateTimeImmutable;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Label-based compose: the From selector is an Account (unmapped form field),
 * not a Mailbox. Drafts carry the chosen account's Drafts label; for plain-
 * IMAP accounts the physical Drafts folder is attached as mailbox, for Gmail
 * accounts mailbox stays null.
 */
#[Route('/compose', name: 'app_compose_')]
class ComposeController extends AbstractController
{
    use ParsesAddressFields;

    public function __construct(
        private readonly EntityManagerInterface  $em,
        private readonly MailboxRepository       $mailboxRepository,
        private readonly MessageRepository       $messageRepository,
        private readonly AccountRepository       $accountRepository,
        private readonly LabelResolver           $labelResolver,
        private readonly MessageBusInterface     $bus,
        private readonly MessageThreader         $threader,
        private readonly ThreadLabelSynchronizer $threadLabelSynchronizer,
        private readonly ContactRepository       $contactRepository,
    )
    {
    }

    #[Route('/new', name: 'new', methods: ['GET'])]
    #[Route('/edit/{id}', name: 'edit', methods: ['GET'])]
    public function compose(?Message $message = null): Response
    {
        if (null === $message) {
            $account = $this->defaultAccount();
            $message = new Message()
                ->setAccount($account)
                ->setCreatedAt(new DateTimeImmutable());

        } else {
            $this->assertOwnership($message);
            $account = $message->getAccount();
        }

        $form = $this->createForm(ComposeType::class, $message, [
            'user' => $this->getUser(),
            'validation_groups' => ['Default'],
        ]);
        $form->get('account')->setData($account);
        $this->hydrateAddressFields($form, $message);

        return $this->render('compose/_window.html.twig', [
            'form' => $form,
            'message' => $message,
        ]);
    }

    #[Route('/reply/{id}', name: 'reply', methods: ['GET'])]
    public function reply(Message $original): Response
    {
        $this->assertOwnership($original);

        $account = $original->getAccount() ?? $this->defaultAccount();
        $draft = $this->buildReply($original, replyAll: false, account: $account);

        $form = $this->createForm(ComposeType::class, $draft, [
            'user' => $this->getUser(),
            'validation_groups' => ['Default'],
        ]);
        $form->get('account')->setData($account);
        $this->hydrateAddressFields($form, $original);

        return $this->render('compose/_window.html.twig', [
            'form' => $form,
            'message' => $draft,
        ]);
    }

    #[Route('/reply-all/{id}', name: 'reply_all', methods: ['GET'])]
    public function replyAll(Message $original): Response
    {
        $this->assertOwnership($original);

        $account = $original->getAccount() ?? $this->defaultAccount();
        $draft = $this->buildReply($original, replyAll: true, account: $account);

        $form = $this->createForm(ComposeType::class, $draft, [
            'user' => $this->getUser(),
            'validation_groups' => ['Default'],
        ]);
        $form->get('account')->setData($account);
        $this->hydrateAddressFields($form, $original);

        return $this->render('compose/_window.html.twig', [
            'form' => $form,
            'message' => $draft,
        ]);
    }

    #[Route('/forward/{id}', name: 'forward', methods: ['GET'])]
    public function forwardMessage(Message $original): Response
    {
        $this->assertOwnership($original);

        $account = $original->getAccount() ?? $this->defaultAccount();
        $draft = $this->buildForward($original);

        $form = $this->createForm(ComposeType::class, $draft, [
            'user' => $this->getUser(),
            'validation_groups' => ['Default'],
        ]);
        $form->get('account')->setData($account);
        $this->hydrateAddressFields($form, $original);

        return $this->render('compose/_window.html.twig', [
            'form' => $form,
            'message' => $draft,
        ]);
    }

    #[Route('/draft', name: 'form_new', methods: ['POST'])]
    #[Route('/draft/{id}', name: 'form_edit', methods: ['POST'])]
    public function draft(Request $request, ?Message $message = null): Response
    {
        if (null === $message) {
            $message = new Message()
                ->setAccount($this->defaultAccount())
                ->setCreatedAt(new DateTimeImmutable());
        } else {
            $this->assertOwnership($message);
        }

        $form = $this->createForm(ComposeType::class, $message, [
            'user' => $this->getUser(),
            'validation_groups' => ['Default'],
        ]);

        $form->handleRequest($request);

        // Apply Tom Select address fields (override whatever CollectionType bound)
        $this->applyAddressFields($form, $message);

        if ($form->isSubmitted() && $form->isValid()) {
            $account = $this->resolveAccount($form, $message);

            if (null === $account) {
                throw $this->createNotFoundException('No active account to compose from.');
            }

            $this->applyAccount($message, $account);
            $this->persistDraft($message, $account);

            return $this->render('compose/_window.html.twig', [
                'form' => $form,
                'message' => $message,
                'saved' => true,
            ]);
        }

        return $this->render('compose/_window.html.twig', [
            'form' => $form,
            'message' => $message,
        ]);
    }

    #[Route('/send', name: 'mail_send', methods: ['POST'])]
    #[Route('/send/{id}', name: 'mail_send_draft', methods: ['POST'])]
    public function send(Request $request, ?Message $message): Response
    {
        if (null === $message) {
            $message = new Message();
        } else {
            $this->assertOwnership($message);
        }

        $form = $this->createForm(ComposeType::class, $message, [
            'user' => $this->getUser(),
            'validation_groups' => ['Default', 'send'],
        ]);

        $form->handleRequest($request);

        // Apply Tom Select address fields
        $this->applyAddressFields($form, $message);

        if ($form->isSubmitted() && $form->isValid()) {
            if (null !== $message->getSentAt()) {
                return $this->render('compose/_send_toast.html.twig', [
                    'message' => $message,
                ], new Response('', 200, ['Content-Type' => 'text/vnd.turbo-stream.html']));
            }

            $account = $this->resolveAccount($form, $message);

            if (null === $account) {
                throw $this->createNotFoundException('No active account to send from.');
            }

            $this->applyAccount($message, $account);
            $this->persistDraft($message, $account);
            $this->bus->dispatch(
                new SendMessageMessage($message->getId()),
                [new DelayStamp(10_000)],
            );

            return $this->render('compose/_send_toast.html.twig', [
                'message' => $message,
            ], new Response('', 200, ['Content-Type' => 'text/vnd.turbo-stream.html']));
        }

        return $this->render('compose/_window.html.twig', [
            'form' => $form,
            'message' => $message,
        ]);
    }

    #[Route('/undo/{id}', name: 'mail_undo', methods: ['POST'])]
    public function undo(Message $message): Response
    {
        $this->assertOwnership($message);

        $message->setCancelled(true);
        $this->em->flush();

        $form = $this->createForm(ComposeType::class, $message, [
            'user' => $this->getUser(),
            'validation_groups' => ['Default'],
        ]);
        $form->get('account')->setData($message->getAccount());

        return $this->render('compose/_undo_toast.html.twig', [
            'form' => $form,
            'message' => $message,
        ]);
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Submitted From account, falling back to the message's current account,
     * then the user's default.
     */
    private function resolveAccount(FormInterface $form, Message $message): ?Account
    {
        $account = $form->get('account')->getData();

        if (null !== $account) {
            return $account;
        }

        return $message->getAccount() ?? $this->defaultAccount();
    }

    private function defaultAccount(): ?Account
    {
        $account = $this->accountRepository->findOneBy([
            'usr' => $this->getUser(),
            'isActive' => true,
            'isPrimary' => true,
        ]);

        if (null !== $account && $account->getUsr() !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }

        if (null !== $account) {
            return $account;
        }

        return $this->accountRepository->findOneBy([
            'usr' => $this->getUser(),
            'isActive' => true,
        ]);
    }

    /**
     * Wire the message to its From account: Drafts label of that account,
     * plus the physical Drafts folder for plain-IMAP accounts (Gmail
     * accounts have no mailboxes — mailbox stays null).
     *
     * Switching the From account on an existing draft moves it: Drafts
     * labels of other accounts are dropped.
     */
    private function applyAccount(Message $message, Account $account): void
    {
        $message->setAccount($account);

        $draftsLabel = $this->labelResolver->systemLabel(LabelRole::Drafts, $account);

        foreach ($message->getLabels() as $label) {
            if (LabelRole::Drafts === $label->role && $label !== $draftsLabel) {
                $message->removeLabel($label);
            }
        }

        $message->addLabel($draftsLabel);

        $message->setMailbox($this->mailboxRepository->findOneBy([
            'account' => $account,
            'label' => $draftsLabel,
        ]));
    }

    /**
     * Read compose_to[], compose_cc[], compose_bcc[] from the Tom Select
     * fields and write them onto the Message, replacing whatever the
     * Symfony CollectionType may have bound.
     */
    private function applyAddressFields(FormInterface $form, Message $message): void
    {
        $extract = static function (string $field) use ($form): array {
            /** @var Collection $contacts */
            $contacts = $form->get($field)->getData();

            if (empty($contacts)) {
                return [];
            }

            $result = [];
            foreach ($contacts as $contact) {
                $result[] = [
                    'name' => $contact->getDisplayName() ?? '',
                    'address' => $contact->getEmail() ?? '',
                ];
            }

            return array_values(array_filter($result, static fn(array $a): bool => $a['address'] !== ''));
        };

        $to = $extract('toAddresses');
        $cc = $extract('ccAddresses');
        $bcc = $extract('bccAddresses');

        if (!empty($to)) {
            $message->setToAddresses($to);
        }
        if (!empty($cc)) {
            $message->setCcAddresses($cc);
        }
        if (!empty($bcc)) {
            $message->setBccAddresses($bcc);
        }
    }

    private function buildReply(Message $original, bool $replyAll, Account $account): Message
    {
        $to = [[
            'name' => $original->getFromName() ?? '',
            'address' => $original->getFromAddress() ?? '',
        ]];

        $cc = [];
        if (true === $replyAll) {
            $ownAddress = strtolower($account->getEmail() ?? '');
            $candidates = array_merge(
                $original->getToAddresses() ?? [],
                $original->getCcAddresses() ?? [],
            );
            foreach ($candidates as $addr) {
                if (strtolower($addr['address'] ?? '') !== $ownAddress) {
                    $cc[] = $addr;
                }
            }
        }

        $subject = $this->prefixSubject('Re', $original->getSubject());

        $references = array_merge(
            $original->getReferences() ?? [],
            array_filter([$original->getMessageId()]),
        );

        $quotedBody = $this->buildQuotedHtml($original, 'reply');

        $draft = new Message()
            ->setAccount($account)
            ->setSubject($subject)
            ->setToAddresses($to)
            ->setCcAddresses($cc)
            ->setBodyHtml($quotedBody)
            ->setInReplyTo(array_filter([$original->getMessageId()]))
            ->setReferences(array_values(array_unique($references)))
            ->setHasAttachments(false)
            ->setCreatedAt(new DateTimeImmutable())
            ->setUpdatedAt(new DateTimeImmutable());

        if (null !== $original->getThread()) {
            $draft->setThread($original->getThread());
        }

        return $draft;
    }

    private function buildForward(Message $original): Message
    {
        $subject = $this->prefixSubject('Fwd', $original->getSubject());
        $quotedBody = $this->buildQuotedHtml($original, 'forward');

        return new Message()
            ->setAccount($original->getAccount())
            ->setSubject($subject)
            ->setToAddresses([])
            ->setBodyHtml($quotedBody)
            ->setHasAttachments(false)
            ->setCreatedAt(new DateTimeImmutable())
            ->setUpdatedAt(new DateTimeImmutable());
    }

    private function prefixSubject(string $prefix, ?string $subject): string
    {
        $subject = trim($subject ?? '');

        if ($subject === '') {
            return $prefix . ': ';
        }

        $pattern = '/^(re|fwd?)\s*:\s*/i';
        if (preg_match($pattern, $subject)) {
            if (strtolower($prefix) === 're') {
                return $subject;
            }
        }

        return $prefix . ': ' . $subject;
    }

    // NOTE: keep YOUR existing buildQuotedHtml() body — only the callers
    // changed. The reply branch below is reconstructed and may differ from
    // your version; the forward branch is verbatim.
    private function buildQuotedHtml(Message $original, string $mode): string
    {
        $dateStr = $original->getReceivedAt() ? $original->getReceivedAt()->format('D, M j, Y \a\t g:i a') : '';
        $fromName = htmlspecialchars($original->getFromName() ?? '', ENT_QUOTES, 'UTF-8');
        $fromAddr = htmlspecialchars($original->getFromAddress() ?? '', ENT_QUOTES, 'UTF-8');
        $fromLine = $fromName !== '' ? "{$fromName} &lt;{$fromAddr}&gt;" : $fromAddr;

        $bodyHtml = trim($original->getBodyHtml() ?? '');
        $bodyText = trim($original->getBodyText() ?? '');
        $innerBody = $bodyHtml !== '' ? $bodyHtml : nl2br(htmlspecialchars($bodyText, ENT_QUOTES, 'UTF-8'));

        if ('reply' === $mode) {
            return <<<HTML
                <p><br></p>
                <div style="font-size:0.85em;color:#555;margin-bottom:0.25em">
                    On {$dateStr}, {$fromLine} wrote:
                </div>
                <blockquote style="border-left:2px solid #e0e0e0;margin:0;padding-left:0.75em;color:#555">
                    {$innerBody}
                </blockquote>
                HTML;
        }

        $subjectLine = htmlspecialchars($original->getSubject() ?? '', ENT_QUOTES, 'UTF-8');
        $toLine = implode(', ', array_map(
            static fn(array $a) => htmlspecialchars(
                ($a['name'] ? $a['name'] . ' <' . $a['address'] . '>' : $a['address']),
                ENT_QUOTES,
                'UTF-8',
            ),
            $original->getToAddresses() ?? [],
        ));

        return <<<HTML
            <p><br></p>
            <div style="border-top:1px solid #e0e0e0;padding-top:0.75em;margin-top:0.5em;font-size:0.85em;color:#555">
                <p style="margin:0 0 0.25em"><strong>---------- Forwarded message ----------</strong></p>
                <p style="margin:0 0 0.1em"><strong>From:</strong> {$fromLine}</p>
                <p style="margin:0 0 0.1em"><strong>Date:</strong> {$dateStr}</p>
                <p style="margin:0 0 0.1em"><strong>Subject:</strong> {$subjectLine}</p>
                <p style="margin:0 0 0.75em"><strong>To:</strong> {$toLine}</p>
                <div>{$innerBody}</div>
            </div>
            HTML;
    }

    private function persistDraft(Message $message, Account $account): void
    {
        $now = new DateTimeImmutable();

        $message
            ->setFromAddress($account->getEmail())
            ->setFromName($account->getName())
            ->addFlag(MessageFlag::DRAFT)
            ->setHasAttachments(false)
            ->setSeenAt($message->getSeenAt() ?? $now)
            ->setUpdatedAt($now);

        $this->em->persist($message);

        if (null === $message->getThread()) {
            // Uses in_reply_to / references, so reply drafts land on the
            // original thread; fresh composes get a new one.
            $this->threader->assignThread($message, $account);
        }
        $this->threader->resyncDraftThreadSubject($message);
        $this->threadLabelSynchronizer->sync($message->getThread());

        $this->em->flush();
    }

    private function assertOwnership(Message $message): void
    {
        $account = $message->getAccount();

        if (null === $account || $account->getUsr() !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }
    }

    /**
     * Inverse of applyAddressFields(): turn the stored {name, address} JSON
     * back into the Contact entities the autocomplete field renders as
     * selected options. Addresses typed freehand may have no contact row yet,
     * so those are harvested on the spot — the field cannot represent an
     * address that is not a Contact.
     */
    private function hydrateAddressFields(FormInterface $form, Message $message): void
    {
        $groups = [
            'toAddresses'  => $message->getToAddresses() ?? [],
            'ccAddresses'  => $message->getCcAddresses() ?? [],
            'bccAddresses' => $message->getBccAddresses() ?? [],
        ];

        $pending = [];

        foreach ($groups as $addresses) {
            foreach ($addresses as $addr) {
                $email = mb_strtolower(trim($addr['address'] ?? ''));

                if ($email === '') {
                    continue;
                }

                $pending[$email] = ['email' => $email, 'name' => $addr['name'] ?? null];
            }
        }

        if (count($pending) === 0) {
            return;
        }

        $user     = $this->getUser();
        $contacts = $this->contactRepository->findByEmailsForUser($user, array_keys($pending));

        $missing = array_values(array_filter(
            $pending,
            static fn(array $addr): bool => false === array_key_exists($addr['email'], $contacts),
        ));

        // Only upsert what is genuinely absent — upsertBatch bumps frequency,
        // and merely opening a draft is not a new contact signal.
        if (count($missing) > 0) {
            $this->contactRepository->upsertBatch($user, $missing);
            $contacts = $this->contactRepository->findByEmailsForUser($user, array_keys($pending));
        }

        foreach ($groups as $field => $addresses) {
            $selected = [];

            foreach ($addresses as $addr) {
                $email = mb_strtolower(trim($addr['address'] ?? ''));

                if (true === array_key_exists($email, $contacts)) {
                    $selected[] = $contacts[$email];
                }
            }

            $form->get($field)->setData($selected);
        }
    }
}
