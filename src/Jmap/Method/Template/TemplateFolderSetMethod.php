<?php

declare(strict_types=1);

namespace App\Jmap\Method\Template;

use App\Entity\Template\TemplateFolder;
use App\Jmap\Mapper\TemplateMapper;
use App\Jmap\Method\JmapMethod;
use App\Jmap\Protocol\Exception\MethodException;
use App\Jmap\Protocol\JmapContext;
use App\Repository\Template\TemplateFolderRepository;
use App\Service\Template\TemplateLibrary;
use Doctrine\ORM\EntityManagerInterface;

/**
 * "TemplateFolder/set" — plMail extension, `urn:plmail:params:jmap:templates`.
 *
 * Create a folder, rename one, destroy one.
 *
 * `accountId` and `parentId` are CREATE-ONLY: a folder cannot be moved, here or
 * in the settings page, and for the same reason (TemplateController::
 * saveFolder()). An update carrying either is refused rather than ignored, as
 * Identity/set refuses a changed `email` — a client that believes it moved a
 * folder would go on drawing it in the wrong place.
 *
 * On create, `parentId` decides `accountId` exactly as a template's folder
 * decides its account (see TemplateSetMethod).
 *
 * DESTROYING A FOLDER DESTROYS NO TEMPLATE. The folders inside it go with it;
 * the templates in any of them move up to where the folder was
 * (TemplateLibrary::deleteFolder()). A client holding those templates sees
 * their `folderId` change on its next Template/get, which the moved
 * Template state token tells it to make.
 */
final class TemplateFolderSetMethod implements JmapMethod
{
    use TemplateMethodSupport;

    public function __construct(
        private readonly TemplateFolderRepository $folders,
        private readonly TemplateLibrary          $library,
        private readonly TemplateMapper           $mapper,
        private readonly EntityManagerInterface   $em,
    ) {
    }

    public function name(): string
    {
        return 'TemplateFolder/set';
    }

    public function handle(array $arguments, JmapContext $context): array
    {
        $this->refuseAccountId($arguments, 'TemplateFolder');

        $oldState  = $this->state($context);
        $ifInState = $arguments['ifInState'] ?? null;

        if (null !== $ifInState && $ifInState !== $oldState) {
            throw new MethodException('stateMismatch', 'The template folders have changed since ifInState was issued.');
        }

        $created = $notCreated = $updated = $notUpdated = $notDestroyed = [];
        $destroyed = [];

        foreach ($this->objects($arguments['create'] ?? null, 'create') as $creationId => $properties) {
            $creationId = (string) $creationId;

            try {
                $folder = $this->create($properties, $context);
            } catch (MethodException $exception) {
                $notCreated[$creationId] = $exception->toError();

                continue;
            }

            // The id is the response, and what "#creationId" resolves to — a
            // folder and the template that goes into it arrive in one request.
            $this->em->flush();

            $context->recordCreatedId($creationId, (string) $folder->id);
            $created[$creationId] = $this->mapper->folder($folder);
        }

        foreach ($this->objects($arguments['update'] ?? null, 'update') as $id => $patch) {
            $id     = (string) $id;
            $folder = $this->owned($context->resolveId($id) ?? $id, $context);

            if (null === $folder) {
                $notUpdated[$id] = ['type' => 'notFound', 'description' => 'No such TemplateFolder.'];

                continue;
            }

            try {
                if (false === is_array($patch)) {
                    throw new MethodException('invalidProperties', 'Each update must be an object.');
                }

                $this->rejectUnsupported($patch, ['name']);

                $name = $this->line($patch['name'] ?? '');

                if ('' === $name) {
                    throw new MethodException('invalidProperties', 'A folder needs a "name".');
                }
            } catch (MethodException $exception) {
                $notUpdated[$id] = $exception->toError();

                continue;
            }

            $folder->name = $name;
            $updated[$id] = $name === $patch['name'] ? null : ['name' => $name];
        }

        $destroy = $arguments['destroy'] ?? [];

        if (false === is_array($destroy)) {
            throw new MethodException('invalidArguments', '"destroy" must be an array of ids.');
        }

        foreach ($destroy as $id) {
            $id     = (string) $id;
            $folder = $this->owned($real = $context->resolveId($id) ?? $id, $context);

            if (null === $folder) {
                // Including a folder that an earlier id in this same list took
                // with it: it is gone, which is what was asked.
                $notDestroyed[$id] = ['type' => 'notFound', 'description' => 'No such TemplateFolder.'];

                continue;
            }

            $this->library->deleteFolder($folder);
            $this->em->flush();

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

    private function create(mixed $properties, JmapContext $context): TemplateFolder
    {
        if (false === is_array($properties)) {
            throw new MethodException('invalidProperties', 'Each create must be an object.');
        }

        $this->rejectUnsupported($properties, ['name', 'accountId', 'parentId']);

        $name = $this->line($properties['name'] ?? '');

        if ('' === $name) {
            throw new MethodException('invalidProperties', 'A folder needs a "name".');
        }

        $parentId  = $properties['parentId'] ?? null;
        $accountId = $properties['accountId'] ?? null;

        if (null !== $parentId) {
            $parentId = $context->resolveId((string) $parentId) ?? (string) $parentId;
            $location = $this->library->locate($context->user, sprintf('folder:%s', $parentId));

            if (null === $location) {
                throw new MethodException('invalidProperties', sprintf('No TemplateFolder "%s".', $parentId));
            }

            if (true === array_key_exists('accountId', $properties)
                && (null === $accountId ? null : (string) $accountId) !== (null === $location->account ? null : (string) $location->account->id)) {
                throw new MethodException(
                    'invalidProperties',
                    '"accountId" does not match the account "parentId" is under. Send the parent alone, or the account it belongs to.',
                );
            }
        } else {
            $location = $this->library->locate($context->user, null === $accountId ? 'root' : sprintf('account:%s', (string) $accountId));

            if (null === $location) {
                throw new MethodException('invalidProperties', sprintf('No account "%s" for this user.', (string) $accountId));
            }
        }

        return $this->library->createFolder($context->user, $name, $location);
    }

    private function owned(string $id, JmapContext $context): ?TemplateFolder
    {
        if (1 !== preg_match('/^\d{1,9}$/', $id)) {
            return null;
        }

        $folder = $this->folders->find((int) $id);

        return null !== $folder && $folder->usr === $context->user ? $folder : null;
    }

    private function state(JmapContext $context): string
    {
        return $this->mapper->state(array_map(
            fn (TemplateFolder $folder): array => $this->mapper->folder($folder),
            $this->folders->findForUser($context->user),
        ));
    }

    /**
     * @return array<array-key, mixed>
     */
    private function objects(mixed $value, string $argument): array
    {
        if (null === $value) {
            return [];
        }

        if (false === is_array($value)) {
            throw new MethodException('invalidArguments', sprintf('"%s" must be an object.', $argument));
        }

        return $value;
    }
}
