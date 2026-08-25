<?php

namespace App\Form;

use App\Entity\HelloAssoConfig;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

class HelloAssoConfigType extends AbstractType
{
    public const LABEL_CHOICES = [
        'Particuliers' => 'Particuliers',
        'Professionnels' => 'Professionnels',
    ];

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            // labelChoice/useCustomLabel/labelCustom aren't mapped to
            // HelloAssoConfig::$label directly — the SUBMIT listener below
            // resolves the final value from them, so the dropdown acts as a
            // guardrail with an explicit escape hatch for the rare client
            // whose forms aren't a "Particuliers"/"Professionnels" split.
            // Added via PRE_SET_DATA (rather than directly here) so editing
            // an existing config pre-selects the right side of the toggle
            // instead of always defaulting to the dropdown.
            ->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event): void {
                $config = $event->getData();
                $currentLabel = $config instanceof HelloAssoConfig ? $config->getLabel() : null;
                $isCustom = $currentLabel !== null && $currentLabel !== '' && !isset(self::LABEL_CHOICES[$currentLabel]);

                $event->getForm()
                    ->add('labelChoice', ChoiceType::class, [
                        'label' => 'Libellé',
                        'mapped' => false,
                        'required' => false,
                        'placeholder' => false,
                        'choices' => self::LABEL_CHOICES,
                        'data' => $isCustom ? array_key_first(self::LABEL_CHOICES) : ($currentLabel ?: array_key_first(self::LABEL_CHOICES)),
                    ])
                    ->add('useCustomLabel', CheckboxType::class, [
                        'label' => 'Libellé personnalisé',
                        'mapped' => false,
                        'required' => false,
                        'data' => $isCustom,
                    ])
                    ->add('labelCustom', TextType::class, [
                        'label' => 'Libellé personnalisé',
                        'mapped' => false,
                        'required' => false,
                        'data' => $isCustom ? $currentLabel : null,
                    ]);
            })
            // Resolves labelChoice/useCustomLabel/labelCustom down to
            // HelloAssoConfig::$label. Must run on SUBMIT, not in the
            // controller after isValid() — validation (including $label's
            // own Assert\NotBlank) happens on POST_SUBMIT, right after, so by
            // then it's too late to still be setting the mapped value.
            ->addEventListener(FormEvents::SUBMIT, function (FormEvent $event): void {
                $config = $event->getData();
                if (!$config instanceof HelloAssoConfig) {
                    return;
                }

                $form = $event->getForm();
                $useCustom = (bool) $form->get('useCustomLabel')->getData();
                $label = $useCustom ? (string) $form->get('labelCustom')->getData() : (string) $form->get('labelChoice')->getData();

                if ($useCustom && trim($label) === '') {
                    $form->get('labelCustom')->addError(new FormError('Merci de renseigner un libellé personnalisé.'));

                    return;
                }

                $config->setLabel($label);
            })
            ->add('apiUrl', TextType::class, [
                'label' => "URL de l'API HelloAsso",
                'row_attr' => ['class' => 'form-row--span2'],
            ])
            ->add('helloAssoClientId', TextType::class, [
                'label' => 'Client ID',
            ])
            ->add('clientSecret', PasswordType::class, [
                'label' => 'Client secret',
                'mapped' => false,
                'required' => $options['secret_required'],
                'attr' => ['autocomplete' => 'new-password'],
                'constraints' => $options['secret_required'] ? [new Assert\NotBlank()] : [],
                'help' => $options['secret_required'] ? null : 'Laisser vide pour conserver le secret actuel.',
            ])
            ->add('organizationSlug', TextType::class, [
                'label' => "Slug de l'organisation",
            ])
            ->add('formType', ChoiceType::class, [
                'label' => 'Type de formulaire',
                'choices' => [
                    'Cagnotte / Financement participatif (CrowdFunding)' => 'CrowdFunding',
                    'Paiement (PaymentForm)' => 'PaymentForm',
                    'Adhésion (Membership)' => 'Membership',
                    'Événement (Event)' => 'Event',
                    'Don (Donation)' => 'Donation',
                    'Boutique (Shop)' => 'Shop',
                ],
                'help' => "Doit correspondre exactement au type de la campagne dans HelloAsso, sinon la synchro ne trouvera aucun paiement.",
            ])
            ->add('formSlug', TextType::class, [
                'label' => 'Slug du formulaire',
            ])
            ->add('maxAmount', IntegerType::class, [
                'label' => 'Montant maximum autorisé (€)',
            ])
            ->add('extraMailFieldName', TextType::class, [
                'label' => "Nom du champ personnalisé pour l'email alternatif",
                'required' => false,
                'row_attr' => ['class' => 'form-row--span2'],
            ])
            ->add('fetchNbDays', IntegerType::class, [
                'label' => 'Nombre de jours à récupérer lors de la synchro',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => HelloAssoConfig::class,
            'secret_required' => true,
        ]);
    }
}
