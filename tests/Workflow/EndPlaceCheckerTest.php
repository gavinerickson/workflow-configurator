<?php

namespace WorkflowConfigurator\Tests\Workflow;

use PHPUnit\Framework\TestCase;
use WorkflowConfigurator\EndPlaceChecker;
use WorkflowConfigurator\Entity\WorkflowDefinition;
use WorkflowConfigurator\Entity\WorkflowPlace;
use WorkflowConfigurator\Entity\WorkflowTransition;

/**
 * specs/DynamicWorkflows.md §10.5 — a place marked as an end that a Timing
 * transition leaves is reported; an operator's way out (a recall) is not.
 */
class EndPlaceCheckerTest extends TestCase
{
    private WorkflowDefinition $definition;
    private WorkflowPlace $sending;
    private WorkflowPlace $posted;
    private WorkflowPlace $recalled;

    protected function setUp(): void
    {
        $this->definition = new WorkflowDefinition()->setName('lane')->setLabel('Lane');
        $this->sending = new WorkflowPlace()->setName('sending')->setLabel('Sending');
        $this->posted = new WorkflowPlace()->setName('posted')->setLabel('Posted')->setEnd(true);
        $this->recalled = new WorkflowPlace()->setName('recalled')->setLabel('Recalled');
        $this->definition->addPlace($this->sending)->addPlace($this->posted)->addPlace($this->recalled)->setInitialPlace($this->sending);
    }

    public function testAnOperatorsWayOutOfAnEndPlaceIsNotReported(): void
    {
        $this->definition
            ->addTransition(new WorkflowTransition()->setName('mark_posted')->addFrom($this->sending)->addTo($this->posted))
            ->addTransition(new WorkflowTransition()->setName('recall')->addFrom($this->posted)->addTo($this->recalled));

        self::assertSame([], new EndPlaceChecker()->findTimedExits($this->definition));
    }

    public function testATransitionThatFiresItselfFromAnEndPlaceIsReported(): void
    {
        $this->definition
            ->addTransition(new WorkflowTransition()->setName('mark_posted')->addFrom($this->sending)->addTo($this->posted))
            ->addTransition(new WorkflowTransition()->setName('expire')->addFrom($this->posted)->addTo($this->recalled)->setMetadata(['deadline' => ['after' => 'P7D']]));

        $checker = new EndPlaceChecker();
        self::assertSame([['place' => 'posted', 'transition' => 'expire']], $checker->findTimedExits($this->definition));
        self::assertStringContainsString('Documents finish at "posted", but "expire" moves them on', $checker->describeTimedExits($this->definition)[0]);
    }

    public function testADeadlineOnTheWayInThatNamesAWayOutIsReported(): void
    {
        $this->definition
            ->addTransition(new WorkflowTransition()->setName('mark_posted')->addFrom($this->sending)->addTo($this->posted)->setMetadata(['deadline' => ['after' => 'P7D', 'transition' => 'recall']]))
            ->addTransition(new WorkflowTransition()->setName('recall')->addFrom($this->posted)->addTo($this->recalled));

        self::assertSame([['place' => 'posted', 'transition' => 'recall']], new EndPlaceChecker()->findTimedExits($this->definition));
    }

    public function testATimingTransitionElsewhereIsNotReported(): void
    {
        // Fires itself from `sending`, which is not an end; and leads into an end without naming a way out of it.
        $this->definition
            ->addTransition(new WorkflowTransition()->setName('mark_posted')->addFrom($this->sending)->addTo($this->posted)->setMetadata(['deadline' => ['after' => 'PT10M']]))
            ->addTransition(new WorkflowTransition()->setName('recall')->addFrom($this->posted)->addTo($this->recalled));

        self::assertSame([], new EndPlaceChecker()->findTimedExits($this->definition));
    }
}
