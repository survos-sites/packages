<?php

namespace App\Controller\Admin;

use ApiPlatform\Doctrine\Odm\Filter\BooleanFilter;
use App\Entity\Package;
use App\Workflow\BundleWorkflow;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextEditorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Workflow\WorkflowInterface;

class PackageCrudController extends AbstractCrudController
{
    public function __construct(
        #[Target(BundleWorkflow::WORKFLOW_NAME)] private readonly WorkflowInterface $workflow,
    )
    {
    }

    public static function getEntityFqcn(): string
    {
        return Package::class;
    }

    public function configureFields(string $pageName): iterable
    {

        yield IdField::new('id')->hideOnForm();
        yield ChoiceField::new('marking')->setChoices(
            $this->workflow->getDefinition()->getPlaces()
        );

        /** @var Field $field */
        foreach (parent::configureFields($pageName) as $field) {
            $propertyName = $field->getAsDto()->getPropertyNameWithSuffix();
            $easyadminField = match ($propertyName) {
                'marking' => null,
                'id' => null,
//                'fetchStatusCode' => $field->setLabel('Fetch Status'),

                default => $field,
            };
            if ($easyadminField) {
                yield $easyadminField;
            }
        }
    }

    public function configureFilters(Filters $filters): Filters
    {
        $places = $this->workflow->getDefinition()->getPlaces();
        return $filters
            ->add(ChoiceFilter::new('marking')
                ->setChoices($places)
            )
            ->add('vendor')
//            ->add(BooleanFilter::new('owned'));
        ;
    }

}
