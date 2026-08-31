# workflow-configurator

Operator-editable workflow graphs over Symfony Workflow: definitions, places
and transitions live as Doctrine entities an operator edits, and are compiled
into `Workflow`/`StateMachine` objects at runtime. The governing principle:
**the operator configures the graph; behaviour attached to transitions remains
code**, supplied by the consuming application through tagged services.

> **Status: extraction in progress.** This bundle is being extracted from the
> application it was built in. It now ships the full generic core: entities and
> repositories, the runtime registry (with cache invalidation), guard
> expressions, the task and transition-role contracts, deadlines (declaration
> and validation — sweeping is yours), the five save-time validators, and the
> EasyAdmin admin layer (CRUD, Mermaid diagram, guided transition form), which
> registers only when EasyAdmin and the Form component are installed. Its own
> integration test suite is the next arrival.

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

## What the consumer supplies

The operator configures the graph; behaviour attached to transitions remains
code — yours. Each seam is an interface the bundle collects or resolves:

| Seam | How |
| :--- | :--- |
| Subjects | Implement `WorkflowSubjectInterface` on the entity that moves through workflows; obtain workflows via `DynamicWorkflowRegistry::get($subject)`. |
| Tasks | Implement `WorkflowTaskInterface` — the `workflow_configurator.task` tag is applied automatically. `{"task": "name"}` on a transition dispatches your message to the queue your task names, after the transition completes. |
| Transition roles | Implement `TransitionRoleInterface` (`workflow_configurator.transition_role`, also automatic) — one class per role vocabulary: its form field, cardinality rule and typo check all derive from the provider. |
| Occupancy | Alias `PlaceOccupancyCheckerInterface` to an implementation that counts your subjects; until then the occupied-place protections are inert (`NullPlaceOccupancyChecker`). |
| Deadline sweeping | `Deadline::fromTransition()` tells you what an operator declared; when and how to sweep resting subjects is your scheduler's business. |
| Admin wiring | Point your EasyAdmin dashboard's menu at the three CRUD controllers, and add the routes import for the diagram action. Access control is yours — the controllers ship with `#[IsGranted('ROLE_ADMIN')]`. |
| Transition-form JS | With AssetMapper, add to `importmap.php`: `'workflow-configurator/transition-form' => ['path' => 'workflow-configurator/workflow_transition_form.js']`. |

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
