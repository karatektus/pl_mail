<?php

declare(strict_types=1);

namespace App\Jmap\Method\Settings;

use App\Domain\Enum\Theme\BackgroundKind;
use App\Domain\Enum\Theme\BackgroundPreset;
use App\Domain\Enum\Theme\Density;
use App\Domain\Enum\Theme\MotionLevel;
use App\Domain\Enum\Theme\FontFamily;
use App\Domain\Enum\Theme\Layout;
use App\Domain\Enum\Theme\Theme;
use App\Domain\Enum\Theme\UnreadEmphasis;
use App\Entity\Embeddable\Appearance;
use App\Jmap\Mapper\AppearanceMapper;
use App\Jmap\Method\JmapMethod;
use App\Jmap\Protocol\Exception\MethodException;
use App\Jmap\Protocol\JmapContext;
use Doctrine\ORM\EntityManagerInterface;

/**
 * "Appearance/set" — plMail extension, `urn:plmail:params:jmap:appearance`.
 *
 * The singleton half of RFC 8620 §5.3: `create` and `destroy` are answered
 * with the spec's own `singleton` SetError, and only `update: {"singleton":
 * …}` does anything. No accountId, for the reason Appearance/get gives.
 *
 * WHAT IS REFUSED AND WHAT IS CLAMPED — the one decision worth reading here.
 * `Appearance`'s setters swallow anything they dislike: a theme name they do
 * not know keeps the old theme, a malformed hex resets the accent to plMail's
 * default, and an out-of-range slider is pulled to the nearest end. That is
 * right for the web pane, which is a closed form that cannot send anything
 * else. It is wrong over the wire, where the client is somebody else's code:
 * a phone that sets `theme: "midnight"` would be told it succeeded and then
 * show the old theme forever, with nothing anywhere saying why. So:
 *
 *  - **Closed vocabularies are refused** with `invalidProperties` naming what
 *    is accepted — themes, layouts, densities, background kinds and presets.
 *    The Mailbox.color precedent, for the same reason.
 *  - **Malformed colours are refused**, since the setter's fallback (the
 *    default accent, or null) is a value the client never asked for.
 *  - **Numeric ranges are clamped, not refused** — 1.4 for an alpha is a
 *    client being sloppy about a continuum, not a client meaning something
 *    this server cannot represent. Nothing is dropped silently: a clamp lands
 *    in the `updated` map (RFC 8620 §5.3 — properties the server changed
 *    beyond what was asked for), so the client sees the number it will get.
 *    The ranges are published in the Session's appearance capability.
 *
 * The patch is validated whole before any of it is applied. A rejected update
 * must leave nothing behind, and these are seventeen properties written onto
 * one embeddable — half-applying it would flush a theme the client was told
 * was refused.
 */
