<?php

namespace WorkflowConfigurator\Tests\Form;

use PHPUnit\Framework\Attributes\Group;
use RequirementsAsCode\Attribute\Verifies;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use WorkflowConfigurator\Form\TransitionMetadataType;
use WorkflowConfigurator\Form\TransitionPickerType;
use WorkflowConfigurator\Tests\Workflow\WorkflowTestCase;

/**
 * The guided transition metadata editor's mapping: metadata array → guided
 * fields → metadata array, with round-tripping of what the form does not
 * manage. Adapted from the origin suite onto the bundle's fixture tasks and
 * role vocabularies.
 */
#[Group('rac')]
#[Verifies('REQ-006')]
#[Verifies('REQ-004')]
class TransitionMetadataFormTest extends WorkflowTestCase
{
    /**
     * @param array<string, mixed> $data
     *
     * @return FormInterface<array<string, mixed>>
     */
    private function createForm(array $data = []): FormInterface
    {
        return self::getContainer()->get(FormFactoryInterface::class)
            ->create(TransitionMetadataType::class, $data);
    }

    public function testGuidedFieldsBuildTheCanonicalMetadataShape(): void
    {
        $form = $this->createForm();
        $form->submit([
            'task' => 'rotate',
            'args_rotate' => ['degrees' => '90', 'pages' => '1-3'],
            'next' => ['pick' => TransitionPickerType::NOT_MADE_YET, 'other' => 'finish_rotate'],
            'timing' => TransitionMetadataType::TIMING_WAIT,
            'timing_amount' => '3',
            'timing_unit' => 'days',
            'deadline_transition' => ['pick' => TransitionPickerType::NOT_MADE_YET, 'other' => 'give_up'],
            'review' => '',
        ]);

        self::assertTrue($form->isSynchronized());
        // The canonical stored shape, canonical types included.
        self::assertSame([
            'task' => 'rotate',
            'args' => ['degrees' => 90, 'pages' => '1-3'],
            'next' => 'finish_rotate',
            'deadline' => ['after' => 'P3D', 'transition' => 'give_up'],
        ], $form->getData());
    }

    public function testRoleFieldsRoundTripPerProvider(): void
    {
        $form = $this->createForm(['review' => 'approve', 'lifecycle' => 'close']);
        self::assertSame('approve', $form->get('review')->getData());
        self::assertSame('close', $form->get('lifecycle')->getData());

        $form = $this->createForm();
        $form->submit(['review' => 'reject']);

        self::assertTrue($form->isSynchronized());
        // A selected role is stored under its provider's key; an untouched
        // one is omitted rather than stored empty.
        self::assertSame(['review' => 'reject'], $form->getData());
    }

    public function testSwitchingTaskDiscardsTheOtherPanel(): void
    {
        // The rotate panel is filled but another task is selected — no rotate
        // parameter may leak into args.
        $form = $this->createForm(['task' => 'rotate', 'args' => ['degrees' => 180]]);
        $form->submit([
            'task' => 'noop',
            'args_rotate' => ['degrees' => '180'],
            'next' => ['other' => 'done'],
        ]);

        self::assertSame(['task' => 'noop', 'next' => 'done'], $form->getData());
    }

    public function testUnmanagedMetadataRoundTripsThroughTheAdvancedField(): void
    {
        // A transition with a key the form does not manage, and an args value
        // its widget cannot represent (a hand-typed string where an integer
        // belongs).
        $metadata = [
            'task' => 'stamper',
            'args' => ['supply_chain_id' => '1234567', 'x' => '40'],
            'custom_key' => ['kept' => true],
        ];

        $form = $this->createForm($metadata);

        $advanced = $form->get('extra')->getData();
        self::assertSame(['kept' => true], $advanced['custom_key']);
        self::assertSame(['x' => '40'], $advanced['args'], 'A value the widget cannot represent surfaces in the advanced field.');
        self::assertSame('1234567', $form->get('args_stamper')->get('supply_chain_id')->getData());

        // Re-submitting exactly what the form rendered loses nothing.
        $form = $this->createForm($metadata);
        $form->submit([
            'task' => 'stamper',
            'args_stamper' => ['supply_chain_id' => '1234567'],
            'extra' => json_encode(['custom_key' => ['kept' => true], 'args' => ['x' => '40']], \JSON_THROW_ON_ERROR),
        ]);

        self::assertTrue($form->isSynchronized());
        self::assertSame([
            'task' => 'stamper',
            'args' => ['supply_chain_id' => '1234567', 'x' => '40'],
            'custom_key' => ['kept' => true],
        ], $form->getData());
    }

