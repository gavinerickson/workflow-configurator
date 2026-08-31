<?php

namespace WorkflowConfigurator;

use WorkflowConfigurator\Entity\WorkflowPlace;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Events;
use Doctrine\Persistence\Event\LifecycleEventArgs;

/**
 * Blocks deletion of a place any subject currently occupies
 * (specs/DynamicWorkflows.md §5.3, §6.2 rule 6). Deletes bypass validation,
 * so this is enforced at flush time.
 */
#[AsDoctrineListener(event: Events::preRemove)]
class OccupiedPlaceRemovalListener
{
    public function __construct(private readonly PlaceOccupancyCheckerInterface $occupancyChecker)
    {
    }

    /**
     * @param LifecycleEventArgs<\Doctrine\Persistence\ObjectManager> $args
     */
    public function preRemove(LifecycleEventArgs $args): void
    {
        $entity = $args->getObject();
        if (!$entity instanceof WorkflowPlace || null === $entity->getDefinition()) {
            return;
        }

        $count = $this->occupancyChecker->countSubjectsIn($entity->getDefinition()->getName(), $entity->getName());
        if ($count > 0) {
            throw new OccupiedPlaceException(\sprintf('Cannot delete place "%s" from workflow "%s": %d document(s) currently occupy it.', $entity->getName(), $entity->getDefinition()->getName(), $count));
        }
    }
}
