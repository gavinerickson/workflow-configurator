<?php

namespace WorkflowConfigurator;

use WorkflowConfigurator\Entity\WorkflowDefinition;
use WorkflowConfigurator\Entity\WorkflowPlace;

/**
 * specs/DynamicWorkflows.md §10.5 — a place marked "Documents finish here"
 * that a Timing transition leaves. An application may treat a document at
 * an end place as finished (archive its files, count it as done), and an
 * operator's way out such as a recall sits well with that. A transition
 * that moves the document on by itself after a time does not: the workflow
 * is probably drawn wrongly. Reported, never refused.
 */
class EndPlaceChecker
{
    /**
     * A Timing transition leaves an end place when it fires itself from one,
     * or when it leads into one and its deadline names a follow-up that
     * leaves it (§8.3).
     *
     * @return list<array{place: string, transition: string}>
     */
    public function findTimedExits(WorkflowDefinition $definition): array
    {
        $exits = [];
        foreach ($definition->getTransitions() as $transition) {
            $deadline = Deadline::fromTransition($transition);
            if (null === $deadline) {
                continue;
            }
            if ($deadline->selfFiring) {
                foreach ($transition->getFroms() as $from) {
                    if ($from->isEnd()) {
                        $exits[$from->getName().' '.$transition->getName()] = ['place' => $from->getName(), 'transition' => $transition->getName()];
                    }
                }

                continue;
            }
            foreach ($transition->getTos() as $to) {
                if ($to->isEnd() && $this->leaves($definition, $deadline->transition, $to)) {
                    $exits[$to->getName().' '.$deadline->transition] = ['place' => $to->getName(), 'transition' => $deadline->transition];
                }
            }
        }

        return array_values($exits);
    }

    /**
     * The same, as sentences for an operator.
     *
     * @return list<string>
     */
    public function describeTimedExits(WorkflowDefinition $definition): array
    {
        return array_map(
            static fn (array $exit): string => \sprintf('Documents finish at "%s", but "%s" moves them on from there by itself after a time. Clear the end mark or take the timing off, unless that is what you mean.', $exit['place'], $exit['transition']),
            $this->findTimedExits($definition),
        );
    }

    private function leaves(WorkflowDefinition $definition, string $transitionName, WorkflowPlace $place): bool
    {
        foreach ($definition->getTransitions() as $transition) {
            if ($transition->getName() === $transitionName && $transition->getFroms()->contains($place)) {
                return true;
            }
        }

        return false;
    }
}
