<?php

declare(strict_types=1);

namespace MulerTech\BlogBundle\Controller;

use MulerTech\BlogBundle\Entity\AbstractBlogPost;
use MulerTech\BlogBundle\Repository\BlogPostRepository;
use MulerTech\BlogBundle\Service\PostUrlGenerator;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\EventListener\AbstractSessionListener;
use Twig\Environment;

/**
 * The Atom feed of one language: its latest articles, each with its excerpt and a link to
 * the page, where readers and the site's statistics see the whole article.
 */
final readonly class BlogFeedController
{
    public const int SIZE = 20;

    /**
     * An hour: a feed reader polls often, and a new article may wait that long.
     */
    private const int MAX_AGE = 3600;

    public function __construct(
        private BlogPostRepository $repository,
        private PostUrlGenerator $urls,
        private Environment $twig,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $locale = $request->getLocale();
        $posts = $this->repository->findRecent($locale, self::SIZE);
        // A feed without article was last updated now as far as a reader can tell.
        $dates = array_filter(array_map(static fn (AbstractBlogPost $post): ?\DateTimeImmutable => $post->getUpdatedAt() ?? $post->getPublishedAt(), $posts));
        $updated = [] === $dates ? $this->clock->now() : max($dates);

        $response = new Response($this->twig->render('@MulerTechBlog/blog/feed.xml.twig', [
            'locale' => $locale,
            'posts' => $posts,
            'updated' => $updated,
            'feed_url' => $this->urls->feed($locale, true),
            'index_url' => $this->urls->index($locale, true),
        ]));
        $response->headers->set('Content-Type', 'application/atom+xml; charset=UTF-8');
        $response->headers->set(AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER, 'true');
        $response->setPublic();
        $response->setMaxAge(self::MAX_AGE);

        return $response;
    }
}
