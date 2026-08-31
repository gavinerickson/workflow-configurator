<?php

namespace WorkflowConfigurator\Tests\Form;

use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use WorkflowConfigurator\Form\TransitionMetadataType;
use WorkflowConfigurator\Tests\Workflow\WorkflowTestCase;

/**
 * The guided transition metadata editor's mapping: metadata array → guided
 * fields → metadata array, with round-tripping of what the form does not
 * manage. Adapted from the origin suite onto the bundle's fixture tasks and
 * role vocabularies.
 */
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
            'next' => 'finish_rotate',
            'deadline_after' => 'P3D',
            'deadline_transition' => 'give_up',
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
            'next' => 'done',
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
}
