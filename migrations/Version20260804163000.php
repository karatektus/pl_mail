<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A mirrored calendar gains the four things a push registration is made of, so
 * a change at Google or Microsoft can arrive instead of being waited for.
 *
 * The registration is per calendar and not per account, which is what makes
 * these columns land here rather than beside Account's graph_subscription_id.
 * Graph subscribes to `me/calendars/{id}/events` and Google watches one
 * calendar's events, so a Microsoft mailbox mirroring six calendars holds six
 * subscriptions, six secrets and six expiries. There is nowhere on the account
 * to put six of anything.
 *
 * Four columns rather than a key in the existing `settings` jsonb, and the
 * reason is the index below rather than tidiness. push_channel_id is what two
 * unauthenticated, internet-facing endpoints look a notification up by; that
 * lookup has to be exact, indexed and provably single-valued, and none of those
 * is something to build on a document whose readers assume their own defaults.
 * push_secret is compared with hash_equals against the value the provider
 * echoes back, and is the only thing standing between those endpoints and
 * anybody who can guess a channel id.
 *
 * push_resource_id exists because Google's channels/stop takes the pair
 * (id, resourceId) and the resourceId is only ever seen in the answer to the
 * watch call. Without it a channel can be opened and never closed — it goes on
 * delivering for the whole week it was registered for. Microsoft cancels by
 * subscription id alone and leaves this null, which is the one asymmetry
 * between the two providers that survives into the schema.
 *
 * push_expires_at stores what the provider granted, not what plMail asked for.
 * Both are free to answer with less, and a renewal driven off a local constant
 * instead is a channel that dies quietly a day before anything tries to replace
 * it.
 *
 * The unique index is the invariant made structural. Both webhooks resolve a
 * notification to exactly one calendar by push_channel_id alone; two rows
 * carrying the same id would make a notification ambiguous, and the endpoint
 * would sync the wrong calendar or sync one on somebody else's secret. Postgres
 * allows any number of NULLs in a unique index, which is what keeps it usable
 * on the column's ordinary state — every calendar that is polled, which is all
 * of them on an install with no public HTTPS address.
 *
 * Nullable, with no backfill and nothing to backfill: an existing calendar has
 * no channel, and the hourly sweep opens one where it can. Reversible, and
 * losing only registrations the sweep makes again.
 */
final class Version20260804163000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Give a mirrored calendar somewhere to keep its push registration';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE calendar ADD push_channel_id VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE calendar ADD push_resource_id VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE calendar ADD push_secret VARCHAR(128) DEFAULT NULL');
        $this->addSql('ALTER TABLE calendar ADD push_expires_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_calendar_push_channel_id ON calendar (push_channel_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_calendar_push_channel_id');
        $this->addSql('ALTER TABLE calendar DROP push_channel_id');
        $this->addSql('ALTER TABLE calendar DROP push_resource_id');
        $this->addSql('ALTER TABLE calendar DROP push_secret');
        $this->addSql('ALTER TABLE calendar DROP push_expires_at');
    }
}
