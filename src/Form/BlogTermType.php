<?php

declare(strict_types=1);

namespace MulerTech\BlogBundle\Form;

use MulerTech\BlogBundle\Entity\AbstractBlogCategory;
use MulerTech\BlogBundle\Entity\AbstractBlogTerm;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A category or a tag: a name, a slug, and a description for a category. A site adds its
 * own fields with a form type whose `getParent()` returns this one, named by
 * `mulertech_blog.category_form_type` or `tag_form_type`.
 */
class BlogTermType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var list<string> $locales */
        $locales = $options['locales'];

        $builder
            ->add('name', TextType::class, [
                'label' => 'admin.terms.name',
                'constraints' => [new Assert\NotBlank(message: 'mulertech_blog.term.name_blank'), new Assert\Length(max: 255)],
            ])
            ->add('slug', TextType::class, [
                'label' => 'admin.form.slug',
                'help' => 'admin.terms.slug_help',
                'required' => false,
                'empty_data' => '',
                'constraints' => [
                    new Assert\Length(max: 255),
                    new Assert\Regex(pattern: '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', message: 'mulertech_blog.slug.format'),
                ],
            ]);

        if (\count($locales) > 1) {
            $builder->add('locale', ChoiceType::class, [
                'label' => 'admin.form.locale',
                'choices' => array_combine($locales, $locales),
                'choice_translation_domain' => false,
            ]);
        }

        if (null !== $options['translation_candidates']) {
            // Not mapped, like the article's: the controller joins the group once checked.
            $builder->add('translationOf', ChoiceType::class, [
                'label' => 'admin.form.translation_of',
                'help' => 'admin.terms.translation_of_help',
                'mapped' => false,
                'required' => false,
                'placeholder' => 'admin.form.no_translation',
                'choices' => $options['translation_candidates'],
                'data' => $options['translation_of'],
                'choice_value' => static fn (?AbstractBlogTerm $candidate): ?int => $candidate?->getId(),
                'choice_label' => static fn (AbstractBlogTerm $candidate): string => sprintf('%s (%s)', $candidate->getName(), $candidate->getLocale()),
                'choice_translation_domain' => false,
            ]);
        }

        if ($builder->getData() instanceof AbstractBlogCategory) {
            $builder->add('description', TextareaType::class, [
                'label' => 'admin.terms.description',
                'help' => 'admin.terms.description_help',
                'required' => false,
                'attr' => ['rows' => 3],
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setDefaults([
                'data_class' => AbstractBlogTerm::class,
                'translation_domain' => 'MulerTechBlogBundle',
            ])
            ->setRequired('locales')
            ->setAllowedTypes('locales', 'string[]')
            // The terms of the same kind this one may be the translation of, null on a
            // single-language site; and the one it already is a version of.
            ->setDefaults(['translation_candidates' => null, 'translation_of' => null])
            ->setAllowedTypes('translation_candidates', ['null', AbstractBlogTerm::class.'[]'])
            ->setAllowedTypes('translation_of', ['null', AbstractBlogTerm::class]);
    }
}
