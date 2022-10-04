<?php declare(strict_types=1);

namespace s9e\MediaSites\Tests;

use Composer\InstalledVersions;
use DOMDocument;
use PHPUnit\Framework\TestCase;
use XF;
use XF\Entity\BbCodeMediaSite;
use s9e\MediaSites\Parser;

abstract class AbstractParserTest extends TestCase
{
	protected static string $rootDir = __DIR__ . '/..';
	protected static $sites = [];
	public static function setUpBeforeClass(): void
	{
		static::$rootDir = realpath(InstalledVersions::getRootPackage()['install_path']);

		$dom = new DOMDocument;
		$dom->load(static::$rootDir . '/addon/_data/bb_code_media_sites.xml');
		foreach ($dom->getElementsByTagName('site') as $site)
		{
			$siteId = $site->getAttribute('media_site_id');
			$regexp = $site->getElementsByTagName('match_urls')->item(0)->textContent;

			self::$sites[$siteId] = $regexp;
		}
	}

	/**
	* @dataProvider getMatchTests
	*/
	public function testMatch($url, $expected, array $config = [])
	{
		XF::$config = $config;

		$mediaKey = false;
		foreach (self::$sites as $siteId => $regexp)
		{
			if (!preg_match($regexp, $url, $m))
			{
				continue;
			}
			$mediaKey = Parser::match($url, $m['id'], new BbCodeMediaSite, $siteId);
			if ($mediaKey !== false)
			{
				break;
			}
		}

		$this->assertSame($expected, $mediaKey);
	}

	abstract public function getMatchTests(): array;
}