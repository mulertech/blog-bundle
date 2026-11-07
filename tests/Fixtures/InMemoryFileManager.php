<?php

declare(strict_types=1);

namespace MulerTech\BlogBundle\Tests\Fixtures;

use Doctrine\ORM\EntityManagerInterface;
use MulerTech\BlogBundle\Tests\Fixtures\Entity\File;
use MulerTech\FileBundle\Model\FileInterface;
use MulerTech\FileBundle\Model\FileUploaderInterface;
use MulerTech\FileBundle\Service\FileManagerInterface;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Stores files in a temporary directory the way the file bundle does, recording the
 * context each upload was made in.
 */
final class InMemoryFileManager implements FileManagerInterface
{
    /** @var list<array{uploader: FileUploaderInterface, context: ?object}> */
    public array $uploads = [];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        public readonly string $directory,
    ) {
    }

    public function upload(UploadedFile $file, FileUploaderInterface $uploader, ?object $context = null, bool $isPublic = false): FileInterface
    {
        if ('exe' === $file->getClientOriginalExtension()) {
            throw new FileException('File type "exe" is not allowed.');
        }

        $extension = $file->getClientOriginalExtension();
        $stored = uniqid('file-', true).'.'.$extension;
        file_put_contents($this->directory.'/'.$stored, $file->getContent());

        $entity = new File()
            ->setFilename($file->getClientOriginalName())
            ->setStoredFilename($stored)
            ->setFilepath($stored)
            ->setMimeType($file->getMimeType() ?? 'application/octet-stream')
            ->setSize((string) filesize($this->directory.'/'.$stored))
            ->setExtension($extension)
            ->setIsPublic($isPublic);
        $this->entityManager->persist($entity);
        $this->entityManager->flush();
        $this->uploads[] = ['uploader' => $uploader, 'context' => $context];

        return $entity;
    }

    public function delete(FileInterface $file): void
    {
        @unlink($this->getAbsolutePath($file));
        $this->entityManager->remove($file);
        $this->entityManager->flush();
    }

    public function getAbsolutePath(FileInterface $file): string
    {
        return $this->directory.'/'.$file->getFilepath();
    }

    public function fileExistsOnDisk(FileInterface $file): bool
    {
        return is_file($this->getAbsolutePath($file));
    }

    public function formatFileSize(string $bytes): string
    {
        return $bytes.' o';
    }

    public function getMaxFileSizeInMB(): int
    {
        return 10;
    }

    public function getAllowedExtensions(): array
    {
        return ['webp', 'png', 'pdf'];
    }
}
