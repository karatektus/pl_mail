<?php

declare(strict_types=1);

namespace App\Service\Demo;

use App\Domain\DTO\Template\TemplateLocation;
use App\Entity\Mail\Account;
use App\Entity\Template\MailTemplate;
use App\Entity\User\User;
use App\Repository\Template\MailTemplateRepository;
use App\Repository\Template\TemplateFolderRepository;
use App\Service\Mail\MailBodySanitizer;
use App\Service\Template\TemplateLibrary;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The templates a demo starts with.
 *
 * A visitor who opens Settings → Templates on a fresh mailbox finds a tree with
 * one empty folder in it and a button, and a visitor who presses "Insert
 * template" in the compose window finds a sentence saying there are none. Both
 * are accurate and neither shows the feature. It shows in a list worth picking
 * from and in a message that arrives with a date already worked out, so the
 * demo is given a handful.
 *
 * Beside DemoMailbox rather than inside it. That class is the mailbox and the
 * week — things that arrive — and is already long; these are things the user
 * is imagined to have written, they are seeded through a different service
 * (TemplateLibrary), and the two share nothing but their callers.
 *
 * CHOSEN TO SHOW THE PARTS, NOT TO BE MANY. Between them the six cover:
 *
 *   - all three places a template can sit — the top level, the account's own
 *     folder, and a folder made inside it — so the tree looks like a tree and
 *     the picker has its "this account first, then everything else" to show;
 *   - every variable at least once, and the date variable with an offset in
 *     each direction and in three formats, because "14 days ago, written out"
 *     is the part nobody believes until they see it;
 *   - one template with no subject, since inserting into a reply is half of
 *     what templates are used for and a subject would be noise there.
 *
 * They read like the mailbox's owner wrote them: the same small workshop the
 * seeded mail is about (a quote for the trim, a bookshelf, an invoice), dull on
 * purpose for the reason DemoMailbox gives.
 *
 * THE SIGNATURE VARIABLE IS THERE AND RENDERS AS NOTHING on a fresh demo,
 * because the demo account has no signature and this class does not give it
 * one: a signature would appear in every new message the visitor opens, and in
 * the README's compose screenshots, to make one variable look busier. A
 * visitor who writes a signature in Settings gets it in these templates at
 * once, which is the better demonstration anyway. Each template that uses it
 * closes with the sender's name on its own line first, so none ends on a
 * bare "Thanks,".
 */
