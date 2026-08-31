<?php

namespace WorkflowConfigurator;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * A role vocabulary a transition can declare in its metadata: one key, one
 * set of values, at most one transition per value within a definition
 * (specs/WorkflowBundleExtraction.md §4).
 *
 * Implementations are code, each owned by the spec that defines its
 * vocabulary (e.g. specs/DigitalFirstDelivery.md §4.4); *which* transition
 * plays a role is operator data. Registering a provider is the whole job:
 * the guided transition form renders its choice field
 * (TransitionMetadataType), ValidWorkflowDefinitionValidator enforces its
 * cardinality, and KnownWorkflowTaskValidator refuses typos in its key.
 */
#[AutoconfigureTag('workflow_configurator.transition_role')]
interface TransitionRoleInterface
{
    /** The metadata key operators write, e.g. "digital_first". */
    public static function getMetadataKey(): string;

    /**
     * @return list<string> every allowed value
     */
    public function getValues(): array;

    /** Adjective phrase for the typo message: '"x" is not a <vocabulary> role'. */
    public function getVocabulary(): string;

    public function getFormLabel(): string;

    /** Help text under the form field; cites the owning spec. */
    public function getFormHelp(): string;

    /**
     * The value this transition's metadata plays, or null. The seam for
     * alternate spellings — {"digital_first": "recall"} counts as
     * {"delivery": "recall"} — so cardinality is checked against what a
     * resolver would actually see, not against one key.
     *
     * @param array<string, mixed> $metadata
     */
    public function resolve(array $metadata): ?string;

    /**
     * Values that must each be claimed by some transition once any value of
     * this vocabulary is declared; empty for most roles.
     *
     * @return list<string>
     */
    public function getRequiredValues(): array;

    /** Violation template for two claimants; params {{ role }}, {{ transitions }}. */
    public function getDuplicateMessage(): string;

    /**
     * Violation template for an unclaimed required value; param {{ role }}.
     * Null only when getRequiredValues() is empty.
     */
    public function getMissingMessage(): ?string;
}
