<?php

namespace App\Form;

use App\Entity\Doc;
use App\Entity\Project;
use App\Entity\Stage;
use App\Repository\ProjectRepository;
use App\Repository\StageRepository;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use FOS\CKEditorBundle\Form\Type\CKEditorType;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class DocType extends AbstractType
{
    private $projectRepository;
    private $stageRepository;
    public function __construct(StageRepository $stageRepository, ProjectRepository $projectRepository)
    {
        $this->projectRepository = $projectRepository;
        $this->stageRepository = $stageRepository;
    }
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $this->options = $options['options'];
        $courseid = $this->options['courseid'] ;
        $builder
            ->add('title', TextType::class, [
                'label'  => 'Title',
                'attr' => ['class' => 'form-control']
            ])
            ->add('project', EntityType::class, [
                'class' => Project::class,
                'choices' => $this->projectRepository->findProjectsByCourse($courseid),
                'choice_label' => 'name',
                'multiple' => false,
                'expanded' => true,
                'attr' => ['class' => 'radio'],
                'label' => 'Project',
                'required' => 'true'
            ])
            ->add('stage', EntityType::class, [
                'class' => Stage::class,
                'choices' => $this->stageRepository->findStagesByCourse($courseid),
                'choice_label' => 'name',
                'multiple' => false,
                'expanded' => true,
                'attr' => ['class' => 'radio'],
                'label' => 'Stage',
                'required' => 'true'
            ])
            ->add('body', CKEditorType::class, [
                'config_name' => 'doc_config',
                'label' => '',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults([
            'data_class' => Doc::class,
            'options' => null,
        ]);
    }
}
