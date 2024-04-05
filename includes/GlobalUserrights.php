<?php

use MediaWiki\MediaWikiServices;
use MediaWiki\User\UserFactory;
use MediaWiki\User\UserIdentity;

/**
 * Special:GlobalUserrights, Special:UserRights for global groups
 *
 * @file
 * @ingroup Extensions
 * @author Nathaniel Herman <redwwjd@yahoo.com>
 * @copyright Copyright © 2008 Nathaniel Herman
 * @license GPL-2.0-or-later
 * @note Some of the code based on stuff by Lukasz 'TOR' Garczewski, as well as SpecialUserrights.php and CentralAuth
 */

class GlobalUserrights extends UserrightsPage {

	public function __construct() {
		parent::__construct();
		$this->mName = 'GlobalUserrights';
	}

	/**
	 * Save global user groups changes in the DB
	 *
	 * @param UserIdentity $user
	 * @param array $add Array of groups to add
	 * @param array $remove Array of groups to remove
	 * @param string $reason Reason for group change
	 * @param array $tags Array of change tags to add to the log entry
	 * @param array $groupExpiries Associative array of (group name => expiry),
	 *   containing only those groups that are to have new expiry values set
	 * @return array Tuple of added, then removed groups
	 * @internal param string $username username
	 */
	function doSaveUserGroups( $user, array $add, array $remove, $reason = '',
		array $tags = [], array $groupExpiries = []
	) {
		if ( method_exists( MediaWikiServices::class, 'getCentralIdLookupFactory' ) ) {
			// MW1.37+
			$uidLookup = MediaWikiServices::getInstance()->getCentralIdLookupFactory()->getLookup();
		} else {
			$uidLookup = CentralIdLookup::factory();
		}

		$uid = $uidLookup->centralIdFromLocalUser( $user );

		$oldUGMs = GlobalUserrightsHooks::getGroupMemberships( $uid );
		$oldGroups = GlobalUserrightsHooks::getGroups( $uid );
		$newGroups = $oldGroups;

		// remove then add groups
		if ( $remove ) {
			$newGroups = array_diff( $newGroups, $remove );

			foreach ( $remove as $group ) {
				// whole reason we're redefining this function is to make it use
				// $this->removeGroup instead of $user->removeGroup, etc.
				$this->removeGroup( $uid, $group );
			}
		}
		if ( $add ) {
			$newGroups = array_merge( $newGroups, $add );

			foreach ( $add as $group ) {
				$expiry = isset( $groupExpiries[$group] ) ? $groupExpiries[$group] : null;
				$this->addGroup( $uid, $group, $expiry );
			}
		}

		// get rid of duplicate groups there might be
		$newGroups = array_unique( $newGroups );
		$newUGMs = GlobalUserrightsHooks::getGroupMemberships( $uid );

		// Ensure that caches are cleared
		if ( method_exists( UserFactory::class, 'invalidateCache' ) ) {
			// MW 1.41+
			MediaWikiServices::getInstance()->getUserFactory()->invalidateCache( $user );
		} else {
			$user->invalidateCache();
		}

		wfDebug( 'oldGlobalGroups: ' . print_r( $oldGroups, true ) . "\n" );
		wfDebug( 'newGlobalGroups: ' . print_r( $newGroups, true ) . "\n" );
		wfDebug( 'oldGlobalUGMs: ' . print_r( $oldUGMs, true ) . "\n" );
		wfDebug( 'newGlobalUGMs: ' . print_r( $newUGMs, true ) . "\n" );

		// if anything changed, log it
		if ( $newGroups != $oldGroups || $newUGMs != $oldUGMs ) {
			$this->addLogEntry( $user, $oldGroups, $newGroups, $reason, $tags, $oldUGMs, $newUGMs );
		}
		return [ $add, $remove ];
	}

