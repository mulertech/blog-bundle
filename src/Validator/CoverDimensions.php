<?php

declare(strict_types=1);

namespace MulerTech\BlogBundle\Validator;

use Symfony\Component\Validator\Constraint;

/**
 * A cover is shown whole at 16:9 on the article and cut to a square in the list and to
 * 1200x630 for social cards: a single stored size frames every page and every card alike.
 * The admin crops in the browser; this check holds for a browser where the cropper did not
 * run.
 */
final class CoverDimensions extends Constraint
{
    public const int WIDTH = 1200;
    public const int HEIGHT = 675;

    public string $message = 'mulertech_blog.cover.wrong_size';
    public string $unreadableMessage = 'mulertech_blog.cover.unreadable';
}
