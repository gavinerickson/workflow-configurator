<?php

namespace WorkflowConfigurator\Admin;

use WorkflowConfigurator\Deadline;
use WorkflowConfigurator\Duration;
use WorkflowConfigurator\Entity\WorkflowDefinition;
use WorkflowConfigurator\Entity\WorkflowPlace;
use WorkflowConfigurator\Entity\WorkflowTransition;
use WorkflowConfigurator\NarratedTransitionRoleInterface;
use WorkflowConfigurator\Task\Args\ArgType;
use WorkflowConfigurator\Task\WorkflowTaskMap;
use WorkflowConfigurator\TransitionRoleMap;

/**
 * A workflow read out in sentences (specs/DynamicWorkflows.md §10.6): for
 * each place, one sentence per transition leaving it, saying when it fires,
 * what it runs and where the document goes.
 *
 * A pure function of the definition's places, transitions and metadata.
 * Nothing here is stored and nothing is inferred beyond what the runtime
 * itself would do with the same metadata, so the reading cannot disagree
 * with the configuration — and where the configuration does not add up, the
 * reading says so under "things to check" rather than guessing.
 *
 * Returns plain strings and names; escaping is the template's job.
 *
 * @phpstan-type Sentence array{transition: string, when: string, task: string|null, tos: list<string>, text: string, id: int|null}
 * @phpstan-type PlaceReading array{name: string, label: string, initial: bool, end: bool, reachable: bool, sentences: list<Sentence>, closing: string|null}
 */
class WorkflowNarrative
{
    public function __construct(
        private readonly TransitionRoleMap $roles,
        private readonly WorkflowTaskMap $tasks,
    ) {
    }

    /**
     * @return array{places: list<PlaceReading>, checks: list<string>}
     */
    public function of(WorkflowDefinition $definition): array
    {
        $transitions = array_values($definition->getTransitions()->toArray());
        usort($transitions, static fn (WorkflowTransition $a, WorkflowTransition $b): int => $a->getName() <=> $b->getName());

        $initial = $definition->getInitialPlace();
        [$ordered, $reached] = $this->walk($definition, $transitions);

        $places = [];
        $checks = null === $initial && [] !== $ordered ? ['No initial place is set, so nothing says where a document starts.'] : [];

        foreach ($ordered as $place) {
            $leaving = array_values(array_filter($transitions, static fn (WorkflowTransition $t): bool => $t->getFroms()->contains($place)));
            $reachable = null === $initial || \in_array($place, $reached, true);

            $sentences = [];
            foreach ($leaving as $transition) {
                $sentences[] = $this->sentence($transition, $place, $transitions);
            }

            $closing = null;
            if ([] === $leaving) {
                $closing = $place->isEnd()
                    ? 'Documents finish here.'
                    : 'Nothing moves a document on from here, and it is not marked as an end.';
                if (!$place->isEnd()) {
                    $checks[] = \sprintf('"%s" has no way out and is not marked as an end.', $place->getName());
                }
            } elseif ($place->isEnd()) {
                $checks[] = \sprintf('"%s" is marked as an end but %s leaves it.', $place->getName(), self::quoted(array_map(static fn (WorkflowTransition $t): string => $t->getName(), $leaving)));
            }

            if (!$reachable) {
                $checks[] = \sprintf('"%s" cannot be reached from the start.', $place->getName());
            }

            $places[] = [
                'name' => $place->getName(),
                'label' => '' !== $place->getLabel() ? $place->getLabel() : $place->getName(),
                'initial' => $place === $initial,
                'end' => $place->isEnd(),
                'reachable' => $reachable,
                'sentences' => $sentences,
                'closing' => $closing,
            ];
        }

        foreach ($transitions as $transition) {
            array_push($checks, ...$this->namingProblems($transition, $transitions));
        }

        return ['places' => $places, 'checks' => array_values(array_unique($checks))];
    }

