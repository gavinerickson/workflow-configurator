<?php

namespace WorkflowConfigurator\Form;

use WorkflowConfigurator\Duration;
use WorkflowConfigurator\Task\Args\ArgDefinition;
use WorkflowConfigurator\Task\Args\ArgsSchema;
use WorkflowConfigurator\Task\Args\ArgType;
use WorkflowConfigurator\Task\WorkflowTaskMap;
use WorkflowConfigurator\TransitionRoleMap;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\DataMapperInterface;
use Symfony\Component\Form\Exception\TransformationFailedException;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The guided editor over WorkflowTransition::$metadata
 * (specs/DynamicWorkflows.md §9.3): a task dropdown owning one
 * schema-rendered parameter panel per registered task (client JS shows the
 * selected panel; only that panel is mapped back into "args"), dedicated
 * inputs for "next", "deadline" and every registered transition role
 * (specs/WorkflowBundleExtraction.md §4), and a collapsed advanced textarea
 * for keys the form does not manage.
 *
 * The deadline is edited as **Timing** (§10.3): one choice of what time does
 * to this transition, a number and a unit in place of an ISO duration, and a
 * transition picker (§10.4) for what follows. It reads and writes the same
 * "deadline" object the sweep consumes.
 *
 * The stored shape (§5.2, §8.3) is unchanged — this is a friendlier writer
 * into the same JSON column, and the entity's #[KnownWorkflowTask] constraint
 * remains the server-side authority (§9.4).
 *
 * Operator-facing strings reaching this form — task-schema help, role labels
 * and help — are escaped before they become form options: EasyAdmin's theme
 * renders `help` through Twig's `raw` filter, so an unescaped angle bracket in
 * a task's own documentation is parsed as markup. A tag that takes content
 * (`<template>`, `<select>`, `<textarea>`) then swallows the remainder of the
 * form, which neither renders nor submits. Task authors write prose, not HTML;
 * escaping here is what keeps that true for every consumer.
 *
 * @extends AbstractType<array<string, mixed>>
 */
class TransitionMetadataType extends AbstractType implements DataMapperInterface
{
    /**
     * Block prefix for the theme that groups these children into sections
     * (templates/form/transition_metadata.html.twig).
     */
    public const BLOCK_PREFIX = 'workflow_transition_metadata';

    public const TIMING_NONE = 'none';
    public const TIMING_SELF_NOW = 'self_now';
    public const TIMING_SELF_AFTER = 'self_after';
    public const TIMING_WAIT = 'wait';

    /** What "straight away" writes when there is no stored value to keep. */
    private const STRAIGHT_AWAY = 'PT1S';

    public function __construct(
        private readonly WorkflowTaskMap $taskMap,
        private readonly TransitionRoleMap $roles,
    ) {
    }

    public function getBlockPrefix(): string
    {
        return self::BLOCK_PREFIX;
    }

