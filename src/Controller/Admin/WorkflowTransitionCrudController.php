<?php

namespace WorkflowConfigurator\Controller\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Config\Asset;
use EasyCorp\Bundle\EasyAdminBundle\Config\Assets;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\ArrayField;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WorkflowConfigurator\Admin\TransitionSummary;
use WorkflowConfigurator\Admin\WorkflowAdminContext;
use WorkflowConfigurator\Entity\WorkflowPlace;
use WorkflowConfigurator\Entity\WorkflowTransition;
use WorkflowConfigurator\Form\TransitionMetadataType;
use WorkflowConfigurator\ReachabilityChecker;

/**
 * specs/DynamicWorkflows.md §6.1.
 *
 * @extends AbstractCrudController<WorkflowTransition>
 */
#[IsGranted('ROLE_ADMIN')]
class WorkflowTransitionCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly ReachabilityChecker $reachabilityChecker,
        private readonly WorkflowAdminContext $workflowContext,
        private readonly TransitionSummary $summary,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return WorkflowTransition::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Transition')
            ->setEntityLabelInPlural('Transitions')
            ->setDefaultSort(['definition' => 'ASC', 'name' => 'ASC'])
            ->addFormTheme('@WorkflowConfigurator/form/transition_metadata.html.twig')
            ->overrideTemplate('crud/index', '@WorkflowConfigurator/crud/index.html.twig')
            ->overrideTemplate('crud/new', '@WorkflowConfigurator/crud/new.html.twig')
            ->overrideTemplate('crud/edit', '@WorkflowConfigurator/crud/edit.html.twig')
            ->overrideTemplate('crud/detail', '@WorkflowConfigurator/crud/detail.html.twig');
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters->add('definition');
    }

    public function configureAssets(Assets $assets): Assets
    {
        // Filters the froms/tos options to the selected definition's places
        // as the Definition dropdown changes. Only with AssetMapper installed:
        // without it the form still works, just without the dependent-select
        // and panel-toggling enhancement — EasyAdmin throws on an AssetMapper
        // entry when the component is absent, which would 500 the whole form.
        if (class_exists(\Symfony\Component\AssetMapper\AssetMapper::class)) {
            $assets = $assets->addAssetMapperEntry(Asset::new('workflow-configurator/transition-form')->onlyOnForms());
        }

        return $assets;
    }

    /**
     * Constrained to the workflow in context — see WorkflowPlaceCrudController.
     */
    public function createIndexQueryBuilder(SearchDto $searchDto, EntityDto $entityDto, FieldCollection $fields, FilterCollection $filters): QueryBuilder
    {
        $queryBuilder = parent::createIndexQueryBuilder($searchDto, $entityDto, $fields, $filters);

        if (null !== $definitionId = $this->workflowContext->getSelectedId()) {
            $queryBuilder
                ->andWhere('entity.definition = :workflowContextDefinition')
                ->setParameter('workflowContextDefinition', $definitionId);
        }

        return $queryBuilder;
    }

    public function createEntity(string $entityFqcn): WorkflowTransition
    {
        $transition = new WorkflowTransition();
        $transition->setDefinition($this->workflowContext->getSelected());

        return $transition;
    }

    public function configureFields(string $pageName): iterable
    {
        if (Crud::PAGE_INDEX === $pageName) {
            yield from $this->indexFields();

            return;
        }

        // Grouped by the question each answers, rather than one column of
        // inputs. The metadata field renders its own sections — task and
        // parameters, deadline, roles, advanced — from the same data mapper
        // (templates/form/transition_metadata.html.twig).
        yield FormField::addFieldset('Route', 'fa fa-route')
            ->setHelp('Where the document comes from, and where it ends up.');
        yield AssociationField::new('definition')
            ->setHelp('The workflow this transition belongs to.');
        yield TextField::new('name')
            ->setHelp('Machine-safe slug. Same-named transitions from the same place are rejected on state machines.');
        yield AssociationField::new('froms', 'From')
            ->setFormTypeOption('by_reference', false)
            ->setFormTypeOption('choice_attr', self::placeDefinitionAttr(...))
            ->setHelp('Places this transition can be applied from; only the selected definition\'s places are offered.');
        yield AssociationField::new('tos', 'To')
            ->setFormTypeOption('by_reference', false)
            ->setFormTypeOption('choice_attr', self::placeDefinitionAttr(...))
            ->setHelp('Places the subject moves to; only the selected definition\'s places are offered.');

        yield FormField::addFieldset('Guard', 'fa fa-shield-halved')
            ->setHelp('Whether this transition may be applied at all.');
        yield TextField::new('guard')
            ->setRequired(false)
            ->setHelp('Optional expression; variables: subject, metadata. Example: subject.getPageCount() &lt;= 6. Blocks the transition when false or on error.');

        if (Crud::PAGE_DETAIL === $pageName) {
            yield TextField::new('task');

            return;
        }

        // A header-less fieldset so the guided editor's own sections are not
        // nested inside the Guard group.
        yield FormField::addFieldset();
        yield Field::new('metadata')
            ->setLabel(false)
            ->onlyOnForms()
            ->setColumns(12)
            ->setFormType(TransitionMetadataType::class);
    }

    /**
     * The index answers "what does this workflow do" without opening eight
     * forms: where each transition runs between, and what it carries.
     *
     * @return iterable<Field|IdField|TextField|ArrayField|AssociationField>
     */
    private function indexFields(): iterable
    {
        yield IdField::new('id');
        // Redundant once a workflow is in context: every row would repeat it.
        if (null === $this->workflowContext->getSelectedId()) {
            yield AssociationField::new('definition');
        }
        yield TextField::new('name');
        yield ArrayField::new('froms', 'From');
        yield ArrayField::new('tos', 'To');
        yield TextField::new('task', 'Behaviour')
            ->formatValue(fn (mixed $value, WorkflowTransition $transition): string => $this->summary->describe($transition));
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
     * Each place option carries its definition id for the dependent-select
     * filtering (assets/workflow_transition_form.js).
     *
     * @return array<string, string>
     */
    private static function placeDefinitionAttr(WorkflowPlace $place): array
    {
        return ['data-definition' => (string) $place->getDefinition()?->getId()];
    }

    /**
     * §6.2 rule 7 — transitions change reachability too.
     */
    private function warnAboutUnreachablePlaces(WorkflowTransition $transition): void
    {
        $definition = $transition->getDefinition();
        if (null === $definition) {
            return;
        }

        $unreachable = $this->reachabilityChecker->findUnreachablePlaces($definition);
        if ([] !== $unreachable) {
            $this->addFlash('warning', \sprintf('Places not reachable from the initial place: %s.', implode(', ', $unreachable)));
        }
    }
}
