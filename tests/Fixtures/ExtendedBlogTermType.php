<?php

declare(strict_types=1);

namespace MulerTech\BlogBundle\Tests\Fixtures;

use MulerTech\BlogBundle\Form\BlogTermType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;

/**
 * A site's category form: the bundle's fields, plus one of its own.
 */
final class ExtendedBlogTermType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('color', TextType::class, ['required' => false]);
    }

    public function getParent(): string
    {
        return BlogTermType::class;
    }
}
