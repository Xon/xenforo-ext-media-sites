<?php

/**
* @package   s9e\AddonBuilder\MediaSites
* @copyright Copyright (c) 2017-2018 The s9e Authors
* @license   http://www.opensource.org/licenses/mit-license.php The MIT License
*/
namespace s9e\AddonBuilder\MediaSites\Transpilers;

use DOMDocument;
use DOMElement;
use RuntimeException;
use s9e\TextFormatter\Configurator\Helpers\TemplateHelper;

class XenForoTemplate implements TranspilerInterface
{
	/**
	* Transpile given XSLT template to XenForo template
	*
	* @param  string $template XSLT template
	* @return string           XenForo template
	*/
	public function transpile($template)
	{
		$replacements = [
			'(\\{\\{)'                             => '&#123;',
			'(\\}\\})'                             => '&#125;',
			'(\\{@(\\w+)\\})'                      => '{$$1}',
			'(<xsl:value-of select="@(\\w+)"/>)'   => '{$$1}',
			'((<iframe[^>]+?)/>)'                  => '$1></iframe>',
			'( data-s9e-livepreview[^=]*="[^"]*")' => '',

			'(<xsl:if test="([^"]++)">)'               => '<xf:if is="$1">',
			'(</xsl:if>)'                              => '</xf:if>',
			'(<xsl:choose><xsl:when test="([^"]++)">)' => '<xf:if is="$1">',
			'(</xsl:when><xsl:when test="([^"]++)">)'  => '<xf:elseif is="$1">',
			'(</xsl:when><xsl:otherwise>)'             => '<xf:else/>',
			'(</xsl:otherwise></xsl:choose>)'          => '</xf:if>',
		];
		$template = preg_replace(array_keys($replacements), array_values($replacements), $template);
		$template = preg_replace_callback(
			'(<xf:(?:else)?if is="\\K[^"]++)',
			function ($m)
			{
				return self::convertXPath($m[0]);
			},
			$template
		);
		$template = preg_replace_callback(
			'(<xsl:value-of select="(.*?)"/>)',
			function ($m)
			{
				return '{{ ' . self::convertXPath($m[1]) . ' }}';
			},
			$template
		);

		// Replace xf:if with inline ternaries in attributes
		$template = preg_replace_callback(
			'(<xsl:attribute[^>]+>\\K.*?(?=</xsl:attribute))',
			function ($m)
			{
				return $this->convertTernaries($m[0]);
			},
			$template
		);

		// Inline xsl:attribute elements in HTML elements
		do
		{
			$template = preg_replace(
				'((<(?!\\w+:)[^>]*)><xsl:attribute name="(\\w+)">(.*?)</xsl:attribute>)',
				'$1 $2="$3">',
				$template,
				1,
				$cnt
			);
		}
		while ($cnt > 0);

		if (strpos($template, '<xsl:') !== false)
		{
			throw new RuntimeException('Cannot transpile XSL element');
		}
		if (preg_match('((?<!\\{)\\{(?![{$])[^}]*\\}?)', $template, $m))
		{
			throw new RuntimeException("Cannot transpile attribute value template '" . $m[0] . "'");
		}

		// Unescape braces
		$template = strtr($template, ['&#123;' => '{', '&#125;' => '}']);

		return $template;
	}

	/**
	* Convert an XPath expression to a XenForo expression
	*
	* @param  string $expr
	* @return string
	*/
	protected static function convertXPath($expr)
	{
		$replacements = [
			"(^@(\\w+)$)D"                 => '$$1',
			"(^@(\\w+)(='.*?')$)D"         => '$$1=$2',
			"(^@(\\w+)>(\\d+)$)D"          => '$$1>$2',
			'(^100\\*@height div@width$)D' => '100*$height/$width',
			'(^100\\*\\(@height\\+(\\d+)\\)div@width$)D' => '100*($height+$1)/$width'
		];

		$expr = html_entity_decode($expr);
		$expr = preg_replace(array_keys($replacements), array_values($replacements), $expr, -1, $cnt);
		if (!$cnt)
		{
			throw new RuntimeException('Cannot convert ' . $expr);
		}

		return $expr;
	}

	/**
	* Convert template content to be used in a ternary
	*
	* @param  string $str
	* @return string
	*/
	protected function convertMixedContent($str)
	{
		// Escape ternaries
		$str = preg_replace_callback(
			'(\\{\\{\\s*(.*?)\\s*\\}\\})',
			function ($m)
			{
				return '@' . base64_encode($m[1]) . '@';
			},
			$str
		);
		$str = "'" . $str . "'";
		$str = preg_replace('(\\{(\\$\\w+)\\})', "' . $1 . '", $str);

		// Unescape ternaries
		$str = preg_replace_callback(
			'(@([^@]++)@)',
			function ($m)
			{
				return "' . (" . base64_decode($m[1]) . ") . '";
			},
			$str
		);

		// Remove empty concatenations
		$str = str_replace("'' . ", '', $str);
		$str = str_replace(" . ''", '', $str);

		return $str;
	}

	/**
	* Convert xf:if elements into inline ternaries
	*
	* @param  string $template
	* @return string
	*/
	protected function convertTernaries($template)
	{
		$old       = $template;
		$template = preg_replace_callback(
			'(<xf:if is="([^"]+)">([^<]+)(?:<xf:else/>([^<]+))?</xf:if>)',
			function ($m)
			{
				$true  = $this->convertMixedContent($m[2]);
				$false = (isset($m[3])) ? $this->convertMixedContent($m[3]) : "''";

				return '{{ ' . $m[1] . ' ? ' . $true . ' : ' . $false . ' }}';
			},
			$template
		);
		if ($template !== $old)
		{
			$template = $this->convertTernaries($template);
		}

		return $template;
	}
}