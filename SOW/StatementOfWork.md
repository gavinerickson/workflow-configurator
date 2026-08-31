# Statement of Work — workflow-configurator

What this package guarantees a consumer. Transcribed from the behavioural
commitments of its birthplace (printmanager's `specs/DynamicWorkflows.md`
§1–§7 and `specs/WorkflowBundleExtraction.md` §10) and reframed as the
package's contract; the requirements register (`tests/Requirements/`) cites
these sections, and `composer rac` is the gate that the contract holds.

## 1. Overview

Operator-editable workflow graphs over Symfony Workflow. The governing
principle: **the operator configures the graph; behaviour attached to
transitions remains code**, supplied by the consumer through tagged services.
The package owns the graph's persistence, compilation, runtime semantics,
save-time protection and (optionally) its admin UI; the consumer owns what a
subject is, what tasks do, what roles mean, and when deadlines are swept.

## 2. Guarantees

### 2.1 Graphs are operator data, compiled at runtime

An enabled definition compiles to a `Workflow`/`StateMachine` with no YAML or
container configuration. A missing or disabled workflow is refused loudly,
never silently no-opped. A warm cache serves without definition queries, and
any ORM edit to a definition invalidates it. Dynamic workflows dispatch the
same events, in the same order, as `framework.workflows`-defined ones.

### 2.2 Guards never fail open

A transition's guard expression allows when true and blocks when false; a
guard that throws blocks the transition and logs — an error is never an
allowance.

### 2.3 Tasks ride their declared queue

A completed transition with `{"task": "..."}` dispatches that task's message
to the transport the task names; a transition without a task dispatches
nothing. Task parameters are validated against the task's declared schema at
save time — requiredness, exact types, choices, patterns, and rejection of
unknown keys.

### 2.4 Transition roles are a provider contract

A role vocabulary is one registered provider: its form field, its at-most-one-
transition-per-value rule, its required-values rule and its typo check all
derive from the provider. An unrecognised role value is refused at save, never
silently ignored.

### 2.5 Deadlines are declared, sweeping is the consumer's

A transition may declare a deadline (ISO-8601 rest duration, optional target
transition, self-firing when omitted); malformed declarations are refused at
save. The package defines what a deadline means — when a scheduler sweeps
resting subjects is consumer territory.

### 2.6 The graph is protected at save time

An enabled definition has an initial place belonging to it; transitions have
non-empty froms/tos within their definition (enforced on definition saves and
transition saves alike); state machines reject duplicate arcs; guards must
parse; a place any subject occupies can be neither renamed nor deleted;
unreachable places warn without rejecting.

### 2.7 The guided metadata form is a lossless writer

The guided form submits the canonical metadata shape with canonical types;
only the selected task's panel reaches `args`; keys and values the form cannot
represent surface in the advanced field and round-trip unchanged; keys owned
by dedicated inputs are refused there.

### 2.8 The admin layer is optional and consumer-wired

The CRUD controllers, diagram and guided form register only when the EasyAdmin
bundle is registered in the consumer's kernel; without it the package is a
headless workflow store whose container compiles cleanly. With it, a consumer
wiring only its dashboard menu gets create/edit through real forms, the guided
transition editor, and the Mermaid diagram. Access control is the consumer's.

### 2.9 The schema is five tables, prefix-configurable

Enabling the bundle maps exactly five tables (`workflow_definition`,
`workflow_place`, `workflow_transition` and the two join tables), created by
the consumer's own `doctrine:migrations:diff`; a `table_prefix` override
renames all five, join tables included. Table names never depend on the
consumer's naming strategy.

## 3. QA and acceptance

Every §2 guarantee is registered as a requirement (`tests/Requirements/`,
`REQ-001`…`REQ-008`, sections referencing this document) with `#[Verifies]`
evidence in the package's own suite, gated by `composer rac` in CI. The
fresh-skeleton CI job additionally proves the install story (§2.9) against a
real consumer. Browser-level behaviour of the dependent-select JS is
deliberately evidenced by the birthplace application's Panther suite, not
here. Gherkin scenarios are not yet written: requirements carry PHPUnit-only
warnings, honestly, until stakeholder-agreed scenarios exist.
