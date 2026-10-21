<?php

declare(strict_types=1);

namespace App\Jmap\Protocol;

use App\Entity\User\ApiToken;
use App\Entity\User\User;
use App\Jmap\Method\MethodRegistry;
use App\Jmap\Protocol\Exception\MethodException;
use App\Jmap\Protocol\Exception\UnknownCapabilityException;
use App\Jmap\State\StateManager;

/**
 * The heart of the server: runs each method call in order, resolving
 * back-references against earlier results, and assembles the response
 * envelope. A failing method call yields an inline error and does not
 * abort the remaining calls.
 */
final class JmapProcessor
{
    public function __construct(
        private readonly MethodRegistry $registry,
        private readonly ReferenceResolver $referenceResolver,
        private readonly StateManager $stateManager,
    ) {
    }

    public function process(JmapRequest $request, User $user, ?ApiToken $token = null): JmapResponse
    {
        $this->assertCapabilities($request->using);

        $context = new JmapContext($user, $request->createdIds, $token);

        foreach ($request->methodCalls as $invocation) {
            $this->processInvocation($invocation, $context);
        }

        return new JmapResponse(
            $context->responses(),
            $this->stateManager->sessionState($user),
            $context->createdIds(),
        );
    }

    private function processInvocation(Invocation $invocation, JmapContext $context): void
    {
        $method = $this->registry->get($invocation->name);

        if (null === $method) {
            $context->addResponse($invocation->toError('unknownMethod'));

            return;
        }

        if (false === $this->permitted($invocation, $context)) {
            // An INLINE error, not a 403. RFC 8620 §3.6.2 has an error type
            // for exactly this, and a client that receives one can report it
            // and carry on with its other calls; a transport-level 403 makes
            // most clients mark the session dead and stop trying, which turns
            // a missing capability into a broken account.
            $context->addResponse($invocation->toError(
                'forbidden',
                [
                    'description' => 'This app password does not grant that capability.',
                ],
            ));

            return;
        }

        try {
            $arguments = $this->referenceResolver->resolve($invocation->arguments, $context);
            $result = $method->handle($arguments, $context);
            $context->addResponse($invocation->toResult($result));

            foreach ($context->drainImplicitResponses() as [$name, $implicit]) {
                $context->addResponse([$name, $implicit, $invocation->callId]);
            }
        } catch (MethodException $exception) {
            $context->drainImplicitResponses();
            $context->addResponse($invocation->toError($exception->errorType, $exception->extra));
        }
    }

    /**
     * Whether the caller's credential allows this method call.
     *
     * The browser session is never limited — it authenticates as the user, and
     * the settings page that mints app passwords lives in it. Only a request
     * that arrived on an app password is checked.
     *
     * Method-level for everything except Email/set, which carries three
     * different powers in one call and is therefore decided per operation; see
     * EmailSetOperations.
     */
    private function permitted(Invocation $invocation, JmapContext $context): bool
    {
        $token = $context->token;

        if (null === $token) {
            return true;
        }

        $scopes = $token->grantedScopes;

        foreach ($scopes as $scope) {
            if (true === in_array($invocation->name, $scope->methods(), true)) {
                return true;
            }
        }

        if ('Email/set' !== $invocation->name) {
            return false;
        }

        // Every operation present in this call must be covered, and an empty
        // arguments object asks for nothing and is allowed. A call carrying
        // both an update and a destroy needs both capabilities: half-applying
        // it would tell the client it succeeded while silently dropping the
        // half it was not allowed to do.
        $operations = $this->emailSetOperations($invocation->arguments);

        if ([] === $operations) {
            return true;
        }

        foreach ($operations as $operation) {
            $covered = false;

            foreach ($scopes as $scope) {
                if (true === in_array($operation, $scope->emailSetOperations(), true)) {
                    $covered = true;

                    break;
                }
            }

            if (false === $covered) {
                return false;
            }
        }

        return true;
    }

    /**
     * Which of create / update / destroy an Email/set call asks for.
     *
     * Read off the raw arguments rather than the resolved ones: a back-
     * reference like `"#ids"` is still a string here, but the KEY it appears
     * under is what decides the operation, and that never needs resolving.
     *
     * @param array<string, mixed> $arguments
     *
     * @return list<string>
     */
    private function emailSetOperations(array $arguments): array
    {
        $operations = [];

        foreach (['create', 'update', 'destroy'] as $operation) {
            $value = $arguments[$operation] ?? null;

            if (true === is_array($value) && [] !== $value) {
                $operations[] = $operation;
            }
        }

        return $operations;
    }

    /**
     * @param list<string> $using
     */
    private function assertCapabilities(array $using): void
    {
        foreach ($using as $capability) {
            if (false === in_array($capability, Capability::SUPPORTED, true)) {
                throw new UnknownCapabilityException(sprintf('Capability "%s" is not supported.', $capability));
            }
        }
    }
}
