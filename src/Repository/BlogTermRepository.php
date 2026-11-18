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

    /**
     * Every version of the groups the terms belong to, themselves included, keyed by
     * translation group and by language.
     *
     * @param list<AbstractBlogTerm> $terms
     *
     * @return array<string, list<AbstractBlogTerm>>
     */
    public function findGroups(array $terms): array
    {
        $groups = [];

        foreach ($terms as $term) {
            $groups[$term->getTranslationGroup()] = [];
        }

        if ([] === $groups) {
            return [];
        }

        foreach ($this->entityManager->getRepository($this->getClass())->findBy(['translationGroup' => array_keys($groups)], ['locale' => 'ASC']) as $version) {
            $groups[$version->getTranslationGroup()][] = $version;
        }

        return $groups;
    }

    /**
     * The other versions of the term, by language.
     *
     * @return list<AbstractBlogTerm>
     */
    public function findVersions(AbstractBlogTerm $term): array
    {
        return array_values(array_filter(
            $this->findGroups([$term])[$term->getTranslationGroup()],
            static fn (AbstractBlogTerm $version): bool => $version !== $term,
        ));
    }

    /**
     * The version of the group named in `$locale`: a group holds one per language.
     */
    public function findVersion(string $translationGroup, string $locale, ?int $exceptId = null): ?AbstractBlogTerm
    {
        foreach ($this->entityManager->getRepository($this->getClass())->findBy(['translationGroup' => $translationGroup, 'locale' => $locale]) as $term) {
            if ($term->getId() !== $exceptId) {
                return $term;
            }
        }

        return null;
    }

    public function isSlugTaken(string $slug, string $locale, ?int $exceptId = null): bool
    {
        $term = $this->findBySlug($slug, $locale);

        return null !== $term && $term->getId() !== $exceptId;
    }
}
