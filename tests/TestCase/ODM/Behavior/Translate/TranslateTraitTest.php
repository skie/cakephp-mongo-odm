<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\ODM\Behavior\Translate;

use Crustum\Mongo\ODM\Document;
use Crustum\Mongo\Test\TestCase\ODM\TestCase;
use TestApp\Model\Document\TranslateTestEntity;

/**
 * Translate behavior test case
 */
class TranslateTraitTest extends TestCase
{
    /**
     * Tests that translation() returns null for non-existent translations
     */
    public function testTranslationReturnsNull(): void
    {
        $document = new TranslateTestEntity();
        $this->assertNull($document->translation('eng'));
        $this->assertNull($document->translation('spa'));
    }

    /**
     * Tests that translation() returns existing translations
     */
    public function testTranslationReturnsExisting(): void
    {
        $document = new TranslateTestEntity();
        $document->set('_translations', [
            'eng' => new Document(['title' => 'My Title']),
            'spa' => new Document(['title' => 'Titulo']),
        ]);
        $this->assertSame('My Title', $document->translation('eng')->get('title'));
        $this->assertSame('Titulo', $document->translation('spa')->get('title'));
        $this->assertNull($document->translation('fra'));
    }

    /**
     * Tests that getOrCreateTranslation() creates missing translations
     */
    public function testGetOrCreateTranslation(): void
    {
        $document = new TranslateTestEntity();
        $document->getOrCreateTranslation('eng')->set('title', 'My Title');
        $this->assertSame('My Title', $document->translation('eng')->get('title'));

        $this->assertTrue($document->isDirty('_translations'));

        $document->getOrCreateTranslation('spa')->set('body', 'Contenido');
        $this->assertSame('My Title', $document->translation('eng')->get('title'));
        $this->assertSame('Contenido', $document->translation('spa')->get('body'));
    }

    /**
     * Tests that getOrCreateTranslation() returns correct entity type
     */
    public function testGetOrCreateTranslationDocumentType(): void
    {
        $document = new TranslateTestEntity();
        $document->set('_translations', [
            'eng' => new Document(['title' => 'My Title']),
        ]);
        $translation = $document->getOrCreateTranslation('pol');
        $this->assertTrue($translation->isNew());
        $this->assertInstanceOf(TranslateTestEntity::class, $translation);
    }

    /**
     * Tests that getOrCreateTranslation() marks _translations as dirty
     */
    public function testGetOrCreateTranslationDirty(): void
    {
        $document = new TranslateTestEntity();
        $document->set('_translations', [
            'eng' => new Document(['title' => 'My Title']),
        ]);
        $document->clean();
        $document->getOrCreateTranslation('eng');
        $this->assertTrue($document->isDirty('_translations'));
    }

    /**
     * Tests hasTranslation() method
     */
    public function testHasTranslation(): void
    {
        $document = new TranslateTestEntity();
        $this->assertFalse($document->hasTranslation('eng'));

        $document->set('_translations', [
            'eng' => new Document(['title' => 'My Title']),
            'spa' => new Document(['title' => 'Titulo']),
        ]);
        $this->assertTrue($document->hasTranslation('eng'));
        $this->assertTrue($document->hasTranslation('spa'));
        $this->assertFalse($document->hasTranslation('fra'));
    }
}
