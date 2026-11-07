<?php

declare(strict_types=1);

namespace MulerTech\BlogBundle\Controller;

use MulerTech\BlogBundle\Repository\BlogPostRepository;
use MulerTech\FileBundle\Model\FileInterface;
use MulerTech\FileBundle\Service\FileManagerInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\EventListener\AbstractSessionListener;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Serves the featured image and the attached files of a published article.
 *
 * The file storage is private: a file is served only through the article it belongs to,
 * so a draft's files and the site's other documents stay out of reach, whatever id is
 * typed in the address.
 */
final readonly class BlogFileController
{
    /**
     * A day: long enough to spare the server, short enough that a file corrected in the
     * admin is seen again soon. The ETag lets a browser revalidate without downloading.
     */
    private const int MAX_AGE = 86400;

    public function __construct(
        private BlogPostRepository $repository,
        private FileManagerInterface $fileManager,
    ) {
    }

    public function __invoke(Request $request, string $slug, int $id): Response
    {
        $post = $this->repository->findPublishedBySlug($slug, $request->getLocale())
            ?? throw new NotFoundHttpException(sprintf('No published article "%s" in "%s".', $slug, $request->getLocale()));

        $file = $post->getFeaturedImage()?->getId() === $id
            ? $post->getFeaturedImage()
            : $post->getFiles()->findFirst(static fn (int $key, FileInterface $file): bool => $file->getId() === $id);

        if (null === $file) {
            throw new NotFoundHttpException(sprintf('The article "%s" has no file %d.', $slug, $id));
        }

        if (!$this->fileManager->fileExistsOnDisk($file)) {
            throw new \RuntimeException(sprintf('The file %d of the article "%s" is missing from the storage at %s.', $id, $slug, $this->fileManager->getAbsolutePath($file)));
        }

        $response = new BinaryFileResponse($this->fileManager->getAbsolutePath($file), autoEtag: true, autoLastModified: true);
        $response->headers->set('Content-Type', $file->getMimeType());
        $response->setContentDisposition(
            str_starts_with($file->getMimeType(), 'image/') ? ResponseHeaderBag::DISPOSITION_INLINE : ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $file->getFilename(),
            'file-'.$id.'.'.$file->getExtension(),
        );
        // Behind a stateful firewall, Symfony would otherwise mark the response private on
        // the grounds that the session was touched, and no intermediary would keep it.
        $response->headers->set(AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER, 'true');
        $response->setPublic();
        $response->setMaxAge(self::MAX_AGE);
        $response->isNotModified($request);

        return $response;
    }
}
