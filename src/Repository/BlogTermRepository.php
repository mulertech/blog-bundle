<?php

declare(strict_types=1);

namespace MulerTech\BlogBundle\Repository;

use Doctrine\ORM\EntityManagerInterface;
use MulerTech\BlogBundle\Entity\AbstractBlogTerm;

/**
 * The categories or the tags of the site, one instance for each. A site that does not
 * name the class of one of them has none: the repository says so through `isEnabled()`,
 * and reading from it then fails, naming the configuration key to set.
 */
final readonly class BlogTermRepository
{
    /**
     * @param class-string<AbstractBlogTerm>|null $termClass
     * @param string                              $configKey the key naming the class, quoted when it is missing
     */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ?string $termClass,
        private string $configKey,
    ) {
    }

    public function isEnabled(): bool
    {
        return null !== $this->termClass;
    }

    /**
     * @return class-string<AbstractBlogTerm>
     */
    public function getClass(): string
    {
        return $this->termClass ?? throw new \LogicException(sprintf('The blog has no such terms: set %s to the site\'s entity.', $this->configKey));
    }

    public function find(int $id): ?AbstractBlogTerm
    {
        $term = $this->entityManager->find($this->getClass(), $id);

        return $term instanceof AbstractBlogTerm ? $term : null;
    }

    public function findBySlug(string $slug, string $locale): ?AbstractBlogTerm
    {
        $term = $this->entityManager->getRepository($this->getClass())->findOneBy(['slug' => $slug, 'locale' => $locale]);

        return $term instanceof AbstractBlogTerm ? $term : null;
    }

    /**
     * Every term, by language then by name.
     *
     * @return list<AbstractBlogTerm>
     */
    public function findAll(): array
    {
        return $this->entityManager->getRepository($this->getClass())->findBy([], ['locale' => 'ASC', 'name' => 'ASC']);
    }

    public function isSlugTaken(string $slug, string $locale, ?int $exceptId = null): bool
    {
        $term = $this->findBySlug($slug, $locale);

        return null !== $term && $term->getId() !== $exceptId;
    }
}
