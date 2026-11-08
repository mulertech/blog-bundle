# MulerTech Blog Bundle

___
[![Latest Version on Packagist](https://img.shields.io/packagist/v/mulertech/blog-bundle.svg?style=flat-square)](https://packagist.org/packages/mulertech/blog-bundle)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/mulertech/blog-bundle/tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/mulertech/blog-bundle/actions/workflows/tests.yml)
[![GitHub PHPStan Action Status](https://img.shields.io/github/actions/workflow/status/mulertech/blog-bundle/phpstan.yml?branch=main&label=phpstan&style=flat-square)](https://github.com/mulertech/blog-bundle/actions/workflows/phpstan.yml)
[![Total Downloads](https://img.shields.io/packagist/dt/mulertech/blog-bundle.svg?style=flat-square)](https://packagist.org/packages/mulertech/blog-bundle)
[![Test Coverage](https://raw.githubusercontent.com/mulertech/blog-bundle/badge/badge-coverage.svg)](https://packagist.org/packages/mulertech/blog-bundle)
___

Symfony bundle for a Markdown blog: shared article model, admin, public pages, search, social sharing and SEO, with one article per language.

## Requirements

- PHP 8.4+ with the GD extension, which converts covers to WebP and lets `mulertech/seo-bundle` derive the sharing images
- Symfony 8.0+, with AssetMapper serving the admin script
- Doctrine ORM 3; the queries are portable, the sites run PostgreSQL

## Installation

From a Symfony application with Doctrine, Twig and AssetMapper, in this order. Each step links to the section detailing it.

1. Require the bundle. Symfony Flex registers it, along with `mulertech/file-bundle`, `mulertech/seo-bundle` and `twig/extra-bundle`; without Flex, add them to `config/bundles.php`:

    ```bash
    composer require mulertech/blog-bundle
    ```

2. Set up `mulertech/file-bundle`, which stores the featured images and attached files: a file entity extending `MulerTech\FileBundle\Entity\AbstractFile`, and its `storage_directory` and `file_class`, as its README says. The logged-in user who writes articles must implement `MulerTech\FileBundle\Model\FileUploaderInterface`.
3. Set up `mulertech/seo-bundle`, which declares the pages to search engines and derives the sharing images: its configuration, a `SeoCompanyInfoProviderInterface` service and its routes, as its README says. Its `default_image` is shared for an article without featured image.
4. Create the blog post entity and point `FileInterface` at the file entity ([1. The blog post entity](#1-the-blog-post-entity)).
5. Name the entity in `config/packages/mulertech_blog.yaml` ([Configuration](#configuration)).
6. Import the public and admin routes ([Routes](#routes)), and keep the admin prefix behind the site's firewall ([5. Administration](#5-administration)).
7. Generate and run the migration: `bin/console doctrine:migrations:diff`, then `doctrine:migrations:migrate`. It creates the blog's table, its join table to the files, the unique constraint on `(slug, locale)` and the index on `translation_group`.
8. Make Tailwind scan the bundle's templates and enable `@tailwindcss/typography` ([3. Templates](#3-templates)).
9. Check that the layouts fill the blocks the pages use: `title`, `seo_meta` and `body` for `public_layout`; `title` and `body` for `admin_layout`, which also shows the flash messages ([Configuration](#configuration)).

`/blog` then lists the articles, `/admin/blog` creates them.

## Configuration

```yaml
# config/packages/mulertech_blog.yaml
mulertech_blog:
    # The site's entity extending AbstractBlogPost (see below). Required.
    post_class: App\Entity\BlogPost
    # Template the public pages extend; they fill its `title`, `seo_meta` and `body`
    # blocks. Defaults to `base.html.twig`.
    public_layout: base.html.twig
    # Template the admin pages extend; they fill its `title` and `body` blocks and leave
    # flash messages (`success`, `error`) to it. Defaults to `base.html.twig`.
    admin_layout: admin/base.html.twig
    # Role every admin action checks. Defaults to ROLE_ADMIN.
    admin_role: ROLE_ADMIN
    # Form of the admin, to add the site's own fields (see below).
    post_form_type: App\Form\BlogPostType
    # Languages the articles are written in. Defaults to the site's default locale; with
    # more than one, the admin asks for the language of each article.
    locales: [fr, en]
```

### Routes

Import the routes under the prefix of your choice:

```yaml
# config/routes/mulertech_blog.yaml
mulertech_blog:
    resource: '@MulerTechBlogBundle/config/routes.yaml'
    prefix: /blog            # or /{_locale}/blog on a multilingual site
    trailing_slash_on_root: false
```

`trailing_slash_on_root: false` keeps the list at `/blog` rather than `/blog/`.

The admin routes go under the site's admin prefix:

```yaml
# config/routes/mulertech_blog.yaml
mulertech_blog_admin:
    resource: '@MulerTechBlogBundle/config/admin_routes.yaml'
    prefix: /admin/blog
    trailing_slash_on_root: false
```

| Route | Path | Page |
|---|---|---|
| `mulertech_blog_index` | `/blog` | The list, `?q=` to search, `?page=` from 2 on |
| `mulertech_blog_show` | `/blog/{slug}` | An article |
| `mulertech_blog_file` | `/blog/{slug}/files/{id}` | The featured image or an attached file of a published article |

The pages read the articles of the request's locale: a single-language site serves its default locale, a multilingual one the `{_locale}` of its prefix. A draft, an article of another language, a page number that is not a positive integer or lies beyond the last page answer 404.

Files are served only through the published article they belong to, so the private storage of `mulertech/file-bundle` stays out of reach whatever id is typed. Images are shown inline, other files downloaded under their original name, both cached a day by browsers and proxies, with an ETag. A file the article points at but the disk no longer holds fails with a 500 naming the path.

## Usage

### 1. The blog post entity

The bundle maps the fields every blog shares in `AbstractBlogPost`, a Doctrine mapped superclass. The site declares the entity, which owns the table and adds whatever only that site needs:

```php
namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use MulerTech\BlogBundle\Entity\AbstractBlogPost;

#[ORM\Entity]
#[ORM\Table(name: 'blog_posts')]
class BlogPost extends AbstractBlogPost
{
    // Fields of this site only: category, tags, author...

    // Who writes, shown with the article by search engines: an author, the company.
    public function getSeoAuthorName(): string
    {
        return 'MulerTech';
    }
}
```

A constructor of its own calls `parent::__construct()`: it creates the collection of attached files and the translation group.

The featured image and the attached files target `FileInterface` from `mulertech/file-bundle`. Point it at the site's file entity:

```yaml
# config/packages/doctrine.yaml
doctrine:
    orm:
        resolve_target_entities:
            MulerTech\FileBundle\Model\FileInterface: App\Entity\File
```

| Field | Column | Notes |
|---|---|---|
| `id` | `id` | Integer |
| `title`, `slug`, `content` | same | `content` is Markdown |
| `metaDescription` | `meta_description` | |
| `featuredImage` | `featured_image_id` | |
| `files` | join table `<entity>_file` | `blog_post_file` for `BlogPost` under the underscore naming strategy |
| `isPublished`, `publishedAt` | `is_published`, `published_at` | `publish()` keeps the date of first publication, so unpublishing then publishing again never moves an article to the top of the list |
| `readingTime`, `viewCount` | `reading_time`, `view_count` | |
| `createdAt`, `updatedAt` | `created_at`, `updated_at` | Set on persist and on update |
| `locale` | `locale` | The language the article is written in |
| `translationGroup` | `translation_group` | Shared by the versions of one article in several languages; `setTranslationOf()` joins the group of another article |

Column names are fixed rather than left to the naming strategy, so an existing table keeps its columns.

The bundle adds to the entity's table a unique constraint on `(slug, locale)` and an index on `translation_group`. A mapped superclass cannot carry them: Doctrine ignores table constraints declared there.

#### Multilingual model

One row is one article in one language. The versions of an article in several languages are separate rows sharing a `translationGroup`, each with its own slug, publication date and view count. A single-language site never sees more than a column filled on its own.

### 2. Services

| Service | Role |
|---|---|
| `BlogPostRepository` | The queries of the blog, on the entity named by `post_class`. Every list holds published articles of one language |
| `SlugGenerator` | `generate($post)`: the slug from the title, unique in the article's language (`bienvenue`, then `bienvenue-2`…) |
| `BlogPostPublisher` | `publish($post)` and `unpublish($post)`, saved at once |
| `ViewCounter` | `count($post)`: one view per article and per session, incremented in the database so simultaneous readers lose none |

`BlogPostRepository`:

| Method | Returns |
|---|---|
| `findPublishedBySlug($slug, $locale)` | The article, or null when it is unknown or a draft |
| `paginatePublished($locale, $page, $search = null, $size = 12)` | A `Page` of articles, newest first, through Doctrine's `Paginator` |
| `findRecent($locale, $limit = 5)` | The latest published |
| `findPopular($locale, $limit = 5)` | The most viewed |
| `findPrevious($post)`, `findNext($post)` | The neighbours by publication date, in the same language |
| `findTranslations($post)` | The published versions in the other languages |
| `isSlugTaken($slug, $locale, $exceptId = null)` | Whether the language already has the slug, drafts included |

`Page` carries `items`, `number`, `size`, `totalItems`, `pageCount`, `hasPrevious()`, `hasNext()`, and `exists()`, false beyond the last page, whose address should answer 404.

The search keeps the articles holding every word typed, each in the title or the content, ignoring case. A word finds the longer ones containing it (`hébergement` finds `hébergements`), and the articles holding all the words in their title come first. Accents count: `heberger` does not find `héberger`.

The reading time is derived from the content each time it changes, at 200 words a minute; the address of a link or an image is not counted.

### 3. Templates

The pages need `twig/extra-bundle` (installed with the bundle), which provides the `markdown_to_html` filter. Their markup uses Tailwind CSS, with the default palette and the `prose` classes of `@tailwindcss/typography` for the article.

Tailwind only generates the classes it reads in the files it scans, and it does not scan `vendor/`. Without this line, the pages render unstyled and nothing says why:

```css
/* assets/styles/app.css */
@source "../../vendor/mulertech/blog-bundle/templates";
```

Each part of a page is a template of its own, so a site replaces only what differs from its own design, in `templates/bundles/MulerTechBlogBundle/`:

| Template | Part |
|---|---|
| `blog/index.html.twig` | The list page; its `mulertech_blog_header` block holds the heading |
| `blog/show.html.twig` | The article page |
| `blog/_search.html.twig` | The search form |
| `blog/_card.html.twig` | An article in the list: square thumbnail cut from the centre of the featured image, title, date, reading time, views, "Lire la suite" |
| `blog/_sidebar.html.twig` | The recent and popular articles |
| `blog/_pagination.html.twig` | Previous and next pages |
| `blog/_share.html.twig` | Sharing on LinkedIn, X and Facebook |
| `blog/_navigation.html.twig` | Previous article, back to the list, next article |

Both pages replace the layout's `seo_meta` block with their own meta tags (see below): whether a layout leaves that block empty or includes the seo-bundle template there, the page carries its tags exactly once.

### 4. Labels

Every visible text goes through the `MulerTechBlogBundle` translation domain. The bundle ships French. A site rewords a label by redefining its key in its own `translations/MulerTechBlogBundle.fr.yaml`; the site's translations take precedence over the bundle's.

### 5. Administration

The admin lists every article, drafts and every language included, creates, edits, publishes, unpublishes and deletes them, and manages their featured image and attached files.

Every admin action checks `admin_role` itself, whatever the firewall says, and every change requires a CSRF token. Keep the admin prefix behind the site's firewall all the same, so that a visitor is sent to the login page rather than refused.

Files are stored through `mulertech/file-bundle` on behalf of the logged-in user, which must implement `MulerTech\FileBundle\Model\FileUploaderInterface`; the article is passed as the upload context, so the site's directory strategy can file the blog's media apart. A file belongs to one article: removing it from the article, or deleting the article, deletes it from the storage.

| Feature | Behaviour |
|---|---|
| Slug | Built from the title when left blank; one typed by hand must be free in the article's language |
| Featured image | Stored at exactly 1200×675 px (16:9) as WebP. Another size opens a cropper in the browser; a cover of another size that reaches the server anyway is refused, naming both sizes |
| Attached files | Listed with an "insert" button that writes `![name](blog-file:12)` for an image, `[name](blog-file:12)` for a document, where the cursor stands in the content |
| Publication | "Publier" and "Dépublier" buttons; the date of first publication is kept |

The content refers to an attached file as `blog-file:<id>` rather than by its address, which carries the slug and the language prefix: correcting a slug would otherwise break every image of the article. The `mulertech_blog_content` Twig filter turns these references into addresses; the article template applies it before `markdown_to_html`.

The admin script, `@mulertech/blog-bundle/admin.js`, is served by the site's AssetMapper, which the bundle registers it with. It has no inline code, so a CSP allowing `'self'` scripts lets it through, and its listeners are delegated to the document, so it keeps working on pages replaced by Turbo.

To add the site's own fields, give a form type whose parent is the bundle's:

```php
namespace App\Form;

use MulerTech\BlogBundle\Form\BlogPostType as BaseBlogPostType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;

final class BlogPostType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('excerpt', TextareaType::class, ['required' => false]);
    }

    public function getParent(): string
    {
        return BaseBlogPostType::class;
    }
}
```

The fields it adds are rendered after the bundle's ones; replace `admin/_form.html.twig` to lay them out otherwise.

| Template | Part |
|---|---|
| `admin/index.html.twig` | The list |
| `admin/edit.html.twig` | Creation and edition |
| `admin/_actions.html.twig` | Status, publish or unpublish, delete |
| `admin/_form.html.twig` | The form, with the cover cropper |
| `admin/_files.html.twig` | The attached files |

### 6. Search engines and social platforms

The blog relies on `mulertech/seo-bundle`, configured and with its routes imported as its README says: the sitemap, `llms.txt` and the social cards are served by its routes.

| Page or file | What the blog declares |
|---|---|
| List | Title and description (the `blog.title` and `blog.meta.description` labels, to reword in the site's translations). A search points its canonical at the list, so no query is indexed as a page of its own |
| Article | Title, meta description, `og:type` `article`, publication and modification dates, author, and an `hreflang` link per published version in another language |
| Article image | The 1200×630 JPEG card seo-bundle derives from the featured image, under the social card type `blog`; without featured image, the site's `default_image` |
| Structured data | A schema.org `BlogPosting` (headline, author, dates, description) |
| Sitemap | The list of each language, then every published article, dated by its last change |
| `llms.txt` | A section listing the published articles with their meta description |

With `mulertech/csp-bundle` installed, the structured data script carries the nonce of its `main` handle; without it, no nonce, and the site's policy is its own to adapt.

A site keeping a social card provider of its own under the type `blog` makes seo-bundle refuse both, naming them: remove the site's one.

## Testing

```bash
./vendor/bin/mtdocker test-ai
```

## License

MIT. See [LICENSE](LICENSE).
