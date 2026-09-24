<?php

declare(strict_types=1);

namespace App\Infrastructure\Setup;

use Symfony\Component\Cache\Adapter\DoctrineDbalAdapter;
use Symfony\Component\DependencyInjection\Argument\IteratorArgument;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Hands DatabaseCacheTables every DoctrineDbalAdapter the container defines.
 *
 * Found by class, not injected by pool id. In dev and test the profiler wraps
 * each pool in a TraceableAdapter under the pool's own id and moves the adapter
 * itself to `<id>.recorder_inner`, so `cache.messenger.restart_workers_signal`
 * would hand over a wrapper that has no createTable(). By class, the adapters
 * are found wherever they ended up — which is how DoctrineBundle finds them
 * for the schema listener that puts cache_items into the migrations diff — and
 * a pool added to cache.yaml later is covered without anyone remembering this.
 *
 * Only correct once the profiler has done its wrapping; Kernel::build() is
 * what orders it after that.
 */
final class DatabaseCachePoolsPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $pools = [];

        foreach ($container->getDefinitions() as $id => $definition) {
            if ($definition->isAbstract() || $definition->isSynthetic()) {
                continue;
            }

            if (DoctrineDbalAdapter::class === $definition->getClass()) {
                $pools[] = new Reference($id);
            }
        }

        // Argument 0 is $pools, left abstract in services.yaml so that a
        // container this pass never ran on fails loudly instead of quietly
        // creating nothing.
        $container->getDefinition(DatabaseCacheTables::class)->replaceArgument(0, new IteratorArgument($pools));
    }
}
