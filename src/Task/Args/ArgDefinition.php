<?php

namespace WorkflowConfigurator\Task\Args;

/**
 * One operator-facing task parameter (specs/DynamicWorkflows.md §9.2): the
 * machine-readable authority both the guided form and the generic validator
 * read, so a task cannot render one rule and validate another.
 */
final readonly class ArgDefinition
{
    /**
     * @param string                $name        the "args" key
     * @param string                $label       operator-facing field label
     * @param ArgType               $type        input vocabulary (§9.2)
     * @param bool                  $required    absence is a save-time error
     * @param string|null           $help        operator-facing guidance; carries what
     *                                           otherwise lives only in specs
     * @param int|string|null       $default     shown in the form, never silently
     *                                           merged (§9.2) — runtime defaults stay
     *                                           the task's business
     * @param list<int|string>|null $choices     allowed values (Choice only)
     * @param string|null           $pattern     PCRE the value must match (Text only)
     * @param string|null           $patternHint human wording of the pattern, used in
     *                                           the violation message and form help
     * @param string|null           $formType    form type rendering this parameter's
     *                                           widget, for structures the built-in
     *                                           widgets cannot express — required at
     *                                           render time for RuleCollection
     *                                           (specs/WorkflowBundleExtraction.md §5).
     *                                           The task supplies the class; the guided
     *                                           form stays generic.
     */
    public function __construct(
        public string $name,
        public string $label,
        public ArgType $type,
        public bool $required = false,
        public ?string $help = null,
        public int|string|null $default = null,
        public ?array $choices = null,
        public ?string $pattern = null,
        public ?string $patternHint = null,
        /** @var class-string<\Symfony\Component\Form\AbstractType<mixed>>|null */
        public ?string $formType = null,
    ) {
        // Empty is allowed — a runtime-sourced list (e.g. configured
        // providers) may legitimately have no entries yet; null is an
        // authoring mistake.
        if (ArgType::Choice === $type && null === $choices) {
            throw new \LogicException(\sprintf('Choice parameter "%s" declares no choices.', $name));
        }
        if (null !== $pattern && ArgType::Text !== $type) {
            throw new \LogicException(\sprintf('Parameter "%s" declares a pattern but is not a text parameter.', $name));
        }
    }
}
