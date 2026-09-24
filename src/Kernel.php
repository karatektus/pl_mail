<?php

namespace App;

use App\Infrastructure\Doctrine\Type\EncryptedStringType;
use App\Infrastructure\Encryption\Encryptor;
use App\Infrastructure\Setup\DatabaseCachePoolsPass;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    /**
     * Late on purpose: DatabaseCachePoolsPass has to see the cache pools after
     * the profiler has wrapped them (before-removing, priority 0), or it would
     * take a wrapper for an adapter. -10 is the slot DoctrineBundle's own pass
     * over the same adapters takes, for the same reason.
     */
    protected function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new DatabaseCachePoolsPass(), PassConfig::TYPE_BEFORE_REMOVING, -10);
    }

    /**
     * Doctrine builds its types through a static registry with no access to
     * the container, so EncryptedStringType is handed its Encryptor here —
     * after boot, when the container exists, and before any entity is loaded.
     *
     * The has() guard covers booting against a compiled container that
     * predates this service, which is what a deployment looks like in the
     * moment before cache:clear rebuilds it. Skipping is safe rather than
     * silently insecure: the type refuses to convert a value without an
     * encryptor, so credentials can never fall back to plain text.
     */
    public function boot(): void
    {
        parent::boot();

        if ($this->container->has(Encryptor::class)) {
            EncryptedStringType::setEncryptor($this->container->get(Encryptor::class));
        }
    }

    /**
     * @return list<string> An array of allowed values for APP_ENV
     */
    private function getAllowedEnvs(): array
    {
        return ['prod', 'dev', 'test'];
    }
}
