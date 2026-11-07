<?php

declare(strict_types=1);

namespace MulerTech\BlogBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use MulerTech\BlogBundle\Entity\AbstractBlogPost;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Counts one view per article and per session, so reloading a page or coming back to it
 * from the list does not inflate the count.
 */
final readonly class ViewCounter
{
    public const string SESSION_KEY = 'mulertech_blog.viewed_posts';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private RequestStack $requestStack,
    ) {
    }

    /**
     * Reports whether this view was counted.
     *
     * The count is incremented in the database rather than on the entity: two visitors
     * reading the same article at once would otherwise each write the count they loaded
     * plus one, and one view would be lost.
     */
    public function count(AbstractBlogPost $post): bool
    {
        $id = $post->getId() ?? throw new \LogicException('An article is counted once it is saved: this one has no id yet.');
        $session = $this->requestStack->getSession();

        /** @var list<int> $viewed */
        $viewed = $session->get(self::SESSION_KEY, []);

        if (\in_array($id, $viewed, true)) {
            return false;
        }

        $this->entityManager->createQueryBuilder()
            ->update($this->entityManager->getClassMetadata($post::class)->getName(), 'p')
            ->set('p.viewCount', 'p.viewCount + 1')
            ->where('p.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->execute();

        $viewed[] = $id;
        $session->set(self::SESSION_KEY, $viewed);

        return true;
    }
}