    public function testAdvancedFieldRejectsReservedKeys(): void
    {
        $form = $this->createForm();
        $form->submit(['extra' => '{"guard": "subject.isUrgent()"}']);

        self::assertFalse($form->get('extra')->isSynchronized());
        self::assertStringContainsString('"guard" belongs to its own input', (string) $form->get('extra')->getErrors(true));
    }

    public function testAdvancedFieldRejectsRoleKeys(): void
    {
        // Role keys are owned by their provider-rendered selects, so the
        // escape hatch refuses them like the fixed keys.
        $form = $this->createForm();
        $form->submit(['extra' => '{"review": "approve"}']);

        self::assertFalse($form->get('extra')->isSynchronized());
        self::assertStringContainsString('"review" belongs to its own input', (string) $form->get('extra')->getErrors(true));
    }

    public function testAdvancedFieldRejectsInvalidJson(): void
    {
        $form = $this->createForm();
        $form->submit(['extra' => 'not json']);

        self::assertFalse($form->get('extra')->isSynchronized());
        self::assertStringContainsString('not a valid JSON object', (string) $form->get('extra')->getErrors(true));
    }

    /*
     * Timing (specs/DynamicWorkflows.md §10.3): the same "deadline" object,
     * written from a choice, a number and a unit.
     */

    public function testStraightAwayWritesASelfFiringDeadline(): void
    {
        $form = $this->createForm();
        $form->submit(['timing' => TransitionMetadataType::TIMING_SELF_NOW]);

        self::assertTrue($form->isValid());
        // No "transition" key: its absence is what makes a deadline self-firing.
        self::assertSame(['deadline' => ['after' => 'PT1S']], $form->getData());
    }

    public function testStraightAwayKeepsTheDurationItWasLoadedWith(): void
    {
        $form = $this->createForm(['deadline' => ['after' => 'PT5S']]);
        self::assertSame(TransitionMetadataType::TIMING_SELF_NOW, $form->get('timing')->getData());

        // What the page would post back untouched.
        $form = $this->createForm(['deadline' => ['after' => 'PT5S']]);
        $form->submit(['timing' => TransitionMetadataType::TIMING_SELF_NOW, 'timing_original' => 'PT5S']);

        self::assertSame(['deadline' => ['after' => 'PT5S']], $form->getData());
    }

    public function testAStoredWaitReadsAsANumberAndAUnit(): void
    {
        $form = $this->createForm(['deadline' => ['after' => 'P2W', 'transition' => 'post_unread']]);

        self::assertSame(TransitionMetadataType::TIMING_WAIT, $form->get('timing')->getData());
        self::assertSame(2, $form->get('timing_amount')->getData());
        self::assertSame('weeks', $form->get('timing_unit')->getData());
        self::assertNull($form->get('timing_exact')->getData());
        self::assertSame('post_unread', $form->get('deadline_transition')->getData());
    }

    public function testASelfFiringWaitOfHoursIsWrittenInHours(): void
    {
        $form = $this->createForm();
        $form->submit([
            'timing' => TransitionMetadataType::TIMING_SELF_AFTER,
            'timing_amount' => '2',
            'timing_unit' => 'hours',
            // Ignored: a self-firing deadline never names a transition.
            'deadline_transition' => ['other' => 'stray'],
        ]);

        self::assertTrue($form->isValid());
        self::assertSame(['deadline' => ['after' => 'PT2H']], $form->getData());
    }

