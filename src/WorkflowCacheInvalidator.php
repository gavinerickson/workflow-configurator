<?php

namespace WorkflowConfigurator;

use WorkflowConfigurator\Entity\WorkflowDefinition;
use WorkflowConfigurator\Entity\WorkflowPlace;
use WorkflowConfigurator\Entity\WorkflowTransition;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Events;
use Doctrine\Persistence\Event\LifecycleEventArgs;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Invalidates the registry cache whenever any part of a definition changes,
 * so edits take effect on the next DynamicWorkflowRegistry::get()
 * (specs/DynamicWorkflows.md §3, §5.3).
 */
#[AsDoctrineListener(event: Events::postPersist)]
#[AsDoctrineListener(event: Events::postUpdate)]
#[AsDoctrineListener(event: Events::postRemove)]
class WorkflowCacheInvalidator
{
    public function __construct(
        private readonly CacheInterface $cache,
        private readonly DynamicWorkflowRegistry $registry,
    ) {
    }

    /**
     * @param LifecycleEventArgs<\Doctrine\Persistence\ObjectManager> $args
     */
    public function postPersist(LifecycleEventArgs $args): void
    {
        $this->invalidate($args->getObject());
    }

    /**
     * @param LifecycleEventArgs<\Doctrine\Persistence\ObjectManager> $args
     */
    public function postUpdate(LifecycleEventArgs $args): void
    {
        $this->invalidate($args->getObject());
    }

    /**
     * @param LifecycleEventArgs<\Doctrine\Persistence\ObjectManager> $args
     */
    public function postRemove(LifecycleEventArgs $args): void
    {
        $this->invalidate($args->getObject());
    }

    private function invalidate(object $entity): void
    {
        $definition = match (true) {
            $entity instanceof WorkflowDefinition => $entity,
            $entity instanceof WorkflowPlace, $entity instanceof WorkflowTransition => $entity->getDefinition(),
            default => null,
        };

        if (null !== $definition) {
            $this->cache->delete(DynamicWorkflowRegistry::CACHE_KEY_PREFIX.$definition->getName());
            $this->registry->forget($definition->getName());
        }
    }
}
