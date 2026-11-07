<?php

declare(strict_types=1);

namespace MulerTech\BlogBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use MulerTech\BlogBundle\Entity\AbstractBlogPost;
use MulerTech\FileBundle\Model\FileInterface;
use MulerTech\FileBundle\Model\FileUploaderInterface;
use MulerTech\FileBundle\Service\FileManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * The files of an article, stored through `mulertech/file-bundle`.
 *
 * The article is passed as the upload context, so the site's directory strategy can file
 * the media of its blog apart from the rest. A file belongs to one article: removing it
 * from the article, or deleting the article, deletes it from the storage too, so nothing
 * is left on disk that no page shows.
 */
final readonly class BlogMediaManager
{
    private const int WEBP_QUALITY = 85;

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
    public function setCover(AbstractBlogPost $post, UploadedFile $image): void
    {
        $previous = $post->getFeaturedImage();
        $webp = $this->toWebp($image);

        try {
            $post->setFeaturedImage($this->fileManager->upload($webp, $this->uploader(), $post, true));
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

    public function attach(AbstractBlogPost $post, UploadedFile $file): FileInterface
    {
        $attached = $this->fileManager->upload($file, $this->uploader(), $post, true);
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

    private function uploader(): FileUploaderInterface
    {
        $user = $this->tokenStorage->getToken()?->getUser();

        if (!$user instanceof FileUploaderInterface) {
            throw new \LogicException(sprintf('The files of an article are stored on behalf of the logged-in user, which must implement %s: %s does not.', FileUploaderInterface::class, null === $user ? 'no user is logged in, so this' : $user::class));
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

        imagepalettetotruecolor($bitmap);
        imagesavealpha($bitmap, true);
        $path = tempnam(sys_get_temp_dir(), 'mulertech_blog_cover_');

        // Reached only with a temporary directory full or not writable.
        if (false === $path || !imagewebp($bitmap, $path, self::WEBP_QUALITY)) {
            throw new \RuntimeException(sprintf('The cover "%s" could not be encoded as WebP in %s.', $image->getClientOriginalName(), sys_get_temp_dir()));
        }

        // `test` lets the file manager accept a file that did not arrive through an HTTP
        // upload, which this one, written just above, never did.
        return new UploadedFile($path, pathinfo($image->getClientOriginalName(), \PATHINFO_FILENAME).'.webp', 'image/webp', test: true);
    }
}
