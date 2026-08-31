<?php

namespace WorkflowConfigurator\Form;

use WorkflowConfigurator\Repository\WorkflowTransitionRepository;
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
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
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
 * The stored shape (§5.2, §8.3) is unchanged — this is a friendlier writer
 * into the same JSON column, and the entity's #[KnownWorkflowTask] constraint
 * remains the server-side authority (§9.4).
 *
 * @extends AbstractType<array<string, mixed>>
 */
class TransitionMetadataType extends AbstractType implements DataMapperInterface
{
    public function __construct(
        private readonly WorkflowTaskMap $taskMap,
        private readonly TransitionRoleMap $roles,
        private readonly WorkflowTransitionRepository $transitions,
    ) {
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

        $suggestions = ['data-transition-suggest' => $this->transitionSuggestions()];
        $builder->add('next', TextType::class, [
            'label' => 'Next transition',
            'required' => false,
            'attr' => $suggestions,
            'help' => 'Follow-up transition the task handler applies when its work completes. Free text: it may name a transition you have not created yet.',
        ]);
        $builder->add('deadline_after', TextType::class, [
            'label' => 'Deadline — after',
            'required' => false,
            'attr' => ['placeholder' => 'e.g. P3D'],
            'help' => 'ISO-8601 duration a document may rest before the deadline fires — "PT5M" is five minutes, "P3D" is three days (§8.3).',
        ]);
        $builder->add('deadline_transition', TextType::class, [
            'label' => 'Deadline — transition',
            'required' => false,
            'attr' => $suggestions,
            'help' => 'Transition the deadline applies. Leave empty and this transition fires itself once a document has rested "after" in any of its from-places.',
        ]);
        foreach ($this->roles as $key => $role) {
            $values = $role->getValues();
            $builder->add($key, ChoiceType::class, [
                'label' => $role->getFormLabel(),
                'choices' => array_combine(array_map(ucfirst(...), $values), $values),
                'required' => false,
                'placeholder' => '(none)',
                'help' => $role->getFormHelp(),
            ]);
        }

        $ownedKeys = $this->ownedKeys();
        $extra = $builder->create('extra', TextareaType::class, [
            'label' => 'Additional metadata (advanced)',
            'required' => false,
            'attr' => ['rows' => 3],
            'help' => \sprintf('JSON object for keys the guided inputs do not manage — normally empty. The managed keys (%s) and "guard" are rejected here; an "args" object may carry unrecognised task parameters awaiting cleanup.', implode(', ', $ownedKeys)),
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
        $forms['deadline_after']->setData(\is_string($deadline['after'] ?? null) ? $deadline['after'] : null);
        $forms['deadline_transition']->setData(\is_string($deadline['transition'] ?? null) ? $deadline['transition'] : null);

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

        $deadline = [];
        foreach (['after' => 'deadline_after', 'transition' => 'deadline_transition'] as $key => $fieldName) {
            $value = $forms[$fieldName]->getData();
            if (null !== $value && '' !== $value) {
                $deadline[$key] = $value;
            }
        }
        if ([] !== $deadline) {
            $metadata['deadline'] = $deadline;
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
            'label' => \sprintf('"%s" parameters', $taskName),
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
            'label' => $arg->label,
            'required' => $arg->required,
            'help' => [] === $help ? null : implode(' ', $help),
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
            ArgType::Text => \is_string($value),
            ArgType::RuleCollection => \is_array($value),
        };
    }

    /**
     * Maps the entity validator's keyed violations (§9.4 rule 1) onto the
     * matching panel fields: "[args][degrees]" → the rotate panel's degrees
     * input. "[task]" and "[digital_first]" match their children by name
     * already; "[deadline]" lands on the after input.
     *
     * Arg names are assumed unique across tasks (true today); on a collision
     * the first task's panel wins and the message still names the key.
     *
     * @return array<string, string>
     */
    private function argsErrorMapping(): array
    {
        $mapping = ['[deadline]' => 'deadline_after'];
        foreach ($this->taskMap->names() as $taskName) {
            foreach ($this->taskMap->get($taskName)->describeArgs()->names() as $argName) {
                $mapping['[args]['.$argName.']'] ??= 'args_'.$taskName.'.'.$argName;
            }
        }

        return $mapping;
    }

    /**
     * Every transition name with the definitions it appears in, for the
     * filtered completion lists on "next"/"deadline_transition" (criterion
     * 18). JSON: {"finish_rotate": ["3"], ...}.
     */
    private function transitionSuggestions(): string
    {
        $byName = [];
        foreach ($this->transitions->findAll() as $transition) {
            $definitionId = $transition->getDefinition()?->getId();
            if (null !== $definitionId) {
                $byName[$transition->getName()][] = (string) $definitionId;
            }
        }
        ksort($byName);

        return json_encode(array_map(array_values(...), array_map(array_unique(...), $byName)), \JSON_THROW_ON_ERROR);
    }
}
