<?php

namespace WorkflowConfigurator\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use WorkflowConfigurator\Entity\WorkflowPlace;

/**
 * @extends ServiceEntityRepository<WorkflowPlace>
 */
class WorkflowPlaceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WorkflowPlace::class);
    }
}
