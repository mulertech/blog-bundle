<?php

declare(strict_types=1);

namespace MulerTech\BlogBundle\Form;

use MulerTech\BlogBundle\Entity\AbstractBlogPost;
use MulerTech\BlogBundle\Validator\CoverDimensions;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The fields every blog shares. A site adds its own with a form type whose `getParent()`
 * returns this one, named by `mulertech_blog.post_form_type`.
 */
class BlogPostType extends AbstractType
{
    public const int MAX_COVER_SIZE_MB = 10;

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var list<string> $locales */
        $locales = $options['locales'];
        $post = $builder->getData();

        $builder
            ->add('title', TextType::class, [
                'label' => 'admin.form.title',
                'constraints' => [new Assert\NotBlank(message: 'mulertech_blog.title.blank'), new Assert\Length(max: 255)],
            ])
            ->add('slug', TextType::class, [
                'label' => 'admin.form.slug',
                'help' => 'admin.form.slug_help',
                'required' => false,
                'empty_data' => '',
                'constraints' => [
                    new Assert\Length(max: 255),
                    new Assert\Regex(pattern: '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', message: 'mulertech_blog.slug.format'),
                ],
            ]);

        // A single-language site has nothing to choose: the controller fills it in.
        if (\count($locales) > 1) {
            $builder->add('locale', ChoiceType::class, [
                'label' => 'admin.form.locale',
                'choices' => array_combine($locales, $locales),
                'choice_translation_domain' => false,
            ]);
        }

        $builder
            ->add('content', TextareaType::class, [
                'label' => 'admin.form.content',
                'constraints' => [new Assert\NotBlank(message: 'mulertech_blog.content.blank')],
            ])
            ->add('metaDescription', TextType::class, [
                'label' => 'admin.form.meta_description',
                'help' => 'admin.form.meta_description_help',
                'required' => false,
                'constraints' => [new Assert\Length(max: 255)],
            ])
            ->add('featuredImage', FileType::class, [
                'label' => 'admin.form.featured_image',
                'mapped' => false,
                'required' => false,
                'attr' => ['accept' => 'image/jpeg,image/png,image/webp,image/gif'],
                'constraints' => [new Assert\Sequentially([
                    new Assert\File(
                        maxSize: self::MAX_COVER_SIZE_MB.'M',
                        mimeTypes: ['image/jpeg', 'image/png', 'image/webp', 'image/gif'],
                        mimeTypesMessage: 'mulertech_blog.cover.type',
                    ),
                    new CoverDimensions(),
                ])],
            ]);

        if ($post instanceof AbstractBlogPost && null !== $post->getFeaturedImage()) {
            $builder->add('removeFeaturedImage', CheckboxType::class, [
                'label' => 'admin.form.remove_featured_image',
                'mapped' => false,
                'required' => false,
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setDefaults([
                'data_class' => AbstractBlogPost::class,
                'translation_domain' => 'MulerTechBlogBundle',
            ])
            ->setRequired('locales')
            ->setAllowedTypes('locales', 'string[]');
    }
}
