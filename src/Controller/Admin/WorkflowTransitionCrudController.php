<?php

namespace WorkflowConfigurator\Controller\Admin;

use WorkflowConfigurator\Entity\WorkflowPlace;
use WorkflowConfigurator\Entity\WorkflowTransition;
use WorkflowConfigurator\Form\TransitionMetadataType;
use WorkflowConfigurator\ReachabilityChecker;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Asset;
use EasyCorp\Bundle\EasyAdminBundle\Config\Assets;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Security\Http\Attribute\IsGranted;

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
            ->setDefaultSort(['definition' => 'ASC', 'name' => 'ASC']);
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

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnIndex();
        yield AssociationField::new('definition');
        yield TextField::new('name')
            ->setHelp('Machine-safe slug. Same-named transitions from the same place are rejected on state machines.');
        yield AssociationField::new('froms')
            ->setFormTypeOption('by_reference', false)
            ->setFormTypeOption('choice_attr', self::placeDefinitionAttr(...))
            ->setHelp('Places this transition can be applied from; only the selected definition\'s places are offered.');
        yield AssociationField::new('tos')
            ->setFormTypeOption('by_reference', false)
            ->setFormTypeOption('choice_attr', self::placeDefinitionAttr(...))
            ->setHelp('Places the subject moves to; only the selected definition\'s places are offered.');
        yield TextField::new('guard')
            ->hideOnIndex()
            ->setRequired(false)
            ->setHelp('Optional expression; variables: subject, metadata. Example: subject.getPageCount() <= 6. Blocks the transition when false or on error.');

        yield TextField::new('task')->onlyOnDetail();

        // The guided metadata editor (specs/DynamicWorkflows.md §9.3): task
        // dropdown, schema-rendered parameter panels, and dedicated inputs
        // for next/deadline/digital_first — no raw JSON.
        yield Field::new('metadata')
            ->setLabel(false)
            ->onlyOnForms()
            ->setFormType(TransitionMetadataType::class);
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
     * filtering (assets/admin/workflow_transition_form.js).
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
