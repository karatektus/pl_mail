<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail;

use App\Entity\Mail\Contact;
use App\Entity\Mail\Message;
use App\Entity\User\User;
use App\Repository\Mail\ContactRepository;
use App\Service\Mail\DraftAddressFields;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\FormInterface;

/**
 * Recipients, moved between the header shape a Message stores them in and the
 * Contact shape the autocomplete field can render.
 *
 * The two directions have to stay inverse, so they are tested together. The
 * parts worth pinning are the ones that are not copying: which addresses cause
 * a contact to be written, and which empty field means "unchanged" rather than
 * "cleared".
 */
final class DraftAddressFieldsTest extends TestCase
{
    private User $user;

    /** @var array<string, mixed> field name => whatever was set on it */
    private array $applied = [];

    /** @var array<string, Contact> */
    private array $known = [];

    /** @var list<list<array{email: string, name: string|null}>> */
    private array $upserted = [];

    protected function setUp(): void
    {
        $this->user     = new User();
        $this->applied  = [];
        $this->known    = [];
        $this->upserted = [];
    }

    // ── stored addresses → the autocomplete field ─────────────────────────

    public function testTheFieldsAreFilledWithTheContactsBehindTheStoredAddresses(): void
    {
        $this->known['k@example.test'] = $this->contact('k@example.test', 'Katharina');

        $message              = new Message();
        $message->toAddresses = [['name' => 'Katharina', 'address' => 'k@example.test']];

        $this->fields()->hydrate($this->form(), $message, $this->user);

        self::assertSame([$this->known['k@example.test']], $this->applied['toAddresses']);
        self::assertSame([], $this->applied['ccAddresses']);
    }

    /**
     * An address typed freehand has no contact row yet, and the field cannot
     * represent an address that is not a Contact — so it is harvested on the
     * spot rather than silently dropped from the reopened draft.
     */
    public function testAnAddressWithNoContactYetIsHarvested(): void
    {
        $message              = new Message();
        $message->toAddresses = [['name' => 'New', 'address' => 'new@example.test']];

        $this->fields()->hydrate($this->form(), $message, $this->user);

        self::assertSame([[['email' => 'new@example.test', 'name' => 'New']]], $this->upserted);
    }

    /**
     * upsertBatch bumps a contact's frequency, and merely opening a draft is
     * not a signal that its recipients matter more.
     */
    public function testOpeningADraftDoesNotTouchContactsThatAlreadyExist(): void
    {
        $this->known['k@example.test'] = $this->contact('k@example.test', 'Katharina');

        $message              = new Message();
        $message->toAddresses = [['name' => 'Katharina', 'address' => 'k@example.test']];
        $message->ccAddresses = [['name' => 'New', 'address' => 'new@example.test']];

        $this->fields()->hydrate($this->form(), $message, $this->user);

        self::assertSame([[['email' => 'new@example.test', 'name' => 'New']]], $this->upserted);
    }

    /** Addresses are matched case-insensitively; a header spells them freely. */
    public function testAnAddressIsMatchedWhateverCaseItWasStoredIn(): void
    {
        $this->known['k@example.test'] = $this->contact('k@example.test', 'Katharina');

        $message              = new Message();
        $message->toAddresses = [['name' => 'Katharina', 'address' => 'K@Example.TEST']];

        $this->fields()->hydrate($this->form(), $message, $this->user);

        self::assertSame([$this->known['k@example.test']], $this->applied['toAddresses']);
        self::assertSame([], $this->upserted, 'a known contact was harvested again over its casing');
    }

    public function testADraftWithNoRecipientsAsksTheAddressBookNothing(): void
    {
        $repository = $this->createMock(ContactRepository::class);
        $repository->expects(self::never())->method('findByEmailsForUser');

        new DraftAddressFields($repository)->hydrate($this->form(), new Message(), $this->user);

        self::assertSame([], $this->applied);
    }

    // ── the autocomplete field → stored addresses ─────────────────────────

    public function testTheSelectedContactsBecomeTheDraftsAddresses(): void
    {
        $message = new Message();

        $form = $this->form([
            'toAddresses' => [$this->contact('k@example.test', 'Katharina')],
            'ccAddresses' => [$this->contact('t@example.test', null)],
        ]);

        $this->fields()->apply($form, $message);

        self::assertSame([['name' => 'Katharina', 'address' => 'k@example.test']], $message->toAddresses);
        self::assertSame([['name' => '', 'address' => 't@example.test']], $message->ccAddresses);
    }

    /**
     * The Tom Select fields and the mapped collection are two sources for one
     * property, so a field that submitted nothing means "unchanged". Writing
     * the empty selection through would clear recipients the form never asked
     * about.
     */
    public function testAFieldThatSubmittedNothingLeavesTheDraftAlone(): void
    {
        $message              = new Message();
        $message->toAddresses = [['name' => 'Katharina', 'address' => 'k@example.test']];

        $this->fields()->apply($this->form(), $message);

        self::assertSame([['name' => 'Katharina', 'address' => 'k@example.test']], $message->toAddresses);
    }

    /** A contact with no address cannot be a recipient. */
    public function testAContactWithoutAnAddressIsDropped(): void
    {
        $message = new Message();

        $this->fields()->apply(
            $this->form(['toAddresses' => [$this->contact('k@example.test', 'Katharina'), new Contact()]]),
            $message,
        );

        self::assertSame([['name' => 'Katharina', 'address' => 'k@example.test']], $message->toAddresses);
    }

    // ── fixture ───────────────────────────────────────────────────────────

    private function fields(): DraftAddressFields
    {
        $repository = $this->createStub(ContactRepository::class);

        $repository->method('findByEmailsForUser')->willReturnCallback(
            fn (mixed $user, array $emails): array => array_intersect_key(
                $this->known,
                array_flip(array_map(mb_strtolower(...), $emails)),
            ),
        );

        $repository->method('upsertBatch')->willReturnCallback(
            function (mixed $user, array $addresses): void {
                $this->upserted[] = $addresses;

                foreach ($addresses as $address) {
                    $this->known[$address['email']] = $this->contact($address['email'], $address['name']);
                }
            },
        );

        return new DraftAddressFields($repository);
    }

    /**
     * @param array<string, list<Contact>> $selected what each field submitted
     */
    private function form(array $selected = []): FormInterface
    {
        $form = $this->createStub(FormInterface::class);

        $form->method('get')->willReturnCallback(
            fn (string $name): FormInterface => $this->field($name, $selected[$name] ?? []),
        );

        return $form;
    }

    /**
     * @param list<Contact> $data
     */
    private function field(string $name, array $data): FormInterface
    {
        $field = $this->createStub(FormInterface::class);

        $field->method('getData')->willReturn($data);
        $field->method('setData')->willReturnCallback(
            function (mixed $value) use ($name, $field): FormInterface {
                $this->applied[$name] = $value;

                return $field;
            },
        );

        return $field;
    }

    private function contact(string $email, ?string $displayName): Contact
    {
        $contact              = new Contact();
        $contact->email       = $email;
        $contact->displayName = $displayName;

        return $contact;
    }
}
