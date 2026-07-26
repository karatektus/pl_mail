<?php

declare(strict_types=1);

namespace App\Jmap\Protocol;

use App\Entity\User;
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

    public function process(JmapRequest $request, User $user): JmapResponse
    {
        $this->assertCapabilities($request->using);

        $context = new JmapContext($user, $request->createdIds);

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

        try {
            $arguments = $this->referenceResolver->resolve($invocation->arguments, $context);
            $result = $method->handle($arguments, $context);
            $context->addResponse($invocation->toResult($result));
        } catch (MethodException $exception) {
            $context->addResponse($invocation->toError($exception->errorType, $exception->extra));
        }
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
