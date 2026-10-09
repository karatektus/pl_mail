<?php

declare(strict_types=1);

namespace App\Jmap\Method\Template;

use App\Domain\DTO\Template\TemplateLocation;
use App\Entity\Template\MailTemplate;
use App\Jmap\Mapper\TemplateMapper;
use App\Jmap\Method\JmapMethod;
use App\Jmap\Protocol\Exception\MethodException;
use App\Jmap\Protocol\JmapContext;
use App\Repository\Template\MailTemplateRepository;
use App\Service\Mail\MailBodySanitizer;
use App\Service\Template\TemplateLibrary;
use Doctrine\ORM\EntityManagerInterface;

/**
 * "Template/set" — plMail extension, `urn:plmail:params:jmap:templates`.
 *
 * Create, update and destroy, going through the same two things the settings
 * page goes through: TemplateLibrary for where a template sits, and the
 * inbound-mail sanitiser for its body. A template written on a phone is
 * inserted into the web compose window's own document, so "the client is ours"
 * is not a reason to store what it sent unread.
 *
 * WHERE A TEMPLATE SITS is two properties, `accountId` and `folderId`, and the
 * second decides the first: a folder is under one account (or none), so a
 * template in it is under the same one. A write may therefore name the folder
 * alone. Naming both is accepted when they agree and refused when they do not —
 * silently preferring one would file the template somewhere the client did not
 * ask for. An update that changes only `accountId` moves the template to that
 * account's top level, out of whatever folder it was in, for the same reason:
 * the old folder belongs to the old account.
 *
 * `htmlBody` in the response of a create or update is the SANITISED body when
 * sanitising changed it, per RFC 8620 §5.3 — a client must not go on believing
 * the server holds what it sent.
 */
final class TemplateSetMethod implements JmapMethod
{
    use TemplateMethodSupport;

    private const array SETTABLE = ['name', 'subject', 'htmlBody', 'accountId', 'folderId'];

