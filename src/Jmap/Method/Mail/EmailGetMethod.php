<?php

declare(strict_types=1);

namespace App\Jmap\Method\Mail;

use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Jmap\Account\AccountResolver;
use App\Jmap\Mail\IdentityResolver;
use App\Jmap\Mapper\EmailMapper;
use App\Jmap\Method\JmapMethod;
use App\Jmap\Protocol\Exception\MethodException;
use App\Jmap\Protocol\JmapContext;
use App\Jmap\State\JmapObjectType;
use App\Jmap\State\StateManager;
use App\Repository\Label\LabelBindingRepository;
use App\Repository\Mail\MessageRepository;
use App\Service\Mail\ReplyFromResolver;

/**
 * "Email/get" (RFC 8621 §4.2).
 *
 * Unlike Mailbox/get, ids is REQUIRED: an account's full mail is unbounded, so
 * the spec has clients reach Emails through Email/query. A null ids is
 * therefore requestTooLarge rather than "return everything".
 *
 * ONE PROPERTY THAT IS NOT IN RFC 8621: `replyFrom`
 *
 * The Identity a reply to this Email — or a forward of it — should be sent
 * as: the address the mail came in for. It is the answer the web composer
 * opens a reply on (ReplyDraftBuilder), from the same ReplyFromResolver, so a
 * phone and the browser pick the same sender for the same mail and neither has
 * a second copy of the rule. Null when the mail names none of the account's
 * addresses, and the client stays on the primary identity.
 *
 * The value is a whole Identity object, as Identity/get would give it. For an
 * alias it is the one in that list, same id. For an address at a catch-all
 * domain it is one that list cannot contain — see IdentityResolver — and the
 * object is then the only place the client learns its id, name and signature.
 * Its id goes into EmailSubmission/set as any other identityId does.
 *
 * On a draft it is the address the draft is already written from (the
 * resolver looks at From first when the mail is the user's own), which is what
 * a client reopening the draft should put in its picker.
 *
 * **Only when asked for by name.** It is left out of a get with no
 * `properties`, unlike every other property here: it carries a signature,
 * which can run to hundreds of kilobytes with images embedded, and a client
 * written before it existed must not start paying that per message for
 * something it does not read. A server that predates the property ignores the
 * name, as it ignores any it does not know, so a client asks unconditionally
 * and treats absent as null.
 */
final class EmailGetMethod implements JmapMethod
{
    /**
     * Ceiling on ids per call, matching maxObjectsInGet in the Session object.
     */
    private const int MAX_OBJECTS = 500;

    /** The property name; see the class docblock. */
    private const string REPLY_FROM = 'replyFrom';

    public function __construct(
        private readonly AccountResolver $accountResolver,
        private readonly MessageRepository $messageRepository,
        private readonly EmailMapper $mapper,
        private readonly StateManager $stateManager,
        private readonly LabelBindingRepository $bindingRepository,
        private readonly IdentityResolver $identityResolver,
        private readonly ReplyFromResolver $replyFrom = new ReplyFromResolver(),
    ) {
    }

    public function name(): string
    {
        return 'Email/get';
    }

    public function handle(array $arguments, JmapContext $context): array
    {
        $account = $this->accountResolver->resolve($context->user, $arguments['accountId'] ?? null);
        $accountId = $account->id;

        $requestedIds = $arguments['ids'] ?? null;

        if (null === $requestedIds) {
            throw new MethodException('requestTooLarge', '"ids" is required for Email/get; use Email/query to select them.');
        }

        if (false === is_array($requestedIds)) {
            throw new MethodException('invalidArguments', '"ids" must be an array.');
        }

        if (count($requestedIds) > self::MAX_OBJECTS) {
            throw new MethodException('requestTooLarge', sprintf('At most %d ids per Email/get.', self::MAX_OBJECTS));
        }

        $properties = $arguments['properties'] ?? null;

        if (null !== $properties && false === is_array($properties)) {
            throw new MethodException('invalidArguments', '"properties" must be an array or null.');
        }

        $bodyProperties = $arguments['bodyProperties'] ?? null;

        if (null !== $bodyProperties && false === is_array($bodyProperties)) {
            throw new MethodException('invalidArguments', '"bodyProperties" must be an array or null.');
        }

        // "#creationId" entries refer to Emails created earlier in this same
        // request; an unknown one stays as-is and falls out as notFound.
        $requestedIds = array_values(array_map(
            static fn (mixed $id): string => $context->resolveId((string) $id) ?? (string) $id,
            $requestedIds,
        ));

        $messages = $this->messageRepository->findByAccountAndIds(
            $accountId,
            array_map('intval', $requestedIds),
        );

        // mailboxIds is emitted in the binding id space, and a message may
        // carry any of the account's labels — so the map is resolved once for
        // the whole account rather than per message.
        $bindingIdByLabelId = $this->bindingRepository->bindingIdsByLabelId($accountId);

        $withReplyFrom = null !== $properties && true === in_array(self::REPLY_FROM, $properties, true);

        $list = [];
        $found = [];

        foreach ($messages as $message) {
            $found[] = (string) $message->id;
            $email = $this->mapper->toJmap(
                $message,
                $properties,
                true === ($arguments['fetchTextBodyValues'] ?? false),
                true === ($arguments['fetchHTMLBodyValues'] ?? false),
                $bodyProperties,
                $bindingIdByLabelId,
            );

            if (true === $withReplyFrom) {
                $email[self::REPLY_FROM] = $this->replyFromFor($message, $account);
            }

            $list[] = $email;
        }

        return [
            'accountId' => (string) $accountId,
            'state' => $this->stateManager->stateFor($accountId, JmapObjectType::Email),
            'list' => $list,
            'notFound' => array_values(array_diff($requestedIds, $found)),
        ];
    }

    /**
     * Two questions, each asked of the class that owns it: which address
     * answers this mail (ReplyFromResolver), and what identity that address is
     * (IdentityResolver). Added here rather than in EmailMapper because the
     * mapper turns one row into one object and knows no account; this needs
     * the account's aliases, its catch-all domains and its signatures.
     *
     * @return array<string,mixed>|null
     */
    private function replyFromFor(Message $message, Account $account): ?array
    {
        $address = $this->replyFrom->addressFor($message, $account);

        if (null === $address) {
            return null;
        }

        return $this->identityResolver->forAddress($account, $address);
    }
}
