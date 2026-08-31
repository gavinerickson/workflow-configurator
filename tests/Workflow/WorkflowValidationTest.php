<?php

namespace WorkflowConfigurator\Tests\Workflow;

use WorkflowConfigurator\Entity\WorkflowDefinition;
use WorkflowConfigurator\Entity\WorkflowPlace;
use WorkflowConfigurator\Entity\WorkflowTransition;
use WorkflowConfigurator\Tests\Fixtures\CountingOccupancyChecker;
use WorkflowConfigurator\OccupiedPlaceException;
use WorkflowConfigurator\ReachabilityChecker;
use WorkflowConfigurator\WorkflowType;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * specs/DynamicWorkflows.md §7 criteria 6 and 7 (§6.2 rules).
 */
class WorkflowValidationTest extends WorkflowTestCase
{
    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = self::getContainer()->get(ValidatorInterface::class);
    }

    /**
     * @return list<string>
     */
    private function messagesOf(ConstraintViolationListInterface $violations): array
    {
        $messages = [];
        foreach ($violations as $violation) {
            $messages[] = $violation->getPropertyPath().': '.$violation->getMessage();
        }

        return $messages;
    }

    public function testRule1EnabledDefinitionRequiresInitialPlace(): void
    {
        $definition = new WorkflowDefinition()->setName('rule1')->setLabel('Rule 1')->setEnabled(true);
        $definition->addPlace(new WorkflowPlace()->setName('a')->setLabel('A'));

        $messages = $this->messagesOf($this->validator->validate($definition));
        self::assertContains('initialPlace: An enabled workflow must have an initial place.', $messages);
    }

    public function testRule1DisabledDefinitionMayOmitInitialPlace(): void
    {
        $definition = new WorkflowDefinition()->setName('rule1b')->setLabel('Rule 1b')->setEnabled(false);

        self::assertSame([], $this->messagesOf($this->validator->validate($definition)));
    }

    public function testRule1InitialPlaceMustBelongToDefinition(): void
    {
        $foreign = new WorkflowPlace()->setName('foreign')->setLabel('Foreign');
        new WorkflowDefinition()->setName('other')->setLabel('Other')->addPlace($foreign);

        $definition = new WorkflowDefinition()->setName('rule1c')->setLabel('Rule 1c')->setEnabled(false);
        $definition->setInitialPlace($foreign);

        $messages = $this->messagesOf($this->validator->validate($definition));
        self::assertContains("initialPlace: The initial place must be one of this workflow's places.", $messages);
    }

    public function testRule2TransitionsNeedFromAndToPlaces(): void
    {
        $definition = new WorkflowDefinition()->setName('rule2')->setLabel('Rule 2')->setEnabled(false);
        $a = new WorkflowPlace()->setName('a')->setLabel('A');
        $definition->addPlace($a);
        $definition->addTransition(new WorkflowTransition()->setName('t1')->addFrom($a)); // no tos

        $messages = $this->messagesOf($this->validator->validate($definition));
        self::assertContains('transitions[0]: Transition "t1" must have at least one "from" and one "to" place.', $messages);
    }

    public function testRule2TransitionsMayNotUseForeignPlaces(): void
    {
        $foreign = new WorkflowPlace()->setName('foreign')->setLabel('Foreign');
        new WorkflowDefinition()->setName('other2')->setLabel('Other')->addPlace($foreign);

        $definition = new WorkflowDefinition()->setName('rule2b')->setLabel('Rule 2b')->setEnabled(false);
        $a = new WorkflowPlace()->setName('a')->setLabel('A');
        $definition->addPlace($a);
        $definition->addTransition(new WorkflowTransition()->setName('t1')->addFrom($a)->addTo($foreign));

        $messages = $this->messagesOf($this->validator->validate($definition));
        self::assertContains('transitions[0]: Transition "t1" references places from another workflow.', $messages);
    }

    public function testRule2ForeignPlacesAreRejectedWhenValidatingTheTransitionItself(): void
    {
        // The definition-level check only runs on definition saves; the
        // transition form saves the transition alone, so the rule must also
        // hold there (§6.2 "enforced on every save from any screen").
        $foreign = new WorkflowPlace()->setName('foreign')->setLabel('Foreign');
        new WorkflowDefinition()->setName('other3')->setLabel('Other')->addPlace($foreign);

        $definition = new WorkflowDefinition()->setName('rule2c')->setLabel('Rule 2c')->setEnabled(false);
        $a = new WorkflowPlace()->setName('a')->setLabel('A');
        $definition->addPlace($a);
        $transition = new WorkflowTransition()->setName('t1')->addFrom($a)->addTo($foreign);
        $definition->addTransition($transition);

        $messages = $this->messagesOf($this->validator->validate($transition));
        self::assertContains('tos: Place "foreign" belongs to workflow "other3", not "rule2c".', $messages);
    }

    public function testRule3StateMachineRejectsDuplicateArcs(): void
    {
        $definition = new WorkflowDefinition()->setName('rule3')->setLabel('Rule 3')->setEnabled(false)->setType(WorkflowType::StateMachine);
        $a = new WorkflowPlace()->setName('a')->setLabel('A');
        $b = new WorkflowPlace()->setName('b')->setLabel('B');
        $c = new WorkflowPlace()->setName('c')->setLabel('C');
        $definition->addPlace($a)->addPlace($b)->addPlace($c);
        $definition->addTransition(new WorkflowTransition()->setName('go')->addFrom($a)->addTo($b));
        $definition->addTransition(new WorkflowTransition()->setName('go')->addFrom($a)->addTo($c));

        $messages = $this->messagesOf($this->validator->validate($definition));
        self::assertContains('transitions[1]: A state machine cannot have two transitions named "go" leaving place "a".', $messages);

        // The same graph is legal for the 'workflow' type.
        $definition->setType(WorkflowType::Workflow);
        self::assertSame([], $this->messagesOf($this->validator->validate($definition)));
    }

    public function testRule4GuardMustParse(): void
    {
        $transition = new WorkflowTransition()->setName('t')->setGuard('subject.pageCount <=');

        $messages = $this->messagesOf($this->validator->validate($transition));
        self::assertNotSame([], array_filter($messages, static fn (string $m) => str_starts_with($m, 'guard: Guard is not a valid expression')));
    }

    public function testRule5TaskMustExistInTaskMap(): void
    {
        $transition = new WorkflowTransition()->setName('t')->setTask('no_such_task');
        $messages = $this->messagesOf($this->validator->validate($transition));
        self::assertNotEmpty(array_filter($messages, static fn (string $m) => str_starts_with($m, 'metadata[task]: Unknown task "no_such_task"')));

        $transition->setTask('test_stamp');
        $messages = $this->messagesOf($this->validator->validate($transition));
        self::assertSame([], array_filter($messages, static fn (string $m) => str_starts_with($m, 'metadata:')));
    }

    public function testUnknownArgKeysAreRejected(): void
    {
        // specs/DynamicWorkflows.md §9.4 rule 2 / criterion 16: a typo'd args
        // key must fail the save however the metadata was written, instead of
        // being stored, ignored at runtime, and defaulted over (§9.1).
        $transition = new WorkflowTransition()->setName('t')->setTask('test_stamp');
        $transition->setMetadata(array_merge($transition->getMetadata(), ['args' => ['stray' => 1]]));

        $messages = $this->messagesOf($this->validator->validate($transition));
        self::assertNotEmpty(array_filter($messages, static fn (string $m) => str_contains($m, 'unknown parameter "stray"')));
    }

    // testTaskDropdownSurvivesMetadataJsonMapping was retired with the JSON
    // textarea it guarded: the guided form's single mapping makes the
    // dropdown/textarea clobbering impossible by construction
    // (specs/DynamicWorkflows.md §9.6; form coverage in
    // TransitionMetadataFormTest).

    public function testRule6RenamingOccupiedPlaceIsRejected(): void
    {
        $definition = $this->createDefinition('rule6');
        CountingOccupancyChecker::$counts['rule6:received'] = 2;

        $received = self::place($definition, 'received');
        $received->setName('renamed');

        $messages = $this->messagesOf($this->validator->validate($received));
        self::assertContains('name: Cannot rename place "received": 2 document(s) currently occupy it.', $messages);
    }

    public function testRule6DeletingOccupiedPlaceIsRejected(): void
    {
        $definition = $this->createDefinition('rule6b');
        CountingOccupancyChecker::$counts['rule6b:stamped'] = 1;

        $stamped = self::place($definition, 'stamped');

        $this->expectException(OccupiedPlaceException::class);
        $this->entityManager->remove($stamped);
        $this->entityManager->flush();
    }

    public function testRule7UnreachablePlacesAreReported(): void
    {
        $definition = $this->createDefinition('rule7');
        $orphan = new WorkflowPlace()->setName('orphan')->setLabel('Orphan');
        $definition->addPlace($orphan);

        $unreachable = new ReachabilityChecker()->findUnreachablePlaces($definition);
        self::assertSame(['orphan'], $unreachable);
    }
}
