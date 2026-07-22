<?php

namespace justinholtweb\peek\tests\integration;

use justinholtweb\peek\tests\Support\PeekTestCase;

/**
 * Sanity check on the fixture helpers the rest of the integration suite leans on.
 */
class FixtureSmokeTest extends PeekTestCase
{
    public function testEntryAndDraftCreation(): void
    {
        $entry = $this->createEntry('Original', ['body' => 'first']);

        $this->assertNotNull($entry->id);
        $this->assertSame('first', $entry->getFieldValue('body'));

        $draft = $this->createDraft($entry, ['title' => 'Changed'], ['body' => 'second']);

        $this->assertTrue($draft->getIsDraft());
        $this->assertFalse($draft->isProvisionalDraft);
        $this->assertSame($entry->id, $draft->getCanonicalId());
        $this->assertSame('Changed', $draft->title);
        $this->assertSame('second', $draft->getFieldValue('body'));
    }
}
