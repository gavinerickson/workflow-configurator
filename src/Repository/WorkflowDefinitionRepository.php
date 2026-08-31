<?php

namespace WorkflowConfigurator\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use WorkflowConfigurator\Entity\WorkflowDefinition;

/**
 * @extends ServiceEntityRepository<WorkflowDefinition>
 */
class WorkflowDefinitionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WorkflowDefinition::class);
    }

    public function findOneEnabledByName(string $name): ?WorkflowDefinition
    {
        return $this->findOneBy(['name' => $name, 'enabled' => true]);
    }
}