    /**
     * One transition's sentences — one per place it leaves — for the
     * transition form, which shows what the saved transition currently says.
     *
     * @return list<string>
     */
    public function sentencesFor(WorkflowTransition $transition): array
    {
        $definition = $transition->getDefinition();
        if (null === $definition) {
            return [];
        }

        $all = array_values($definition->getTransitions()->toArray());
        $sentences = [];
        foreach ($transition->getFroms() as $from) {
            $label = '' !== $from->getLabel() ? $from->getLabel() : $from->getName();
            $sentences[] = \sprintf('In %s: %s', $label, lcfirst($this->sentence($transition, $from, $all)['text']));
        }

        return $sentences;
    }

    /**
     * The names this transition gives that the definition cannot honour:
     * a follow-up, deadline transition or transition-typed parameter naming
     * no transition, or one that does not start where this one ends.
     *
     * @param list<WorkflowTransition>|null $all the definition's transitions, when already at hand
     *
     * @return list<string>
     */
    public function namingProblems(WorkflowTransition $transition, ?array $all = null): array
    {
        $all ??= array_values($transition->getDefinition()?->getTransitions()->toArray() ?? []);

        $problems = [];
        foreach ($this->namedFollowUps($transition) as $followUp) {
            ['how' => $what, 'name' => $name] = $followUp;
            $sameName = array_filter($all, static fn (WorkflowTransition $t): bool => $t->getName() === $name);
            if ([] === $sameName) {
                $problems[] = \sprintf('"%s" names "%s" as %s, and no transition is called that.', $transition->getName(), $name, $what);
                continue;
            }

            $startsHere = false;
            foreach ($sameName as $candidate) {
                foreach ($transition->getTos() as $to) {
                    $startsHere = $startsHere || $candidate->getFroms()->contains($to);
                }
            }
            if (!$startsHere) {
                $problems[] = \sprintf(
                    '"%s" names "%s" as %s, but "%s" does not start from %s, where "%s" leaves the document.',
                    $transition->getName(), $name, $what, $name,
                    self::quoted(array_map(static fn (WorkflowPlace $p): string => $p->getName(), $transition->getTos()->toArray())),
                    $transition->getName(),
                );
            }
        }

        return $problems;
    }

    /**
     * Places in breadth-first order from the initial place, then the rest by
     * name; and the set the walk reached.
     *
     * @param list<WorkflowTransition> $transitions
     *
     * @return array{list<WorkflowPlace>, list<WorkflowPlace>}
     */
    private function walk(WorkflowDefinition $definition, array $transitions): array
    {
        $reached = [];
        $queue = null === $definition->getInitialPlace() ? [] : [$definition->getInitialPlace()];
        while ([] !== $queue) {
            $place = array_shift($queue);
            if (\in_array($place, $reached, true)) {
                continue;
            }
            $reached[] = $place;

            foreach ($transitions as $transition) {
                if ($transition->getFroms()->contains($place)) {
                    foreach ($transition->getTos() as $to) {
                        $queue[] = $to;
                    }
                }
            }
        }

        $rest = array_values(array_filter($definition->getPlaces()->toArray(), static fn (WorkflowPlace $p): bool => !\in_array($p, $reached, true)));
        usort($rest, static fn (WorkflowPlace $a, WorkflowPlace $b): int => $a->getName() <=> $b->getName());

        return [[...$reached, ...$rest], $reached];
    }

    /**
     * @param list<WorkflowTransition> $all
     *
     * @return Sentence
     */
    private function sentence(WorkflowTransition $transition, WorkflowPlace $from, array $all): array
    {
        $when = self::joinWhen($this->triggers($transition, $from, $all));
        $task = $transition->getTask();
        $tos = array_values(array_map(
            static fn (WorkflowPlace $p): string => '' !== $p->getLabel() ? $p->getLabel() : $p->getName(),
            $transition->getTos()->toArray(),
        ));

        return [
            'transition' => $transition->getName(),
            'when' => $when,
            'task' => $task,
            'tos' => $tos,
            'id' => $transition->getId(),
            'text' => \sprintf(
                '%s, %s %s and the document moves to %s.',
                $when,
                $transition->getName(),
                null === $task ? 'does nothing else' : 'runs '.$task,
                [] === $tos ? 'nowhere' : implode(' and ', $tos),
            ),
        ];
    }

