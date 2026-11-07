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
```

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
