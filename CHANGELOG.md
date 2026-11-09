# Release notes for blog-bundle

## v1.4.0 - 2026-11-09

Categories and tags, scheduled publication, an Atom feed, an excerpt and the AI label. The files of an article are stored under its id.

Requires `mulertech/file-bundle` 1.2: publish that release first.

### Files

- The files of an article are stored under a name that starts with its id, on every site, whatever its directory strategy:
  - `blog/images/image-12-<unique>.webp` for a cover
  - `blog/files/file-12-<name>-<unique>.pdf` for an attached file
  
- `BlogMediaManager::setCover()` and `attach()` take the uploader as an optional last argument, for an API or an import without a session. If it is not given, the logged-in user is used. If there is neither, an exception says so.

### Categories and tags

- Both are optional. `category_class` and `tag_class` name the site's entities, which extend `AbstractBlogCategory` and `AbstractBlogTag` (name, slug, language, translation group, plus a description for a category). If they are not set, there is no column, no form field and no filter.
- The article gets two relations:
  - `category`, stored in a nullable `category_id` column
  - `tags`, stored in a join table named `<entity>_<tag entity>` (`blog_post_blog_tag` for `BlogPost` and `BlogTag`)
  
- The list can be filtered with `?category=<slug>` and `?tag=<slug>`. An unknown slug returns 404.
- The sidebar lists the categories that hold articles, with their article count. The card shows the category; the article page shows the category and the tags.
- The admin lists, creates, edits and deletes them, and shows how many articles each one holds. Deleting a category that still holds articles is refused. Deleting a tag removes it from its articles. An article can only use categories and tags in its own language.
- `category_form_type` and `tag_form_type` let a site add its own fields to these forms.

### Publication

- "Programmer" hides an article until a future date. From that date, it appears everywhere (list, page, files, sitemap, `llms.txt`, feed, sharing image) without any scheduled task. "Publier maintenant" publishes it right away. An article that was already published keeps its original date and cannot be scheduled.
- New API: `AbstractBlogPost::schedule()`, `isVisibleAt()` and `isScheduledAt()`; `BlogPostPublisher::schedule()`; the Twig test `mulertech_blog_scheduled`.
- `BlogPostRepository` takes the clock, so every public query returns only the articles visible at that moment.

### Content

- `excerpt` (column `excerpt`): shown on the card. It is also the description for search engines when the meta description is empty.
- `aiGenerated` (column `ai_generated`, default `false`): shows the European Union's AI-generated label on the article (AI Act, article 50 §4). The admin form has a checkbox for it.

### Feed

- `/blog/feed.xml` (route `mulertech_blog_feed`): the 20 latest articles in the current language, in Atom format. The list and every article page link to it in their `<head>`.

### Upgrading

- Run `doctrine:migrations:diff`. It adds `excerpt` and `ai_generated`, plus the category and tag tables and relations if the site enables them.
- If the site's entity declares `excerpt`, `aiGenerated`, `category` or `tags` itself, remove them, because the bundle now maps them.
- If the site overrides `blog/_pagination.html.twig`, its links must keep the `category` and `tag` filters.
- If the site overrides `blog/index.html.twig` or `blog/show.html.twig`, it must add the feed link to get it.
- Files uploaded with earlier versions keep their path and stay readable. Moving them under `blog/` is optional.

## v1.3.1 - 2026-11-09

A table wider than the screen scrolls in its own box instead of being cut off.

- In `blog/show.html.twig`, the tables of an article's content scroll horizontally (`[&_table]:block [&_table]:overflow-x-auto` on the content container). On a phone held upright, a table of several columns was clipped at the right edge with no way to reach it, since sites commonly keep `overflow-x: hidden` on the page so that nothing makes it slide sideways
- The text around the table stays in place; a table that fits keeps its look
- A site replacing `blog/show.html.twig` adds the same two classes to its content container

## v1.3.0 - 2026-11-08

An article is previewed in the site's own template before it is published.

- "Aperçu" on the admin page of an article opens it in the public template, drafts included, from its last saved version, under a banner saying so and linking back to the edition. The route, `mulertech_blog_admin_preview`, sits under the admin prefix: only the admin reaches it. It counts no view and sends
  `X-Robots-Tag: noindex, nofollow`
- `mulertech_blog_file_url(post, file)` gives the address of an article's file: the public one, or the admin one during a preview. The public file route answers only for a published article, and the admin is often signed in behind a firewall the public pages do not share. A site replacing `blog/show.html.twig`
  or `blog/_card.html.twig` uses it instead of `path('mulertech_blog_file', …)`, or the preview of a draft shows its images broken
- The `mulertech_blog_content` filter addresses inserted files the same way
- An article never published shows no publication date, where it showed the current day

## v1.2.0 - 2026-11-08

Attached images are stored ready for the web.

- A JPEG or PNG attached to an article is stored as WebP, turned upright from its EXIF orientation, and brought down to 1600 px wide when wider (`BlogMediaManager::ATTACHED_IMAGE_MAX_WIDTH`). A photo straight from a phone, 4000 px wide and several megabytes, no longer reaches every reader as sent
- A WebP is only brought down when wider than 1600 px, otherwise kept as sent
- A GIF keeps its animation and an SVG stays a vector: both are stored as sent, like documents
- An image that cannot be decoded is refused with a message naming the file, instead of failing the request
- An attached image is named after the stored file, `photo.webp`, in the insert button and the removal message
- Requires `ext-exif`, which reads how a phone photo is to be turned upright

## v1.1.0 - 2026-11-08

The admin form looks the same on every site, whether or not it declares a global form theme.

- The admin form carries its own form theme, `@MulerTechBlog/form/theme.html.twig`, applied over the site's. On a site declaring no global form theme, Symfony's default one left the fields without a border, indistinguishable from the text around them. Labels sit on their own line, help and errors are set in small type, and file, choice and checkbox fields are styled as well. The fields a site adds through `post_form_type` take the same look
- The content field shows the Markdown syntax it accepts as a two-column reference: bold, italic, three heading levels, link, image, quote, bulleted and numbered lists, inline code, code block, horizontal rule and strikethrough. Each label and example is a translatable label (`admin.markdown.*`), so a site rewords them in its own translations
- `admin.form.content_help` is removed, replaced by the reference above. A site that redefined it keeps an unused label, nothing more
- The field for attaching files is styled like the rest of the form
- A site already declaring a global form theme sees the article form take the bundle's neutral look instead of its own

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