    /**
     * Everything that fires this transition for a document resting in
     * $from, as clauses: time first, then what another transition's task
     * hands on, then outside events.
     *
     * @param list<WorkflowTransition> $all
     *
     * @return list<string>
     */
    private function triggers(WorkflowTransition $transition, WorkflowPlace $from, array $all): array
    {
        $name = $transition->getName();
        $triggers = [];

        $own = Deadline::fromTransition($transition);
        if (null !== $own && $own->selfFiring) {
            $triggers[] = self::afterClause($own->after);
        }

        foreach ($all as $inbound) {
            if (!$inbound->getTos()->contains($from)) {
                continue;
            }

            $deadline = Deadline::fromTransition($inbound);
            if (null !== $deadline && !$deadline->selfFiring && $deadline->transition === $name) {
                $triggers[] = self::afterClause($deadline->after);
            }

            $task = $inbound->getTask();
            if (null === $task) {
                continue;
            }
            foreach ($this->namedFollowUps($inbound) as $followUp) {
                if ($followUp['name'] !== $name || 'deadline' === $followUp['kind']) {
                    continue;
                }
                $triggers[] = match (true) {
                    'next' === $followUp['kind'] => \sprintf('when %s finishes', $task),
                    'otherwise' === $followUp['arg'] => \sprintf('when %s says otherwise', $task),
                    default => \sprintf('when %s chooses %s', $task, $followUp['how']),
                };
            }
        }

        $metadata = $transition->getMetadata();
        foreach ($this->roles as $key => $role) {
            $value = $metadata[$key] ?? null;
            if (!\is_string($value) || '' === $value) {
                continue;
            }

            $phrase = $role instanceof NarratedTransitionRoleInterface ? $role->getNarrative($value) : null;
            $triggers[] = 'when '.($phrase ?? \sprintf('applied as the %s role "%s"', $role->getVocabulary(), $value));
        }

        $triggers = array_values(array_unique($triggers));

        return [] === $triggers ? ['when someone applies it'] : $triggers;
    }

    /**
     * The other transitions this one names: by "next", by a transition-typed
     * task parameter, or as what follows its wait. "how" is the wording a
     * check uses for it.
     *
     * @return list<array{kind: 'next'|'arg'|'deadline', how: string, arg: string|null, name: string}>
     */
    private function namedFollowUps(WorkflowTransition $transition): array
    {
        $metadata = $transition->getMetadata();
        $named = [];

        $next = $metadata['next'] ?? null;
        if (\is_string($next) && '' !== $next) {
            $named[] = ['kind' => 'next', 'how' => 'what follows its task', 'arg' => null, 'name' => $next];
        }

        $task = $transition->getTask();
        if (null !== $task && $this->tasks->has($task)) {
            $args = \is_array($metadata['args'] ?? null) ? $metadata['args'] : [];
            foreach ($this->tasks->get($task)->describeArgs()->args as $arg) {
                $value = $args[$arg->name] ?? null;
                if (ArgType::Transition === $arg->type && \is_string($value) && '' !== $value) {
                    $named[] = ['kind' => 'arg', 'how' => \sprintf('its "%s"', mb_strtolower($arg->label)), 'arg' => $arg->name, 'name' => $value];
                }
            }
        }

        $own = Deadline::fromTransition($transition);
        if (null !== $own && !$own->selfFiring) {
            $named[] = ['kind' => 'deadline', 'how' => 'what follows its wait', 'arg' => null, 'name' => $own->transition];
        }

        return $named;
    }

    private static function afterClause(\DateInterval $after): string
    {
        return Duration::isStraightAway($after) ? 'straight away' : \sprintf('after %s here', Duration::words($after));
    }

    /**
     * @param list<string> $clauses
     */
    private static function joinWhen(array $clauses): string
    {
        return ucfirst(implode(', or ', $clauses));
    }

    /**
     * @param array<array-key, string> $names
     */
    private static function quoted(array $names): string
    {
        return implode(' and ', array_map(static fn (string $name): string => '"'.$name.'"', array_values($names)));
    }
}
