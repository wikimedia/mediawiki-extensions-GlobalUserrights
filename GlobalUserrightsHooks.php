<?php

class GlobalUserrightsHooks {

	/**
	 * Function to get a given user's global groups
	 *
	 * @param $user instance of User class
	 * @return array of global groups
	 */
	public static function getGroups( $user ) {
		if ( $user instanceof User ) {
			$uid = $user->getId();
		} else {
			// if $user isn't an instance of user, assume it's the uid
			$uid = $user;
		}

		$groups = array();
		if ( $uid === 0 ) {
			// Optimization -- we know that anons (user ID #0) cannot be members
			// of any (global) user groups, so we don't need to run the DB query
			// to figure that out and we can just return the empty array here.
			return $groups;
		}

		$dbr = wfGetDB( DB_MASTER );
		$res = $dbr->select(
			'global_user_groups',
			array( 'gug_group' ),
			array( 'gug_user' => $uid ),
			__METHOD__
		);

		foreach ( $res as $row ) {
			$groups[] = $row->gug_group;
		}

		return $groups;
	}

	/**
	 * Hook function for UserEffectiveGroups
	 * Adds any global groups the user has to $groups
	 *
	 * @param $user instance of User
	 * @param &$groups array of groups the user is in
	 */
	public static function onUserEffectiveGroups( $user, &$groups ) {
		$groups = array_merge( $groups, GlobalUserrightsHooks::getGroups( $user ) );
		$groups = array_unique( $groups );

		return true;
	}

	/**
	 * Hook function for SpecialListusersQueryInfo
	 * Updates UsersPager::getQueryInfo() to account for the global_user_groups table
	 * This ensures that global rights show up on Special:ListUsers
	 *
	 * @param $that instance of UsersPager
	 * @param &$query the query array to be returned
	 */
	public static function onSpecialListusersQueryInfo( $that, &$query ) {
		$dbr = wfGetDB( DB_SLAVE );

		$query['tables'][] = 'global_user_groups';
		$query['join_conds']['global_user_groups'] = array(
			'LEFT JOIN',
			'user_id = gug_user'
		);

		$query['fields'][3] = 'COUNT(ug_group) + COUNT(gug_group) AS numgroups';
		// kind of yucky statement, I blame MySQL 5.0.13 http://bugs.mysql.com/bug.php?id=15610
		$query['fields'][4] = 'GREATEST(COALESCE(ug_group, gug_group), COALESCE(gug_group, ug_group)) AS singlegroup';

		// if there's a $query['conds']['ug_group'], destroy it and make one that accounts for gug_group
		if ( isset( $query['conds']['ug_group'] ) ) {
			unset( $query['conds']['ug_group'] );
			$reqgrp = $dbr->addQuotes( $that->requestedGroup );
			$query['conds'][] = 'ug_group = ' . $reqgrp . 'OR gug_group = ' . $reqgrp;
		}

		return true;
	}

	/**
	 * Fixes Special:Statistics so that the correct amount of global group members
	 * is shown there.
	 *
	 * @param ResultWrapper $hit
	 * @param string $group User group name
	 * @return bool
	 */
	public static function updateStatsForGUR( &$hit, $group ) {
		if ( $group == 'staff' || $group == 'globalbot' ) {
			$dbr = wfGetDB( DB_SLAVE );
			$hit = $dbr->selectField(
				'global_user_groups',
				'COUNT(*)',
				array( 'gug_group' => $group ),
				__METHOD__
			);
		}
		return true;
	}

	/**
	 * Create SQL automatically when running update.php so sql does not have to be
	 * applied manually
	 */
	public static function onLoadExtensionSchemaUpdates( $updater ) {
		$updater->addExtensionTable( 'global_user_groups', __DIR__ . '/global_user_groups.sql' );
		return true;
	}
}
