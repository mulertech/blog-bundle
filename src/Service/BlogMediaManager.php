<?php

declare(strict_types=1);

namespace MulerTech\BlogBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use MulerTech\BlogBundle\Entity\AbstractBlogPost;
use MulerTech\BlogBundle\Storage\BlogFileTarget;
use MulerTech\FileBundle\Model\FileInterface;
use MulerTech\FileBundle\Model\FileUploaderInterface;
use MulerTech\FileBundle\Service\FileManagerInterface;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * The files of an article, stored through `mulertech/file-bundle`.
 *
 * Each file is stored under a name starting with its article's id (see BlogFileTarget). A
 * file belongs to one article: removing it from the article, or deleting the article,
 * deletes it from the storage too, so nothing is left on disk that no page shows.
 *
 * The uploader only feeds the file manager's log and a site's own file entity. It is the
 * one given, an author for an API without session, else the logged-in user.
 */
final readonly class BlogMediaManager
{
    private const int WEBP_QUALITY = 85;

    /**
     * Twice the width of the article column, so an inserted image stays sharp on a high
     * density screen while a photo straight from a phone, 4000 px wide and several
     * megabytes, is not what every reader downloads.
     */
    public const int ATTACHED_IMAGE_MAX_WIDTH = 1600;

    /**
     * The attached images stored as WebP. A GIF keeps its animation and an SVG stays a
     * vector, so both are stored as sent, like the documents.
     */
    private const array CONVERTED_IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    public function __construct(
        private FileManagerInterface $fileManager,
        private TokenStorageInterface $tokenStorage,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Stored as WebP whatever the format sent: the browser crops to PNG, lossless and
     * several times heavier, and every visitor would download that weight.
     */
    public function setCover(AbstractBlogPost $post, UploadedFile $image, ?FileUploaderInterface $uploader = null): void
    {
        $previous = $post->getFeaturedImage();
        $target = BlogFileTarget::cover($post);
        $uploader = $this->uploader($uploader);
        $webp = $this->toWebp($image);

        try {
            $post->setFeaturedImage($this->fileManager->upload($webp, $uploader, $target, true));
        } finally {
            @unlink($webp->getPathname());
        }

        $this->entityManager->flush();

        if (null !== $previous) {
            $this->fileManager->delete($previous);
        }
    }

    public function removeCover(AbstractBlogPost $post): void
    {
        $cover = $post->getFeaturedImage();

        if (null === $cover) {
            return;
        }

        $post->setFeaturedImage(null);
        $this->entityManager->flush();
        $this->fileManager->delete($cover);
    }

    /**
     * A JPEG or PNG image is stored as WebP, turned upright and brought down to
     * ATTACHED_IMAGE_MAX_WIDTH; a WebP already within that width is kept as sent.
     *
     * @throws FileException when an image cannot be decoded, naming the file
     */
    public function attach(AbstractBlogPost $post, UploadedFile $file, ?FileUploaderInterface $uploader = null): FileInterface
    {
        $target = BlogFileTarget::attachment($post, $file->getClientOriginalName());
        $uploader = $this->uploader($uploader);
        $webp = $this->attachedImageAsWebp($file);

        try {
            $attached = $this->fileManager->upload($webp ?? $file, $uploader, $target, true);
        } finally {
            if (null !== $webp) {
                @unlink($webp->getPathname());
            }
        }

        $post->addFile($attached);
        $this->entityManager->flush();

        return $attached;
    }

    public function detach(AbstractBlogPost $post, FileInterface $file): void
    {
        $post->removeFile($file);
        $this->entityManager->flush();
        $this->fileManager->delete($file);
    }

    /**
     * The article goes first: its files are deleted only once nothing points at them.
     */
    public function delete(AbstractBlogPost $post): void
    {
        $files = [...$post->getFiles()];

        if (null !== $post->getFeaturedImage()) {
            $files[] = $post->getFeaturedImage();
        }

        $this->entityManager->remove($post);
        $this->entityManager->flush();

        foreach ($files as $file) {
            $this->fileManager->delete($file);
        }
    }

    private function uploader(?FileUploaderInterface $given): FileUploaderInterface
    {
        if (null !== $given) {
            return $given;
        }

        $user = $this->tokenStorage->getToken()?->getUser();

        if (!$user instanceof FileUploaderInterface) {
            throw new \LogicException(sprintf('The files of an article are stored on behalf of the uploader passed to %s, else of the logged-in user, which must implement %s: %s.', self::class, FileUploaderInterface::class, null === $user ? 'no uploader was passed and no user is logged in' : $user::class.' does not'));
        }

        return $user;
    }

    private function toWebp(UploadedFile $image): UploadedFile
    {
        $contents = $image->getContent();
        $bitmap = '' === $contents ? false : @imagecreatefromstring($contents);

        if (false === $bitmap) {
            throw new \InvalidArgumentException(sprintf('The cover "%s" cannot be read as an image.', $image->getClientOriginalName()));
        }

        return $this->encodeWebp($bitmap, $image->getClientOriginalName());
    }

    private function attachedImageAsWebp(UploadedFile $file): ?UploadedFile
    {
        $type = $file->getMimeType();

        if (!\in_array($type, self::CONVERTED_IMAGE_TYPES, true)) {
            return null;
        }

        $contents = $file->getContent();
        $bitmap = '' === $contents ? false : @imagecreatefromstring($contents);

        if (false === $bitmap) {
            throw new FileException(sprintf('The image "%s" cannot be decoded as %s.', $file->getClientOriginalName(), $type));
        }

        $upright = 'image/jpeg' === $type ? $this->upright($bitmap, $file->getPathname()) : $bitmap;

        if ('image/webp' === $type && imagesx($upright) <= self::ATTACHED_IMAGE_MAX_WIDTH) {
            return null;
        }

        if (imagesx($upright) > self::ATTACHED_IMAGE_MAX_WIDTH) {
            imagealphablending($upright, false);
            $scaled = imagescale($upright, self::ATTACHED_IMAGE_MAX_WIDTH);

            // Reached only when GD runs out of memory for the scaled copy.
            if (false === $scaled) {
                throw new \RuntimeException(sprintf('The image "%s" could not be scaled down to %d px.', $file->getClientOriginalName(), self::ATTACHED_IMAGE_MAX_WIDTH));
            }

            $upright = $scaled;
        }

        return $this->encodeWebp($upright, $file->getClientOriginalName());
    }

    /**
     * A phone stores a photo as the sensor saw it and records how to turn it in its EXIF
     * orientation; GD ignores that record, and the WebP, which carries none, would show
     * the photo on its side.
     */
    private function upright(\GdImage $bitmap, string $path): \GdImage
    {
        $exif = @exif_read_data($path);
        $orientation = \is_array($exif) && \is_int($exif['Orientation'] ?? null) ? $exif['Orientation'] : 1;

        $rotated = match ($orientation) {
            3 => imagerotate($bitmap, 180, 0),
            5, 6 => imagerotate($bitmap, -90, 0),
            7, 8 => imagerotate($bitmap, 90, 0),
            default => $bitmap,
        };

        // Reached only when GD runs out of memory for the turned copy.
        if (false === $rotated) {
            throw new \RuntimeException(sprintf('The image at %s could not be turned upright (EXIF orientation %d).', $path, $orientation));
        }

        match ($orientation) {
            2, 5, 7 => imageflip($rotated, \IMG_FLIP_HORIZONTAL),
            4 => imageflip($rotated, \IMG_FLIP_VERTICAL),
            default => null,
        };

        return $rotated;
    }

    private function encodeWebp(\GdImage $bitmap, string $clientName): UploadedFile
    {
        imagepalettetotruecolor($bitmap);
        imagesavealpha($bitmap, true);
        $path = tempnam(sys_get_temp_dir(), 'mulertech_blog_image_');

        // Reached only with a temporary directory full or not writable.
        if (false === $path || !imagewebp($bitmap, $path, self::WEBP_QUALITY)) {
            throw new \RuntimeException(sprintf('The image "%s" could not be encoded as WebP in %s.', $clientName, sys_get_temp_dir()));
        }

        // `test` lets the file manager accept a file that did not arrive through an HTTP
        // upload, which this one, written just above, never did.
        return new UploadedFile($path, pathinfo($clientName, \PATHINFO_FILENAME).'.webp', 'image/webp', test: true);
    }
}
