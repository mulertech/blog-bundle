<?php

declare(strict_types=1);

namespace MulerTech\BlogBundle\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\Pagination\Paginator;
use MulerTech\BlogBundle\Entity\AbstractBlogPost;
use MulerTech\BlogBundle\Model\Page;

/**
 * The queries of the blog, on the site's own entity.
 *
 * Every list holds published articles of one language only: the versions of an article in
 * other languages are reached through `findTranslations()`, never mixed into a list.
 */
final readonly class BlogPostRepository
{
    public const int PAGE_SIZE = 12;
    public const int SIDEBAR_SIZE = 5;

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
     * title or the content, ignoring case, those holding them all in the title first.
     *
     * @param positive-int $page
     * @param positive-int $size
     */
    public function paginatePublished(string $locale, int $page, ?string $search = null, int $size = self::PAGE_SIZE): Page
    {
        $query = $this->published($locale);
        $words = null === $search ? [] : preg_split('/\s+/u', mb_strtolower(trim($search)), -1, \PREG_SPLIT_NO_EMPTY);

        if ([] !== $words && false !== $words) {
            $inTitle = [];

            foreach ($words as $index => $word) {
                $parameter = 'word'.$index;
                $query->setParameter($parameter, '%'.strtr($word, [self::LIKE_ESCAPE => self::LIKE_ESCAPE.self::LIKE_ESCAPE, '%' => self::LIKE_ESCAPE.'%', '_' => self::LIKE_ESCAPE.'_']).'%');
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

        $paginator = new Paginator($query, fetchJoinCollection: false);

        return new Page(array_values(array_filter(
            iterator_to_array($paginator),
            static fn (mixed $post): bool => $post instanceof AbstractBlogPost,
        )), $page, $size, \count($paginator));
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
     * The published versions of the article in the other languages.
     *
     * @return list<AbstractBlogPost>
     */
    public function findTranslations(AbstractBlogPost $post): array
    {
        return $this->results($this->entityManager->createQueryBuilder()
            ->select('p')
            ->from($this->postClass, 'p')
            ->where('p.isPublished = true')
            ->andWhere('p.translationGroup = :group')
            ->andWhere('p.locale <> :locale')
            ->setParameter('group', $post->getTranslationGroup())
            ->setParameter('locale', $post->getLocale())
            ->orderBy('p.locale', 'ASC'));
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

    private function published(string $locale): QueryBuilder
    {
        return $this->entityManager->createQueryBuilder()
            ->select('p')
            ->from($this->postClass, 'p')
            ->where('p.isPublished = true')
            ->andWhere('p.locale = :locale')
            ->setParameter('locale', $locale);
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
