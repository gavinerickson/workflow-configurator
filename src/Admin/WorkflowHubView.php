<?php

namespace WorkflowConfigurator\Admin;

use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use Symfony\Component\Workflow\Dumper\MermaidDumper;
use WorkflowConfigurator\Controller\Admin\WorkflowPlaceCrudController;
use WorkflowConfigurator\Controller\Admin\WorkflowTransitionCrudController;
use WorkflowConfigurator\DynamicWorkflowRegistry;
use WorkflowConfigurator\Entity\WorkflowDefinition;
use WorkflowConfigurator\PlaceOccupancyCheckerInterface;
use WorkflowConfigurator\ReachabilityChecker;
use WorkflowConfigurator\WorkflowType;

/**
 * Everything the definition detail page shows about a workflow in one read:
 * the graph, its places and what occupies them, and its transitions with what
 * each carries.
 *
 * The three were previously a page each — fields here, diagram behind an
 * action, places and transitions behind two filtered indexes — so answering
 * "what does this workflow do" meant four screens. Assembled in PHP so the
 * template stays declarative.
 */
class WorkflowHubView
{
    public function __construct(
        private readonly DynamicWorkflowRegistry $registry,
        private readonly ReachabilityChecker $reachabilityChecker,
        private readonly PlaceOccupancyCheckerInterface $occupancy,
        private readonly TransitionSummary $summary,
        private readonly AdminUrlGeneratorInterface $urls,
    ) {
    }

    /**
     * @return array{
     *     mermaid: string,
     *     unreachable: list<string>,
     *     places: list<array{name: string, label: string, initial: bool, occupancy: int, url: string}>,
     *     transitions: list<array{name: string, froms: list<string>, tos: list<string>, behaviour: string, url: string}>,
     *     addPlaceUrl: string,
     *     addTransitionUrl: string,
     * }
     */
    public function of(WorkflowDefinition $definition): array
    {
        return [
            'mermaid' => $this->mermaid($definition),
            'unreachable' => $this->reachabilityChecker->findUnreachablePlaces($definition),
            'places' => $this->places($definition),
            'transitions' => $this->transitions($definition),
            'addPlaceUrl' => $this->newUrl(WorkflowPlaceCrudController::class),
            'addTransitionUrl' => $this->newUrl(WorkflowTransitionCrudController::class),
        ];
    }

    /**
     * The Mermaid source, or an empty string when there is nothing worth
     * drawing.
     *
     * A definition is created empty and built incrementally (§6.2 rule 1), and
     * the dumper is happy to emit a bare "graph LR" header for a graph with no
     * places — an empty box on the page. Below one place there is nothing to
     * show, so the panels do the talking. The catch is the same courtesy for a
     * graph the registry cannot build at all: this is the page an operator
     * builds the workflow from, so it must not be the page that breaks.
     */
    private function mermaid(WorkflowDefinition $definition): string
    {
        if ($definition->getPlaces()->isEmpty()) {
            return '';
        }

        try {
            $dumper = new MermaidDumper(
                WorkflowType::StateMachine === $definition->getType()
                    ? MermaidDumper::TRANSITION_TYPE_STATEMACHINE
                    : MermaidDumper::TRANSITION_TYPE_WORKFLOW
            );

            return $dumper->dump($this->registry->buildFromDefinitionEntity($definition)->getDefinition());
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * @return list<array{name: string, label: string, initial: bool, occupancy: int, url: string}>
     */
    private function places(WorkflowDefinition $definition): array
    {
        $initial = $definition->getInitialPlace()?->getName();

        $places = [];
        foreach ($definition->getPlaces() as $place) {
            $places[] = [
                'name' => $place->getName(),
                'label' => $place->getLabel(),
                'initial' => $place->getName() === $initial,
                'occupancy' => $this->occupancy->countSubjectsIn($definition->getName(), $place->getName()),
                'url' => $this->editUrl(WorkflowPlaceCrudController::class, $place->getId()),
            ];
        }

        usort($places, static fn (array $a, array $b): int => [$b['initial'], $a['name']] <=> [$a['initial'], $b['name']]);

        return $places;
    }

    /**
     * @return list<array{name: string, froms: list<string>, tos: list<string>, behaviour: string, url: string}>
     */
    private function transitions(WorkflowDefinition $definition): array
    {
        $transitions = [];
        foreach ($definition->getTransitions() as $transition) {
            $transitions[] = [
                'name' => $transition->getName(),
                'froms' => array_values(array_map(static fn ($place): string => $place->getName(), $transition->getFroms()->toArray())),
                'tos' => array_values(array_map(static fn ($place): string => $place->getName(), $transition->getTos()->toArray())),
                'behaviour' => $this->summary->describe($transition),
                'url' => $this->editUrl(WorkflowTransitionCrudController::class, $transition->getId()),
            ];
        }

        usort($transitions, static fn (array $a, array $b): int => $a['name'] <=> $b['name']);

        return $transitions;
    }

    /**
     * @param class-string $controller
     */
    private function editUrl(string $controller, ?int $id): string
    {
        if (null === $id) {
            return '';
        }

        return $this->urls->setController($controller)->setAction(Action::EDIT)->setEntityId($id)->generateUrl();
    }

    /**
     * @param class-string $controller
     */
    private function newUrl(string $controller): string
    {
        return $this->urls->setController($controller)->setAction(Action::NEW)->unset('entityId')->generateUrl();
    }
}
