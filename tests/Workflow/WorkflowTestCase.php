<?php

namespace WorkflowConfigurator\Tests\Workflow;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use WorkflowConfigurator\Entity\WorkflowDefinition;
use WorkflowConfigurator\Entity\WorkflowPlace;
use WorkflowConfigurator\Entity\WorkflowTransition;
use WorkflowConfigurator\Tests\Fixtures\CountingOccupancyChecker;
use WorkflowConfigurator\WorkflowType;

abstract class WorkflowTestCase extends KernelTestCase
{
    private static bool $schemaInitialized = false;

    protected EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);

        // Drop and recreate the sqlite schema once per phpunit process.
        if (!self::$schemaInitialized) {
            $schemaTool = new SchemaTool($this->entityManager);
            $metadata = $this->entityManager->getMetadataFactory()->getAllMetadata();
            $schemaTool->dropSchema($metadata);
            $schemaTool->createSchema($metadata);
            self::$schemaInitialized = true;
        }

        CountingOccupancyChecker::reset();
    }

    /** A definition's place by name; fails the test when absent. */
    protected static function place(WorkflowDefinition $definition, string $name): WorkflowPlace
    {
        $place = $definition->getPlaces()->findFirst(static fn ($i, WorkflowPlace $p) => $p->getName() === $name);
        self::assertNotNull($place, \sprintf('Definition "%s" has no place "%s".', $definition->getName(), $name));

        return $place;
    }

    /**
     * The reference graph: received --stamp--> stamped, plus an optional
     * guard/task on the transition.
     */
    protected function createDefinition(
        string $name,
        bool $enabled = true,
        ?string $guard = null,
        ?string $task = null,
        WorkflowType $type = WorkflowType::StateMachine,
    ): WorkflowDefinition {
        $definition = new WorkflowDefinition()
            ->setName($name)
            ->setLabel(ucfirst($name))
            ->setType($type);

        $received = new WorkflowPlace()->setName('received')->setLabel('Received');
        $stamped = new WorkflowPlace()->setName('stamped')->setLabel('Stamped');
        $definition->addPlace($received)->addPlace($stamped);
        $definition->setInitialPlace($received);

        $stamp = new WorkflowTransition()
            ->setName('stamp')
            ->addFrom($received)
            ->addTo($stamped)
            ->setGuard($guard);
        if (null !== $task) {
            $stamp->setTask($task);
        }
        $definition->addTransition($stamp);

        $definition->setEnabled($enabled);

        $this->entityManager->persist($definition);
        $this->entityManager->flush();

        return $definition;
    }
}
