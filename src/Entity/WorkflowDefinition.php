<?php

namespace WorkflowConfigurator\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use WorkflowConfigurator\Repository\WorkflowDefinitionRepository;
use WorkflowConfigurator\Validator\ValidWorkflowDefinition;
use WorkflowConfigurator\WorkflowType;

/**
 * An operator-defined workflow: a named graph of places and transitions,
 * compiled into a Symfony Workflow at runtime once enabled.
 */
#[ORM\Entity(repositoryClass: WorkflowDefinitionRepository::class)]
#[ORM\Table(name: 'workflow_definition')]
#[ORM\HasLifecycleCallbacks]
#[ValidWorkflowDefinition]
class WorkflowDefinition
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Regex(pattern: '/^[a-z0-9]+(?:[_-][a-z0-9]+)*$/', message: 'Use a machine-safe slug: lowercase letters, digits, "-" or "_".')]
    private string $name = '';

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    private string $label = '';

    #[ORM\Column(length: 20, enumType: WorkflowType::class)]
    private WorkflowType $type = WorkflowType::StateMachine;

    /** Nullable at the DB level only while places are being created; enabling requires one. */
    #[ORM\ManyToOne(targetEntity: WorkflowPlace::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?WorkflowPlace $initialPlace = null;

    // Disabled by default: definitions are built incrementally and enabled
    // once complete.
    #[ORM\Column]
    private bool $enabled = false;

    /**
     * Subject property holding this workflow's marking — lets one subject run
     * several workflows under different marking properties.
     */
    #[ORM\Column(length: 50, options: ['default' => 'marking'])]
    #[Assert\NotBlank]
    #[Assert\Regex(pattern: '/^[a-zA-Z][a-zA-Z0-9]*$/', message: 'Use a property-style name, e.g. "marking" or "ingestMarking".')]
    private string $markingProperty = 'marking';

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    /** @var Collection<int, WorkflowPlace> */
    #[ORM\OneToMany(targetEntity: WorkflowPlace::class, mappedBy: 'definition', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $places;

    /** @var Collection<int, WorkflowTransition> */
    #[ORM\OneToMany(targetEntity: WorkflowTransition::class, mappedBy: 'definition', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $transitions;

    public function __construct()
    {
        $this->places = new ArrayCollection();
        $this->transitions = new ArrayCollection();
        $this->updatedAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getType(): WorkflowType
    {
        return $this->type;
    }

    public function setType(WorkflowType $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getInitialPlace(): ?WorkflowPlace
    {
        return $this->initialPlace;
    }

    public function setInitialPlace(?WorkflowPlace $initialPlace): static
    {
        $this->initialPlace = $initialPlace;

        return $this;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): static
    {
        $this->enabled = $enabled;

        return $this;
    }

    public function getMarkingProperty(): string
    {
        return $this->markingProperty;
    }

    public function setMarkingProperty(string $markingProperty): static
    {
        $this->markingProperty = $markingProperty;

        return $this;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * @return Collection<int, WorkflowPlace>
     */
    public function getPlaces(): Collection
    {
        return $this->places;
    }

    public function addPlace(WorkflowPlace $place): static
    {
        if (!$this->places->contains($place)) {
            $this->places->add($place);
            $place->setDefinition($this);
        }

        return $this;
    }

    public function removePlace(WorkflowPlace $place): static
    {
        $this->places->removeElement($place);

        return $this;
    }

    /**
     * @return Collection<int, WorkflowTransition>
     */
    public function getTransitions(): Collection
    {
        return $this->transitions;
    }

    public function addTransition(WorkflowTransition $transition): static
    {
        if (!$this->transitions->contains($transition)) {
            $this->transitions->add($transition);
            $transition->setDefinition($this);
        }

        return $this;
    }

    public function removeTransition(WorkflowTransition $transition): static
    {
        $this->transitions->removeElement($transition);

        return $this;
    }

    public function __toString(): string
    {
        return '' !== $this->label ? $this->label : $this->name;
    }
}
