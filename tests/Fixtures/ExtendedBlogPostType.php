<?php

declare(strict_types=1);

namespace MulerTech\BlogBundle\Tests\Fixtures;

use MulerTech\BlogBundle\Form\BlogPostType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;

/**
 * A site's form: the bundle's fields, plus one of its own.
 *
 * @extends AbstractType<\MulerTech\BlogBundle\Tests\Fixtures\Entity\BlogPost>
 */
final class ExtendedBlogPostType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('excerpt', TextareaType::class, ['required' => false]);
    }

    public function getParent(): string
    {
        return BlogPostType::class;
    }
}