    /**
     * Operator-facing text on its way to a `help` or `label` option, which
     * EasyAdmin renders unescaped. Prose in, prose out — a task documenting
     * `"<prefix>_<n>"` shows those angle brackets instead of injecting an
     * element that eats the rest of the form.
     */
    private static function asText(?string $text): ?string
    {
        return null === $text ? null : htmlspecialchars($text, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Keys owned by dedicated inputs, banned from the advanced textarea
     * (§9.3): the fixed ones plus one per registered role.
     *
     * @return list<string>
     */
    private function ownedKeys(): array
    {
        return ['task', 'next', 'deadline', ...$this->roles->keys()];
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $taskNames = $this->taskMap->names();

        $builder->add('task', ChoiceType::class, [
            'label' => 'Task',
            'choices' => array_combine($taskNames, $taskNames),
            'required' => false,
            'placeholder' => '(no task)',
            'help' => 'Async task dispatched when this transition completes.',
        ]);

        foreach ($taskNames as $taskName) {
            $builder->add($this->buildTaskPanel($builder, $taskName, $this->taskMap->get($taskName)->describeArgs()));
        }

        $builder->add('next', TransitionPickerType::class, [
            'label' => 'Then',
            'help' => 'The transition applied when the task finishes its work. Leave at (none) and the document rests where this transition put it.',
        ]);

        $builder->add('timing', ChoiceType::class, [
            'label' => 'What time does here',
            'expanded' => true,
            'required' => false,
            'placeholder' => false,
            'empty_data' => self::TIMING_NONE,
            'choices' => [
                'Nothing: this transition waits to be applied' => self::TIMING_NONE,
                'Starts by itself, straight away' => self::TIMING_SELF_NOW,
                'Starts by itself, after a wait' => self::TIMING_SELF_AFTER,
                'Limits the wait afterwards' => self::TIMING_WAIT,
            ],
        ]);
        $builder->add('timing_amount', IntegerType::class, [
            'label' => 'How long',
            'required' => false,
            'attr' => ['min' => 1, 'placeholder' => 'e.g. 7'],
        ]);
        $units = Duration::units();
        $builder->add('timing_unit', ChoiceType::class, [
            'label' => 'Unit',
            'required' => false,
            'placeholder' => false,
            'empty_data' => 'days',
            'choices' => array_combine($units, $units),
        ]);
        $builder->add('timing_exact', TextType::class, [
            'label' => 'Exact duration',
            'required' => false,
            'attr' => ['placeholder' => 'e.g. P1DT12H'],
            'help' => 'Only for a wait a number and a unit cannot say: an ISO-8601 duration such as "P1DT12H" (a day and a half) or "P1M" (a month). A number above takes precedence.',
        ]);
        // The duration as stored, so "straight away" re-saves the value it
        // was loaded with (PT5S stays PT5S) rather than normalising it.
        $builder->add('timing_original', HiddenType::class, ['required' => false]);
        $builder->add('deadline_transition', TransitionPickerType::class, [
            'label' => 'Then',
            'help' => 'The transition applied to a document that has waited that long.',
        ]);

        foreach ($this->roles as $key => $role) {
            $values = $role->getValues();
            $builder->add($key, ChoiceType::class, [
                'label' => self::asText($role->getFormLabel()),
                'choices' => array_combine(array_map(ucfirst(...), $values), $values),
                'required' => false,
                'placeholder' => '(none)',
                'help' => self::asText($role->getFormHelp()),
            ]);
        }

        $ownedKeys = $this->ownedKeys();
        $extra = $builder->create('extra', TextareaType::class, [
            'label' => 'Additional metadata (advanced)',
            'required' => false,
            'attr' => ['rows' => 3],
            'help' => self::asText(\sprintf('JSON object for keys the guided inputs do not manage — normally empty. The managed keys (%s) and "guard" are rejected here; an "args" object may carry unrecognised task parameters awaiting cleanup.', implode(', ', $ownedKeys))),
        ]);
        $extra->addModelTransformer(new CallbackTransformer(
            static function (?array $keys): string {
                return null === $keys || [] === $keys
                    ? ''
                    : json_encode($keys, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
            },
            static function (?string $json) use ($ownedKeys): array {
                if (null === $json || '' === trim($json)) {
                    return [];
                }

                try {
                    $keys = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    throw new TransformationFailedException('invalid JSON', invalidMessage: 'This is not a valid JSON object.');
                }
                if (!\is_array($keys)) {
                    throw new TransformationFailedException('not an object', invalidMessage: 'This is not a valid JSON object.');
                }

                // "guard" is a column, injected into runtime metadata by the
                // registry (§3) — a metadata key would collide with it.
                foreach ([...$ownedKeys, 'guard'] as $reserved) {
                    if (\array_key_exists($reserved, $keys)) {
                        throw new TransformationFailedException('reserved key', invalidMessage: \sprintf('"%s" belongs to its own input and cannot be set here.', $reserved));
                    }
                }

                return $keys;
            },
        ));
        $builder->add($extra);

        $builder->setDataMapper($this);

        // The two things a Timing choice cannot be saved without. Checked
        // here rather than left to Deadline::validate(): a wait with no
        // follow-up would be stored as a deadline with no transition, which
        // *means* self-firing (§8.3) — a different behaviour, not an error.
        $builder->addEventListener(FormEvents::POST_SUBMIT, static function (FormEvent $event): void {
            $form = $event->getForm();
            $timing = $form->get('timing')->getData();
            if (!\in_array($timing, [self::TIMING_SELF_AFTER, self::TIMING_WAIT], true)) {
                return;
            }

            if (null === self::submittedDuration($form->get('timing_amount')->getData(), $form->get('timing_unit')->getData(), $form->get('timing_exact')->getData())) {
                $form->get('timing_amount')->addError(new FormError('Say how long: a whole number and a unit.'));
            }
            if (self::TIMING_WAIT === $timing && null === $form->get('deadline_transition')->getData()) {
                $form->get('deadline_transition')->addError(new FormError('Choose the transition that follows the wait.'));
            }
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'required' => false,
            'error_mapping' => $this->argsErrorMapping(),
        ]);

        // EasyAdmin autodetects the JSON column as an ArrayField and stamps
        // CollectionType options onto whatever form type the field declares
        // (ArrayConfigurator); accepted here so resolution passes, unused.
        $resolver->setDefined(['allow_add', 'allow_delete', 'delete_empty', 'entry_type', 'entry_options']);
    }

    /**
     * @param array<string, mixed>|null                      $viewData
     * @param \Traversable<int|string, FormInterface<mixed>> $forms
     */
    public function mapDataToForms(mixed $viewData, \Traversable $forms): void
    {
        $data = \is_array($viewData) ? $viewData : [];
        $forms = iterator_to_array($forms);

        $task = $data['task'] ?? null;
        $task = \is_string($task) && '' !== $task ? $task : null;
        $forms['task']->setData($task);

        // Every child gets its data set here — a child left uninitialized
        // would be lazily re-initialized to null on first access (e.g. by the
        // form profiler), wiping what was mapped into it.
        $args = \is_array($data['args'] ?? null) ? $data['args'] : [];
        $leftoverArgs = $args;
        foreach ($this->taskMap->names() as $taskName) {
            if (!isset($forms['args_'.$taskName])) {
                continue;
            }
            $panelData = [];
            if ($taskName === $task) {
                $schema = $this->taskMap->get($task)->describeArgs();
                foreach ($args as $name => $value) {
                    $arg = $schema->byName((string) $name);
                    if (null !== $arg && self::valueFitsField($arg, $value)) {
                        $panelData[$name] = $value;
                        unset($leftoverArgs[$name]);
                    }
                }
            }
            $forms['args_'.$taskName]->setData($panelData);
        }

        $forms['next']->setData(\is_string($data['next'] ?? null) ? $data['next'] : null);

        $deadline = \is_array($data['deadline'] ?? null) ? $data['deadline'] : [];
        $after = \is_string($deadline['after'] ?? null) && '' !== $deadline['after'] ? $deadline['after'] : null;
        $target = \is_string($deadline['transition'] ?? null) && '' !== $deadline['transition'] ? $deadline['transition'] : null;

        $timing = self::TIMING_NONE;
        $split = null;
        $exact = null;
        if (null !== $after) {
            $interval = Duration::parse($after);
            if (null === $target && null !== $interval && Duration::isStraightAway($interval)) {
                $timing = self::TIMING_SELF_NOW;
            } else {
                $timing = null === $target ? self::TIMING_SELF_AFTER : self::TIMING_WAIT;
                $split = Duration::split($after);
                $exact = null === $split ? $after : null;
            }
        } elseif (null !== $target) {
            // A follow-up with no duration: shown as the wait it was meant
            // to be, so the missing number is asked for on the next save.
            $timing = self::TIMING_WAIT;
        }

        $forms['timing']->setData($timing);
        $forms['timing_amount']->setData($split['amount'] ?? null);
        $forms['timing_unit']->setData($split['unit'] ?? 'days');
        $forms['timing_exact']->setData($exact);
        $forms['timing_original']->setData($after);
        $forms['deadline_transition']->setData($target);

        foreach ($this->roles->keys() as $roleKey) {
            $forms[$roleKey]->setData(\is_string($data[$roleKey] ?? null) ? $data[$roleKey] : null);
        }

        // Everything the guided inputs do not manage — including args values
        // they cannot represent — surfaces in the advanced textarea instead
        // of being silently dropped (§9.5).
        $extra = array_diff_key($data, array_flip([...$this->ownedKeys(), 'args']));
        if ([] !== $leftoverArgs) {
            $extra['args'] = $leftoverArgs;
        }
        $forms['extra']->setData($extra);
    }

    /**
     * @param \Traversable<int|string, FormInterface<mixed>> $forms
     * @param array<string, mixed>|null                      $viewData
     *
     * @param-out array<string, mixed> $viewData
     */
    public function mapFormsToData(\Traversable $forms, mixed &$viewData): void
    {
        $forms = iterator_to_array($forms);
        $metadata = [];

        $extra = $forms['extra']->getData();
        /** @var array<string, mixed> $extra */
        $extra = \is_array($extra) ? $extra : [];
        $extraArgs = \is_array($extra['args'] ?? null) ? $extra['args'] : [];
        unset($extra['args']);

        $task = $forms['task']->getData();
        if (null !== $task) {
            $metadata['task'] = $task;

            // Only the selected task's panel is read, so switching task can
            // never leak the previous task's parameters (§9.3, criterion 19).
            $args = [];
            if (isset($forms['args_'.$task])) {
                foreach ($forms['args_'.$task]->all() as $name => $field) {
                    $value = $field->getData();
                    if (null !== $value && '' !== $value) {
                        $args[$name] = $value;
                    }
                }
            }
            $args += $extraArgs;
            if ([] !== $args) {
                $metadata['args'] = $args;
            }
        }

        $next = $forms['next']->getData();
        if (null !== $next && '' !== $next) {
            $metadata['next'] = $next;
        }

        $timing = $forms['timing']->getData();
        $after = self::submittedDuration($forms['timing_amount']->getData(), $forms['timing_unit']->getData(), $forms['timing_exact']->getData());
        if (self::TIMING_SELF_NOW === $timing) {
            $original = $forms['timing_original']->getData();
            $interval = \is_string($original) ? Duration::parse($original) : null;
            $metadata['deadline'] = [
                'after' => null !== $interval && Duration::isStraightAway($interval) ? $original : self::STRAIGHT_AWAY,
            ];
        } elseif (self::TIMING_SELF_AFTER === $timing && null !== $after) {
            // No "transition" key: that absence is what makes it self-firing.
            $metadata['deadline'] = ['after' => $after];
        } elseif (self::TIMING_WAIT === $timing) {
            $target = $forms['deadline_transition']->getData();
            // Never stored without its follow-up — see the POST_SUBMIT check.
            if (null !== $after && \is_string($target) && '' !== $target) {
                $metadata['deadline'] = ['after' => $after, 'transition' => $target];
            }
        }

        foreach ($this->roles->keys() as $roleKey) {
            $role = $forms[$roleKey]->getData();
            if (null !== $role) {
                $metadata[$roleKey] = $role;
            }
        }

        $viewData = array_merge($metadata, $extra);
    }

    /**
     * The duration the Timing inputs say: the number and unit when a number
     * is given, otherwise the exact ISO value, otherwise nothing.
     */
    private static function submittedDuration(mixed $amount, mixed $unit, mixed $exact): ?string
    {
        if (\is_int($amount) && \is_string($unit)) {
            return Duration::toIso($amount, $unit);
        }

        return \is_string($exact) && '' !== trim($exact) ? trim($exact) : null;
    }

    /**
     * One panel per registered task, every field rendered from the schema so
     * the form cannot show a rule the validator does not enforce (§9.2).
     * Client JS toggles panel visibility on the task dropdown and disables
     * hidden panels' inputs (assets/admin/workflow_transition_form.js).
     *
     * @param FormBuilderInterface<mixed> $builder
     *
     * @return FormBuilderInterface<mixed>
     */
    private function buildTaskPanel(FormBuilderInterface $builder, string $taskName, ArgsSchema $schema): FormBuilderInterface
    {
        $panel = $builder->create('args_'.$taskName, FormType::class, [
            'label' => self::asText(\sprintf('%s parameters', $taskName)),
            'attr' => ['data-task-panel' => $taskName],
            'required' => false,
            'help' => [] === $schema->args ? 'This task takes no parameters.' : null,
        ]);

        foreach ($schema->args as $arg) {
            $panel->add($arg->name, self::fieldTypeFor($arg), self::fieldOptionsFor($arg));
        }

        return $panel;
    }

    /**
     * @return class-string<AbstractType<mixed>>
     */
    private static function fieldTypeFor(ArgDefinition $arg): string
    {
        // A declared formType outranks the built-in widgets; RuleCollection
        // has no built-in widget, so its schema must declare one
        // (specs/WorkflowBundleExtraction.md §5).
        return $arg->formType ?? match ($arg->type) {
            ArgType::Choice => ChoiceType::class,
            ArgType::Integer => IntegerType::class,
            ArgType::Text => TextType::class,
            ArgType::Transition => TransitionPickerType::class,
            ArgType::RuleCollection => throw new \LogicException(\sprintf('Rule-collection parameter "%s" declares no formType.', $arg->name)),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private static function fieldOptionsFor(ArgDefinition $arg): array
    {
        $help = array_filter([
            $arg->help,
            null !== $arg->patternHint ? 'Format: '.$arg->patternHint.'.' : null,
            null !== $arg->default ? \sprintf('Default: %s.', (string) $arg->default) : null,
        ]);

        $options = [
            'label' => self::asText($arg->label),
            'required' => $arg->required,
            'help' => [] === $help ? null : self::asText(implode(' ', $help)),
        ];

        if (ArgType::Choice === $arg->type) {
            $choices = $arg->choices ?? [];
            $options['choices'] = array_combine(array_map(strval(...), $choices), $choices);
            $options['placeholder'] = '';
        }

        return $options;
    }

    /**
     * A stored value only pre-fills its field when the widget can represent
     * it; anything else (e.g. a hand-typed "40" where an integer belongs)
     * falls through to the advanced textarea and fails validation visibly on
     * the next save (§9.5).
     */
    private static function valueFitsField(ArgDefinition $arg, mixed $value): bool
    {
        return match ($arg->type) {
            ArgType::Choice => \in_array($value, $arg->choices ?? [], true),
            ArgType::Integer => \is_int($value),
            ArgType::Text, ArgType::Transition => \is_string($value),
            ArgType::RuleCollection => \is_array($value),
        };
    }

    /**
     * Maps the entity validator's keyed violations (§9.4 rule 1) onto the
     * matching panel fields: "[args][degrees]" → the rotate panel's degrees
     * input. "[task]" and "[digital_first]" match their children by name
     * already; "[deadline]" lands on the Timing choice.
     *
     * Arg names are assumed unique across tasks (true today); on a collision
     * the first task's panel wins and the message still names the key.
     *
     * @return array<string, string>
     */
    private function argsErrorMapping(): array
    {
        // The Timing choice is always on screen; the input a deadline
        // problem belongs to may not be.
        $mapping = ['[deadline]' => 'timing'];
        foreach ($this->taskMap->names() as $taskName) {
            foreach ($this->taskMap->get($taskName)->describeArgs()->names() as $argName) {
                $mapping['[args]['.$argName.']'] ??= 'args_'.$taskName.'.'.$argName;
            }
        }

        return $mapping;
    }
}