final class AppearanceSetMethod implements JmapMethod
{
    public function __construct(
        private readonly AppearanceMapper $mapper,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function name(): string
    {
        return 'Appearance/set';
    }

    public function handle(array $arguments, JmapContext $context): array
    {
        if (true === array_key_exists('accountId', $arguments) && null !== $arguments['accountId']) {
            throw new MethodException(
                'invalidArguments',
                'Appearance is per user, not per account; "accountId" is not accepted.',
            );
        }

        $appearance = $context->user->appearance;
        $oldState = $this->mapper->state($appearance);
        $ifInState = $arguments['ifInState'] ?? null;

        if (null !== $ifInState && $ifInState !== $oldState) {
            throw new MethodException('stateMismatch', 'The appearance has changed since ifInState was issued.');
        }

        $updated = [];
        $notUpdated = [];

        $notCreated = $this->refuseCreates($arguments['create'] ?? null);
        $this->applyUpdates($appearance, $arguments['update'] ?? null, $updated, $notUpdated);
        $notDestroyed = $this->refuseDestroys($arguments['destroy'] ?? null);

        $this->entityManager->flush();

        return [
            'oldState' => $oldState,
            'newState' => $this->mapper->state($appearance),
            'created' => new \stdClass(),
            'notCreated' => 0 === count($notCreated) ? new \stdClass() : $notCreated,
            'updated' => 0 === count($updated) ? new \stdClass() : $updated,
            'notUpdated' => 0 === count($notUpdated) ? new \stdClass() : $notUpdated,
            'destroyed' => [],
            'notDestroyed' => 0 === count($notDestroyed) ? new \stdClass() : $notDestroyed,
        ];
    }

    /**
     * @param array<string,mixed> $updated
     * @param array<string,mixed> $notUpdated
     */
    private function applyUpdates(
        Appearance $appearance,
        mixed $update,
        array &$updated,
        array &$notUpdated,
    ): void {
        if (null === $update) {
            return;
        }

        if (false === is_array($update)) {
            throw new MethodException('invalidArguments', '"update" must be an object.');
        }

        foreach ($update as $id => $patch) {
            $id = (string) $id;

            if (AppearanceMapper::SINGLETON_ID !== $id) {
                $notUpdated[$id] = [
                    'type' => 'notFound',
                    'description' => sprintf('Appearance has one object, id "%s".', AppearanceMapper::SINGLETON_ID),
                ];
                continue;
            }

            if (false === is_array($patch)) {
                $notUpdated[$id] = ['type' => 'invalidPatch', 'description' => 'Each update must be an object.'];
                continue;
            }

            /** @var array<string,mixed> $patch */
            $before = $this->mapper->toJmap($appearance);

            try {
                $this->validate($appearance, $patch);
            } catch (MethodException $exception) {
                $notUpdated[$id] = $exception->toError();
                continue;
            }

            $this->apply($appearance, $patch);

            $updated[$id] = $this->serverSideChanges($patch, $before, $this->mapper->toJmap($appearance));
        }
    }

    /**
     * Validates the whole patch, mutating nothing.
     *
     * @param array<string,mixed> $patch
     */
    private function validate(Appearance $appearance, array $patch): void
    {
        $current = $this->mapper->toJmap($appearance);

        foreach ($patch as $property => $value) {
            $property = (string) $property;

            // `id` and `backgroundFile` are server-set. Echoing the current
            // value back is allowed, because get → edit one field → set is how
            // a client is *supposed* to work and refusing the untouched
            // remainder of its own read would make that impossible; changing
            // either is refused. backgroundFile is uploaded through the web
            // settings pane and served from behind the session firewall, so a
            // JMAP client cannot produce one — see AppearanceMapper.
            if ('id' === $property || 'backgroundFile' === $property) {
                if (($current[$property] ?? null) === $value) {
                    continue;
                }

                throw new MethodException('invalidProperties', sprintf(
                    '"%s" is not settable over JMAP.',
                    $property,
                ));
            }

            if (false === in_array($property, AppearanceMapper::PROPERTIES, true)) {
                throw new MethodException('invalidProperties', sprintf(
                    '"%s" is not an Appearance property. Use one of: %s.',
                    $property,
                    implode(', ', AppearanceMapper::PROPERTIES),
                ));
            }

            match ($property) {
                'theme' => $this->requireEnum(Theme::class, $value, $property, false),
                'layout' => $this->requireEnum(Layout::class, $value, $property, false),
                'density' => $this->requireEnum(Density::class, $value, $property, false),
                'motion' => $this->requireEnum(MotionLevel::class, $value, $property, false),
                'backgroundKind' => $this->requireEnum(BackgroundKind::class, $value, $property, false),
                'backgroundPreset' => $this->requireEnum(BackgroundPreset::class, $value, $property, true),
                'accent' => $this->requireHex($value, $property, false),
                'backgroundSolid', 'inkColor', 'inkMuted', 'inkFaint', 'mainTint' => $this->requireHex($value, $property, true),
                'paneBlur', 'previewLines' => $this->requireInt($value, $property),
                'paneAlpha', 'radius', 'scrimAlpha', 'fontScale' => $this->requireNumber($value, $property, false),
                'mainAlpha' => $this->requireNumber($value, $property, true),
                'unreadEmphasis' => $this->requireEnum(UnreadEmphasis::class, $value, $property, false),
                'fontFamily' => $this->requireEnum(FontFamily::class, $value, $property, false),
                // Nullable: null is the value meaning "follow the global
                // density", not an omission — see AppearanceMapper.
                'sidebarDensity', 'listDensity', 'readingDensity' => $this->requireEnum(Density::class, $value, $property, true),
                'accountCorner', 'listAvatars' => $this->requireBool($value, $property),
                default => null,
            };
        }
    }

    /**
     * @param array<string,mixed> $patch
     */
    private function apply(Appearance $appearance, array $patch): void
    {
        // Layout first, and through applyLayout() rather than a plain
        // assignment: picking a layout *means* its knob preset (see the Layout
        // enum — the web pane seeds the same values client-side so its sliders
        // stay in step). A client that sends only `layout` therefore gets the
        // look it asked for rather than the new structure wearing the old
        // layout's numbers. Explicit knobs in the same patch are applied after
        // it by applyArray() and win, which is why this cannot simply be
        // folded into that call.
        if (true === array_key_exists('layout', $patch)) {
            $layout = Layout::tryFrom((string) $patch['layout']);

            if (null !== $layout) {
                $appearance->applyLayout($layout);
            }
        }

        // Everything else goes through the entity's own applier, so JMAP and
        // the web settings pane write appearance the same way. It ignores what
        // it does not recognise, which is safe only because validate() has
        // already refused all of it.
        $appearance->applyArray($patch);
    }

    /**
     * Properties the server changed beyond what the patch asked for — a
     * clamped slider, or the knobs a layout seeded. RFC 8620 §5.3 requires
     * these in the `updated` map; null means "exactly what you sent".
     *
     * @param array<string,mixed> $patch
     * @param array<string,mixed> $before
     * @param array<string,mixed> $after
     *
     * @return array<string,mixed>|null
     */
    private function serverSideChanges(array $patch, array $before, array $after): ?array
    {
        $changes = [];

        foreach ($after as $property => $value) {
            // A property the patch named: report it when what is stored is not
            // what was asked for. Comparing against the *previous* value
            // instead would miss the case that matters most — a slider clamped
            // back to the value it already had reads as "no change" while the
            // client is holding the number it sent.
            if (true === array_key_exists($property, $patch)) {
                if (false === $this->sameValue($patch[$property], $value)) {
                    $changes[$property] = $value;
                }

                continue;
            }

            // A property the patch did not name: the knobs a layout seeded.
            if (false === $this->sameValue($before[$property] ?? null, $value)) {
                $changes[$property] = $value;
            }
        }

        return 0 === count($changes) ? null : $changes;
    }

    /**
     * Whether a value the client sent and a value now stored are the same
     * thing. JSON gives no way to say "1.0 rather than 1", and a hex colour is
     * stored lowercased, so a strict comparison would report both as changes
     * the server made — noise in exactly the field that exists to carry
     * signal.
     */
    private function sameValue(mixed $sent, mixed $stored): bool
    {
        if ((true === is_int($sent) || true === is_float($sent)) && (true === is_int($stored) || true === is_float($stored))) {
            return abs((float) $sent - (float) $stored) < 0.000001;
        }

        if (true === is_string($sent) && true === is_string($stored)) {
            return 0 === strcasecmp($sent, $stored);
        }

        return $sent === $stored;
    }

    /**
     * @template T of \BackedEnum
     *
     * @param class-string<T> $enum
     */
    private function requireEnum(string $enum, mixed $value, string $property, bool $nullable): void
    {
        if (null === $value && true === $nullable) {
            return;
        }

        if (true === is_string($value) && null !== $enum::tryFrom($value)) {
            return;
        }

        throw new MethodException('invalidProperties', sprintf(
            '"%s" is not a known %s. Use one of: %s%s.',
            true === is_scalar($value) ? (string) $value : gettype($value),
            $property,
            implode(', ', array_column($enum::cases(), 'value')),
            true === $nullable ? ', or null for none' : '',
        ));
    }

    private function requireHex(mixed $value, string $property, bool $nullable): void
    {
        if (null === $value && true === $nullable) {
            return;
        }

        if (true === is_string($value) && 1 === preg_match('/^#[0-9a-fA-F]{6}$/', $value)) {
            return;
        }

        throw new MethodException('invalidProperties', sprintf(
            '"%s" must be a six-digit hex colour like "#7d6b4f"%s.',
            $property,
            true === $nullable ? ', or null for none' : '',
        ));
    }

    /**
     * A real JSON boolean, not "1" and not 1.
     *
     * The web pane posts these as the strings its DOM nodes hold and
     * Appearance::applyArray() accepts that spelling — but over the wire a
     * client sending "0" is far more likely to mean false and be silently
     * given true, so the loose spelling is refused here for the same reason
     * an unknown theme name is. Same argument as the closed vocabularies.
     */
    private function requireBool(mixed $value, string $property): void
    {
        if (true === is_bool($value)) {
            return;
        }

        throw new MethodException('invalidProperties', sprintf('"%s" must be true or false.', $property));
    }

    private function requireInt(mixed $value, string $property): void
    {
        if (true === is_int($value)) {
            return;
        }

        throw new MethodException('invalidProperties', sprintf('"%s" must be a whole number.', $property));
    }

    private function requireNumber(mixed $value, string $property, bool $nullable): void
    {
        if (null === $value && true === $nullable) {
            return;
        }

        if (true === is_int($value) || true === is_float($value)) {
            return;
        }

        throw new MethodException('invalidProperties', sprintf(
            '"%s" must be a number%s.',
            $property,
            true === $nullable ? ', or null for none' : '',
        ));
    }

    /**
     * @return array<string,mixed>
     */
    private function refuseCreates(mixed $create): array
    {
        if (null === $create) {
            return [];
        }

        if (false === is_array($create)) {
            throw new MethodException('invalidArguments', '"create" must be an object.');
        }

        $notCreated = [];

        foreach (array_keys($create) as $creationId) {
            $notCreated[(string) $creationId] = [
                'type' => 'singleton',
                'description' => 'A user has exactly one Appearance; update "singleton" instead.',
            ];
        }

        return $notCreated;
    }

    /**
     * @return array<string,mixed>
     */
    private function refuseDestroys(mixed $destroy): array
    {
        if (null === $destroy) {
            return [];
        }

        if (false === is_array($destroy)) {
            throw new MethodException('invalidArguments', '"destroy" must be an array of ids.');
        }

        $notDestroyed = [];

        foreach ($destroy as $id) {
            $notDestroyed[(string) $id] = [
                'type' => 'singleton',
                'description' => 'Appearance cannot be destroyed; reset it by updating "singleton".',
            ];
        }

        return $notDestroyed;
    }
}
