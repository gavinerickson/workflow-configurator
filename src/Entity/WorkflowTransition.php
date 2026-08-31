<?php

namespace WorkflowConfigurator\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use WorkflowConfigurator\Repository\WorkflowTransitionRepository;

/**
 * A transition in an operator-defined workflow. Behaviour attaches through
 * the metadata JSON column (task, next, deadline, role keys); the guard
 * column holds an expression evaluated at runtime.
 */
#[ORM\Entity(repositoryClass: WorkflowTransitionRepository::class)]
#[ORM\Table(name: 'workflow_transition')]
class WorkflowTransition
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: WorkflowDefinition::class, inversedBy: 'transitions')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?WorkflowDefinition $definition = null;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank]
    #[Assert\Regex(pattern: '/^[a-z0-9]+(?:[_-][a-z0-9]+)*$/', message: 'Use a machine-safe slug: lowercase letters, digits, "-" or "_".')]
    private string $name = '';

    /** @var Collection<int, WorkflowPlace> */
    #[ORM\ManyToMany(targetEntity: WorkflowPlace::class)]
    #[ORM\JoinTable(name: 'workflow_transition_from')]
    private Collection $froms;

    /** @var Collection<int, WorkflowPlace> */
    #[ORM\ManyToMany(targetEntity: WorkflowPlace::class)]
    #[ORM\JoinTable(name: 'workflow_transition_to')]
    private Collection $tos;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $guard = null;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON)]
    private array $metadata = [];

    public function __construct()
    {
        $this->froms = new ArrayCollection();
        $this->tos = new ArrayCollection();
    }

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

    /**
     * @return Collection<int, WorkflowPlace>
     */
    public function getFroms(): Collection
    {
        return $this->froms;
    }

    public function addFrom(WorkflowPlace $place): static
    {
        if (!$this->froms->contains($place)) {
            $this->froms->add($place);
        }

        return $this;
    }

    public function removeFrom(WorkflowPlace $place): static
    {
        $this->froms->removeElement($place);

        return $this;
    }

    /**
     * @return Collection<int, WorkflowPlace>
     */
    public function getTos(): Collection
    {
        return $this->tos;
    }

    public function addTo(WorkflowPlace $place): static
    {
        if (!$this->tos->contains($place)) {
            $this->tos->add($place);
        }

        return $this;
    }

    public function removeTo(WorkflowPlace $place): static
    {
        $this->tos->removeElement($place);

        return $this;
    }

    public function getGuard(): ?string
    {
        return $this->guard;
    }

    public function setGuard(?string $guard): static
    {
        $this->guard = '' === $guard ? null : $guard;

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

    /**
     * Convenience accessor for the "task" metadata key.
     */
    public function getTask(): ?string
    {
        $task = $this->metadata['task'] ?? null;

        return \is_string($task) && '' !== $task ? $task : null;
    }

    public function setTask(?string $task): static
    {
        if (null === $task || '' === $task) {
            unset($this->metadata['task']);
        } else {
            $this->metadata['task'] = $task;
        }

        return $this;
    }

    public function __toString(): string
    {
        return $this->name;
    }
}