    public function __construct(
        private readonly MailTemplateRepository $templates,
        private readonly TemplateLibrary        $library,
        private readonly TemplateMapper         $mapper,
        private readonly MailBodySanitizer      $sanitizer,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function name(): string
    {
        return 'Template/set';
    }

    public function handle(array $arguments, JmapContext $context): array
    {
        $this->refuseAccountId($arguments, 'Template');

        $oldState  = $this->state($context);
        $ifInState = $arguments['ifInState'] ?? null;

        if (null !== $ifInState && $ifInState !== $oldState) {
            throw new MethodException('stateMismatch', 'The templates have changed since ifInState was issued.');
        }

        $created = $notCreated = $updated = $notUpdated = $notDestroyed = [];
        $destroyed = [];

        foreach ($this->objectMap($arguments['create'] ?? null, 'create') as $creationId => $properties) {
            $creationId = (string) $creationId;

            try {
                $template      = new MailTemplate();
                $template->usr = $context->user;
                $echo          = $this->apply($template, $this->objectOf($properties), $context, creating: true);
            } catch (MethodException $exception) {
                $notCreated[$creationId] = $exception->toError();

                continue;
            }

            $this->em->persist($template);
            // The id is the response, and what "#creationId" resolves to.
            $this->em->flush();

            $context->recordCreatedId($creationId, (string) $template->id);
            $created[$creationId] = ['id' => (string) $template->id] + $echo;
        }

        foreach ($this->objectMap($arguments['update'] ?? null, 'update') as $id => $patch) {
            $id       = (string) $id;
            $template = $this->owned($context->resolveId($id) ?? $id, $context);

            if (null === $template) {
                $notUpdated[$id] = ['type' => 'notFound', 'description' => 'No such Template.'];

                continue;
            }

            try {
                $echo = $this->apply($template, $this->objectOf($patch), $context, creating: false);
            } catch (MethodException $exception) {
                // Nothing of a refused patch may reach the flush below.
                $this->em->refresh($template);
                $notUpdated[$id] = $exception->toError();

                continue;
            }

            $updated[$id] = [] === $echo ? null : $echo;
        }

        $destroy = $arguments['destroy'] ?? [];

        if (false === is_array($destroy)) {
            throw new MethodException('invalidArguments', '"destroy" must be an array of ids.');
        }

        foreach ($destroy as $id) {
            $id       = (string) $id;
            $template = $this->owned($real = $context->resolveId($id) ?? $id, $context);

            if (null === $template) {
                $notDestroyed[$id] = ['type' => 'notFound', 'description' => 'No such Template.'];

                continue;
            }

            $this->em->remove($template);
            // The real id, also when the client named it by "#creationId".
            $destroyed[] = $real;
        }

        $this->em->flush();

        return [
            'oldState'     => $oldState,
            'newState'     => $this->state($context),
            'created'      => $this->map($created),
            'notCreated'   => $this->map($notCreated),
            'updated'      => $this->map($updated),
            'notUpdated'   => $this->map($notUpdated),
            'destroyed'    => $destroyed,
            'notDestroyed' => $this->map($notDestroyed),
        ];
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * Put a create's properties or an update's patch onto the template.
     *
     * @param array<string, mixed> $properties
     *
     * @return array<string, mixed> the properties the server set to something
     *                              other than what was sent
     */
    private function apply(MailTemplate $template, array $properties, JmapContext $context, bool $creating): array
    {
        $this->rejectUnsupported($properties, self::SETTABLE);

        $echo = [];

        if (true === $creating || true === array_key_exists('name', $properties)) {
            $name = $this->line($properties['name'] ?? '');

            if ('' === $name) {
                throw new MethodException('invalidProperties', 'A template needs a "name".');
            }

            $template->name = $name;

            if ($name !== ($properties['name'] ?? null)) {
                $echo['name'] = $name;
            }
        }

        if (true === array_key_exists('subject', $properties)) {
            $subject           = $this->line($properties['subject']);
            $template->subject = '' === $subject ? null : $subject;

            if ($template->subject !== $properties['subject']) {
                $echo['subject'] = $template->subject;
            }
        }

        if (true === array_key_exists('htmlBody', $properties)) {
            if (null !== $properties['htmlBody'] && false === is_string($properties['htmlBody'])) {
                throw new MethodException('invalidProperties', '"htmlBody" must be a string.');
            }

            $template->body = $this->sanitizer->sanitizeFragment((string) $properties['htmlBody']);

            if ($template->body !== $properties['htmlBody']) {
                $echo['htmlBody'] = $template->body;
            }
        }

        if (true === array_key_exists('accountId', $properties) || true === array_key_exists('folderId', $properties)) {
            $location = $this->location($template, $properties, $context);
            $this->library->place($template, $location);

            $placed = $this->mapper->template($template);

            foreach (['accountId', 'folderId'] as $property) {
                if (false === array_key_exists($property, $properties) || $properties[$property] !== $placed[$property]) {
                    $echo[$property] = $placed[$property];
                }
            }
        }

        return $echo;
    }

    /**
     * The place a write names. See the class docblock for the rule.
     *
     * @param array<string, mixed> $properties
     */
    private function location(MailTemplate $template, array $properties, JmapContext $context): TemplateLocation
    {
        $folderId = array_key_exists('folderId', $properties)
            ? $properties['folderId']
            // Only the account was named: out of the folder, which belonged
            // to the account the template is leaving.
            : null;

        if (null !== $folderId) {
            $folderId = $context->resolveId((string) $folderId) ?? (string) $folderId;
            $location = $this->library->locate($context->user, sprintf('folder:%s', $folderId));

            if (null === $location) {
                throw new MethodException('invalidProperties', sprintf('No TemplateFolder "%s".', $folderId));
            }

            $wanted = $properties['accountId'] ?? null;

            if (true === array_key_exists('accountId', $properties)
                && (null === $wanted ? null : (string) $wanted) !== (null === $location->account ? null : (string) $location->account->id)) {
                throw new MethodException(
                    'invalidProperties',
                    '"accountId" does not match the account "folderId" is under. Send the folder alone, or the account it belongs to.',
                );
            }

            return $location;
        }

        $accountId = array_key_exists('accountId', $properties)
            ? $properties['accountId']
            : (null === $template->account ? null : (string) $template->account->id);

        if (null === $accountId) {
            return new TemplateLocation();
        }

        $location = $this->library->locate($context->user, sprintf('account:%s', (string) $accountId));

        if (null === $location) {
            throw new MethodException('invalidProperties', sprintf('No account "%s" for this user.', (string) $accountId));
        }

        return $location;
    }

    private function owned(string $id, JmapContext $context): ?MailTemplate
    {
        if (1 !== preg_match('/^\d{1,9}$/', $id)) {
            return null;
        }

        $template = $this->templates->find((int) $id);

        return null !== $template && $template->usr === $context->user ? $template : null;
    }

    private function state(JmapContext $context): string
    {
        return $this->mapper->state(array_map(
            fn (MailTemplate $template): array => $this->mapper->template($template),
            $this->templates->findForUser($context->user),
        ));
    }

    /**
     * @return array<array-key, mixed>
     */
    private function objectMap(mixed $value, string $argument): array
    {
        if (null === $value) {
            return [];
        }

        if (false === is_array($value)) {
            throw new MethodException('invalidArguments', sprintf('"%s" must be an object.', $argument));
        }

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    private function objectOf(mixed $value): array
    {
        if (false === is_array($value)) {
            throw new MethodException('invalidProperties', 'Each create and update must be an object.');
        }

        return $value;
    }
}
