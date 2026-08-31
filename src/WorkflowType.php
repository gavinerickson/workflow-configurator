<?php

namespace WorkflowConfigurator;

/**
 * Which Symfony Workflow flavour a definition compiles to: a state machine
 * (single marking, exclusive places) or a workflow (multiple markings).
 */
enum WorkflowType: string
{
    case StateMachine = 'state_machine';
    case Workflow = 'workflow';
}
