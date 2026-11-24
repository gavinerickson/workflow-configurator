<?php

namespace WorkflowConfigurator\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use WorkflowConfigurator\Repository\WorkflowPlaceRepository;
use WorkflowConfigurator\Validator\ProtectedOccupiedPlace;

/**
 * A place in an operator-defined workflow.
 */
#[ORM\Entity(repositoryClass: WorkflowPlaceRepository::class)]
#[ORM\Table(name: 'workflow_place')]
#[ORM\UniqueConstraint(name: 'uniq_place_per_definition', columns: ['definition_id', 'name'])]
#[ProtectedOccupiedPlace]
class WorkflowPlace
{
    use MetadataJsonTrait {
        getMetadataJson as private allMetadataJson;
        setMetadataJson as private setAllMetadataJson;
    }

    /**
     * The metadata key marking a place where documents finish
     * (specs/DynamicWorkflows.md §10.5). It changes nothing a document does
     * in the workflow, and a transition may still leave the place (a
     * recall). It lets the workflow read out in words (§10.6) tell an end
     * from a place nobody has drawn a way out of, and an application may read
     * it through isEnd() to treat documents there as finished.
     */
    public const END_KEY = 'end';

    private bool $endKeyTyped = false;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: WorkflowDefinition::class, inversedBy: 'places')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?WorkflowDefinition $definition = null;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank]
    #[Assert\Regex(pattern: '/^[a-z0-9]+(?:[_-][a-z0-9]+)*$/', message: 'Use a machine-safe slug: lowercase letters, digits, "-" or "_".')]
    private string $name = '';

    #[ORM\Column(length: 255)]
    private string $label = '';

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON)]
    private array $metadata = [];

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDefinition(): ?WorkflowDefinition
    {
        return $this->definition;
    }

    public function setDefinition(?WorkflowDefinition $definition): static
    {
        $this->definition = $definition;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): static
    {
        $this->label = $label;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function setMetadata(array $metadata): static
    {
        $this->metadata = $metadata;

        return $this;
    }

    public function isEnd(): bool
    {
        return true === ($this->metadata[self::END_KEY] ?? null);
    }

    public function setEnd(bool $end): static
    {
        if ($end) {
            $this->metadata[self::END_KEY] = true;
        } else {
            unset($this->metadata[self::END_KEY]);
        }

        return $this;
    }

    /**
     * The metadata as the admin form's JSON textarea shows it: everything
     * but the end mark, which has an input of its own. Two inputs writing
     * one key is the defect §9.1 records for transitions.
     */
    public function getMetadataJson(): string
    {
        if (null !== $this->invalidMetadataJson) {
            return $this->invalidMetadataJson;
        }

        $end = $this->isEnd();
        unset($this->metadata[self::END_KEY]);
        try {
            return $this->allMetadataJson();
        } finally {
            $this->setEnd($end);
        }
    }

    /**
     * Replaces every key except the end mark, which survives whatever the
     * textarea holds and in whichever order the form writes its fields.
     */
    public function setMetadataJson(?string $json): static
    {
        $end = $this->isEnd();
        $this->setAllMetadataJson($json);

        $this->endKeyTyped = null === $this->invalidMetadataJson && \array_key_exists(self::END_KEY, $this->metadata);
        $this->setEnd($end);

        return $this;
    }

    #[Assert\Callback]
    public function validateEndKeyNotTyped(ExecutionContextInterface $context): void
    {
        if ($this->endKeyTyped) {
            $context->buildViolation('"end" belongs to the "Documents finish here" box and cannot be set here.')
                ->atPath('metadataJson')
                ->addViolation();
        }
    }

    public function __toString(): string
    {
        return $this->name;
    }
}
