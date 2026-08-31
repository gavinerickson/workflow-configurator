<?php

namespace WorkflowConfigurator\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use WorkflowConfigurator\Entity\WorkflowTransition;

/**
 * @extends ServiceEntityRepository<WorkflowTransition>
 */
class WorkflowTransitionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WorkflowTransition::class);
    }
}
