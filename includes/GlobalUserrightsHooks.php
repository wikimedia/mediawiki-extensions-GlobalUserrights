<?php

use MediaWiki\MediaWikiServices;
use MediaWiki\User\UserIdentity;

class GlobalUserrightsHooks {

	/**
	 * Function to get a given user's global groups
	 *
	 * @param UserIdentity|int $user instance of UserIdentity class or uid
	 * @return array of global groups
	 */
	public static function getGroups( $user ) {
		return array_keys( self::getGroupMemberships( $user ) );
	}

	/**
	 * Function to get a given user's global groups memberships
	 *
	 * @param int|UserIdentity $user instance of UserIdentity class or uid
	 * @return array
	 */
	public static function getGroupMemberships( $user ) {
		if ( $user instanceof UserIdentity ) {
			if ( method_exists( MediaWikiServices::class, 'getCentralIdLookupFactory' ) ) {
				// MW1.37+
				$uidLookup = MediaWikiServices::getInstance()->getCentralIdLookupFactory()->getLookup();
			} else {
				$uidLookup = CentralIdLookup::factory();
			}

			$uid = $uidLookup->centralIdFromLocalUser( $user );
		} else {
			// if $user isn't an instance of user, assume it's the uid
			$uid = $user;
		}

		if ( $uid === 0 ) {
			// Optimization -- we know that anons (user ID #0) cannot be members
			// of any (global) user groups, so we don't need to run the DB query
			// to figure that out and we can just return the empty array here.
			return [];
		} else {
			return GlobalUserGroupMembership::getMembershipsForUser( $uid );
		}
	}

	/**
	 * Hook function for UserEffectiveGroups
	 * Adds any global groups the user has to $groups
	 *
	 * @param User $user instance of User
	 * @param array &$groups array of groups the user is in
	 * @return bool
	 */
	public static function onUserEffectiveGroups( User $user, &$groups ) {
		$groups = array_merge( $groups, self::getGroups( $user ) );
		$groups = array_unique( $groups );

		return true;
	}

	/**
	 * Hook function for SpecialListusersQueryInfo
	 * Updates UsersPager::getQueryInfo() to account for the global_user_groups table
	 * This ensures that global rights show up on Special:ListUsers
	 *
	 * @param UsersPager $that instance of UsersPager
	 * @param array &$query the query array to be returned
	 * @return bool
	 */
	public static function onSpecialListusersQueryInfo( $that, &$query ) {
		$dbr = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( DB_REPLICA );

		$query['tables'][] = 'global_user_groups';
		$query['join_conds']['global_user_groups'] = [
			'LEFT JOIN',
			'user_id = gug_user'
		];

		// if there's a $query['conds']['ug_group'], destroy it and make one that accounts for gug_group
		if ( isset( $query['conds']['ug_group'] ) ) {
			unset( $query['conds']['ug_group'] );
			$reqgrp = $dbr->addQuotes( $that->requestedGroup );
			$query['conds'][] = 'ug_group = ' . $reqgrp . 'OR gug_group = ' . $reqgrp;
		}

		return true;
	}

	/**
	 * Hook function to make User#isBot return true for global bots
	 * This is needed so that various consumers of that method, such as SocialProfile's
	 * Special:TopUsers special page (and other Special:Top* pages), are able to
	 * filter out global bots the way they always were supposed to.
	 *
	 * @param User $user User whose botness is being tested
	 * @param bool &$isBot Well, are they a bot (true) or not (false)?
	 */
	public static function onUserIsBot( User $user, &$isBot ) {
		if ( !$user->isAnon() ) {
			$groups = self::getGroups( $user );
			if ( in_array( 'globalbot', $groups ) ) {
				$isBot = true;
			}
		}
	}

	/**
	 * Hook function for UsersPagerDoBatchLookups
	 *
	 * @param \Wikimedia\Rdbms\IDatabase $dbr
	 * @param array $userIds
	 * @param array &$cache
	 * @param array &$groups
	 * @return bool
	 */
	public static function onUsersPagerDoBatchLookups( \Wikimedia\Rdbms\IDatabase $dbr, array $userIds, array &$cache, array &$groups ) {
		$globalGroupsRes = $dbr->select(
			'global_user_groups',
			GlobalUserGroupMembership::selectFields(),
			[ 'gug_user' => $userIds ],
			__METHOD__
		);

		foreach ( $globalGroupsRes as $row ) {
			$gugm = GlobalUserGroupMembership::newFromRow( $row );
			if ( !$gugm->isExpired() ) {
				$cache[$row->gug_user][$row->gug_group] = $gugm;
				$groups[$row->gug_group] = true;
			}
		}

		return true;
	}

	/**
	 * Fixes Special:Statistics so that the correct amount of global group members
	 * is shown there.
	 *
	 * @param string|null &$hit
	 * @param string $group User group name
	 * @return bool
	 */
	public static function updateStatsForGUR( &$hit, $group ) {
		if ( $group == 'staff' || $group == 'globalbot' ) {
			$dbr = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( DB_REPLICA );
			$hit = $dbr->selectField(
				'global_user_groups',
				'COUNT(*)',
				[ 'gug_group' => $group ],
				__METHOD__
			);
		}
		return true;
	}

	/**
	 * Create SQL automatically when running update.php so sql does not have to be
	 * applied manually
	 *
	 * @param DatabaseUpdater $updater
	 * @return bool
	 */
	public static function onLoadExtensionSchemaUpdates( DatabaseUpdater $updater ) {
		$dir = __DIR__;

		$dbType = $updater->getDB()->getType();
		$dir = dirname( __DIR__ ) . '/sql/';

		$updater->addExtensionTable(
			'global_user_groups',
			"$dir/$dbType/tables-generated.sql"
		);

		$dir = dirname( __DIR__ ) . '/db_patches';

		if ( $dbType === 'postgres' ) {
			// Currently no schema changes with postgres, bail out
			return;
		}

		// Update the table with the new definitions
		// This ensures backwards compatibility
		$updater->addExtensionField( 'global_user_groups', 'gug_expiry', $dir . '/patch-gug_expiry-field.sql' );
		$updater->modifyExtensionField( 'global_user_groups', 'gug_group', $dir . '/patch-gug_group-field.sql' );
		$updater->addExtensionIndex( 'global_user_groups', 'gug_expiry', $dir . '/patch-gug_expiry-index.sql' );

		return true;
	}
}
