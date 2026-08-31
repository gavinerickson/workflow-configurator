<?php

namespace WorkflowConfigurator;

use WorkflowConfigurator\Entity\WorkflowDefinition;

/**
 * specs/DynamicWorkflows.md §6.2 rule 7 — places unreachable from the initial
 * place are a warning, not a rejection (operators may build incrementally).
 */
class ReachabilityChecker
{
    /**
     * @return list<string> names of places not reachable from the initial place
     */
    public function findUnreachablePlaces(WorkflowDefinition $definition): array
    {
        $initial = $definition->getInitialPlace()?->getName();
        if (null === $initial) {
            return [];
        }

        $edges = [];
        foreach ($definition->getTransitions() as $transition) {
            foreach ($transition->getFroms() as $from) {
                foreach ($transition->getTos() as $to) {
                    $edges[$from->getName()][] = $to->getName();
                }
            }
        }

        $reachable = [$initial => true];
        $queue = [$initial];
        while (null !== ($current = array_shift($queue))) {
            foreach ($edges[$current] ?? [] as $next) {
                if (!isset($reachable[$next])) {
                    $reachable[$next] = true;
                    $queue[] = $next;
                }
            }
        }

        $unreachable = [];
        foreach ($definition->getPlaces() as $place) {
            if (!isset($reachable[$place->getName()])) {
                $unreachable[] = $place->getName();
            }
        }

        return $unreachable;
    }
}
