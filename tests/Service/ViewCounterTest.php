<?php

declare(strict_types=1);

namespace MulerTech\BlogBundle\Tests\Service;

use MulerTech\BlogBundle\Service\ViewCounter;
use MulerTech\BlogBundle\Tests\DatabaseTestCase;
use MulerTech\BlogBundle\Tests\Fixtures\Entity\BlogPost;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class ViewCounterTest extends DatabaseTestCase
{
    public function testAVisitorIsCountedOncePerArticle(): void
    {
        $post = $this->post('Bienvenue');
        $other = $this->post('Autre');
        $counter = $this->counter(new Session(new MockArraySessionStorage()));

        self::assertTrue($counter->count($post));
        self::assertFalse($counter->count($post));
        self::assertTrue($counter->count($other));

        self::assertSame(1, $this->viewCount($post));
        self::assertSame(1, $this->viewCount($other));
    }

    public function testEachVisitorIsCounted(): void
    {
        $post = $this->post('Bienvenue');

        $this->counter(new Session(new MockArraySessionStorage()))->count($post);
        $this->counter(new Session(new MockArraySessionStorage()))->count($post);

        self::assertSame(2, $this->viewCount($post));
    }

    public function testAnUnsavedArticleCannotBeCounted(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('this one has no id yet');

        $this->counter(new Session(new MockArraySessionStorage()))->count(new BlogPost());
    }

    private function counter(Session $session): ViewCounter
    {
        $request = new Request();
        $request->setSession($session);
        $requestStack = new RequestStack();
        $requestStack->push($request);

        return new ViewCounter($this->entityManager, $requestStack);
    }

    private function viewCount(BlogPost $post): int
    {
        return (int) $this->entityManager->getConnection()->fetchOne('SELECT view_count FROM blog_posts WHERE id = ?', [$post->getId()]);
    }
}
