<?php

declare(strict_types=1);

namespace MulerTech\BlogBundle\Validator;

use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

final class CoverDimensionsValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof CoverDimensions) {
            throw new UnexpectedTypeException($constraint, CoverDimensions::class);
        }

        if (null === $value) {
            return;
        }

        if (!$value instanceof File) {
            throw new UnexpectedValueException($value, File::class);
        }

        $expected = sprintf('%d×%d', CoverDimensions::WIDTH, CoverDimensions::HEIGHT);
        $size = @getimagesize($value->getPathname());

        if (false === $size) {
            $this->context->buildViolation($constraint->unreadableMessage)
                ->setParameter('{{ expected }}', $expected)
                ->addViolation();

            return;
        }

        if (CoverDimensions::WIDTH !== $size[0] || CoverDimensions::HEIGHT !== $size[1]) {
            $this->context->buildViolation($constraint->message)
                ->setParameter('{{ expected }}', $expected)
                ->setParameter('{{ actual }}', sprintf('%d×%d', $size[0], $size[1]))
                ->addViolation();
        }
    }
}
