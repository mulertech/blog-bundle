<?php

declare(strict_types=1);

namespace MulerTech\BlogBundle\Storage;

use MulerTech\BlogBundle\Entity\AbstractBlogPost;
use MulerTech\FileBundle\Storage\StorageTargetInterface;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Where the files of an article are stored, the same on every site: the article's id
 * starts the name, so a file on disk is matched to the article it illustrates at a glance,
 * whoever uploaded it.
 *
 * `blog/images/image-12-<unique>.webp` for a cover, `blog/files/file-12-<name>-<unique>.pdf`
 * for an attached file, whose original name is kept since nothing else tells what a
 * document holds.
 */
final readonly class BlogFileTarget implements StorageTargetInterface
{
    public const string COVER_DIRECTORY = 'blog/images';
    public const string ATTACHMENT_DIRECTORY = 'blog/files';

    /**
     * Room for the prefix, the article id, the unique part and the extension within the
     * 255 characters of a stored filename.
     */
    private const int MAX_NAME_LENGTH = 60;

    private function __construct(
        private string $directory,
        private string $prefix,
    ) {
    }

    public static function cover(AbstractBlogPost $post): self
    {
        return new self(self::COVER_DIRECTORY, sprintf('image-%d-', self::postId($post)));
    }

    public static function attachment(AbstractBlogPost $post, string $originalName): self
    {
        $name = mb_substr(
            (new AsciiSlugger())->slug(pathinfo($originalName, \PATHINFO_FILENAME))->lower()->toString(),
            0,
            self::MAX_NAME_LENGTH,
        );

        return new self(
            self::ATTACHMENT_DIRECTORY,
            sprintf('file-%d-', self::postId($post)).('' === $name ? '' : rtrim($name, '-').'-'),
        );
    }

    public function getRelativeDirectory(): string
    {
        return $this->directory;
    }

    public function getFilenamePrefix(): string
    {
        return $this->prefix;
    }

    private static function postId(AbstractBlogPost $post): int
    {
        return $post->getId()
            ?? throw new \LogicException('An article is saved before its files: their name starts with its id.');
    }
}
