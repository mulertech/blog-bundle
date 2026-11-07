# MulerTech Blog Bundle

___
[![Latest Version on Packagist](https://img.shields.io/packagist/v/mulertech/blog-bundle.svg?style=flat-square)](https://packagist.org/packages/mulertech/blog-bundle)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/mulertech/blog-bundle/tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/mulertech/blog-bundle/actions/workflows/tests.yml)
[![GitHub PHPStan Action Status](https://img.shields.io/github/actions/workflow/status/mulertech/blog-bundle/phpstan.yml?branch=main&label=phpstan&style=flat-square)](https://github.com/mulertech/blog-bundle/actions/workflows/phpstan.yml)
[![Total Downloads](https://img.shields.io/packagist/dt/mulertech/blog-bundle.svg?style=flat-square)](https://packagist.org/packages/mulertech/blog-bundle)
[![Test Coverage](https://raw.githubusercontent.com/mulertech/blog-bundle/badge/badge-coverage.svg)](https://packagist.org/packages/mulertech/blog-bundle)
___

Symfony bundle for a Markdown blog: shared article model, admin, public pages, full-text search, social sharing and SEO, with one article per language.

## Requirements

- PHP 8.4+ with the GD extension (required by `mulertech/seo-bundle` to derive social cards)
- Symfony 8.0+
- Doctrine ORM 3

## Installation

```bash
composer require mulertech/blog-bundle
```

Without Symfony Flex, register the bundle in `config/bundles.php`:

```php
return [
    // ...
    MulerTech\BlogBundle\MulerTechBlogBundle::class => ['all' => true],
];
```

## Configuration

```yaml
# config/packages/mulertech_blog.yaml
mulertech_blog:
    # The site's entity extending AbstractBlogPost (see below). Required.
    post_class: App\Entity\BlogPost
    # Template the public pages extend; they fill its `title`, `seo_meta` and `body`
    # blocks. Defaults to `base.html.twig`.
    public_layout: base.html.twig
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

## Testing

```bash
./vendor/bin/mtdocker test-ai
```

## License

MIT. See [LICENSE](LICENSE).

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

The article page adds to the layout's `seo_meta` block, keeping what the layout puts there, an `hreflang` link per published version of the article when it has versions in other languages.

### 4. Labels

Every visible text goes through the `MulerTechBlogBundle` translation domain. The bundle ships French. A site rewords a label by redefining its key in its own `translations/MulerTechBlogBundle.fr.yaml`; the site's translations take precedence over the bundle's.
