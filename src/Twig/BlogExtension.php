<?php

declare(strict_types=1);

namespace MulerTech\BlogBundle\Twig;

use MulerTech\BlogBundle\Entity\AbstractBlogPost;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * An image inserted from the admin is written `![…](blog-file:12)` in the content rather
 * than with its address: the address carries the slug and the language prefix, and an
 * article whose slug is corrected would otherwise lose every image of its content.
 */
final class BlogExtension extends AbstractExtension
{
    public const string FILE_SCHEME = 'blog-file:';

    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function getFilters(): array
    {
        return [new TwigFilter('mulertech_blog_content', $this->content(...))];
    }

    /**
     * The Markdown of the article, its `blog-file:` references turned into the addresses
     * of its files. A reference to a file the article does not hold is left as written, so
     * the broken image shows on the page instead of pointing at another article's file.
     */
    public function content(AbstractBlogPost $post): string
    {
        $ids = [];

        foreach ($post->getFiles() as $file) {
            $ids[$file->getId()] = true;
        }

        return (string) preg_replace_callback(
            '/\('.preg_quote(self::FILE_SCHEME, '/').'(\d+)\)/',
            fn (array $match): string => isset($ids[(int) $match[1]])
                ? '('.$this->urlGenerator->generate('mulertech_blog_file', ['slug' => $post->getSlug(), 'id' => (int) $match[1]]).')'
                : $match[0],
            $post->getContent(),
        );
    }
}
