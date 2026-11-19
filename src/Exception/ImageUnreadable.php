<?php

declare(strict_types=1);

namespace MulerTech\BlogBundle\Exception;

/**
 * The image sent cannot be decoded: the sender fixes it by sending another file, nothing failed
 * on the server.
 */
final class ImageUnreadable extends \InvalidArgumentException
{
}