    public function testADurationNoUnitCanSayRoundTripsThroughTheExactInput(): void
    {
        $stored = ['deadline' => ['after' => 'P1DT12H', 'transition' => 'post_unread']];

        $form = $this->createForm($stored);
        self::assertNull($form->get('timing_amount')->getData());
        self::assertSame('P1DT12H', $form->get('timing_exact')->getData());

        $form = $this->createForm($stored);
        $form->submit([
            'timing' => TransitionMetadataType::TIMING_WAIT,
            'timing_unit' => 'days',
            'timing_exact' => 'P1DT12H',
            'timing_original' => 'P1DT12H',
            'deadline_transition' => ['other' => 'post_unread'],
        ]);

        self::assertTrue($form->isValid());
        self::assertSame($stored, $form->getData());
    }

    public function testAWaitWithoutItsFollowUpIsRefusedRatherThanStoredAsSelfFiring(): void
    {
        $form = $this->createForm();
        $form->submit([
            'timing' => TransitionMetadataType::TIMING_WAIT,
            'timing_amount' => '7',
            'timing_unit' => 'days',
        ]);

        self::assertFalse($form->isValid());
        self::assertStringContainsString('Choose the transition that follows the wait', (string) $form->get('deadline_transition')->getErrors());
        // {"after": "P7D"} alone would mean "this transition fires itself".
        self::assertArrayNotHasKey('deadline', $form->getData());
    }

    public function testAWaitWithoutALengthIsRefused(): void
    {
        $form = $this->createForm();
        $form->submit(['timing' => TransitionMetadataType::TIMING_SELF_AFTER]);

        self::assertFalse($form->isValid());
        self::assertStringContainsString('Say how long', (string) $form->get('timing_amount')->getErrors());
    }

    public function testChoosingNoTimingRemovesAStoredDeadline(): void
    {
        $form = $this->createForm(['deadline' => ['after' => 'P3D', 'transition' => 'give_up']]);
        $form->submit(['timing' => TransitionMetadataType::TIMING_NONE, 'timing_amount' => '3', 'timing_unit' => 'days']);

        self::assertSame([], $form->getData());
    }

    /*
     * Transition pickers (§10.4).
     */

    public function testAPickerOffersTransitionsThatExistAndTakesANewNameOnlyAsText(): void
    {
        // The reference graph has one transition, "stamp".
        $this->createDefinition('picker');

        $form = $this->createForm(['next' => 'stamp']);
        self::assertSame('stamp', $form->get('next')->get('pick')->getData());
        self::assertNull($form->get('next')->get('other')->getData());

        // A name matching nothing loads as "one I have not made yet".
        $form = $this->createForm(['next' => 'stmp']);
        self::assertSame(TransitionPickerType::NOT_MADE_YET, $form->get('next')->get('pick')->getData());
        self::assertSame('stmp', $form->get('next')->get('other')->getData());

        $form = $this->createForm();
        $form->submit(['next' => ['pick' => 'stamp']]);
        self::assertSame(['next' => 'stamp'], $form->getData());

        // The sentinel alone names nothing.
        $form = $this->createForm();
        $form->submit(['next' => ['pick' => TransitionPickerType::NOT_MADE_YET, 'other' => '']]);
        self::assertSame([], $form->getData());

        // A name not offered cannot be smuggled through the select.
        $form = $this->createForm();
        $form->submit(['next' => ['pick' => 'no_such_transition']]);
        self::assertFalse($form->isValid());
    }

    public function testATransitionTypedTaskParameterIsAPicker(): void
    {
        $form = $this->createForm(['task' => 'fork', 'args' => ['otherwise' => 'to_post']]);
        self::assertSame('to_post', $form->get('args_fork')->get('otherwise')->getData());

        $form = $this->createForm();
        $form->submit([
            'task' => 'fork',
            'args_fork' => ['otherwise' => ['pick' => TransitionPickerType::NOT_MADE_YET, 'other' => 'to_post']],
            'next' => ['other' => 'publish'],
        ]);

        self::assertTrue($form->isValid());
        self::assertSame(['task' => 'fork', 'args' => ['otherwise' => 'to_post'], 'next' => 'publish'], $form->getData());
    }
}
