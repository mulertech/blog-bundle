<?php

declare(strict_types=1);

namespace MulerTech\BlogBundle\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\Pagination\Paginator;
use MulerTech\BlogBundle\Entity\AbstractBlogCategory;
use MulerTech\BlogBundle\Entity\AbstractBlogPost;
use MulerTech\BlogBundle\Entity\AbstractBlogTag;
use MulerTech\BlogBundle\Entity\AbstractBlogTerm;
use MulerTech\BlogBundle\Model\Page;
use Psr\Clock\ClockInterface;

/**
 * The queries of the blog, on the site's own entity.
 *
 * Every list holds published articles of one language only: the versions of an article in
 * other languages are reached through `findTranslations()`, never mixed into a list.
 *
 * Published means visible now: an article scheduled later stays out of every public query
 * until its date, read from the clock, without any task to run.
 */
final readonly class BlogPostRepository
{
    public const int PAGE_SIZE = 12;
    public const int SIDEBAR_SIZE = 5;
    public const int ADMIN_PAGE_SIZE = 20;

    /**
     * Escapes the wildcards a visitor may type, so `100%` looks for "100%" and not for
     * anything starting with "100".
     */
    private const string LIKE_ESCAPE = '!';

    /**
     * @param class-string<AbstractBlogPost> $postClass
     */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private string $postClass,
        private ClockInterface $clock,
    ) {
    }

    public function findPublishedBySlug(string $slug, string $locale): ?AbstractBlogPost
    {
        $post = $this->published($locale)
            ->andWhere('p.slug = :slug')
            ->setParameter('slug', $slug)
            ->getQuery()
            ->getOneOrNullResult();

        return $post instanceof AbstractBlogPost ? $post : null;
    }

    /**
     * Newest first. With `$search`, only the articles holding every word of it, in the
     * title or the content, ignoring case, those holding them all in the title first. With
     * `$category` or `$tag`, only the articles filed under it.
     *
     * @param positive-int $page
     * @param positive-int $size
     */
    public function paginatePublished(
        string $locale,
        int $page,
        ?string $search = null,
        int $size = self::PAGE_SIZE,
        ?AbstractBlogCategory $category = null,
        ?AbstractBlogTag $tag = null,
    ): Page {
        $query = $this->published($locale);

        if (null !== $category) {
            $query->andWhere('p.category = :category')->setParameter('category', $category);
        }

        if (null !== $tag) {
            $query->andWhere(':tag MEMBER OF p.tags')->setParameter('tag', $tag);
        }

        $words = null === $search ? [] : preg_split('/\s+/u', mb_strtolower(trim($search)), -1, \PREG_SPLIT_NO_EMPTY);

        if ([] !== $words && false !== $words) {
            $inTitle = [];

            foreach ($words as $index => $word) {
                $parameter = 'word'.$index;
                $query->setParameter($parameter, $this->containing($word));
                $query->andWhere(sprintf("LOWER(p.title) LIKE :%1\$s ESCAPE '%2\$s' OR LOWER(p.content) LIKE :%1\$s ESCAPE '%2\$s'", $parameter, self::LIKE_ESCAPE));
                $inTitle[] = sprintf("LOWER(p.title) LIKE :%s ESCAPE '%s'", $parameter, self::LIKE_ESCAPE);
            }

            $query->addSelect(sprintf('CASE WHEN %s THEN 1 ELSE 0 END AS HIDDEN titleMatch', implode(' AND ', $inTitle)))
                ->orderBy('titleMatch', 'DESC');
        }

        $query->addOrderBy('p.publishedAt', 'DESC')
            ->addOrderBy('p.id', 'DESC')
            ->setFirstResult(($page - 1) * $size)
            ->setMaxResults($size);

        return $this->page($query, $page, $size);
    }

    /**
     * Every article, drafts and every language included, the latest created first. With
     * `$search`, only those whose title holds it, ignoring case.
     *
     * @param positive-int $page
     * @param positive-int $size
     */
    public function paginateAll(int $page, ?string $search = null, int $size = self::ADMIN_PAGE_SIZE): Page
    {
        $query = $this->entityManager->createQueryBuilder()
            ->select('p')
            ->from($this->postClass, 'p')
            ->orderBy('p.createdAt', 'DESC')
            ->addOrderBy('p.id', 'DESC')
            ->setFirstResult(($page - 1) * $size)
            ->setMaxResults($size);
        $search = null === $search ? '' : mb_strtolower(trim($search));

        if ('' !== $search) {
            $query->where(sprintf("LOWER(p.title) LIKE :search ESCAPE '%s'", self::LIKE_ESCAPE))
                ->setParameter('search', $this->containing($search));
        }

        return $this->page($query, $page, $size);
    }

    public function find(int $id): ?AbstractBlogPost
    {
        $post = $this->entityManager->find($this->postClass, $id);

        return $post instanceof AbstractBlogPost ? $post : null;
    }

    /**
     * @return list<AbstractBlogPost>
     */
    public function findRecent(string $locale, int $limit = self::SIDEBAR_SIZE): array
    {
        return $this->results($this->published($locale)
            ->orderBy('p.publishedAt', 'DESC')
            ->addOrderBy('p.id', 'DESC')
            ->setMaxResults($limit));
    }

    /**
     * @return list<AbstractBlogPost>
     */
    public function findPopular(string $locale, int $limit = self::SIDEBAR_SIZE): array
    {
        return $this->results($this->published($locale)
            ->orderBy('p.viewCount', 'DESC')
            ->addOrderBy('p.publishedAt', 'DESC')
            ->addOrderBy('p.id', 'DESC')
            ->setMaxResults($limit));
    }

    /**
     * The article published just before, in the same language. Articles sharing a
     * publication date are told apart by id, so none is skipped nor reached twice.
     */
    public function findPrevious(AbstractBlogPost $post): ?AbstractBlogPost
    {
        return $this->neighbour($post, '<', 'DESC');
    }

    public function findNext(AbstractBlogPost $post): ?AbstractBlogPost
    {
        return $this->neighbour($post, '>', 'ASC');
    }

    /**
     * Every published article, in every language, newest first.
     *
     * @return list<AbstractBlogPost>
     */
    public function findAllPublished(): array
    {
        return $this->results($this->visible($this->entityManager->createQueryBuilder()
            ->select('p')
            ->from($this->postClass, 'p'))
            ->orderBy('p.publishedAt', 'DESC')
            ->addOrderBy('p.id', 'DESC'));
    }

    /**
     * The published versions of the article in the other languages.
     *
     * @return list<AbstractBlogPost>
     */
    public function findTranslations(AbstractBlogPost $post): array
    {
        return $this->results($this->visible($this->entityManager->createQueryBuilder()
            ->select('p')
            ->from($this->postClass, 'p'))
            ->andWhere('p.translationGroup = :group')
            ->andWhere('p.locale <> :locale')
            ->setParameter('group', $post->getTranslationGroup())
            ->setParameter('locale', $post->getLocale())
            ->orderBy('p.locale', 'ASC'));
    }

    /**
     * The categories holding published articles in `$locale`, by name, each with the
     * number of those articles.
     *
     * @return list<array{term: AbstractBlogCategory, count: int}>
     */
    public function findCategoriesInUse(string $locale): array
    {
        return $this->termsInUse($locale, 'category', AbstractBlogCategory::class);
    }

    /**
     * @return list<array{term: AbstractBlogTag, count: int}>
     */
    public function findTagsInUse(string $locale): array
    {
        return $this->termsInUse($locale, 'tags', AbstractBlogTag::class);
    }

    /**
     * Every article filed under the term, drafts and scheduled ones included: what deleting
     * the term would touch.
     */
    public function countFiledUnder(AbstractBlogTerm $term): int
    {
        $query = $this->entityManager->createQueryBuilder()
            ->select('COUNT(p.id)')
            ->from($this->postClass, 'p')
            ->setParameter('term', $term);

        $term instanceof AbstractBlogCategory
            ? $query->where('p.category = :term')
            : $query->where(':term MEMBER OF p.tags');

        return (int) $query->getQuery()->getSingleScalarResult();
    }

    /**
     * Drafts included: a draft holds its slug as much as a published article does.
     */
    public function isSlugTaken(string $slug, string $locale, ?int $exceptId = null): bool
    {
        $query = $this->entityManager->createQueryBuilder()
            ->select('COUNT(p.id)')
            ->from($this->postClass, 'p')
            ->where('p.slug = :slug')
            ->andWhere('p.locale = :locale')
            ->setParameter('slug', $slug)
            ->setParameter('locale', $locale);

        if (null !== $exceptId) {
            $query->andWhere('p.id <> :id')->setParameter('id', $exceptId);
        }

        return 0 < (int) $query->getQuery()->getSingleScalarResult();
    }

    /**
     * @template T of AbstractBlogTerm
     *
     * @param class-string<T> $type
     *
     * @return list<array{term: T, count: int}>
     */
    private function termsInUse(string $locale, string $field, string $type): array
    {
        $metadata = $this->entityManager->getClassMetadata($this->postClass);

        // The site has not named the class of these terms: it has none.
        if (!$metadata->hasAssociation($field)) {
            return [];
        }

        /** @var list<array{id: int|string, total: int|string}> $rows */
        $rows = $this->published($locale)
            ->select('t.id AS id, COUNT(p.id) AS total')
            ->join('p.'.$field, 't')
            ->groupBy('t.id')
            ->getQuery()
            ->getArrayResult();
        $counts = array_column($rows, 'total', 'id');

        if ([] === $counts) {
            return [];
        }

        $terms = $this->entityManager->createQueryBuilder()
            ->select('t')
            ->from($metadata->getAssociationTargetClass($field), 't')
            ->where('t.id IN (:ids)')
            ->setParameter('ids', array_keys($counts))
            ->orderBy('t.name', 'ASC')
            ->getQuery()
            ->getResult();

        $inUse = [];

        foreach ((array) $terms as $term) {
            if ($term instanceof $type && null !== $term->getId()) {
                $inUse[] = ['term' => $term, 'count' => (int) $counts[$term->getId()]];
            }
        }

        return $inUse;
    }

    private function neighbour(AbstractBlogPost $post, string $direction, string $order): ?AbstractBlogPost
    {
        if (null === $post->getPublishedAt() || null === $post->getId()) {
            return null;
        }

        $neighbour = $this->published($post->getLocale())
            ->andWhere(sprintf('p.publishedAt %1$s :publishedAt OR (p.publishedAt = :publishedAt AND p.id %1$s :id)', $direction))
            ->setParameter('publishedAt', $post->getPublishedAt())
            ->setParameter('id', $post->getId())
            ->orderBy('p.publishedAt', $order)
            ->addOrderBy('p.id', $order)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $neighbour instanceof AbstractBlogPost ? $neighbour : null;
    }

    /**
     * @param positive-int $number
     * @param positive-int $size
     */
    private function page(QueryBuilder $query, int $number, int $size): Page
    {
        $paginator = new Paginator($query, fetchJoinCollection: false);

        return new Page(array_values(array_filter(
            iterator_to_array($paginator),
            static fn (mixed $post): bool => $post instanceof AbstractBlogPost,
        )), $number, $size, \count($paginator));
    }

    private function containing(string $text): string
    {
        return '%'.strtr($text, [self::LIKE_ESCAPE => self::LIKE_ESCAPE.self::LIKE_ESCAPE, '%' => self::LIKE_ESCAPE.'%', '_' => self::LIKE_ESCAPE.'_']).'%';
    }

    private function published(string $locale): QueryBuilder
    {
        return $this->visible($this->entityManager->createQueryBuilder()
            ->select('p')
            ->from($this->postClass, 'p'))
            ->andWhere('p.locale = :locale')
            ->setParameter('locale', $locale);
    }

    private function visible(QueryBuilder $query): QueryBuilder
    {
        return $query
            ->where('p.isPublished = true')
            ->andWhere('p.publishedAt <= :now')
            ->setParameter('now', $this->clock->now());
    }

    /**
     * @return list<AbstractBlogPost>
     */
    private function results(QueryBuilder $query): array
    {
        return array_values(array_filter(
            (array) $query->getQuery()->getResult(),
            static fn (mixed $post): bool => $post instanceof AbstractBlogPost,
        ));
    }
}
