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
- Doctrine ORM 3 on PostgreSQL

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

## Usage

The bundle's configuration lives under the `mulertech_blog` key.

## Testing

```bash
./vendor/bin/mtdocker test-ai
```

## License

MIT. See [LICENSE](LICENSE).
