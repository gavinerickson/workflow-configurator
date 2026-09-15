<?php

namespace WorkflowConfigurator\Admin;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use WorkflowConfigurator\Entity\WorkflowDefinition;
use WorkflowConfigurator\Repository\WorkflowDefinitionRepository;

/**
 * The workflow an operator is currently working on, remembered across the
 * three admin screens.
 *
 * The Places and Transitions indexes have always been filterable by
 * definition, but the filter is per-page state: walking Definitions → Places
 * → Transitions dropped it at every hop, so on a deployment with more than a
 * handful of graphs the three screens stopped being a way to work on *a*
 * workflow and became three flat cross-workflow lists.
 *
 * The selection lives in the session and is set three ways, in this order of
 * authority: the `workflowContext` query parameter (what the picker and the
 * "all workflows" control submit), an explicit `definition` filter on an
 * index (so the long-standing links from a definition's row still work and
 * now also set the context), and otherwise whatever was last chosen.
 *
 * This is navigation only. Which places a transition may join remains the
 * business of TransitionPlacesBelongToDefinition and ValidWorkflowDefinition
 * (SOW §2.6) — a context that disagreed with a submitted form would be
 * refused at save time exactly as it is today.
 */
class WorkflowAdminContext
{
    public const QUERY_PARAM = 'workflowContext';

    /** Value of QUERY_PARAM that clears the selection. */
    public const ALL = 'all';

    private const SESSION_KEY = 'workflow_configurator.admin.definition';

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly WorkflowDefinitionRepository $definitions,
    ) {
    }

    /**
     * The selected definition id, resolving whatever the current request says
     * about it first.
     */
    public function getSelectedId(): ?int
    {
        $request = $this->requestStack->getCurrentRequest();
        if (null === $request || !$request->hasSession()) {
            return null;
        }

        if (null !== $fromRequest = self::readRequest($request)) {
            $this->select(self::ALL === $fromRequest ? null : (int) $fromRequest);
        }

        $stored = $request->getSession()->get(self::SESSION_KEY);

        return \is_int($stored) ? $stored : null;
    }

    public function getSelected(): ?WorkflowDefinition
    {
        $id = $this->getSelectedId();

        if (null === $id) {
            return null;
        }

        // A definition deleted in another tab must not pin every index to a
        // row that no longer exists.
        $definition = $this->definitions->find($id);
        if (null === $definition) {
            $this->select(null);
        }

        return $definition;
    }

    public function select(?int $id): void
    {
        $request = $this->requestStack->getCurrentRequest();
        if (null === $request || !$request->hasSession()) {
            return;
        }

        $session = $request->getSession();
        if (null === $id) {
            $session->remove(self::SESSION_KEY);

            return;
        }

        $session->set(self::SESSION_KEY, $id);
    }

    /**
     * Every definition, for the picker.
     *
     * @return list<WorkflowDefinition>
     */
    public function definitions(): array
    {
        return $this->definitions->findBy([], ['label' => 'ASC']);
    }

    /**
     * What the current request asks the context to become: the query
     * parameter if present, otherwise an explicit definition filter, otherwise
     * nothing. Returns self::ALL for "clear", a numeric string for a
     * selection, or null to leave the stored value alone.
     */
    private static function readRequest(Request $request): ?string
    {
        if ($request->query->has(self::QUERY_PARAM)) {
            $raw = trim((string) $request->query->get(self::QUERY_PARAM));

            return '' === $raw || self::ALL === $raw || !ctype_digit($raw) ? self::ALL : $raw;
        }

        $filters = $request->query->all('filters');
        $value = $filters['definition']['value'] ?? null;
        if (\is_string($value) && ctype_digit($value)) {
            return $value;
        }

        return null;
    }
}