	/**
	 * Add a user to a group
	 *
	 * @param int $uid central Id
	 * @param string $group name of the group to add
	 * @param string|null $expiry expiration of the group membership
	 * @return bool
	 */
	function addGroup( $uid, $group, $expiry = null ) {
		if ( $expiry ) {
			$expiry = wfTimestamp( TS_MW, $expiry );
		}

		$gugm = new GlobalUserGroupMembership( $uid, $group, $expiry );
		if ( !$gugm->insert( true ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Removes a user from a group
	 *
	 * @param int $uid central Id
	 * @param string $group name of the group
	 * @return bool
	 */
	function removeGroup( $uid, $group ) {
		$gugm = new GlobalUserGroupMembership( $uid, $group );

		if ( !$gugm || !$gugm->delete() ) {
			return false;
		}

		return true;
	}

	/**
	 * Add a gblrights log entry
	 *
	 * @param UserIdentity $user
	 * @param array $oldGroups list of groups before the change
	 * @param array $newGroups list of groups after the change
	 * @param string $reason reason for the group change
	 * @param array $tags Change tags for the log entry
	 * @param array $oldUGMs Associative array of (group name => GlobalUserGroupMembership)
	 * @param array $newUGMs Associative array of (group name => GlobalUserGroupMembership)
	 */
	protected function addLogEntry( $user, array $oldGroups, array $newGroups, $reason,
		array $tags, array $oldUGMs, array $newUGMs
	) {
		// make sure $oldUGMs and $newUGMs are in the same order, and serialise
		// each UGM object to a simplified array
		$oldUGMs = array_map( function ( $group ) use ( $oldUGMs ) {
			return isset( $oldUGMs[$group] ) ?
				self::serialiseUgmForLog( $oldUGMs[$group] ) :
				null;
		}, $oldGroups );
		$newUGMs = array_map( function ( $group ) use ( $newUGMs ) {
			return isset( $newUGMs[$group] ) ?
				self::serialiseUgmForLog( $newUGMs[$group] ) :
				null;
		}, $newGroups );

		$logEntry = new ManualLogEntry( 'gblrights', 'rights' );
		$logEntry->setPerformer( $this->getUser() );
		$logEntry->setTarget( Title::makeTitle( NS_USER, $user->getName() ) );
		$logEntry->setComment( $reason );
		$logEntry->setParameters( [
			'4::oldgroups' => $oldGroups,
			'5::newgroups' => $newGroups,
			'oldmetadata' => $oldUGMs,
			'newmetadata' => $newUGMs,
		] );
		$logid = $logEntry->insert();
		if ( $tags ) {
			$logEntry->addTags( $tags );
		}
		$logEntry->publish( $logid );
	}

	/**
	 * @param UserIdentity $user
	 * @param array $groups
	 * @param array $groupMemberships
	 */
	protected function showEditUserGroupsForm( $user, $groups, $groupMemberships ) {
		// override the $groups that is passed, which will be
		// the user's local groups
		$groupMemberships = GlobalUserrightsHooks::getGroupMemberships( $user );
		parent::showEditUserGroupsForm( $user, $groups, $groupMemberships );
	}

	/**
	 * @return array
	 */
	function changeableGroups() {
		$groups = [
			'add' => [],
			'remove' => [],
			'add-self' => [],
			'remove-self' => []
		];

		if ( $this->getUser()->isAllowed( 'userrights-global' ) ) {
			// all groups can be added globally
			$all = array_merge( MediaWikiServices::getInstance()->getUserGroupManager()->listAllGroups() );
			$groups['add'] = $all;
			$groups['remove'] = $all;
		}

		return $groups;
	}

	/**
	 * Show a rights log fragment for the specified user
	 *
	 * @param UserIdentity $user
	 * @param OutputPage $output
	 */
	protected function showLogFragment( $user, $output ) {
		$log = new LogPage( 'gblrights' );
		$output->addHTML( Xml::element( 'h2', null, $log->getName()->text() ) );
		LogEventsList::showLogExtract( $output, 'gblrights', Title::makeTitle( NS_USER, $user->getName() ) );
	}

	protected function getGroupName() {
		return 'users';
	}
}
