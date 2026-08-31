<?php

namespace WorkflowConfigurator\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
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
    use MetadataJsonTrait;

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

    public function __toString(): string
    {
        return $this->name;
    }
}
