<?php
/**
 * Special:GlobalUserrights, Special:UserRights for global groups
 *
 * @file
 * @ingroup Extensions
 * @author Nathaniel Herman <redwwjd@yahoo.com>
 * @copyright Copyright © 2008 Nathaniel Herman
 * @license http://www.gnu.org/copyleft/gpl.html GNU General Public License 2.0 or later
 * @note Some of the code based on stuff by Lukasz 'TOR' Garczewski, as well as SpecialUserrights.php and CentralAuth
 */

class GlobalUserrights extends UserrightsPage {

	/* Constructor */
	public function __construct() {
		SpecialPage::__construct( 'GlobalUserrights' );
	}

	/**
	 * Save global user groups changes in the DB
	 *
	 * @param $username String: username
	 * @param $reason String: reason
	 */
	function doSaveUserGroups( $user, $add, $remove, $reason = '' ) {
		$oldGroups = GlobalUserrightsHooks::getGroups( $user );
		$newGroups = $oldGroups;

		// remove then add groups
		if ( $remove ) {
			$newGroups = array_diff( $newGroups, $remove );
			$uid = $user->getId();
			foreach ( $remove as $group ) {
				// whole reason we're redefining this function is to make it use
				// $this->removeGroup instead of $user->removeGroup, etc.
				$this->removeGroup( $uid, $group );
			}
		}
		if ( $add ) {
			$newGroups = array_merge( $newGroups, $add );
			$uid = $user->getId();
			foreach ( $add as $group ) {
				$this->addGroup( $uid, $group );
			}
		}
		// get rid of duplicate groups there might be
		$newGroups = array_unique( $newGroups );

		// Ensure that caches are cleared
		$user->invalidateCache();

		// if anything changed, log it
		if ( $newGroups != $oldGroups ) {
			$this->addLogEntry( $user, $oldGroups, $newGroups, $reason );
		}
		return array( $add, $remove );
	}

	function addGroup( $uid, $group ) {
		$dbw = wfGetDB( DB_MASTER );
		$dbw->insert(
			'global_user_groups',
			array(
				'gug_user' => $uid,
				'gug_group' => $group
			),
			__METHOD__,
			'IGNORE'
		);
	}

	function removeGroup( $uid, $group ) {
		$dbw = wfGetDB( DB_MASTER );
		$dbw->delete(
			'global_user_groups',
			array(
				'gug_user' => $uid,
				'gug_group' => $group
			),
			__METHOD__
		);
	}

	/**
	 * Add a gblrights log entry
	 */
	function addLogEntry( $user, $oldGroups, $newGroups, $reason ) {
		$log = new LogPage( 'gblrights' );

		$log->addEntry( 'rights',
			$user->getUserPage(),
			$reason,
			array(
				$this->makeGroupNameListForLog( $oldGroups ),
				$this->makeGroupNameListForLog( $newGroups )
			)
		);
	}

	/**
	 * Make a list of group names to be stored as parameter for log entries.
	 *
	 * This is an ugly hack backported from MediaWiki 1.26.
	 * @todo FIXME Per the associated comment in MW 1.26 and older, we shouldn't
	 * be using this but rather LogFormatter.
	 *
	 * @param array $ids
	 * @return string
	 */
	function makeGroupNameListForLog( $ids ) {
		if ( empty( $ids ) ) {
			return '';
		} else {
			return $this->makeGroupNameList( $ids );
		}
	}

	protected function showEditUserGroupsForm( $user, $groups ) {
		// override the $groups that is passed, which will be
		// the user's local groups
		$groups = GlobalUserrightsHooks::getGroups( $user );
		parent::showEditUserGroupsForm( $user, $groups );
	}

	function changeableGroups() {
		global $wgUser;
		if ( $wgUser->isAllowed( 'userrights-global' ) ) {
			// all groups can be added globally
			$all = array_merge( User::getAllGroups() );
			return array(
				'add' => $all,
				'remove' => $all,
				'add-self' => array(),
				'remove-self' => array()
			);
		} else {
			return array();
		}
	}

	protected function showLogFragment( $user, $output ) {
		$log = new LogPage( 'gblrights' );
		$output->addHTML( Xml::element( 'h2', null, $log->getName() . "\n" ) );
		LogEventsList::showLogExtract( $output, 'gblrights', $user->getUserPage()->getPrefixedText() );
	}

	protected function getGroupName() {
		return 'users';
	}
}
