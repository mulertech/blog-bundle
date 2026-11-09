<?php

declare(strict_types=1);

namespace MulerTech\BlogBundle\Tests\Service;

use MulerTech\BlogBundle\Service\BlogMediaManager;
use MulerTech\BlogBundle\Storage\BlogFileTarget;
use MulerTech\BlogBundle\Tests\DatabaseTestCase;
use MulerTech\BlogBundle\Tests\Fixtures\AdminUser;
use MulerTech\BlogBundle\Tests\Fixtures\Entity\File;
use MulerTech\BlogBundle\Tests\Fixtures\InMemoryFileManager;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
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
    }

    /**
     * The article's id starts the name of each of its files, whoever uploaded them, so a
     * file on disk is matched to its article at a glance.
     */
    public function testTheFilesOfAnArticleAreNamedAfterIt(): void
    {
        $post = $this->post('Illustré');
        $id = $post->getId();

        $this->manager()->setCover($post, $this->png('Couverture.png', 1200, 675));
        $this->manager()->attach($post, new UploadedFile($this->text('Programme du séjour.'), 'Programme du séjour.txt', 'text/plain', test: true));

        $cover = $this->fileManager->uploads[0]['context'];
        $attachment = $this->fileManager->uploads[1]['context'];
        self::assertInstanceOf(BlogFileTarget::class, $cover);
        self::assertInstanceOf(BlogFileTarget::class, $attachment);
        self::assertSame(['blog/images', 'image-'.$id.'-'], [$cover->getRelativeDirectory(), $cover->getFilenamePrefix()]);
        self::assertSame(['blog/files', 'file-'.$id.'-programme-du-sejour-'], [$attachment->getRelativeDirectory(), $attachment->getFilenamePrefix()]);
    }

    /**
     * An API without session, an import, stores the files on behalf of the author it names.
     */
    public function testTheUploaderGivenStoresTheFilesWithNobodyLoggedIn(): void
    {
        $this->tokenStorage->setToken(new NullToken());
        $author = new AdminUser();
        $post = $this->post('Importé');

        $this->manager()->setCover($post, $this->png('a.png', 1200, 675), $author);
        $this->manager()->attach($post, $this->png('b.png', 10, 10), $author);

        self::assertSame([$author, $author], array_column($this->fileManager->uploads, 'uploader'));
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

    public function testAWidePhotoIsStoredAsWebpAtTheMaximumWidth(): void
    {
        $file = $this->manager()->attach($this->post('Photo'), $this->jpeg('gîte.jpg', 3200, 1800));

        self::assertSame('gîte.webp', $file->getFilename());
        self::assertSame('image/webp', $file->getMimeType());
        self::assertSame([BlogMediaManager::ATTACHED_IMAGE_MAX_WIDTH, 900], $this->dimensions($file));
    }

    /**
     * Orientation 6 records a photo taken with the phone upright and stored on its side.
     */
    public function testAPhonePhotoIsTurnedUpright(): void
    {
        $file = $this->manager()->attach($this->post('Photo'), $this->jpeg('portrait.jpg', 400, 200, orientation: 6));

        self::assertSame([200, 400], $this->dimensions($file));
    }

    public function testAPngWithinTheWidthIsStoredAsWebpAtItsSize(): void
    {
        $file = $this->manager()->attach($this->post('Schéma'), $this->png('schéma.png', 300, 200));

        self::assertSame('schéma.webp', $file->getFilename());
        self::assertSame([300, 200], $this->dimensions($file));
    }

    public function testAWideTransparentPngKeepsItsTransparencyOnceBroughtDown(): void
    {
        $image = imagecreatetruecolor(2000, 1000);
        imagealphablending($image, false);
        imagefill($image, 0, 0, (int) imagecolorallocatealpha($image, 0, 0, 0, 127));
        imagesavealpha($image, true);
        $path = (string) tempnam(sys_get_temp_dir(), 'upload');
        imagepng($image, $path);

        $file = $this->manager()->attach($this->post('Logo'), new UploadedFile($path, 'logo.png', 'image/png', test: true));

        $stored = imagecreatefromwebp($this->fileManager->getAbsolutePath($file));
        self::assertNotFalse($stored);
        self::assertSame(127, imagecolorsforindex($stored, (int) imagecolorat($stored, 10, 10))['alpha']);
    }

    public function testAWebpWithinTheWidthIsKeptAsSent(): void
    {
        $sent = $this->webp('léger.webp', 800, 400);
        $hash = md5_file($sent->getPathname());

        $file = $this->manager()->attach($this->post('Léger'), $sent);

        self::assertSame('léger.webp', $file->getFilename());
        self::assertSame($hash, md5_file($this->fileManager->getAbsolutePath($file)));
    }

    public function testAWideWebpIsBroughtDownToTheMaximumWidth(): void
    {
        $file = $this->manager()->attach($this->post('Large'), $this->webp('large.webp', 2000, 1000));

        self::assertSame([BlogMediaManager::ATTACHED_IMAGE_MAX_WIDTH, 800], $this->dimensions($file));
    }

    /**
     * Converting a GIF would lose its animation, and a document has nothing to convert.
     */
    public function testAGifAndADocumentAreStoredAsSent(): void
    {
        $post = $this->post('Divers');
        $gifPath = (string) tempnam(sys_get_temp_dir(), 'upload');
        imagegif(imagecreatetruecolor(20, 20), $gifPath);
        $textPath = (string) tempnam(sys_get_temp_dir(), 'upload');
        file_put_contents($textPath, 'Programme du séjour.');

        $gif = $this->manager()->attach($post, new UploadedFile($gifPath, 'anime.gif', 'image/gif', test: true));
        $text = $this->manager()->attach($post, new UploadedFile($textPath, 'programme.txt', 'text/plain', test: true));

        self::assertSame(['anime.gif', 'image/gif'], [$gif->getFilename(), $gif->getMimeType()]);
        self::assertSame(['programme.txt', 'text/plain'], [$text->getFilename(), $text->getMimeType()]);
    }

    public function testAnImageThatCannotBeDecodedIsRefusedByName(): void
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'upload');
        file_put_contents($path, "\xFF\xD8\xFF\xE0".str_repeat('x', 64));

        $this->expectException(FileException::class);
        $this->expectExceptionMessage('The image "abime.jpg" cannot be decoded as image/jpeg.');

        $this->manager()->attach($this->post('Abîmé'), new UploadedFile($path, 'abime.jpg', 'image/jpeg', test: true));
    }

    /**
     * With no uploader given and nobody to name, the failure says what is expected.
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
        $this->expectExceptionMessage('no uploader was passed and no user is logged in');

        $this->manager()->attach($this->post('Article'), $this->png('a.png', 10, 10));
    }

    private function manager(): BlogMediaManager
    {
        return new BlogMediaManager($this->fileManager, $this->tokenStorage, $this->entityManager);
    }

    /**
     * @return array{int, int}
     */
    private function dimensions(\MulerTech\FileBundle\Model\FileInterface $file): array
    {
        $size = getimagesize($this->fileManager->getAbsolutePath($file));
        self::assertIsArray($size);

        return [$size[0], $size[1]];
    }

    /**
     * A JPEG as a phone writes it: with `orientation`, an EXIF segment telling how to turn
     * the picture upright.
     */
    private function jpeg(string $name, int $width, int $height, ?int $orientation = null): UploadedFile
    {
        \assert($width > 0 && $height > 0);
        ob_start();
        imagejpeg(imagecreatetruecolor($width, $height));
        $jpeg = (string) ob_get_clean();

        if (null !== $orientation) {
            $tiff = "II*\x00".pack('V', 8).pack('v', 1).pack('vvVvv', 0x0112, 3, 1, $orientation, 0).pack('V', 0);
            $exif = "Exif\x00\x00".$tiff;
            $jpeg = substr($jpeg, 0, 2)."\xFF\xE1".pack('n', \strlen($exif) + 2).$exif.substr($jpeg, 2);
        }

        $path = (string) tempnam(sys_get_temp_dir(), 'upload');
        file_put_contents($path, $jpeg);

        return new UploadedFile($path, $name, 'image/jpeg', test: true);
    }

    private function text(string $content): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'upload');
        file_put_contents($path, $content);

        return $path;
    }

    private function webp(string $name, int $width, int $height): UploadedFile
    {
        \assert($width > 0 && $height > 0);
        $path = (string) tempnam(sys_get_temp_dir(), 'upload');
        imagewebp(imagecreatetruecolor($width, $height), $path);

        return new UploadedFile($path, $name, 'image/webp', test: true);
    }

    private function png(string $name, int $width, int $height): UploadedFile
    {
        \assert($width > 0 && $height > 0);
        $path = (string) tempnam(sys_get_temp_dir(), 'upload');
        imagepng(imagecreatetruecolor($width, $height), $path);

        return new UploadedFile($path, $name, 'image/png', test: true);
    }
}
