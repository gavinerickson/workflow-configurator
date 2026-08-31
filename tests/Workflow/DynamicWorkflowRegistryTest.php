<?php

namespace WorkflowConfigurator\Tests\Workflow;

use WorkflowConfigurator\Tests\Fixtures\TestSubject;
use WorkflowConfigurator\DynamicWorkflowRegistry;
use WorkflowConfigurator\WorkflowNotFoundException;
use Doctrine\DBAL\Connection;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Workflow\Event\Event;

/**
 * specs/DynamicWorkflows.md §7 criteria 1, 2, 3, 8.
 */
class DynamicWorkflowRegistryTest extends WorkflowTestCase
{
    private DynamicWorkflowRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registry = self::getContainer()->get(DynamicWorkflowRegistry::class);
    }

    public function testOperatorConfiguredWorkflowRunsWithoutYaml(): void
    {
        $this->createDefinition('crit1');
        $subject = new TestSubject('crit1');

        $workflow = $this->registry->get($subject);

        self::assertTrue($workflow->can($subject, 'stamp'));
        $workflow->apply($subject, 'stamp');
        self::assertSame('stamped', $subject->getMarking());
    }

    public function testMissingWorkflowThrows(): void
    {
        $this->expectException(WorkflowNotFoundException::class);
        $this->registry->getByName('does-not-exist');
    }

    public function testDisabledWorkflowThrows(): void
    {
        $this->createDefinition('crit2-disabled', enabled: false);

        $this->expectException(WorkflowNotFoundException::class);
        $this->registry->getByName('crit2-disabled');
    }

    public function testSubjectWithoutWorkflowNameThrows(): void
    {
        $this->expectException(WorkflowNotFoundException::class);
        $this->registry->get(new TestSubject(null));
    }

    public function testWarmCacheSkipsDatabaseAndEditsInvalidate(): void
    {
        $definition = $this->createDefinition('crit3');
        $this->registry->getByName('crit3'); // warm the cache

        // Change the transition name behind the ORM's back: no Doctrine event
        // fires, so the cache must still serve the old graph (criterion 2 —
        // zero definition queries when warm).
        self::getContainer()->get(Connection::class)
            ->executeStatement("UPDATE workflow_transition SET name = 'stamp_v2' WHERE name = 'stamp'");

        $workflow = $this->registry->getByName('crit3');
        self::assertNotNull($workflow->getDefinition()->getTransitions()[0] ?? null);
        self::assertSame('stamp', $workflow->getDefinition()->getTransitions()[0]->getName());

        // An ORM edit fires the invalidation listener (criterion 3): the next
        // get() must reload and now see the renamed transition too. Clear the
        // EntityManager so the reload isn't served from the identity map (a
        // fresh request would have a fresh EntityManager).
        $definition->setLabel('Crit3 edited');
        $this->entityManager->flush();
        $this->entityManager->clear();

        $workflow = $this->registry->getByName('crit3');
        self::assertSame('stamp_v2', $workflow->getDefinition()->getTransitions()[0]->getName());
    }

    public function testEventSequenceMatchesStaticallyConfiguredWorkflows(): void
    {
        $this->createDefinition('crit8');
        $subject = new TestSubject('crit8');
        $workflow = $this->registry->get($subject);

        $recorded = [];
        $recorder = static function (Event $event, string $eventName) use (&$recorded): void {
            $recorded[] = $eventName;
        };

        $dispatcher = self::getContainer()->get(EventDispatcherInterface::class);
        $genericEvents = ['workflow.guard', 'workflow.leave', 'workflow.transition', 'workflow.enter', 'workflow.entered', 'workflow.completed', 'workflow.announce'];
        $namedEvents = array_map(static fn (string $e) => str_replace('workflow.', 'workflow.crit8.', $e), $genericEvents);
        foreach ([...$genericEvents, ...$namedEvents] as $eventName) {
            $dispatcher->addListener($eventName, $recorder);
        }

        try {
            $workflow->apply($subject, 'stamp');
        } finally {
            foreach ([...$genericEvents, ...$namedEvents] as $eventName) {
                $dispatcher->removeListener($eventName, $recorder);
            }
        }

        // The same names, in the same order, as a framework.workflows-defined
        // workflow: generic event immediately followed by its named variant.
        // The leading 'entered' pair fires when the empty marking is
        // initialized to the initial place, exactly as for static workflows.
        $expected = [];
        foreach (['entered', 'guard', 'leave', 'transition', 'enter', 'entered', 'completed', 'announce'] as $step) {
            $expected[] = "workflow.$step";
            $expected[] = "workflow.crit8.$step";
        }
        self::assertSame($expected, $recorded);
    }
}