final readonly class DemoTemplates
{
    /** The folder made inside the demo account. */
    public const string FOLDER = 'Quotes and invoices';

    /**
     * Where each one is filed: `root`, `account`, or `folder` (self::FOLDER).
     *
     * @var list<array{name: string, in: string, subject: ?string, body: string}>
     */
    private const array TEMPLATES = [
        [
            'name'    => 'Quote follow-up',
            'in'      => 'folder',
            'subject' => 'Your quote from {{date|offset=-7d|format=long}}',
            'body'    => '<p>Hi {{recipient.first_name}},</p>'
                . '<p>I sent over a quote on {{date|offset=-7d|format=full}} and wanted to check it reached you. '
                . 'The price holds until {{date|offset=+2w|format=long}}; after that I would need to ask the timber yard again.</p>'
                . '<p>Happy to talk it through if anything is unclear.</p>'
                . '<p>Best,<br>{{sender.name}}</p>'
                . '<p>{{signature}}</p>',
        ],
        [
            'name'    => 'Invoice reminder',
            'in'      => 'folder',
            'subject' => 'Reminder: invoice from {{date|offset=-14d|format=medium}}',
            'body'    => '<p>Hi {{recipient.first_name}},</p>'
                . '<p>just a quick reminder that the invoice from {{date|offset=-14d|format=long}} is still open. '
                . 'Could you settle it by {{date|offset=+7d|format=long}}?</p>'
                . '<p>If it has crossed with your payment, ignore this.</p>'
                . '<p>Thanks,<br>{{sender.name}}</p>'
                . '<p>{{signature}}</p>',
        ],
        [
            'name'    => 'Payment received',
            'in'      => 'folder',
            'subject' => 'Payment received — thank you',
            'body'    => '<p>Hi {{recipient.first_name}},</p>'
                . '<p>your payment arrived on {{date|format=long}}. Thank you — that closes the invoice, '
                . 'and a receipt made out to {{recipient.name}} is on its way to {{recipient.email}}.</p>'
                . '<p>Best,<br>{{sender.name}}</p>',
        ],
        [
            'name'    => 'Meeting follow-up',
            'in'      => 'account',
            'subject' => null,
            'body'    => '<p>Hi {{recipient.first_name}},</p>'
                . '<p>thanks for your time today. I will send my notes by {{date|offset=+2d|format=weekday}}, '
                . 'and you can reach me at {{sender.email}} in the meantime.</p>'
                . '<p>Best,<br>{{sender.name}}</p>',
        ],
        [
            'name'    => 'Out of office',
            'in'      => 'root',
            'subject' => 'Away until {{date|offset=+1w|format=long}}',
            'body'    => '<p>Hello {{recipient.first_name}},</p>'
                . '<p>I am away from the workshop until {{date|offset=+1w|format=full}} and will not be reading mail. '
                . 'I will answer when I am back; if it cannot wait, write "urgent" in the subject.</p>'
                . '<p>{{sender.name}}</p>',
        ],
        [
            'name'    => 'Thanks, received',
            'in'      => 'root',
            'subject' => null,
            'body'    => '<p>Hi {{recipient.first_name}},</p>'
                . '<p>thanks, this has arrived. I will come back to you by {{date|offset=+3d|format=pattern:EEEE, d MMMM}}.</p>'
                . '<p>{{sender.name}}</p>',
        ],
    ];

    public function __construct(
        private TemplateLibrary          $library,
        private MailTemplateRepository   $templates,
        private TemplateFolderRepository $folders,
        private MailBodySanitizer        $sanitizer,
        private EntityManagerInterface   $entityManager,
    ) {
    }

    /**
     * Give the user the demo's templates, filed around `$account`.
     *
     * Wiped first, for the reason everything the demo seeds is: run twice, this
     * must give the same six rather than twelve. That makes it destructive for
     * whoever it is pointed at, which is why its only callers are the two that
     * seed the rest of the demo mailbox — the provisioner, on a user it minted
     * a moment ago, and app:test:seed-demo, which refuses to run in prod.
     *
     * The bodies go through the sanitiser although they are constants in this
     * file. "Every stored body has been through it" is the invariant the
     * compose window relies on when it injects one, and an invariant with an
     * exception for trusted callers is one somebody will widen.
     *
     * @return int how many templates were written
     */
    public function seed(User $user, Account $account): int
    {
        foreach ($this->templates->findBy(['usr' => $user]) as $template) {
            $this->entityManager->remove($template);
        }

        // Top-level folders only: the ones inside them go by the cascade, and
        // removing a child the database has already taken is how a seeder that
        // works once fails the second time.
        foreach ($this->folders->findBy(['usr' => $user, 'parent' => null]) as $folder) {
            $this->entityManager->remove($folder);
        }

        $this->entityManager->flush();

        $folder = $this->library->createFolder($user, self::FOLDER, new TemplateLocation($account));

        // Before the templates, which are filed by the folder's id.
        $this->entityManager->flush();

        $places = [
            'root'    => new TemplateLocation(),
            'account' => new TemplateLocation($account),
            'folder'  => new TemplateLocation($account, $folder),
        ];

        foreach (self::TEMPLATES as $entry) {
            $template          = new MailTemplate();
            $template->usr     = $user;
            $template->name    = $entry['name'];
            $template->subject = $entry['subject'];
            $template->body    = $this->sanitizer->sanitizeFragment($entry['body']);

            $this->library->place($template, $places[$entry['in']]);
            $this->entityManager->persist($template);
        }

        $this->entityManager->flush();

        return count(self::TEMPLATES);
    }
}
