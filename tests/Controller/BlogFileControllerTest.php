<?php

declare(strict_types=1);

namespace MulerTech\BlogBundle\Tests\Controller;

use MulerTech\BlogBundle\Controller\BlogFileController;
use MulerTech\BlogBundle\Tests\Fixtures\Entity\File;
use MulerTech\FileBundle\Model\FileInterface;
use MulerTech\FileBundle\Service\FileManagerInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpKernel\EventListener\AbstractSessionListener;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class BlogFileControllerTest extends PublicPageTestCase
{
    private string $storage = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->storage = sys_get_temp_dir().'/blog_files_'.uniqid('', true);
        mkdir($this->storage, 0o775, true);
    }

    protected function tearDown(): void
    {
        array_map(unlink(...), glob($this->storage.'/*') ?: []);
        rmdir($this->storage);
    }

    public function testTheFeaturedImageIsServedInlineAndCached(): void
    {
        $post = $this->post('Illustré', slug: 'illustre');
        $image = $this->file('Couverture été.webp', 'image/webp', 'webp');
        $post->setFeaturedImage($image);
        $this->entityManager->flush();

        $response = $this->controller()($this->request(), 'illustre', (int) $image->getId());

        self::assertInstanceOf(BinaryFileResponse::class, $response);
        self::assertSame($this->storage.'/'.$image->getStoredFilename(), $response->getFile()->getPathname());
        self::assertSame('image/webp', $response->headers->get('Content-Type'));
        self::assertStringStartsWith('inline;', (string) $response->headers->get('Content-Disposition'));
        self::assertTrue($response->headers->hasCacheControlDirective('public'));
        self::assertSame('86400', $response->headers->getCacheControlDirective('max-age'));
        self::assertNotNull($response->getEtag());
        self::assertSame('true', $response->headers->get(AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER));
    }

    public function testAnAttachedDocumentIsDownloadedUnderItsOriginalName(): void
    {
        $post = $this->post('Avec pièce jointe', slug: 'avec-piece-jointe');
        $document = $this->file('Devis été.pdf', 'application/pdf', 'pdf');
        $post->addFile($document);
        $this->entityManager->flush();

        $response = $this->controller()($this->request(), 'avec-piece-jointe', (int) $document->getId());

        self::assertSame("attachment; filename=file-{$document->getId()}.pdf; filename*=utf-8''Devis%20%C3%A9t%C3%A9.pdf", $response->headers->get('Content-Disposition'));
    }

    /**
     * The storage is private: an id typed in the address reaches no file the article does
     * not show.
     */
    public function testAFileOfAnotherArticleIsNotServed(): void
    {
        $this->post('Sans fichier', slug: 'sans-fichier');
        $other = $this->post('Autre', slug: 'autre');
        $file = $this->file('privé.pdf', 'application/pdf', 'pdf');
        $other->addFile($file);
        $this->entityManager->flush();

        $this->expectException(NotFoundHttpException::class);

        $this->controller()($this->request(), 'sans-fichier', (int) $file->getId());
    }

    public function testTheFilesOfADraftAreNotServed(): void
    {
        $draft = $this->post('Brouillon', publishedAt: null, slug: 'brouillon');
        $image = $this->file('cover.webp', 'image/webp', 'webp');
        $draft->setFeaturedImage($image);
        $this->entityManager->flush();

        $this->expectException(NotFoundHttpException::class);

        $this->controller()($this->request(), 'brouillon', (int) $image->getId());
    }

    /**
     * A file the article points at but the disk no longer holds is a broken storage, not a
     * missing page: it fails loudly, naming the path.
     */
    public function testAFileMissingFromTheStorageFailsLoudly(): void
    {
        $post = $this->post('Illustré', slug: 'illustre');
        $image = $this->file('cover.webp', 'image/webp', 'webp');
        $post->setFeaturedImage($image);
        $this->entityManager->flush();
        unlink($this->storage.'/'.$image->getStoredFilename());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('is missing from the storage at '.$this->storage.'/'.$image->getStoredFilename());

        $this->controller()($this->request(), 'illustre', (int) $image->getId());
    }

    private function controller(): BlogFileController
    {
        $storage = $this->storage;
        $fileManager = $this->createStub(FileManagerInterface::class);
        $fileManager->method('getAbsolutePath')->willReturnCallback(static fn (FileInterface $file): string => $storage.'/'.$file->getStoredFilename());
        $fileManager->method('fileExistsOnDisk')->willReturnCallback(static fn (FileInterface $file): bool => is_file($storage.'/'.$file->getStoredFilename()));

        return new BlogFileController($this->repository(), $fileManager);
    }

    private function file(string $filename, string $mimeType, string $extension): File
    {
        $storedFilename = uniqid('file-', true).'.'.$extension;
        file_put_contents($this->storage.'/'.$storedFilename, 'contents');

        $file = new File()
            ->setFilename($filename)
            ->setStoredFilename($storedFilename)
            ->setFilepath('blog/'.$storedFilename)
            ->setMimeType($mimeType)
            ->setSize('8')
            ->setExtension($extension);
        $this->entityManager->persist($file);

        return $file;
    }
}
