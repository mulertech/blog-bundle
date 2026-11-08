# Release notes for blog-bundle

## v1.0.0 - 2026-11-08

First release: a Markdown blog for Symfony 8, shared by several sites. The bundle holds the article model, the public pages, the administration and the SEO; each site keeps its own entity and table, adds its own fields, and dresses the templates in its own design.

Model

- `AbstractBlogPost`, a Doctrine mapped superclass: title, slug, Markdown content, meta description, featured image, attached files, publication, reading time, view count, creation and modification dates, language and translation group
- One article per language: the versions of an article are rows sharing a `translationGroup`, each with its own slug, date and views. A single-language site sees nothing more than a column filled on its own
- Column names are fixed rather than left to the naming strategy, so an existing table keeps its columns
- The unique constraint on `(slug, locale)`, the index on `translation_group` and the join table to the files are declared on the site's entity, which a mapped superclass cannot do
- `publish()` keeps the date of first publication; the reading time is derived from the content at 200 words a minute

Public pages

- List with search, 12 articles a page, square thumbnails, recent and popular articles
- Article page with Markdown content, sharing on LinkedIn, X and Facebook, previous and next articles, `hreflang` links to the other languages
- A draft, an article of another language or a page beyond the last answer 404
- Featured images and attached files served through their published article only, cached a day with an ETag
- Each part of a page is a template of its own, replaceable alone; every label goes through the `MulerTechBlogBundle` translation domain, French shipped

Administration

- List, creation, edition, deletion, "Publier" and "Dépublier", every action checking `admin_role` and a CSRF token
- Featured image cropped to 1200x675 in the browser and stored as WebP; another size reaching the server is refused, naming both sizes
- Attached files inserted in the content as `blog-file:<id>` references, so correcting a slug breaks no image
- A file belongs to one article: removing it, or deleting the article, deletes it from the storage
- Form extensible through `post_form_type`; language field shown only with several `locales`
- Admin script served by AssetMapper, with listeners delegated to the document

Search engines and social platforms (through `mulertech/seo-bundle`)

- Meta tags and Open Graph, with the 1200x630 JPEG card derived from the featured image
- schema.org `BlogPosting`, carrying the nonce of `mulertech/csp-bundle` when installed
- Sitemap entries for each language and every published article, and a `llms.txt` section

Requirements: PHP 8.4 with GD and fileinfo, Symfony 8, Doctrine ORM 3, AssetMapper, `mulertech/file-bundle` 1.1 and `mulertech/seo-bundle` 1.7. The README walks through an installation from scratch.
