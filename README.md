# workflow-configurator

Operator-editable workflow graphs over Symfony Workflow: definitions, places
and transitions live as Doctrine entities an operator edits, and are compiled
into `Workflow`/`StateMachine` objects at runtime. The governing principle:
**the operator configures the graph; behaviour attached to transitions remains
code**, supplied by the consuming application through tagged services.

> **Status: extraction in progress.** This bundle is being extracted from the
> application it was built in. What ships today is the persistence layer — the
> three entities, their repositories, and the schema/config seam. The runtime
> registry, guards, task contract, transition-role contract, validators and
> the EasyAdmin admin layer arrive as the extraction proceeds.

## Install

```bash
composer config repositories.workflow-configurator vcs https://github.com/gavinerickson/workflow-configurator
composer require gavinerickson/workflow-configurator:dev-main
```

Symfony Flex registers the bundle. The bundle ships **no migrations** —
consumers own their migration history. After enabling it:

```bash
php bin/console doctrine:migrations:diff   # review, then migrate
```

This generates the five workflow tables: `workflow_definition`,
`workflow_place`, `workflow_transition`, and the `workflow_transition_from`/
`_to` join tables.

## Configuration

```yaml
# config/packages/workflow_configurator.yaml
workflow_configurator:
    # Prefix for the five tables. The default matches the entity-declared
    # names; override when your schema already owns "workflow_*".
    table_prefix: workflow_
```

A custom prefix renames all five tables (join tables included). Join-table
*columns* (`workflow_transition_id`, `workflow_place_id`) keep their names —
they live inside the renamed table, so there is no collision surface.

## Design notes

- **Entities declare their table names explicitly**, so the schema never
  depends on the consumer's Doctrine naming strategy; the `table_prefix`
  listener rewrites them from that deterministic base.
- A definition is **disabled by default** and built incrementally; enabling it
  is the point at which structural validation must hold (validators arrive
  with the extraction).
- `WorkflowDefinition::$markingProperty` names the subject property holding
  this workflow's marking, so one subject can run several workflows.
- Transition **behaviour is metadata**: `task`, `next`, `deadline`, guard
  expressions and role keys are JSON on the transition; the contracts that
  interpret them (task services, role providers, occupancy checker) are
  registered by the consumer and arrive with the extraction.
- Deadlines are declared on transitions, but **sweeping them is the
  consumer's job** — the bundle will define what a deadline means, not when
  your scheduler runs.

## Development

```bash
composer install
vendor/bin/phpunit
vendor/bin/phpstan analyse
```
