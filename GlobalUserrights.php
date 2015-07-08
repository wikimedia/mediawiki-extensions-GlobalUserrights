<?php
/**
 * GlobalUserrights -- Special page to allow management of global user groups
 *
 * @file
 * @ingroup Extensions
 * @author Nathaniel Herman <redwwjd@yahoo.com>
 * @copyright Copyright © 2008 Nathaniel Herman
 * @license http://www.gnu.org/copyleft/gpl.html GNU General Public License 2.0 or later
 * @note Some of the code based on stuff by Lukasz 'TOR' Garczewski, as well as SpecialUserrights.php and CentralAuth
 */

// Extension credits
$wgExtensionCredits['specialpage'][] = array(
	'path'           => __FILE__,
	'name'           => 'GlobalUserrights',
	'url'            => 'https://www.mediawiki.org/wiki/Extension:GlobalUserrights',
	'version'        => '1.2',
	'author'         => 'Nathaniel Herman',
	'descriptionmsg' => 'gur-desc',
);

// Set up the new special page
$wgMessagesDirs['GlobalUserrights'] = __DIR__ . '/i18n';
$wgExtensionMessagesFiles['GlobalUserrightsAlias'] = __DIR__ . '/GlobalUserrights.alias.php';
$wgAutoloadClasses['GlobalUserrights'] = __DIR__ . '/GlobalUserrights_body.php';
$wgSpecialPages['GlobalUserrights'] = 'GlobalUserrights';

// New user right, required to use Special:GlobalUserrights
$wgAvailableRights[] = 'userrights-global';
$wgGroupPermissions['staff']['userrights-global'] = true;

// New log type for global right changes
$wgLogTypes[] = 'gblrights';
$wgLogNames['gblrights'] = 'gur-rightslog-name';
$wgLogHeaders['gblrights'] = 'gur-rightslog-header';
$wgLogActions['gblrights/rights'] = 'gur-rightslog-entry';

// Hooked functions
$wgAutoloadClasses['GlobalUserrightsHooks'] = __DIR__ . '/GlobalUserrightsHooks.php';
$wgHooks['UserEffectiveGroups'][] = 'GlobalUserrightsHooks::onUserEffectiveGroups';
$wgHooks['SpecialListusersQueryInfo'][] = 'GlobalUserrightsHooks::onSpecialListusersQueryInfo';