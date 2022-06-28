<?php

use MediaWiki\Config\ServiceOptions;
use MediaWiki\Json\JsonCodec;
use MediaWiki\Parser\ParserCacheFactory;
use MediaWiki\Parser\Parsoid\ParsoidOutputAccess;
use MediaWiki\Revision\RevisionAccessException;
use MediaWiki\Revision\SlotRecord;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\NullLogger;
use Wikimedia\Parsoid\Config\PageConfig;
use Wikimedia\Parsoid\Core\PageBundle;
use Wikimedia\Parsoid\Parsoid;

/**
 * @covers \MediaWiki\Parser\Parsoid\ParsoidOutputAccess
 * @group Database
 */
class ParsoidOutputAccessTest extends MediaWikiIntegrationTestCase {
	private const WIKITEXT = 'Hello \'\'\'Parsoid\'\'\'!';
	private const MOCKED_HTML = 'mocked HTML';

	/**
	 * @param int $expectedCalls
	 *
	 * @return MockObject|Parsoid
	 */
	private function newMockParsoid( $expectedCalls = 1 ) {
		$parsoid = $this->createNoOpMock( Parsoid::class, [ 'wikitext2html' ] );
		$parsoid->expects( $this->exactly( $expectedCalls ) )->method( 'wikitext2html' )->willReturnCallback(
			static function ( PageConfig $pageConfig ) {
				$wikitext = $pageConfig->getRevisionContent()->getContent( SlotRecord::MAIN );
				return new PageBundle( self::MOCKED_HTML . ' of ' . $wikitext, [ 'parsoid-data' ], [ 'mw-data' ], '1.0' );
			}
		);

		return $parsoid;
	}

	/**
	 * @param int $expectedParses
	 * @param array $parsoidCacheConfig
	 *
	 * @return ParsoidOutputAccess
	 * @throws Exception
	 */
	private function getParsoidOutputAccessWithCache( $expectedParses, $parsoidCacheConfig = [] ) {
		$stats = new NullStatsdDataFactory();
		$services = $this->getServiceContainer();

		$parsoidCacheConfig += [
			'CacheThresholdTime' => 0,
		];

		$parserCacheFactoryOptions = new ServiceOptions( ParserCacheFactory::CONSTRUCTOR_OPTIONS, [
			'CacheEpoch' => '20200202112233',
			'OldRevisionParserCacheExpireTime' => 60 * 60,
		] );

		$parserCacheFactory = new ParserCacheFactory(
			new HashBagOStuff(),
			new WANObjectCache( [ 'cache' => new HashBagOStuff(), ] ),
			$this->createHookContainer(),
			new JsonCodec(),
			new NullStatsdDataFactory(),
			new NullLogger(),
			$parserCacheFactoryOptions,
			$services->getTitleFactory(),
			$services->getWikiPageFactory()
		);

		return new ParsoidOutputAccess(
			new ServiceOptions(
				ParsoidOutputAccess::CONSTRUCTOR_OPTIONS,
				[ 'ParsoidCacheConfig' => $parsoidCacheConfig ]
			),
			$parserCacheFactory,
			$services->getRevisionLookup(),
			$services->getGlobalIdGenerator(),
			$stats,
			$this->newMockParsoid( $expectedParses ),
			$services->getParsoidPageConfigFactory()
		);
	}

	/**
	 * @return ParserOptions
	 */
	private function getParserOptions() {
		return ParserOptions::newFromAnon();
	}

	/**
	 * @covers \MediaWiki\Parser\Parsoid\ParsoidOutputAccess::getParserOutput
	 */
	public function testGetParserOutputThrowsIfRevisionNotFound() {
		$access = $this->getParsoidOutputAccessWithCache( 0 );
		$parserOptions = $this->getParserOptions();

		$page = $this->getNonexistingTestPage( __METHOD__ );

		$this->expectException( RevisionAccessException::class );
		$access->getParserOutput( $page, $parserOptions );
	}

	/**
	 * Tests that getParserOutput() will return output.
	 *
	 * @covers \MediaWiki\Parser\Parsoid\ParsoidOutputAccess::getParserOutput
	 * @covers \MediaWiki\Parser\Parsoid\ParsoidOutputAccess::getParsoidRenderID
	 * @covers \MediaWiki\Parser\Parsoid\ParsoidOutputAccess::getParsoidPageBundle
	 */
	public function testGetParserOutput() {
		$access = $this->getParsoidOutputAccessWithCache( 1 );
		$parserOptions = $this->getParserOptions();

		$page = $this->getNonexistingTestPage( __METHOD__ );
		$this->editPage( $page, self::WIKITEXT );

		$output = $access->getParserOutput( $page, $parserOptions );
		$this->assertSame(
			self::MOCKED_HTML . ' of ' . self::WIKITEXT,
			$output->getText()
		);

		// check that getParsoidRenderID() doesn't throw
		$this->assertNotNull( $access->getParsoidRenderID( $output ) );

		// check that getParsoidPageBundle() returns the correct data
		$pageBundle = $access->getParsoidPageBundle( $output );
		$this->assertSame( $output->getRawText(), $pageBundle->html );

		// The actual values of these fields come from newMockParsoid(). We could check them here.
		$this->assertNotEmpty( $pageBundle->mw );
		$this->assertNotEmpty( $pageBundle->parsoid );
	}

