<?php

namespace WorkflowConfigurator\Tests\Workflow;

use Symfony\Component\Validator\ConstraintViolationListInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use WorkflowConfigurator\Entity\WorkflowPlace;
use WorkflowConfigurator\Entity\WorkflowTransition;

/**
 * The transition-role contract, exercised generically through the fixture
 * vocabularies: cardinality, required values, and the typo check — all
 * derived from tagged TransitionRoleInterface providers, never from
 * role-specific code.
 */
class TransitionRoleValidationTest extends WorkflowTestCase
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

    public function testTwoTransitionsClaimingOneRoleValueAreRefused(): void
    {
        $definition = $this->createDefinition('role-dup', enabled: false);
        $stamped = self::place($definition, 'stamped');
        $received = self::place($definition, 'received');

        foreach (['first_approve', 'second_approve'] as $name) {
            $definition->addTransition(
                new WorkflowTransition()->setName($name)
                    ->addFrom($received)->addTo($stamped)
                    ->setMetadata(['review' => 'approve'])
            );
        }

        $messages = $this->messagesOf($this->validator->validate($definition));
        self::assertContains(': Two transitions claim the review "approve" role (first_approve, second_approve); exactly one must.', $messages);
    }

    public function testOneTransitionPerRoleValueIsAccepted(): void
    {
        $definition = $this->createDefinition('role-ok', enabled: false);
        $stamped = self::place($definition, 'stamped');
        $received = self::place($definition, 'received');

        $definition->addTransition(
            new WorkflowTransition()->setName('approve_it')
                ->addFrom($received)->addTo($stamped)
                ->setMetadata(['review' => 'approve'])
        );
        $definition->addTransition(
            new WorkflowTransition()->setName('reject_it')
                ->addFrom($received)->addTo($stamped)
                ->setMetadata(['review' => 'reject'])
        );

        self::assertSame([], $this->messagesOf($this->validator->validate($definition)));
    }

    public function testDeclaringOneRequiredValueWithoutTheOtherIsRefused(): void
    {
        $definition = $this->createDefinition('role-missing', enabled: false);
        $stamped = self::place($definition, 'stamped');
        $received = self::place($definition, 'received');

        $definition->addTransition(
            new WorkflowTransition()->setName('open_it')
                ->addFrom($received)->addTo($stamped)
                ->setMetadata(['lifecycle' => 'open'])
        );

        $messages = $this->messagesOf($this->validator->validate($definition));
        self::assertContains(': This workflow declares lifecycle roles but has no "close" transition.', $messages);
    }

    public function testRequiredValuesAreNotDemandedWhenNoneAreDeclared(): void
    {
        $definition = $this->createDefinition('role-silent', enabled: false);

        self::assertSame([], $this->messagesOf($this->validator->validate($definition)));
    }

    public function testUnrecognisedRoleValueIsATypoNotASilentNoOp(): void
    {
        $transition = new WorkflowTransition()->setName('t')->setMetadata(['review' => 'aprove']);

        $messages = $this->messagesOf($this->validator->validate($transition));
        self::assertNotEmpty(array_filter(
            $messages,
            static fn (string $m) => str_contains($m, '"aprove" is not a review role; expected one of: approve, reject'),
        ));
    }
}
