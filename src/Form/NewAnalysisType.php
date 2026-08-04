<?php

namespace App\Form;

use App\Enum\AnalysisSource;
use App\Form\Model\NewAnalysisInput;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class NewAnalysisType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('query', TextType::class, [
                'label' => 'Investigador',
                'help' => 'ORCID (0000-0002-1692-2216), ID de OpenAlex (A5065080063) o nombre completo.',
                'attr' => [
                    'placeholder' => '0000-0002-1692-2216',
                    'autofocus' => true,
                    'autocomplete' => 'off',
                    'spellcheck' => 'false',
                ],
            ])
            ->add('reportLanguage', ChoiceType::class, [
                'label' => 'Idioma del informe',
                'choices' => ['Español' => 'es', 'Inglés' => 'en'],
            ])
            ->add('wantReport', CheckboxType::class, [
                'label' => 'Generar informe narrativo (requiere Ollama)',
                'required' => false,
            ])
            // OpenAlex is absent on purpose: it is always used (see AnalysisSource).
            ->add('sources', ChoiceType::class, [
                'label' => 'Fuentes',
                'choices' => array_combine(
                    array_map(static fn (AnalysisSource $s) => $s->label(), AnalysisSource::optional()),
                    array_map(static fn (AnalysisSource $s) => $s->value, AnalysisSource::optional()),
                ),
                'expanded' => true,
                'multiple' => true,
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => NewAnalysisInput::class,
            'csrf_token_id' => 'submit',
        ]);
    }
}
