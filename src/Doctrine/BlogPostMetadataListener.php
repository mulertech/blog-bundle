<?php

declare(strict_types=1);

namespace MulerTech\BlogBundle\Doctrine;

use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use MulerTech\BlogBundle\Entity\AbstractBlogPost;

/**
 * Completes the mapping of the site's blog post entity where a mapped superclass falls
 * short, each time silently.
 *
 * - Doctrine does not carry the indexes and unique constraints of a mapped superclass over
 *   to the entities extending it. Declared on `AbstractBlogPost`, the slug would not be
 *   unique, and two articles sharing one in the same language would leave the URL showing
 *   whichever the database returned first.
 * - The join table of `files` would be named after the superclass and the interface it
 *   targets, `abstract_blog_post_file_interface`, rather than after the site's entity. It
 *   is named here the way Doctrine names that of any entity: `blog_post_file` for
 *   `BlogPost` under the underscore strategy, the table mulertech already has.
 */
final class BlogPostMetadataListener
{
    public function loadClassMetadata(LoadClassMetadataEventArgs $event): void
    {
        $metadata = $event->getClassMetadata();

        if ($metadata->isMappedSuperclass
            || !is_subclass_of($metadata->getName(), AbstractBlogPost::class)
            || $metadata->getName() !== $metadata->rootEntityName) {
            return;
        }

        $metadata->table['uniqueConstraints'][] = ['columns' => ['slug', 'locale']];
        $metadata->table['indexes'][] = ['columns' => ['translation_group']];

        $namingStrategy = $event->getObjectManager()->getConfiguration()->getNamingStrategy();
        // Kept as found: the target is the interface or, when the resolver listener ran
        // first, the site's file entity. Either way the resolver ends up with the latter.
        $target = $metadata->getAssociationMapping('files')->targetEntity;
        unset($metadata->associationMappings['files']);

        $metadata->mapManyToMany([
            'fieldName' => 'files',
            'targetEntity' => $target,
            'joinTable' => [
                'name' => $namingStrategy->classToTableName($metadata->getName()).'_file',
                'joinColumns' => [['name' => $namingStrategy->joinKeyColumnName($metadata->getName(), 'id'), 'referencedColumnName' => 'id', 'onDelete' => 'CASCADE']],
                'inverseJoinColumns' => [['name' => 'file_id', 'referencedColumnName' => 'id', 'onDelete' => 'CASCADE']],
            ],
        ]);
    }
}
