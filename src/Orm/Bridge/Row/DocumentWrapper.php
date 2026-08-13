<?php
declare(strict_types=1);

namespace Crustum\Mongo\Orm\Bridge\Row;

use Cake\ORM\Entity;
use Crustum\Mongo\ODM\Document;

/**
 * ODM Document → ORM Entity adapter.
 *
 * Wraps a Mongo `Crustum\Mongo\ODM\Document` behind a `Cake\ORM\Entity`
 * surface so views and forms treat cross-boundary rows uniformly, while the
 * original document stays reachable via {@see getDocument()}.
 *
 * @see docs/reference/29-orm-mongo-association-bridge.md §4 (Row), §7
 */
class DocumentWrapper extends Entity
{
    /**
     * The wrapped Mongo document.
     *
     * @var \Crustum\Mongo\ODM\Document
     */
    protected Document $document;

    /**
     * Constructor.
     *
     * @param \Crustum\Mongo\ODM\Document $document The Mongo document to wrap.
     * @param array<string, mixed> $options Extra entity options.
     */
    public function __construct(Document $document, array $options = [])
    {
        $this->document = $document;

        parent::__construct($document->toArray(), $options + [
            'useSetters' => false,
            'markClean' => true,
        ]);
    }

    /**
     * Gets the wrapped Mongo document.
     *
     * @return \Crustum\Mongo\ODM\Document
     */
    public function getDocument(): Document
    {
        return $this->document;
    }
}
