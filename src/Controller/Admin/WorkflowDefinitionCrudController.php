<?php

namespace WorkflowConfigurator\Controller\Admin;

use WorkflowConfigurator\Entity\WorkflowDefinition;
use WorkflowConfigurator\DynamicWorkflowRegistry;
use WorkflowConfigurator\ReachabilityChecker;
use WorkflowConfigurator\WorkflowType;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Workflow\Dumper\MermaidDumper;

/**
 * specs/DynamicWorkflows.md §6.1.
 *
 * @extends AbstractCrudController<WorkflowDefinition>
 */
#[IsGranted('ROLE_ADMIN')]
class WorkflowDefinitionCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly DynamicWorkflowRegistry $registry,
        private readonly ReachabilityChecker $reachabilityChecker,
        private readonly AdminUrlGenerator $adminUrlGenerator,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return WorkflowDefinition::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Workflow definition')
            ->setEntityLabelInPlural('Workflow definitions')
            ->setDefaultSort(['name' => 'ASC']);
    }

    public function configureActions(Actions $actions): Actions
    {
        $diagram = Action::new('renderDiagram', 'Diagram', 'fa fa-diagram-project')
            ->linkToCrudAction('renderDiagram');

        // Straight into this workflow's pieces: the Places/Transitions
        // indexes pre-filtered to the definition (§6.1).
        $places = Action::new('filteredPlaces', 'Places', 'fa fa-circle-dot')
            ->linkToUrl(fn (WorkflowDefinition $definition): string => $this->filteredIndexUrl(WorkflowPlaceCrudController::class, $definition));
        $transitions = Action::new('filteredTransitions', 'Transitions', 'fa fa-arrow-right')
            ->linkToUrl(fn (WorkflowDefinition $definition): string => $this->filteredIndexUrl(WorkflowTransitionCrudController::class, $definition));

        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_INDEX, $diagram)
            ->add(Crud::PAGE_INDEX, $places)
            ->add(Crud::PAGE_INDEX, $transitions)
            ->add(Crud::PAGE_DETAIL, $diagram)
            ->add(Crud::PAGE_DETAIL, $places)
            ->add(Crud::PAGE_DETAIL, $transitions);
    }

    /**
     * @param class-string $crudController
     */
    private function filteredIndexUrl(string $crudController, WorkflowDefinition $definition): string
    {
        return $this->adminUrlGenerator
            ->setController($crudController)
            ->setAction(Action::INDEX)
            ->set('filters', ['definition' => ['comparison' => '=', 'value' => (string) $definition->getId()]])
            ->generateUrl();
    }

    public function configureFields(string $pageName): iterable
    {
        /** @var ?WorkflowDefinition $current */
        $current = $this->getContext()?->getEntity()?->getInstance();

        yield IdField::new('id')->onlyOnIndex();
        yield TextField::new('name')
            ->setHelp('Machine-safe slug; subjects reference workflows by this name.');
        yield TextField::new('label');
        yield ChoiceField::new('type')
            ->setChoices(['State machine' => WorkflowType::StateMachine, 'Workflow' => WorkflowType::Workflow]);
        yield BooleanField::new('enabled')
            ->setHelp('Only enabled workflows are served to application code. Enabling requires an initial place.');
        yield AssociationField::new('initialPlace')
            ->hideWhenCreating()
            ->setQueryBuilder(function ($queryBuilder) use ($current) {
                return $queryBuilder
                    ->andWhere('entity.definition = :definition')
                    ->setParameter('definition', $current?->getId());
            })
            ->setHelp('Where new subjects start. Must be one of this workflow\'s places.');
        yield AssociationField::new('places')->onlyOnIndex();
        yield AssociationField::new('transitions')->onlyOnIndex();
        yield DateTimeField::new('updatedAt')->hideOnForm();
    }

    public function persistEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        parent::persistEntity($entityManager, $entityInstance);
        $this->warnAboutUnreachablePlaces($entityInstance);
    }

    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        parent::updateEntity($entityManager, $entityInstance);
        $this->warnAboutUnreachablePlaces($entityInstance);
    }

    /**
     * @param AdminContext<WorkflowDefinition> $context
     */
    #[AdminRoute(path: '/{entityId}/diagram', name: 'diagram')]
    public function renderDiagram(AdminContext $context): Response
    {
        /** @var WorkflowDefinition $definition */
        $definition = $context->getEntity()->getInstance();

        $dumper = new MermaidDumper(
            WorkflowType::StateMachine === $definition->getType()
                ? MermaidDumper::TRANSITION_TYPE_STATEMACHINE
                : MermaidDumper::TRANSITION_TYPE_WORKFLOW
        );
        $workflow = $this->registry->buildFromDefinitionEntity($definition);

        return $this->render('@WorkflowConfigurator/workflow_diagram.html.twig', [
            'definition' => $definition,
            'mermaid' => $dumper->dump($workflow->getDefinition()),
            'unreachable' => $this->reachabilityChecker->findUnreachablePlaces($definition),
        ]);
    }

    /**
     * §6.2 rule 7 — unreachable places warn, never reject.
     */
    private function warnAboutUnreachablePlaces(WorkflowDefinition $definition): void
    {
        $unreachable = $this->reachabilityChecker->findUnreachablePlaces($definition);
        if ([] !== $unreachable) {
            $this->addFlash('warning', \sprintf('Places not reachable from the initial place: %s.', implode(', ', $unreachable)));
        }
    }
}
