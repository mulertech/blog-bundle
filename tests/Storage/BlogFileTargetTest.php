<?php

declare(strict_types=1);

namespace MulerTech\BlogBundle\Tests\Storage;

use MulerTech\BlogBundle\Storage\BlogFileTarget;
use MulerTech\BlogBundle\Tests\Fixtures\Entity\BlogPost;
use PHPUnit\Framework\TestCase;

final class BlogFileTargetTest extends TestCase
{
    public function testAnUnsavedArticleHasNoFileName(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('An article is saved before its files');

        BlogFileTarget::cover(new BlogPost());
    }

    public function testANameWithoutLetterLeavesTheIdAlone(): void
    {
        self::assertSame('file-7-', BlogFileTarget::attachment($this->saved(7), '!!!.pdf')->getFilenamePrefix());
    }

    public function testALongNameIsCut(): void
    {
        $prefix = BlogFileTarget::attachment($this->saved(7), str_repeat('compte rendu ', 20).'.pdf')->getFilenamePrefix();

        self::assertLessThanOrEqual(\strlen('file-7-') + 61, \strlen($prefix));
        self::assertStringEndsWith('-', $prefix);
        self::assertStringNotContainsString('--', $prefix);
    }

    private function saved(int $id): BlogPost
    {
        $post = new BlogPost();
        new \ReflectionProperty($post, 'id')->setValue($post, $id);

        return $post;
    }
}
