<?php

namespace WorkflowConfigurator\Task\Args;

/**
 * The input vocabulary for task parameters (specs/DynamicWorkflows.md §9.2):
 * deliberately small — just enough for the registered tasks' args — so the
 * guided form stays a handful of well-understood widgets.
 */
enum ArgType: string
{
    /** One of a fixed (possibly runtime-sourced) set of values. */
    case Choice = 'choice';

    /** A strict integer — form fields submit canonical types (§9.4). */
    case Integer = 'integer';

    /** A string, optionally constrained by a regex pattern. */
    case Text = 'string';

    /**
     * A nested structure the schema cannot express (e.g. deliver's postage
     * rules). The generic validator only recognises the key; the task's
     * validateArgs() owns its validation (§9.2).
     */
    case RuleCollection = 'rule_collection';

    /**
     * The name of another transition of the same definition, e.g. the route
     * a forking task takes when it says no (specs/DynamicWorkflows.md §10.4).
     * Rendered as the transition picker; validated as a non-empty string,
     * since a name may be given before its transition exists.
     */
    case Transition = 'transition';
}
