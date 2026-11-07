<?php

declare(strict_types=1);

namespace MulerTech\BlogBundle\Tests\Service;

use MulerTech\BlogBundle\Service\BlogMediaManager;
use MulerTech\BlogBundle\Tests\DatabaseTestCase;
use MulerTech\BlogBundle\Tests\Fixtures\AdminUser;
use MulerTech\BlogBundle\Tests\Fixtures\Entity\File;
use MulerTech\BlogBundle\Tests\Fixtures\InMemoryFileManager;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;

final class BlogMediaManagerTest extends DatabaseTestCase
{
    private InMemoryFileManager $fileManager;
    private TokenStorage $tokenStorage;

    protected function setUp(): void
    {
        parent::setUp();

        $directory = sys_get_temp_dir().'/blog_media_'.uniqid('', true);
        mkdir($directory, 0o775, true);
        $this->fileManager = new InMemoryFileManager($this->entityManager, $directory);
        $this->tokenStorage = new TokenStorage();
        $this->tokenStorage->setToken(new UsernamePasswordToken(new AdminUser(), 'main', ['ROLE_ADMIN']));
    }

    protected function tearDown(): void
    {
        array_map(unlink(...), glob($this->fileManager->directory.'/*') ?: []);
        rmdir($this->fileManager->directory);
    }

    /**
     * The browser sends the cropped cover as PNG, lossless and heavy: what is stored and
     * served is WebP.
     */
    public function testACoverIsStoredAsWebpOnBehalfOfTheUserForTheArticle(): void
    {
        $post = $this->post('Illustré');

        $this->manager()->setCover($post, $this->png('Couverture.png', 1200, 675));

        $cover = $post->getFeaturedImage();
        self::assertNotNull($cover);
        self::assertSame('Couverture.webp', $cover->getFilename());
        self::assertSame('image/webp', $cover->getMimeType());
        self::assertSame('image/webp', getimagesize($this->fileManager->getAbsolutePath($cover))['mime'] ?? null);
        self::assertInstanceOf(AdminUser::class, $this->fileManager->uploads[0]['uploader']);
        self::assertSame($post, $this->fileManager->uploads[0]['context']);
    }

    public function testATransparentCoverKeepsItsTransparency(): void
    {
        $post = $this->post('Illustré');
        $path = (string) tempnam(sys_get_temp_dir(), 'cover');
        $image = imagecreatetruecolor(1200, 675);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
        imagepng($image, $path);

        $this->manager()->setCover($post, new UploadedFile($path, 'logo.png', 'image/png', test: true));

        $stored = imagecreatefromwebp($this->fileManager->getAbsolutePath($post->getFeaturedImage() ?? new File()));
        self::assertInstanceOf(\GdImage::class, $stored);
        self::assertSame(127, imagecolorsforindex($stored, (int) imagecolorat($stored, 10, 10))['alpha']);
    }

    /**
     * Nothing stays on disk that no page shows.
     */
    public function testReplacingACoverDeletesThePreviousOne(): void
    {
        $post = $this->post('Illustré');
        $this->manager()->setCover($post, $this->png('a.png', 1200, 675));
        $previous = $this->fileManager->getAbsolutePath($post->getFeaturedImage() ?? new File());

        $this->manager()->setCover($post, $this->png('b.png', 1200, 675));

        self::assertFileDoesNotExist($previous);
        self::assertSame('b.webp', $post->getFeaturedImage()?->getFilename());
        self::assertCount(1, $this->entityManager->getRepository(File::class)->findAll());
    }

    public function testRemovingTheCoverDeletesIt(): void
    {
        $post = $this->post('Illustré');
        $this->manager()->setCover($post, $this->png('a.png', 1200, 675));
        $path = $this->fileManager->getAbsolutePath($post->getFeaturedImage() ?? new File());

        $this->manager()->removeCover($post);
        $this->manager()->removeCover($post);

        self::assertNull($post->getFeaturedImage());
        self::assertFileDoesNotExist($path);
    }

    public function testAnUnreadableCoverIsRefusedByName(): void
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'cover');
        file_put_contents($path, 'not an image');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The cover "cover.png" cannot be read as an image.');

        $this->manager()->setCover($this->post('Illustré'), new UploadedFile($path, 'cover.png', 'image/png', test: true));
    }

    public function testAnAttachedFileJoinsTheArticleAndLeavesWithIt(): void
    {
        $post = $this->post('Avec fichier');

        $file = $this->manager()->attach($post, $this->png('schéma.png', 10, 10));
        self::assertTrue($post->getFiles()->contains($file));

        $path = $this->fileManager->getAbsolutePath($file);
        $this->manager()->detach($post, $file);

        self::assertCount(0, $post->getFiles());
        self::assertFileDoesNotExist($path);
    }

    public function testDeletingAnArticleDeletesItsFiles(): void
    {
        $post = $this->post('Complet');
        $this->manager()->setCover($post, $this->png('cover.png', 1200, 675));
        $this->manager()->attach($post, $this->png('schéma.png', 10, 10));

        $this->manager()->delete($post);

        self::assertSame([], glob($this->fileManager->directory.'/*'));
        self::assertCount(0, $this->entityManager->getRepository(File::class)->findAll());
        self::assertSame(0, (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM blog_posts'));
    }

    /**
     * The file bundle files every upload under its uploader; with nobody to name, the
     * failure says what the user is expected to be.
     */
    public function testAUserThatCannotUploadIsNamed(): void
    {
        $this->tokenStorage->setToken(new UsernamePasswordToken(new InMemoryUser('admin', null, ['ROLE_ADMIN']), 'main', ['ROLE_ADMIN']));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('must implement MulerTech\FileBundle\Model\FileUploaderInterface: Symfony\Component\Security\Core\User\InMemoryUser does not.');

        $this->manager()->attach($this->post('Article'), $this->png('a.png', 10, 10));
    }

    public function testNobodyLoggedInIsNamed(): void
    {
        $this->tokenStorage->setToken(new NullToken());

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('no user is logged in');

        $this->manager()->attach($this->post('Article'), $this->png('a.png', 10, 10));
    }

    private function manager(): BlogMediaManager
    {
        return new BlogMediaManager($this->fileManager, $this->tokenStorage, $this->entityManager);
    }

    private function png(string $name, int $width, int $height): UploadedFile
    {
        \assert($width > 0 && $height > 0);
        $path = (string) tempnam(sys_get_temp_dir(), 'upload');
        imagepng(imagecreatetruecolor($width, $height), $path);

        return new UploadedFile($path, $name, 'image/png', test: true);
    }
}
