<?php

/* see http://www.mediawiki.org/wiki/Manual:Special_pages */

# Alert the user that this is not a valid entry point to MediaWiki if they try to access the special pages file directly.
if (!defined('MEDIAWIKI')) {
	echo "See README.md for install instructions.";
	exit(1);
}

$wgExtensionCredits['specialpage'][] = array(
	'name' => 'FbnTraffic',
	'author' => 'Martin Muskulus',
	'url' => 'https://www.fbn-dd.de/wiki/FbnTraffic',
	'description' => 'Trafficanzeige für Vereinsmitglieder',
	'descriptionmsg' => 'fbntraffic-desc',
	'version' => '2.0.0'
);

$dir = dirname(__FILE__) . '/';

$wgAutoloadClasses['FbnTraffic'] = $dir . 'FbnTraffic_body.php'; /* Location of the SpecialMyExtension class (Tell MediaWiki to load this file) */
$wgExtensionMessagesFiles['FbnTraffic'] = $dir . 'FbnTraffic.i18n.php'; /* Location of a messages file (Tell MediaWiki to load this file) */
$wgExtensionAliasesFiles['FbnTraffic'] = $dir . 'FbnTraffic.alias.php'; /* Location of an aliases file (Tell MediaWiki to load this file) */
$wgSpecialPages['FbnTraffic'] = 'FbnTraffic'; /* Tell MediaWiki about the new special page and its class name */
