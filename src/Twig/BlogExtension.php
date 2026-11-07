<?php

declare(strict_types=1);

namespace MulerTech\BlogBundle\Twig;

use MulerTech\BlogBundle\Entity\AbstractBlogPost;
use MulerTech\BlogBundle\Service\PostUrlGenerator;
use MulerTech\CspBundle\CspNonceGenerator;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

final class BlogExtension extends AbstractExtension
{
    public const string FILE_SCHEME = 'blog-file:';

    /**
     * @param ?CspNonceGenerator $nonceGenerator present when the site installs mulertech/csp-bundle
     */
    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly PostUrlGenerator $postUrls,
        private readonly ?CspNonceGenerator $nonceGenerator = null,
        private readonly string $nonceHandle = 'main',
    ) {
    }

    public function getFilters(): array
    {
        return [new TwigFilter('mulertech_blog_content', $this->content(...))];
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('mulertech_blog_post_url', $this->postUrls->post(...)),
            new TwigFunction('mulertech_blog_csp_nonce', $this->cspNonce(...)),
        ];
    }

    /**
     * An image inserted from the admin is written `![…](blog-file:12)` in the content rather
     * than with its address: the address carries the slug and the language prefix, and an
     * article whose slug is corrected would otherwise lose every image of its content.
     *
     * A reference to a file the article does not hold is left as written, so the broken
     * image shows on the page instead of pointing at another article's file.
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

    /**
     * The nonce the site's CSP expects on an inline script, or null without csp-bundle, in
     * which case the site's policy is its own to adapt.
     */
    public function cspNonce(): ?string
    {
        return $this->nonceGenerator?->getNonce($this->nonceHandle);
    }
}
