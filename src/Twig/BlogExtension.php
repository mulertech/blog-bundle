<?php

declare(strict_types=1);

namespace MulerTech\BlogBundle\Twig;

use MulerTech\BlogBundle\Entity\AbstractBlogPost;
use MulerTech\BlogBundle\Service\PostUrlGenerator;
use MulerTech\CspBundle\CspNonceGenerator;
use MulerTech\FileBundle\Model\FileInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;
use Twig\TwigTest;

final class BlogExtension extends AbstractExtension
{
    public const string FILE_SCHEME = 'blog-file:';

    /**
     * Set on the request of an admin preview. The public file route answers only for a
     * published article, and the admin is often signed in behind a firewall of its own
     * that the public pages do not share: during a preview, files are addressed through
     * the admin route instead.
     */
    public const string PREVIEW_ATTRIBUTE = '_mulertech_blog_preview';

    /**
     * @param ?CspNonceGenerator $nonceGenerator present when the site installs mulertech/csp-bundle
     */
    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly PostUrlGenerator $postUrls,
        private readonly RequestStack $requestStack,
        private readonly ?CspNonceGenerator $nonceGenerator = null,
        private readonly string $nonceHandle = 'main',
        private readonly ClockInterface $clock = new NativeClock(),
    ) {
    }

    public function getFilters(): array
    {
        return [new TwigFilter('mulertech_blog_content', $this->content(...))];
    }

    public function getTests(): array
    {
        return [new TwigTest('mulertech_blog_scheduled', $this->isScheduled(...))];
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('mulertech_blog_post_url', $this->postUrls->post(...)),
            new TwigFunction('mulertech_blog_feed_url', $this->postUrls->feed(...)),
            new TwigFunction('mulertech_blog_file_url', $this->fileUrl(...)),
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
            // A file not yet saved has no id, so no content can refer to it.
            if (null !== $id = $file->getId()) {
                $ids[$id] = true;
            }
        }

        return (string) preg_replace_callback(
            '/\('.preg_quote(self::FILE_SCHEME, '/').'(\d+)\)/',
            fn (array $match): string => isset($ids[(int) $match[1]])
                ? '('.$this->fileUrlById($post, (int) $match[1]).')'
                : $match[0],
            $post->getContent(),
        );
    }

    /**
     * The address of a file of the article: the public one, or the admin one during a
     * preview (see PREVIEW_ATTRIBUTE).
     */
    public function fileUrl(AbstractBlogPost $post, FileInterface $file): string
    {
        return $this->fileUrlById($post, (int) $file->getId());
    }

    private function fileUrlById(AbstractBlogPost $post, int $id): string
    {
        if (true === $this->requestStack->getMainRequest()?->attributes->get(self::PREVIEW_ATTRIBUTE)) {
            return $this->urlGenerator->generate('mulertech_blog_admin_file', ['id' => $post->getId(), 'fileId' => $id]);
        }

        return $this->urlGenerator->generate('mulertech_blog_file', ['slug' => $post->getSlug(), 'id' => $id]);
    }

    /**
     * Published, but hidden from visitors until a date still to come.
     */
    public function isScheduled(AbstractBlogPost $post): bool
    {
        return $post->isScheduledAt($this->clock->now());
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
