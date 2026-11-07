<?php

declare(strict_types=1);

namespace MulerTech\BlogBundle\Tests\Validator;

use MulerTech\BlogBundle\Validator\CoverDimensions;
use MulerTech\BlogBundle\Validator\CoverDimensionsValidator;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;
use Symfony\Component\Validator\Test\ConstraintValidatorTestCase;

/**
 * @extends ConstraintValidatorTestCase<CoverDimensionsValidator>
 */
final class CoverDimensionsValidatorTest extends ConstraintValidatorTestCase
{
    public function testACoverOfTheStoredSizeIsAccepted(): void
    {
        $this->validator->validate(new File($this->image(1200, 675)), new CoverDimensions());

        $this->assertNoViolation();
    }

    public function testACoverOfAnotherSizeNamesBothSizes(): void
    {
        $this->validator->validate(new File($this->image(1600, 900)), new CoverDimensions());

        $this->buildViolation('mulertech_blog.cover.wrong_size')
            ->setParameter('{{ expected }}', '1200×675')
            ->setParameter('{{ actual }}', '1600×900')
            ->assertRaised();
    }

    public function testAFileThatIsNotAnImageIsRefused(): void
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'cover');
        file_put_contents($path, 'not an image');

        $this->validator->validate(new File($path), new CoverDimensions());

        $this->buildViolation('mulertech_blog.cover.unreadable')
            ->setParameter('{{ expected }}', '1200×675')
            ->assertRaised();
    }

    public function testNoCoverIsNothingToCheck(): void
    {
        $this->validator->validate(null, new CoverDimensions());

        $this->assertNoViolation();
    }

    public function testOnlyAFileCanBeChecked(): void
    {
        $this->expectException(UnexpectedValueException::class);

        $this->validator->validate('cover.png', new CoverDimensions());
    }

    public function testOnlyItsOwnConstraintIsHandled(): void
    {
        $this->expectException(UnexpectedTypeException::class);

        $this->validator->validate(null, new NotBlank());
    }

    protected function createValidator(): CoverDimensionsValidator
    {
        return new CoverDimensionsValidator();
    }

    private function image(int $width, int $height): string
    {
        \assert($width > 0 && $height > 0);
        $path = (string) tempnam(sys_get_temp_dir(), 'cover');
        imagepng(imagecreatetruecolor($width, $height), $path);

        return $path;
    }
}
