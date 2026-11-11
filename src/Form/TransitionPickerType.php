<?php

namespace WorkflowConfigurator\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\DataMapperInterface;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use WorkflowConfigurator\Repository\WorkflowTransitionRepository;

/**
 * Names another transition (specs/DynamicWorkflows.md §10.4): a select of
 * the transitions that exist, plus an explicit "one I have not made yet"
 * option revealing a text input, so building forwards stays possible but is
 * a stated choice rather than the only way to enter a name.
 *
 * The value is the transition's name as a string — the stored shape of
 * "next", "deadline.transition" and transition-typed task parameters is
 * unchanged (§10.2).
 *
 * The select is rendered with every definition's transition names; client JS
 * (assets/workflow_transition_form.js) narrows it to the definition chosen on
 * the form and shows each transition's route. Without JS both inputs are
 * shown, and a filled text input wins.
 *
 * @extends AbstractType<string|null>
 */
class TransitionPickerType extends AbstractType implements DataMapperInterface
{
    /** Not a legal transition name (names are slugs), so it cannot collide. */
    public const NOT_MADE_YET = '__new__';

    public const BLOCK_PREFIX = 'workflow_transition_picker';

    public function __construct(
        private readonly WorkflowTransitionRepository $transitions,
    ) {
    }

    public function getBlockPrefix(): string
    {
        return self::BLOCK_PREFIX;
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $routes = $this->routes();
        $names = array_map(strval(...), array_keys($routes));

        $builder->add('pick', ChoiceType::class, [
            'label' => false,
            'required' => false,
            'placeholder' => '(none)',
            'choices' => array_combine($names, $names) + ['One I have not made yet…' => self::NOT_MADE_YET],
            'attr' => ['data-transition-picker' => json_encode($routes, \JSON_THROW_ON_ERROR | \JSON_FORCE_OBJECT)],
        ]);
        $builder->add('other', TextType::class, [
            'label' => false,
            'required' => false,
            'attr' => [
                'data-transition-picker-other' => '1',
                'placeholder' => 'the name you will give it',
                'aria-label' => 'Name of a transition not made yet',
            ],
        ]);

        $builder->setDataMapper($this);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'compound' => true,
            'required' => false,
            'empty_data' => null,
            'error_bubbling' => false,
        ]);
    }

    /**
     * @param \Traversable<int|string, FormInterface<mixed>> $forms
     */
    public function mapDataToForms(mixed $viewData, \Traversable $forms): void
    {
        $forms = iterator_to_array($forms);
        $name = \is_string($viewData) && '' !== $viewData ? $viewData : null;

        if (null === $name) {
            $forms['pick']->setData(null);
            $forms['other']->setData(null);

            return;
        }

        // Known in some definition: offered by the select. Whether it is
        // known in *this* definition is the client's to refine, and the
        // definition page's to report (§10.6).
        $known = \array_key_exists($name, $this->routes());
        $forms['pick']->setData($known ? $name : self::NOT_MADE_YET);
        $forms['other']->setData($known ? null : $name);
    }

    /**
     * @param \Traversable<int|string, FormInterface<mixed>> $forms
     */
    public function mapFormsToData(\Traversable $forms, mixed &$viewData): void
    {
        $forms = iterator_to_array($forms);

        $other = $forms['other']->getData();
        if (\is_string($other) && '' !== trim($other)) {
            $viewData = trim($other);

            return;
        }

        $pick = $forms['pick']->getData();
        $viewData = \is_string($pick) && '' !== $pick && self::NOT_MADE_YET !== $pick ? $pick : null;
    }

    /**
     * Every transition name, with its route in each definition that has it:
     * {"post_unread": {"3": "published → await_batch"}}.
     *
     * @return array<array-key, array<array-key, string>>
     */
    private function routes(): array
    {
        $routes = [];
        foreach ($this->transitions->findAll() as $transition) {
            $definitionId = $transition->getDefinition()?->getId();
            if (null === $definitionId) {
                continue;
            }

            $froms = array_map(static fn ($place): string => $place->getName(), $transition->getFroms()->toArray());
            $tos = array_map(static fn ($place): string => $place->getName(), $transition->getTos()->toArray());
            $route = implode(', ', $froms).' → '.implode(', ', $tos);

            $existing = $routes[$transition->getName()][(string) $definitionId] ?? null;
            $routes[$transition->getName()][(string) $definitionId] = null === $existing ? $route : $existing.'; '.$route;
        }
        ksort($routes);

        return $routes;
    }
}
