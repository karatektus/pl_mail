<?php

declare(strict_types=1);

namespace App\Jmap\Method\Template;

use App\Jmap\Account\AccountResolver;
use App\Jmap\Mail\IdentityResolver;
use App\Jmap\Method\JmapMethod;
use App\Jmap\Protocol\Exception\MethodException;
use App\Jmap\Protocol\JmapContext;
use App\Repository\Template\MailTemplateRepository;
use App\Service\Mail\SignatureProvider;
use App\Service\Template\TemplateRecipientFiller;
use App\Service\Template\TemplateRenderer;

/**
 * "Template/render" — plMail extension, `urn:plmail:params:jmap:templates`.
 *
 * One template turned into what a composer inserts. This is the method a
 * client calls when the user picks a template; Template/get's `htmlBody` is
 * the stored form and still has its variables in it.
 *
 * WHAT THE CLIENT SAYS
 *
 *   id          the template
 *   accountId   REQUIRED here, unlike on get and set: the mail account the
 *               message is being written from. It decides the sender
 *               variables and the automatic signature.
 *   identityId  optional — the Identity in From, when it is not the account's
 *               own. It is what tells an alias's signature from its account's.
 *   recipient   optional — { "name": …, "email": … } of the first To address.
 *
 * WHAT COMES BACK
 *
 *   subject        to fill an EMPTY subject line with, never to overwrite one
 *   htmlBody       the body, values filled in
 *   textBody       the same as text, for a plain-text composer
 *   openVariables  the recipient variables nobody could fill
 *
 * The server fills dates, sender and signature, as it does for the web. The
 * recipient is filled here too when one is given — see
 * TemplateRecipientFiller for why that differs from the web, which fills it in
 * the browser. What cannot be answered (no recipient yet; a recipient with an
 * address and no name) is listed in `openVariables` and left in the bodies: in
 * `htmlBody` as `<span data-pl-var="recipient.first_name">First name</span>`,
 * in `subject` and `textBody` as `{{recipient.first_name}}`.
 *
 * A client has two honest ways to treat those. Render again once the recipient
 * is known, if the user has not edited the inserted text; or replace the
 * markers itself and ask before sending while any remain, as the web does.
 * What it must not do is send them unseen.
 *
 * Nothing is written, and nothing is rendered that the caller could not read
 * already: their own template, their own signature, their own clock.
 */
final class TemplateRenderMethod implements JmapMethod
{
    public function __construct(
        private readonly MailTemplateRepository  $templates,
        private readonly AccountResolver         $accounts,
        private readonly IdentityResolver        $identities,
        private readonly TemplateRenderer        $renderer,
        private readonly TemplateRecipientFiller $filler,
        private readonly SignatureProvider       $signatures,
    ) {
    }

    public function name(): string
    {
        return 'Template/render';
    }

    public function handle(array $arguments, JmapContext $context): array
    {
        $account = $this->accounts->resolve($context->user, $arguments['accountId'] ?? null);

        $id       = $context->resolveId((string) ($arguments['id'] ?? '')) ?? (string) ($arguments['id'] ?? '');
        $template = 1 === preg_match('/^\d{1,9}$/', $id) ? $this->templates->find((int) $id) : null;

        if (null === $template || $template->usr !== $context->user) {
            throw new MethodException('invalidArguments', sprintf('No Template "%s".', $id));
        }

        $address = null;

        if (null !== ($arguments['identityId'] ?? null)) {
            $address = $this->identities->addressFor($account, (string) $arguments['identityId']);

            if (null === $address) {
                throw new MethodException('invalidArguments', sprintf(
                    'No Identity "%s" in account "%s".',
                    (string) $arguments['identityId'],
                    (string) $account->id,
                ));
            }
        }

        $recipient = $arguments['recipient'] ?? null;

        if (null !== $recipient && false === is_array($recipient)) {
            throw new MethodException('invalidArguments', '"recipient" must be an object with "name" and "email", or null.');
        }

        $rendered = $this->filler->fill(
            $this->renderer->render($template->subject, $template->body, $account, $address, $context->user),
            is_scalar($recipient['name'] ?? null) ? (string) $recipient['name'] : null,
            is_scalar($recipient['email'] ?? null) ? (string) $recipient['email'] : null,
        );

        return [
            'accountId'     => (string) $account->id,
            'id'            => (string) $template->id,
            'subject'       => $rendered->subject,
            'htmlBody'      => $rendered->html,
            'textBody'      => $this->signatures->toText($this->filler->markersAsTokens($rendered->html)),
            'openVariables' => $this->filler->open($rendered),
        ];
    }
}
