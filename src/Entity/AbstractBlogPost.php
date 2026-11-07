<?php

declare(strict_types=1);

namespace MulerTech\BlogBundle\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use MulerTech\FileBundle\Model\FileInterface;
use Symfony\Component\Uid\Uuid;

/**
 * The fields every blog shares. Each site extends it with its own entity, which owns the
 * table and adds whatever only that site needs.
 *
 * One row is one article in one language: the versions of an article in several languages
 * are separate rows sharing a `translationGroup`. Each version therefore has its own slug,
 * publication date and view count, and a single-language site never sees anything more
 * than a column filled on its own.
 *
 * Column names are written out rather than left to the naming strategy, so the table a
 * site already has keeps its columns whatever strategy it uses.
 */
#[ORM\MappedSuperclass]
#[ORM\HasLifecycleCallbacks]
abstract class AbstractBlogPost
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    protected ?int $id = null;

    #[ORM\Column(length: 255)]
    protected string $title;

    /**
     * Unique per language rather than across the table: two versions of an article may
     * share a slug, a proper name for instance.
     */
    #[ORM\Column(length: 255)]
    protected string $slug;

    /**
     * Markdown.
     */
    #[ORM\Column(type: Types::TEXT)]
    protected string $content;

    #[ORM\Column(name: 'meta_description', length: 255, nullable: true)]
    protected ?string $metaDescription = null;

    #[ORM\ManyToOne(targetEntity: FileInterface::class)]
    #[ORM\JoinColumn(name: 'featured_image_id')]
    protected ?FileInterface $featuredImage = null;

    /**
     * @var Collection<int, FileInterface>
     */
    #[ORM\ManyToMany(targetEntity: FileInterface::class)]
    protected Collection $files;

    #[ORM\Column(name: 'is_published')]
    protected bool $isPublished = false;

    #[ORM\Column(name: 'published_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    protected ?\DateTimeImmutable $publishedAt = null;

    /**
     * In minutes.
     */
    #[ORM\Column(name: 'reading_time')]
    protected int $readingTime = 0;

    #[ORM\Column(name: 'view_count')]
    protected int $viewCount = 0;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    protected ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    protected ?\DateTimeImmutable $updatedAt = null;

    /**
     * A locale code such as `fr` or `en`, the one the article is written in.
     */
    #[ORM\Column(length: 10)]
    protected string $locale;

    #[ORM\Column(name: 'translation_group', type: Types::GUID)]
    protected string $translationGroup;

    public function __construct()
    {
        $this->files = new ArrayCollection();
        $this->translationGroup = Uuid::v7()->toRfc4122();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;

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

    public function getContent(): string
    {
        return $this->content;
    }

    public function setContent(string $content): static
    {
        $this->content = $content;

        return $this;
    }

    public function getMetaDescription(): ?string
    {
        return $this->metaDescription;
    }

    public function setMetaDescription(?string $metaDescription): static
    {
        $this->metaDescription = $metaDescription;

        return $this;
    }

    public function getFeaturedImage(): ?FileInterface
    {
        return $this->featuredImage;
    }

    public function setFeaturedImage(?FileInterface $featuredImage): static
    {
        $this->featuredImage = $featuredImage;

        return $this;
    }

    /**
     * @return Collection<int, FileInterface>
     */
    public function getFiles(): Collection
    {
        return $this->files;
    }

    public function addFile(FileInterface $file): static
    {
        if (!$this->files->contains($file)) {
            $this->files->add($file);
        }

        return $this;
    }

    public function removeFile(FileInterface $file): static
    {
        $this->files->removeElement($file);

        return $this;
    }

    public function isPublished(): bool
    {
        return $this->isPublished;
    }

    /**
     * The date of first publication: unpublishing then publishing again keeps it, so a
     * correction never moves an article to the top of the list.
     */
    public function getPublishedAt(): ?\DateTimeImmutable
    {
        return $this->publishedAt;
    }

    public function publish(\DateTimeImmutable $at): static
    {
        $this->isPublished = true;
        $this->publishedAt ??= $at;

        return $this;
    }

    public function unpublish(): static
    {
        $this->isPublished = false;

        return $this;
    }

    public function getReadingTime(): int
    {
        return $this->readingTime;
    }

    public function setReadingTime(int $readingTime): static
    {
        $this->readingTime = $readingTime;

        return $this;
    }

    public function getViewCount(): int
    {
        return $this->viewCount;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
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
     * Makes this article a version of `$original` in another language.
     */
    public function setTranslationOf(self $original): static
    {
        $this->translationGroup = $original->getTranslationGroup();

        return $this;
    }

    #[ORM\PrePersist]
    public function initializeCreatedAt(): void
    {
        $this->createdAt ??= new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function refreshUpdatedAt(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
