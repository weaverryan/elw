<?php

namespace App\Form;

use App\Entity\Classlist;
use App\Entity\User;
use App\Entity\Course;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ClasslistType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('role', ChoiceType::class, [
                'choices' => ['Student' => 'Student', 'Instructor' => 'Instructor'],
                'multiple' => false,
                'expanded' => true,
                'attr' => ['class' => 'checkbox'],
            ])
            ->add('status', ChoiceType::class, [
                'choices' => ['Pending' => 'Pending', 'Approved' => 'Approved'],
                'multiple' => false,
                'expanded' => true,
                'attr' => ['class' => 'checkbox'],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults([
            'data_class' => Classlist::class,
        ]);
    }
}
