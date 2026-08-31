<?php

namespace WorkflowConfigurator\Controller\Admin;

use WorkflowConfigurator\Entity\WorkflowPlace;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * specs/DynamicWorkflows.md §6.1.
 *
 * @extends AbstractCrudController<WorkflowPlace>
 */
#[IsGranted('ROLE_ADMIN')]
class WorkflowPlaceCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return WorkflowPlace::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Place')
            ->setEntityLabelInPlural('Places')
            ->setDefaultSort(['definition' => 'ASC', 'name' => 'ASC']);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters->add('definition');
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnIndex();
        yield AssociationField::new('definition');
        yield TextField::new('name')
            ->setHelp('Machine-safe slug, unique within the workflow. Renaming is blocked while documents occupy this place.');
        yield TextField::new('label');
        yield TextareaField::new('metadataJson', 'Metadata (JSON)')
            ->onlyOnForms()
            ->setRequired(false)
            ->setHelp('JSON object, e.g. {"color": "#e0f2fe"}.');
    }
}
