<?php

declare(strict_types=1);

namespace MulerTech\BlogBundle\Controller;

use MulerTech\BlogBundle\Entity\AbstractBlogCategory;
use MulerTech\BlogBundle\Entity\AbstractBlogPost;
use MulerTech\BlogBundle\Entity\AbstractBlogTag;
use MulerTech\BlogBundle\Entity\AbstractBlogTerm;
use MulerTech\BlogBundle\Repository\BlogPostRepository;
use MulerTech\BlogBundle\Repository\BlogTermRepository;
use MulerTech\BlogBundle\Seo\BlogPostSocialCardProvider;
use MulerTech\BlogBundle\Service\PostUrlGenerator;
use MulerTech\BlogBundle\Service\ViewCounter;
use MulerTech\BlogBundle\Twig\BlogExtension;
use MulerTech\SeoBundle\Service\MetaTagService;
use MulerTech\SeoBundle\Service\SocialCardService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\RequestContextAwareInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * The public pages of the blog, in the language of the request: a single-language site
 * serves its default locale, a multilingual one the `{_locale}` of its route prefix.
 */
final readonly class BlogController
{
    public function __construct(
        private BlogPostRepository $repository,
        private BlogTermRepository $categories,
        private BlogTermRepository $tags,
        private ViewCounter $viewCounter,
        private Environment $twig,
        private MetaTagService $metaTagService,
        private SocialCardService $socialCardService,
        private BlogPostSocialCardProvider $socialCards,
        private PostUrlGenerator $urls,
        private TranslatorInterface $translator,
        private AuthorizationCheckerInterface $authorizationChecker,
        private RequestContextAwareInterface $router,
        private string $publicLayout,
        private string $adminRole,
    ) {
    }

    public function index(Request $request): Response
    {
        $locale = $request->getLocale();
        $number = $request->query->getString('page', '1');
        $search = trim($request->query->getString('q'));

        // A page number that is not a positive integer is an address nobody links to.
        if (1 !== preg_match('/^[1-9]\d*$/', $number)) {
            throw new NotFoundHttpException(sprintf('"%s" is not a page number.', $number));
        }

        $category = $this->filter($request, 'category', $this->categories, $locale);
        $tag = $this->filter($request, 'tag', $this->tags, $locale);
        \assert(null === $category || $category instanceof AbstractBlogCategory);
        \assert(null === $tag || $tag instanceof AbstractBlogTag);
        $page = $this->repository->paginatePublished($locale, max(1, (int) $number), '' === $search ? null : $search, category: $category, tag: $tag);

        if (!$page->exists()) {
            throw new NotFoundHttpException(sprintf('The blog has %d pages, not %d.', $page->pageCount, $page->number));
        }

        return new Response($this->twig->render('@MulerTechBlog/blog/index.html.twig', [
            'layout' => $this->publicLayout,
            'locale' => $locale,
            'page' => $page,
            'search' => $search,
            'category' => $category,
            'tag' => $tag,
            'categories' => $this->repository->findCategoriesInUse($locale),
            'recent_posts' => $this->repository->findRecent($locale),
            'popular_posts' => $this->repository->findPopular($locale),
            'seo' => $this->metaTagService->generateMetaTags([
                'title' => $this->translator->trans('blog.title', [], 'MulerTechBlogBundle'),
                'description' => $this->translator->trans('blog.meta.description', [], 'MulerTechBlogBundle'),
                // A search result is not a page of its own: it points search engines at
                // the list rather than letting every query be indexed.
                ...('' === $search && null === $category && null === $tag ? [] : ['url' => $this->urls->index($locale, true)]),
            ]),
        ]));
    }

    public function show(Request $request, string $slug): Response
    {
        $post = $this->repository->findPublishedBySlug($slug, $request->getLocale())
            ?? throw new NotFoundHttpException(sprintf('No published article "%s" in "%s".', $slug, $request->getLocale()));

        $this->viewCounter->count($post);

        return new Response($this->twig->render('@MulerTechBlog/blog/show.html.twig', [
            'layout' => $this->publicLayout,
            'post' => $post,
            'previous_post' => $this->repository->findPrevious($post),
            'next_post' => $this->repository->findNext($post),
            'translations' => $this->repository->findTranslations($post),
            'seo' => $this->seo($post, $this->socialCard($post)),
        ]));
    }

    /**
     * The article as its public page will show it, drafts included, from the last saved
     * version: the Markdown is checked before publishing. Only the admin reaches it, its
     * route sitting under the admin prefix, and nothing is counted or indexed.
     */
    public function preview(Request $request, int $id): Response
    {
        if (!$this->authorizationChecker->isGranted($this->adminRole)) {
            throw new AccessDeniedException(sprintf('The preview of an article requires %s.', $this->adminRole));
        }

        $post = $this->repository->find($id) ?? throw new NotFoundHttpException(sprintf('No article %d.', $id));

        // The public templates link to the list and to the article in its own language.
        $request->setLocale($post->getLocale());
        $this->router->getContext()->setParameter('_locale', $post->getLocale());
        $request->attributes->set(BlogExtension::PREVIEW_ATTRIBUTE, true);

        $response = new Response($this->twig->render('@MulerTechBlog/admin/preview.html.twig', [
            'layout' => $this->publicLayout,
            'post' => $post,
            'previous_post' => null,
            'next_post' => null,
            'translations' => [],
            'seo' => $this->seo($post, []),
        ]));
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    /**
     * The term a list is filtered by, in the request's language. One unknown there, or on a
     * site without such terms, answers 404 rather than the whole list under its address.
     */
    private function filter(Request $request, string $parameter, BlogTermRepository $terms, string $locale): ?AbstractBlogTerm
    {
        $slug = $request->query->getString($parameter);

        if ('' === $slug) {
            return null;
        }

        if (!$terms->isEnabled()) {
            throw new NotFoundHttpException(sprintf('The blog has no %s to filter by.', $parameter));
        }

        return $terms->findBySlug($slug, $locale)
            ?? throw new NotFoundHttpException(sprintf('No %s "%s" in "%s".', $parameter, $slug, $locale));
    }

    /**
     * @param array<string, string|int> $socialCard
     *
     * @return array<string, mixed>
     */
    private function seo(AbstractBlogPost $post, array $socialCard): array
    {
        return $this->metaTagService->generateMetaTags([
            'title' => $post->getTitle(),
            'description' => $post->getSeoExcerpt(),
            'type' => 'article',
            'publishedTime' => $post->getSeoPublishedAt(),
            'modifiedTime' => $post->getSeoUpdatedAt(),
            'author' => $post->getSeoAuthorName(),
            ...$socialCard,
        ]);
    }

    /**
     * The card derived from the featured image, or nothing, in which case the meta tags
     * fall back to the site's default image.
     *
     * @return array<string, string|int>
     */
    private function socialCard(AbstractBlogPost $post): array
    {
        $cover = $post->getFeaturedImage();

        if (null === $cover || null === $post->getId()) {
            return [];
        }

        return [
            ...$this->socialCardService->metaTagOptions(BlogPostSocialCardProvider::TYPE, $post->getId(), $this->socialCards->source($cover)),
            'imageAlt' => $post->getTitle(),
        ];
    }
}
