<?php

namespace WorkflowConfigurator;

use WorkflowConfigurator\Repository\WorkflowDefinitionRepository;
use Symfony\Component\Workflow\DefinitionBuilder;
use Symfony\Component\Workflow\MarkingStore\MethodMarkingStore;
use Symfony\Component\Workflow\Metadata\InMemoryMetadataStore;
use Symfony\Component\Workflow\StateMachine;
use Symfony\Component\Workflow\Transition;
use Symfony\Component\Workflow\Workflow;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * The only way application code obtains dynamic workflows
 * (specs/DynamicWorkflows.md §3).
 *
 * Builds Workflow objects from database definitions at runtime. The
 * entity-derived config array is cached (invalidated by
 * WorkflowCacheInvalidator on any definition edit), so a warm cache performs
 * zero definition queries; building from the array is pure PHP.
 */
class DynamicWorkflowRegistry
{
    public const CACHE_KEY_PREFIX = 'dynamic_workflow.';

    /** @var array<string, Workflow> */
    private array $built = [];

    public function __construct(
        private readonly WorkflowDefinitionRepository $definitions,
        private readonly CacheInterface $cache,
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {
    }

    public function get(WorkflowSubjectInterface $subject): Workflow
    {
        $name = $subject->getWorkflowName();
        if (null === $name || '' === $name) {
            throw WorkflowNotFoundException::forSubjectWithoutName($subject);
        }

        return $this->getByName($name);
    }

    public function getByName(string $name): Workflow
    {
        return $this->built[$name] ??= $this->build($name, $this->loadConfig($name));
    }

    /**
     * Drops the in-process memo for a definition; called alongside cache
     * invalidation so edits are visible to the next get() (§3).
     */
    public function forget(string $name): void
    {
        unset($this->built[$name]);
    }

    /**
     * @return array{
     *     type: string,
     *     initial: ?string,
     *     markingProperty?: string,
     *     places: array<string, array<string, mixed>>,
     *     transitions: list<array{name: string, froms: list<string>, tos: list<string>, guard: ?string, metadata: array<string, mixed>}>
     * }
     */
    private function loadConfig(string $name): array
    {
        // Exceptions thrown inside the cache callback are not cached, so a
        // missing definition is re-checked on every call until it exists.
        return $this->cache->get(self::CACHE_KEY_PREFIX.$name, function () use ($name): array {
            $definition = $this->definitions->findOneEnabledByName($name);
            if (null === $definition) {
                throw WorkflowNotFoundException::forName($name);
            }

            return $this->configFromEntity($definition);
        });
    }

    /**
     * Builds a Workflow straight from an entity, bypassing cache and the
     * enabled check — for admin previews of definitions still being built
     * (§6.1); application code must use get()/getByName().
     */
    public function buildFromDefinitionEntity(\WorkflowConfigurator\Entity\WorkflowDefinition $definition): Workflow
    {
        return $this->build($definition->getName(), $this->configFromEntity($definition));
    }

    /**
     * @return array{
     *     type: string,
     *     initial: ?string,
     *     markingProperty?: string,
     *     places: array<string, array<string, mixed>>,
     *     transitions: list<array{name: string, froms: list<string>, tos: list<string>, guard: ?string, metadata: array<string, mixed>}>
     * }
     */
    private function configFromEntity(\WorkflowConfigurator\Entity\WorkflowDefinition $definition): array
    {
        $places = [];
        foreach ($definition->getPlaces() as $place) {
            $places[$place->getName()] = $place->getMetadata();
        }

        $transitions = [];
        foreach ($definition->getTransitions() as $transition) {
            $transitions[] = [
                'name' => $transition->getName(),
                'froms' => array_values(array_map(static fn ($p) => $p->getName(), $transition->getFroms()->toArray())),
                'tos' => array_values(array_map(static fn ($p) => $p->getName(), $transition->getTos()->toArray())),
                'guard' => $transition->getGuard(),
                'metadata' => $transition->getMetadata(),
            ];
        }

        return [
            'type' => $definition->getType()->value,
            'initial' => $definition->getInitialPlace()?->getName(),
            'markingProperty' => $definition->getMarkingProperty(),
            'places' => $places,
            'transitions' => $transitions,
        ];
    }

    /**
     * @param array{
     *     type: string,
     *     initial: ?string,
     *     markingProperty?: string,
     *     places: array<string, array<string, mixed>>,
     *     transitions: list<array{name: string, froms: list<string>, tos: list<string>, guard: ?string, metadata: array<string, mixed>}>
     * } $config
     */
    private function build(string $name, array $config): Workflow
    {
        $builder = new DefinitionBuilder();
        $builder->addPlaces(array_keys($config['places']));

        $isStateMachine = WorkflowType::StateMachine->value === $config['type'];

        $transitionObjects = [];
        /** @var \SplObjectStorage<Transition, array<string, mixed>> $transitionsMetadata */
        $transitionsMetadata = new \SplObjectStorage();
        foreach ($config['transitions'] as $t) {
            // State machines get OR-semantics across from-places by modelling
            // one Transition per from (exactly how framework.workflows
            // normalizes YAML state machines); the plain workflow type keeps
            // AND-semantics with a single multi-from Transition.
            $fromSets = $isStateMachine
                ? array_map(static fn (string $from): array => [$from], $t['froms'])
                : [$t['froms']];

            $metadata = $t['metadata'];
            if (null !== $t['guard']) {
                $metadata['guard'] = $t['guard'];
            }
            if ([] !== $metadata) {
                // The workflow Event API only exposes single metadata keys,
                // so the operator's metadata array is also stored whole under
                // '_all' for the guard expression context (§4).
                $metadata['_all'] = $t['metadata'];
            }

            foreach ($fromSets as $froms) {
                $transition = new Transition($t['name'], $froms, $t['tos']);
                $transitionObjects[] = $transition;
                if ([] !== $metadata) {
                    $transitionsMetadata[$transition] = $metadata;
                }
            }
        }
        $builder->addTransitions($transitionObjects);

        if (null !== $config['initial']) {
            $builder->setInitialPlaces([$config['initial']]);
        }

        $placesMetadata = array_filter($config['places']);
        // @phpstan-ignore argument.type (SplObjectStorage templates are invariant; the store holds exactly array<string, mixed>)
        $builder->setMetadataStore(new InMemoryMetadataStore([], $placesMetadata, $transitionsMetadata));

        // Subjects hold a single current place per workflow (§2.4), so both
        // workflow types use a single-state marking store; the property is
        // per-definition (specs/DocumentSubmission.md §3). The null-coalesce
        // covers cache entries written before markingProperty existed.
        $markingStore = new MethodMarkingStore(true, $config['markingProperty'] ?? 'marking');

        // State machines need the StateMachine class: a transition leaving
        // several places is enabled from ANY of them (OR), where Workflow
        // requires ALL froms to be marked (AND).
        if (WorkflowType::StateMachine->value === $config['type']) {
            return new StateMachine($builder->build(), $markingStore, $this->eventDispatcher, $name);
        }

        return new Workflow($builder->build(), $markingStore, $this->eventDispatcher, $name);
    }
}
