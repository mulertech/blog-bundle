<?php

declare(strict_types=1);

namespace MulerTech\BlogBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * What a category and a tag share: a name and a slug, in one language.
 *
 * Like the articles, one row is one term in one language, the versions of a term in other
 * languages sharing a `translationGroup`: an article in English is filed under the English
 * version of its category.
 */
#[ORM\MappedSuperclass]
abstract class AbstractBlogTerm
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    protected ?int $id = null;

    #[ORM\Column(length: 255)]
    protected string $name;

    /**
     * Unique per language, like the articles' slugs.
     */
    #[ORM\Column(length: 255)]
    protected string $slug;

    #[ORM\Column(length: 10)]
    protected string $locale;

    #[ORM\Column(name: 'translation_group', type: Types::GUID)]
    protected string $translationGroup;

    public function __construct()
    {
        $this->translationGroup = Uuid::v7()->toRfc4122();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): static
    {
        $this->slug = $slug;

        return $this;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function setLocale(string $locale): static
    {
        $this->locale = $locale;

        return $this;
    }

    public function getTranslationGroup(): string
    {
        return $this->translationGroup;
    }

    /**
     * Makes this term a version of `$original` in another language.
     */
    public function setTranslationOf(self $original): static
    {
        $this->translationGroup = $original->getTranslationGroup();

        return $this;
    }
}
