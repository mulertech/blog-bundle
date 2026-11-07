<?php

declare(strict_types=1);

namespace MulerTech\BlogBundle\Controller;

use Doctrine\ORM\EntityManagerInterface;
use MulerTech\BlogBundle\Entity\AbstractBlogPost;
use MulerTech\BlogBundle\Repository\BlogPostRepository;
use MulerTech\BlogBundle\Service\BlogMediaManager;
use MulerTech\BlogBundle\Service\BlogPostPublisher;
use MulerTech\BlogBundle\Service\SlugGenerator;
use MulerTech\FileBundle\Model\FileInterface;
use MulerTech\FileBundle\Service\FileManagerInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * The administration of the articles.
 *
 * Every action checks the configured role itself rather than trusting the site's firewall
 * alone: an admin prefix left out of `access_control` would otherwise open the blog's
 * administration to anyone.
 */
final readonly class BlogAdminController
{
    /**
     * @param class-string<AbstractBlogPost> $postClass
     * @param class-string                   $postFormType
     * @param list<string>                   $locales
     */
    public function __construct(
        private BlogPostRepository $repository,
        private EntityManagerInterface $entityManager,
        private BlogMediaManager $mediaManager,
        private BlogPostPublisher $publisher,
        private SlugGenerator $slugGenerator,
        private FileManagerInterface $fileManager,
        private FormFactoryInterface $formFactory,
        private Environment $twig,
        private UrlGeneratorInterface $urlGenerator,
        private AuthorizationCheckerInterface $authorizationChecker,
        private CsrfTokenManagerInterface $csrfTokenManager,
        private TranslatorInterface $translator,
        private string $postClass,
        private string $postFormType,
        private array $locales,
        private string $adminLayout,
        private string $adminRole,
    ) {
    }

    public function index(Request $request): Response
    {
        $this->denyUnlessAdmin();
        $number = $request->query->getString('page', '1');

        if (1 !== preg_match('/^[1-9]\d*$/', $number)) {
            throw new NotFoundHttpException(sprintf('"%s" is not a page number.', $number));
        }

        $search = trim($request->query->getString('q'));
        $page = $this->repository->paginateAll(max(1, (int) $number), $search);

        if (!$page->exists()) {
            throw new NotFoundHttpException(sprintf('The admin list has %d pages, not %d.', $page->pageCount, $page->number));
        }

        return $this->render('index', ['page' => $page, 'search' => $search]);
    }

    public function new(Request $request): Response
    {
        $this->denyUnlessAdmin();
        $post = new ($this->postClass)();

        if (1 === \count($this->locales)) {
            $post->setLocale($this->locales[0]);
        }

        $form = $this->form($post, $request);

        if ($form->isSubmitted() && $form->isValid() && $this->assignSlug($form, $post)) {
            $this->entityManager->persist($post);
            $this->entityManager->flush();
            $this->saveCover($form, $post);
            $this->flash($request, 'success', 'admin.flash.created');

            return $this->redirectToEdit($post);
        }

        return $this->render('edit', ['post' => $post, 'form' => $form->createView()], $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK);
    }

    public function edit(Request $request, int $id): Response
    {
        $this->denyUnlessAdmin();
        $post = $this->post($id);
        $form = $this->form($post, $request);

        if ($form->isSubmitted() && $form->isValid() && $this->assignSlug($form, $post)) {
            $this->entityManager->flush();

            if ($form->has('removeFeaturedImage') && true === $form->get('removeFeaturedImage')->getData()) {
                $this->mediaManager->removeCover($post);
            }

            $this->saveCover($form, $post);
            $this->flash($request, 'success', 'admin.flash.updated');

            return $this->redirectToEdit($post);
        }

        return $this->render('edit', ['post' => $post, 'form' => $form->createView()], $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK);
    }

    public function delete(Request $request, int $id): Response
    {
        $this->denyUnlessAdmin();
        $post = $this->post($id);
        $this->denyUnlessValidToken($request, 'mulertech_blog_delete_'.$id);

        $this->mediaManager->delete($post);
        $this->flash($request, 'success', 'admin.flash.deleted');

        return new RedirectResponse($this->urlGenerator->generate('mulertech_blog_admin_index'));
    }

    public function publish(Request $request, int $id): Response
    {
        $this->denyUnlessAdmin();
        $post = $this->post($id);
        $this->denyUnlessValidToken($request, 'mulertech_blog_publish_'.$id);

        $this->publisher->publish($post);
        $this->flash($request, 'success', 'admin.flash.published');

        return $this->redirectToEdit($post);
    }

    public function unpublish(Request $request, int $id): Response
    {
        $this->denyUnlessAdmin();
        $post = $this->post($id);
        $this->denyUnlessValidToken($request, 'mulertech_blog_publish_'.$id);

        $this->publisher->unpublish($post);
        $this->flash($request, 'success', 'admin.flash.unpublished');

        return $this->redirectToEdit($post);
    }

    public function addFile(Request $request, int $id): Response
    {
        $this->denyUnlessAdmin();
        $post = $this->post($id);
        $this->denyUnlessValidToken($request, 'mulertech_blog_files_'.$id);
        $file = $request->files->get('file');

        if (!$file instanceof UploadedFile) {
            $this->flash($request, 'error', 'admin.flash.file_missing');

            return $this->redirectToEdit($post);
        }

        try {
            $this->mediaManager->attach($post, $file);
            $this->flash($request, 'success', 'admin.flash.file_added', ['%name%' => $file->getClientOriginalName()]);
        } catch (FileException $exception) {
            // The file manager names what is wrong with the file: its size, its type.
            $this->flash($request, 'error', 'admin.flash.file_refused', ['%name%' => $file->getClientOriginalName(), '%reason%' => $exception->getMessage()]);
        }

        return $this->redirectToEdit($post);
    }

    public function removeFile(Request $request, int $id, int $fileId): Response
    {
        $this->denyUnlessAdmin();
        $post = $this->post($id);
        $this->denyUnlessValidToken($request, 'mulertech_blog_files_'.$id);
        $file = $this->attachedFile($post, $fileId);

        $this->mediaManager->detach($post, $file);
        $this->flash($request, 'success', 'admin.flash.file_removed', ['%name%' => $file->getFilename()]);

        return $this->redirectToEdit($post);
    }

    /**
     * The files of any article, drafts included, for the admin pages only: the response is
     * never stored, since a draft's files must not linger in a shared cache.
     */
    public function file(int $id, int $fileId): Response
    {
        $this->denyUnlessAdmin();
        $post = $this->post($id);
        $file = $post->getFeaturedImage()?->getId() === $fileId ? $post->getFeaturedImage() : $this->attachedFile($post, $fileId);

        $response = new BinaryFileResponse($this->fileManager->getAbsolutePath($file));
        $response->headers->set('Content-Type', $file->getMimeType());
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    /**
     * @return FormInterface<AbstractBlogPost>
     */
    private function form(AbstractBlogPost $post, Request $request): FormInterface
    {
        /** @var FormInterface<AbstractBlogPost> $form */
        $form = $this->formFactory->create($this->postFormType, $post, ['locales' => $this->locales]);
        $form->handleRequest($request);

        return $form;
    }

    /**
     * A blank slug is built from the title; one typed by hand must still be free in the
     * article's language, which no constraint on the field alone can know.
     *
     * @param FormInterface<AbstractBlogPost> $form
     */
    private function assignSlug(FormInterface $form, AbstractBlogPost $post): bool
    {
        $slug = $form->get('slug')->getData();

        if (!\is_string($slug) || '' === $slug) {
            $post->setSlug($this->slugGenerator->generate($post));

            return true;
        }

        if ($this->repository->isSlugTaken($slug, $post->getLocale(), $post->getId())) {
            $form->get('slug')->addError(new FormError($this->translator->trans('mulertech_blog.slug.taken', ['%slug%' => $slug], 'validators')));

            return false;
        }

        return true;
    }

    /**
     * @param FormInterface<AbstractBlogPost> $form
     */
    private function saveCover(FormInterface $form, AbstractBlogPost $post): void
    {
        $cover = $form->get('featuredImage')->getData();

        if ($cover instanceof UploadedFile) {
            $this->mediaManager->setCover($post, $cover);
        }
    }

    private function post(int $id): AbstractBlogPost
    {
        return $this->repository->find($id) ?? throw new NotFoundHttpException(sprintf('No article %d.', $id));
    }

    private function attachedFile(AbstractBlogPost $post, int $fileId): FileInterface
    {
        return $post->getFiles()->findFirst(static fn (int $key, FileInterface $file): bool => $file->getId() === $fileId)
            ?? throw new NotFoundHttpException(sprintf('The article %d has no file %d.', $post->getId(), $fileId));
    }

    private function denyUnlessAdmin(): void
    {
        if (!$this->authorizationChecker->isGranted($this->adminRole)) {
            throw new AccessDeniedException(sprintf('The blog administration requires %s.', $this->adminRole));
        }
    }

    private function denyUnlessValidToken(Request $request, string $id): void
    {
        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken($id, $request->request->getString('_token')))) {
            throw new AccessDeniedException(sprintf('Invalid CSRF token for %s.', $id));
        }
    }

    /**
     * @param array<string, string> $parameters
     */
    private function flash(Request $request, string $type, string $message, array $parameters = []): void
    {
        $session = $request->getSession();

        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add($type, $this->translator->trans($message, $parameters, 'MulerTechBlogBundle'));
        }
    }

    private function redirectToEdit(AbstractBlogPost $post): RedirectResponse
    {
        return new RedirectResponse($this->urlGenerator->generate('mulertech_blog_admin_edit', ['id' => $post->getId()]));
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function render(string $template, array $parameters, int $status = Response::HTTP_OK): Response
    {
        return new Response($this->twig->render('@MulerTechBlog/admin/'.$template.'.html.twig', [
            'layout' => $this->adminLayout,
            'locales' => $this->locales,
            ...$parameters,
        ]), $status);
    }
}
