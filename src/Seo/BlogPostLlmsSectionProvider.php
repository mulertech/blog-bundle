<?php

declare(strict_types=1);

namespace MulerTech\BlogBundle\Seo;

use MulerTech\BlogBundle\Repository\BlogPostRepository;
use MulerTech\BlogBundle\Service\PostUrlGenerator;
use MulerTech\SeoBundle\Model\LlmsLink;
use MulerTech\SeoBundle\Model\LlmsSection;
use MulerTech\SeoBundle\Model\LlmsSectionProviderInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The published articles, listed in `llms.txt` with their meta description.
 */
final readonly class BlogPostLlmsSectionProvider implements LlmsSectionProviderInterface
{
    public function __construct(
        private BlogPostRepository $repository,
        private PostUrlGenerator $urls,
        private TranslatorInterface $translator,
    ) {
    }

    public function getSections(): iterable
    {
        $links = array_map(
            fn ($post): LlmsLink => new LlmsLink($this->urls->post($post, true), $post->getTitle(), $post->getSeoExcerpt() ?? ''),
            $this->repository->findAllPublished(),
        );

        if ([] !== $links) {
            yield new LlmsSection($this->translator->trans('blog.title', [], 'MulerTechBlogBundle'), $links);
        }
    }
}
