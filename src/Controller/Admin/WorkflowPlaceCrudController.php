<?php

namespace WorkflowConfigurator\Controller\Admin;

use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WorkflowConfigurator\Admin\WorkflowAdminContext;
use WorkflowConfigurator\Entity\WorkflowPlace;

/**
 * specs/DynamicWorkflows.md §6.1.
 *
 * @extends AbstractCrudController<WorkflowPlace>
 */
#[IsGranted('ROLE_ADMIN')]
class WorkflowPlaceCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly WorkflowAdminContext $workflowContext,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return WorkflowPlace::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Place')
            ->setEntityLabelInPlural('Places')
            ->setDefaultSort(['definition' => 'ASC', 'name' => 'ASC'])
            ->overrideTemplate('crud/index', '@WorkflowConfigurator/crud/index.html.twig')
            ->overrideTemplate('crud/new', '@WorkflowConfigurator/crud/new.html.twig')
            ->overrideTemplate('crud/edit', '@WorkflowConfigurator/crud/edit.html.twig')
            ->overrideTemplate('crud/detail', '@WorkflowConfigurator/crud/detail.html.twig');
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters->add('definition');
    }

    /**
     * Constrained to the workflow in context, so this index is "this
     * workflow's places" rather than every place in the installation. The
     * picker's "all workflows" clears it.
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

    /**
     * A place created from inside a workflow belongs to it — one fewer
     * dropdown to get wrong. Rule 2 (§6.2) stays the authority.
     */
    public function createEntity(string $entityFqcn): WorkflowPlace
    {
        $place = new WorkflowPlace();
        $place->setDefinition($this->workflowContext->getSelected());

        return $place;
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnIndex();
        // On the index, redundant once a workflow is in context.
        if (Crud::PAGE_INDEX !== $pageName || null === $this->workflowContext->getSelectedId()) {
            yield AssociationField::new('definition');
        }
        yield TextField::new('name')
            ->setHelp('Machine-safe slug, unique within the workflow. Renaming is blocked while documents occupy this place.');
        yield TextField::new('label');
        yield TextareaField::new('metadataJson', 'Metadata (JSON)')
            ->onlyOnForms()
            ->setRequired(false)
            ->setHelp('JSON object, e.g. {"color": "#e0f2fe"}.');
    }
}
