<?php

declare(strict_types=1);

namespace MulerTech\BlogBundle\Doctrine;

use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\NamingStrategy;
use MulerTech\BlogBundle\Entity\AbstractBlogCategory;
use MulerTech\BlogBundle\Entity\AbstractBlogPost;
use MulerTech\BlogBundle\Entity\AbstractBlogTag;
use MulerTech\BlogBundle\Entity\AbstractBlogTerm;

/**
 * Completes the mapping of the site's blog entities where a mapped superclass falls
 * short, each time silently.
 *
 * - Doctrine does not carry the indexes and unique constraints of a mapped superclass over
 *   to the entities extending it. Declared on `AbstractBlogPost`, the slug would not be
 *   unique, and two articles sharing one in the same language would leave the URL showing
 *   whichever the database returned first. The same goes for categories and tags.
 * - The join table of `files` would be named after the superclass and the interface it
 *   targets, `abstract_blog_post_file_interface`, rather than after the site's entity. It
 *   is named here the way Doctrine names that of any entity: `blog_post_file` for
 *   `BlogPost` under the underscore strategy, the table mulertech already has.
 * - A mapped superclass cannot target the site's category and tag classes, which it does
 *   not know: `category` and `tags` are mapped here, once the site names them. The names
 *   are those Doctrine gives any relation, `category_id` and `blog_post_blog_tag`.
 * - A site that turns the excerpt or the AI label off has no column for it: the field is
 *   removed from the mapping, and the property keeps its default, empty and `false`.
 */
final readonly class BlogPostMetadataListener
{
    /**
     * @param class-string<AbstractBlogCategory>|null $categoryClass
     * @param class-string<AbstractBlogTag>|null      $tagClass
     */
    public function __construct(
        private ?string $categoryClass = null,
        private ?string $tagClass = null,
        private bool $excerpt = true,
        private bool $aiLabel = true,
    ) {
    }

    public function loadClassMetadata(LoadClassMetadataEventArgs $event): void
    {
        $metadata = $event->getClassMetadata();

        if ($metadata->isMappedSuperclass || $metadata->getName() !== $metadata->rootEntityName) {
            return;
        }

        if (is_subclass_of($metadata->getName(), AbstractBlogTerm::class)) {
            $this->uniquePerLanguage($metadata);

            return;
        }

        if (!is_subclass_of($metadata->getName(), AbstractBlogPost::class)) {
            return;
        }

        if (!$this->excerpt) {
            $this->unmapField($metadata, 'excerpt');
        }

        if (!$this->aiLabel) {
            $this->unmapField($metadata, 'aiGenerated');
        }

        $this->uniquePerLanguage($metadata);
        $namingStrategy = $event->getObjectManager()->getConfiguration()->getNamingStrategy();

        // Mapped again rather than renamed: the join table is resolved when the superclass
        // mapping is read, before any listener runs.
        $target = $metadata->getAssociationMapping('files')->targetEntity;
        unset($metadata->associationMappings['files']);
        $metadata->mapManyToMany([
            'fieldName' => 'files',
            'targetEntity' => $target,
            'joinTable' => $this->joinTable($namingStrategy, $metadata->getName(), 'file', 'file_id'),
        ]);

        if (null !== $this->categoryClass) {
            $metadata->mapManyToOne([
                'fieldName' => 'category',
                'targetEntity' => $this->categoryClass,
                'joinColumns' => [['name' => 'category_id', 'referencedColumnName' => 'id', 'nullable' => true]],
            ]);
        }

        if (null !== $this->tagClass) {
            $metadata->mapManyToMany([
                'fieldName' => 'tags',
                'targetEntity' => $this->tagClass,
                'joinTable' => $this->joinTable(
                    $namingStrategy,
                    $metadata->getName(),
                    $namingStrategy->classToTableName($this->tagClass),
                    $namingStrategy->joinKeyColumnName($this->tagClass, 'id'),
                ),
            ]);
        }
    }

    /**
     * @param ClassMetadata<object> $metadata
     */
    private function uniquePerLanguage(ClassMetadata $metadata): void
    {
        $metadata->table['uniqueConstraints'][] = ['columns' => ['slug', 'locale']];
        $metadata->table['indexes'][] = ['columns' => ['translation_group']];
    }

    /**
     * The accessors were copied from the mapped superclass before this listener runs: one
     * left behind would still have the field written on every insert.
     *
     * @param ClassMetadata<object> $metadata
     */
    private function unmapField(ClassMetadata $metadata, string $field): void
    {
        $column = $metadata->getColumnName($field);
        unset($metadata->fieldMappings[$field], $metadata->fieldNames[$column], $metadata->columnNames[$field], $metadata->propertyAccessors[$field]);
    }

    /**
     * Deleting either side deletes the link, never the other side.
     *
     * @param class-string $postClass
     *
     * @return array<string, mixed>
     */
    private function joinTable(NamingStrategy $namingStrategy, string $postClass, string $targetTable, string $targetColumn): array
    {
        return [
            'name' => $namingStrategy->classToTableName($postClass).'_'.$targetTable,
            'joinColumns' => [['name' => $namingStrategy->joinKeyColumnName($postClass, 'id'), 'referencedColumnName' => 'id', 'onDelete' => 'CASCADE']],
            'inverseJoinColumns' => [['name' => $targetColumn, 'referencedColumnName' => 'id', 'onDelete' => 'CASCADE']],
        ];
    }
}
