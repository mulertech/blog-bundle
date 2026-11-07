<?php

declare(strict_types=1);

namespace MulerTech\BlogBundle\Tests\Twig;

use MulerTech\BlogBundle\Tests\DatabaseTestCase;
use MulerTech\BlogBundle\Tests\Fixtures\Entity\File;
use MulerTech\BlogBundle\Service\PostUrlGenerator;
use MulerTech\BlogBundle\Twig\BlogExtension;
use MulerTech\CspBundle\CspNonceGenerator;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\Loader\YamlFileLoader;
use Symfony\Component\Routing\RequestContext;
use Twig\TwigFunction;

final class BlogExtensionTest extends DatabaseTestCase
{
    /**
     * The content names a file by id; the page gets its address under the current slug.
     */
    public function testAnAttachedFileIsReplacedByItsAddress(): void
    {
        $post = $this->post('Illustré', slug: 'illustre');
        $file = new File()->setFilename('schéma.png')->setStoredFilename('a.png')->setFilepath('a.png')->setMimeType('image/png')->setSize('1')->setExtension('png');
        $this->entityManager->persist($file);
        $this->entityManager->flush();
        $post->addFile($file)->setContent(sprintf('Voir ![schéma](blog-file:%1$d) et [le schéma](blog-file:%1$d).', $file->getId()));
        $this->entityManager->flush();

        self::assertSame(
            sprintf('Voir ![schéma](/fr/blog/illustre/files/%1$d) et [le schéma](/fr/blog/illustre/files/%1$d).', $file->getId()),
            $this->extension()->content($post),
        );
    }

    /**
     * Pointing at the file of another article would publish a file this one does not show.
     */
    public function testAReferenceToAFileTheArticleDoesNotHoldIsLeftAsWritten(): void
    {
        $post = $this->post('Sans fichier', content: 'Voir ![image](blog-file:999).');

        self::assertSame('Voir ![image](blog-file:999).', $this->extension()->content($post));
    }

    public function testTheTemplatesReachTheFilterAndFunctionsByName(): void
    {
        self::assertSame('mulertech_blog_content', $this->extension()->getFilters()[0]->getName());
        self::assertSame(['mulertech_blog_post_url', 'mulertech_blog_csp_nonce'], array_map(
            static fn (TwigFunction $function): string => $function->getName(),
            $this->extension()->getFunctions(),
        ));
    }

    /**
     * With csp-bundle, the structured data script carries the nonce the site's policy
     * expects; without it, none.
     */
    public function testTheNonceComesFromCspBundleWhenTheSiteHasIt(): void
    {
        $generator = new CspNonceGenerator();

        self::assertSame($generator->getNonce('main'), $this->extension($generator)->cspNonce());
        self::assertNull($this->extension()->cspNonce());
    }

    private function extension(?CspNonceGenerator $nonceGenerator = null): BlogExtension
    {
        $routes = new YamlFileLoader(new FileLocator(\dirname(__DIR__).'/Fixtures/config'))->load('routes.yaml');
        $urlGenerator = new UrlGenerator($routes, new RequestContext(parameters: ['_locale' => 'fr']));

        return new BlogExtension($urlGenerator, new PostUrlGenerator($urlGenerator, ['fr', 'en']), $nonceGenerator);
    }
}
