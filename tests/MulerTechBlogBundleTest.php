<?php

declare(strict_types=1);

namespace MulerTech\BlogBundle\Tests;

use MulerTech\BlogBundle\MulerTechBlogBundle;
use PHPUnit\Framework\TestCase;

final class MulerTechBlogBundleTest extends TestCase
{
    public function testTheConfigurationIsReadUnderMulertechBlog(): void
    {
        self::assertSame('mulertech_blog', new MulerTechBlogBundle()->getContainerExtension()?->getAlias());
    }
}
