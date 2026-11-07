<?php

declare(strict_types=1);

namespace MulerTech\BlogBundle\Controller;

use MulerTech\BlogBundle\Repository\BlogPostRepository;
use MulerTech\BlogBundle\Service\ViewCounter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Twig\Environment;

/**
 * The public pages of the blog, in the language of the request: a single-language site
 * serves its default locale, a multilingual one the `{_locale}` of its route prefix.
 */
final readonly class BlogController
{
    public function __construct(
        private BlogPostRepository $repository,
        private ViewCounter $viewCounter,
        private Environment $twig,
        private string $publicLayout,
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

        $page = $this->repository->paginatePublished($locale, max(1, (int) $number), '' === $search ? null : $search);

        if (!$page->exists()) {
            throw new NotFoundHttpException(sprintf('The blog has %d pages, not %d.', $page->pageCount, $page->number));
        }

        return new Response($this->twig->render('@MulerTechBlog/blog/index.html.twig', [
            'layout' => $this->publicLayout,
            'page' => $page,
            'search' => $search,
            'recent_posts' => $this->repository->findRecent($locale),
            'popular_posts' => $this->repository->findPopular($locale),
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
        ]));
    }
}
