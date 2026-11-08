<?php

declare(strict_types=1);

namespace MulerTech\BlogBundle\Tests\Functional\App\Seo;

use MulerTech\SeoBundle\Model\SeoCompanyInfoProviderInterface;

final class CompanyInfoProvider implements SeoCompanyInfoProviderInterface
{
    public function getName(): string
    {
        return 'Exemple';
    }

    public function getWebsite(): string
    {
        return 'https://example.com';
    }

    public function getEmail(): string
    {
        return 'contact@example.com';
    }

    public function getPhone(): string
    {
        return '';
    }

    public function getPostalCode(): string
    {
        return '14000';
    }

    public function getCity(): string
    {
        return 'Caen';
    }

    public function getCountry(): string
    {
        return 'FR';
    }

    public function getSocialUrls(): array
    {
        return [];
    }
}
