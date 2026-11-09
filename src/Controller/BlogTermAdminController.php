<?php

declare(strict_types=1);

namespace MulerTech\BlogBundle\Controller;

use Doctrine\ORM\EntityManagerInterface;
use MulerTech\BlogBundle\Entity\AbstractBlogCategory;
use MulerTech\BlogBundle\Entity\AbstractBlogTerm;
use MulerTech\BlogBundle\Repository\BlogPostRepository;
use MulerTech\BlogBundle\Repository\BlogTermRepository;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
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
use Symfony\Component\String\Slugger\AsciiSlugger;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * The administration of the categories, or of the tags: one service for each, `$kind`
 * telling which. On a site that does not name the class, every page answers 404 and says
 * which key to set.
 */
final readonly class BlogTermAdminController
{
    /**
     * @param 'category'|'tag' $kind
     * @param class-string     $formType
     * @param list<string>     $locales
     */
    public function __construct(
        private BlogTermRepository $terms,
        private BlogPostRepository $posts,
        private string $kind,
        private string $formType,
        private EntityManagerInterface $entityManager,
        private FormFactoryInterface $formFactory,
        private Environment $twig,
        private UrlGeneratorInterface $urlGenerator,
        private AuthorizationCheckerInterface $authorizationChecker,
        private CsrfTokenManagerInterface $csrfTokenManager,
        private TranslatorInterface $translator,
        private array $locales,
        private string $adminLayout,
        private string $adminRole,
    ) {
    }

    public function index(): Response
    {
        $this->denyUnlessAvailable();

        $rows = array_map(
            fn (AbstractBlogTerm $term): array => ['term' => $term, 'count' => $this->posts->countFiledUnder($term)],
            $this->terms->findAll(),
        );

        return $this->render('terms', ['rows' => $rows]);
    }

    public function new(Request $request): Response
    {
        $this->denyUnlessAvailable();
        $term = new ($this->terms->getClass())();

        if (1 === \count($this->locales)) {
            $term->setLocale($this->locales[0]);
        }

        $form = $this->form($term, $request);

        if ($form->isSubmitted() && $form->isValid() && $this->assignSlug($form, $term)) {
            $this->entityManager->persist($term);
            $this->entityManager->flush();
            $this->flash($request, 'success', 'admin.terms.flash.created', ['%name%' => $term->getName()]);

            return $this->redirectToIndex();
        }

        return $this->render('term_edit', ['term' => null, 'form' => $form->createView()], $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK);
    }

    public function edit(Request $request, int $id): Response
    {
        $this->denyUnlessAvailable();
        $term = $this->term($id);
        $form = $this->form($term, $request);

        if ($form->isSubmitted() && $form->isValid() && $this->assignSlug($form, $term)) {
            $this->entityManager->flush();
            $this->flash($request, 'success', 'admin.terms.flash.updated', ['%name%' => $term->getName()]);

            return $this->redirectToIndex();
        }

        return $this->render('term_edit', ['term' => $term, 'form' => $form->createView()], $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK);
    }

    /**
     * A tag leaves its articles when deleted. A category still holding articles is kept:
     * deleting it would leave them without one, which the site may not allow.
     */
    public function delete(Request $request, int $id): Response
    {
        $this->denyUnlessAvailable();
        $term = $this->term($id);

        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken('mulertech_blog_'.$this->kind.'_delete_'.$id, $request->request->getString('_token')))) {
            throw new AccessDeniedException(sprintf('Invalid CSRF token for the deletion of %s %d.', $this->kind, $id));
        }

        $count = $this->posts->countFiledUnder($term);

        if ($term instanceof AbstractBlogCategory && $count > 0) {
            $this->flash($request, 'error', 'admin.terms.flash.category_in_use', ['%name%' => $term->getName(), '%count%' => (string) $count]);

            return $this->redirectToIndex();
        }

        $this->entityManager->remove($term);
        $this->entityManager->flush();
        $this->flash($request, 'success', 'admin.terms.flash.deleted', ['%name%' => $term->getName()]);

        return $this->redirectToIndex();
    }

    /**
     * @return FormInterface<AbstractBlogTerm>
     */
    private function form(AbstractBlogTerm $term, Request $request): FormInterface
    {
        /** @var FormInterface<AbstractBlogTerm> $form */
        $form = $this->formFactory->create($this->formType, $term, ['locales' => $this->locales]);
        $form->handleRequest($request);

        return $form;
    }

    /**
     * A blank slug is built from the name; either way it must be free in the term's
     * language.
     *
     * @param FormInterface<AbstractBlogTerm> $form
     */
    private function assignSlug(FormInterface $form, AbstractBlogTerm $term): bool
    {
        $typed = $form->get('slug')->getData();
        $slug = \is_string($typed) && '' !== $typed ? $typed : new AsciiSlugger()->slug($term->getName())->lower()->toString();
        $field = $slug === $typed ? 'slug' : 'name';

        if ('' === $slug) {
            $form->get('name')->addError(new FormError($this->translator->trans('mulertech_blog.slug.format', [], 'validators')));

            return false;
        }

        if ($this->terms->isSlugTaken($slug, $term->getLocale(), $term->getId())) {
            $form->get($field)->addError(new FormError($this->translator->trans('mulertech_blog.term.slug_taken', ['%slug%' => $slug], 'validators')));

            return false;
        }

        $term->setSlug($slug);

        return true;
    }

    private function term(int $id): AbstractBlogTerm
    {
        return $this->terms->find($id) ?? throw new NotFoundHttpException(sprintf('No %s %d.', $this->kind, $id));
    }

    private function denyUnlessAvailable(): void
    {
        if (!$this->authorizationChecker->isGranted($this->adminRole)) {
            throw new AccessDeniedException(sprintf('The blog administration requires %s.', $this->adminRole));
        }

        if (!$this->terms->isEnabled()) {
            throw new NotFoundHttpException(sprintf('The blog has no %s: set mulertech_blog.%s_class to enable them.', 'category' === $this->kind ? 'categories' : 'tags', $this->kind));
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

    private function redirectToIndex(): RedirectResponse
    {
        return new RedirectResponse($this->urlGenerator->generate('mulertech_blog_admin_'.$this->kind.'_index'));
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function render(string $template, array $parameters, int $status = Response::HTTP_OK): Response
    {
        return new Response($this->twig->render('@MulerTechBlog/admin/'.$template.'.html.twig', [
            'layout' => $this->adminLayout,
            'locales' => $this->locales,
            'kind' => $this->kind,
            ...$parameters,
        ]), $status);
    }
}