	/**
	 * Tests that getParserOutput() will place the generated output for the latest revision
	 * in the parsoid parser cache.
	 *
	 * @covers \MediaWiki\Parser\Parsoid\ParsoidOutputAccess::getParserOutput
	 * @covers \MediaWiki\Parser\Parsoid\ParsoidOutputAccess::getCachedParserOutput
	 */
	public function testLatestRevisionIsCached() {
		$access = $this->getParsoidOutputAccessWithCache( 1 );
		$parserOptions = $this->getParserOptions();

		$page = $this->getNonexistingTestPage( __METHOD__ );
		$this->editPage( $page, self::WIKITEXT );

		$output = $access->getParserOutput( $page, $parserOptions );
		$this->assertSame(
			self::MOCKED_HTML . ' of ' . self::WIKITEXT,
			$output->getText()
		);

		// Get the ParserOutput again, this should not trigger a new parse.
		$output = $access->getParserOutput( $page, $parserOptions );
		$this->assertSame(
			self::MOCKED_HTML . ' of ' . self::WIKITEXT,
			$output->getText()
		);
	}

	/**
	 * Tests that getParserOutput() will force a parse since we know that
	 * the revision is not in the cache.
	 *
	 * @covers \MediaWiki\Parser\Parsoid\ParsoidOutputAccess::getParserOutput
	 */
	public function testLatestRevisionWithForceParse() {
		$access = $this->getParsoidOutputAccessWithCache( 2 );
		$parserOptions = $this->getParserOptions();

		$page = $this->getNonexistingTestPage( __METHOD__ );
		$this->editPage( $page, self::WIKITEXT );

		$output = $access->getParserOutput( $page, $parserOptions );
		$this->assertSame(
			self::MOCKED_HTML . ' of ' . self::WIKITEXT,
			$output->getText()
		);

		// Get the ParserOutput again, this should trigger a new parse
		// since we're forcing it to.
		$output = $access->getParserOutput(
			$page,
			$parserOptions,
			null,
			ParsoidOutputAccess::OPT_FORCE_PARSE
		);
		$this->assertSame(
			self::MOCKED_HTML . ' of ' . self::WIKITEXT,
			$output->getText()
		);
	}

	public function provideCacheThresholdData() {
		return [
			yield "fast parse" => [ 1, 2 ], // high threshold, no caching
			yield "slow parse" => [ 0, 1 ], // low threshold, caching
		];
	}

	/**
	 * @dataProvider provideCacheThresholdData()
	 */
	public function testHtmlWithCacheThreshold(
		$cacheThresholdTime,
		$expectedCalls
	) {
		$page = $this->getExistingTestPage( __METHOD__ );
		$parsoidCacheConfig = [
			'CacheThresholdTime' => $cacheThresholdTime
		];
		$parserOptions = $this->getParserOptions();

		$access = $this->getParsoidOutputAccessWithCache( $expectedCalls, $parsoidCacheConfig );
		$htmlresult = $access->getParserOutput( $page, $parserOptions )->getRawText();
		$this->assertStringStartsWith( self::MOCKED_HTML, $htmlresult );

		$htmlresult = $access->getParserOutput( $page, $parserOptions )->getRawText();
		$this->assertStringStartsWith( self::MOCKED_HTML, $htmlresult );
	}

	public function testOldRevisionIsCached() {
		$access = $this->getParsoidOutputAccessWithCache( 1 );
		$parserOptions = $this->getParserOptions();

		$page = $this->getNonexistingTestPage( __METHOD__ );
		$status1 = $this->editPage( $page, self::WIKITEXT );
		$rev = $status1->getValue()['revision-record'];

		// Make an edit so that the revision we're getting output
		// for below is not the current revision.
		$this->editPage( $page, 'Second revision' );

		$access->getParserOutput( $page, $parserOptions, $rev );

		// Get the ParserOutput again, this should not trigger a new parse.
		$output = $access->getParserOutput( $page, $parserOptions, $rev );
		$this->assertSame(
			self::MOCKED_HTML . ' of ' . self::WIKITEXT,
			$output->getText()
		);
	}

	public function testGetParserOutputWithOldRevision() {
		$access = $this->getParsoidOutputAccessWithCache( 2 );
		$parserOptions = $this->getParserOptions();

		$page = $this->getNonexistingTestPage( __METHOD__ );
		$status1 = $this->editPage( $page, self::WIKITEXT );
		$rev1 = $status1->getValue()['revision-record'];

		$this->editPage( $page, 'Second revision' );

		$output2 = $access->getParserOutput( $page, $parserOptions );
		$this->assertSame(
			self::MOCKED_HTML . ' of Second revision',
			$output2->getText()
		);

		$output1 = $access->getParserOutput( $page, $parserOptions, $rev1 );
		$this->assertSame(
			self::MOCKED_HTML . ' of ' . self::WIKITEXT,
			$output1->getText()
		);

		// check that getParsoidRenderID() doesn't throw
		$this->assertNotNull( $access->getParsoidRenderID( $output1 ) );
	}
}
