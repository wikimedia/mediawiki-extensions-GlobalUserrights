<?php
/**
 * Represents the membership of a user to a global user group.
 *
 * @file
 * @ingroup Extensions
 * @license GPL-2.0-or-later
 */

use MediaWiki\MediaWikiServices;
use Wikimedia\Rdbms\IDatabase;

/**
 * Represents a "global user group membership" -- a specific instance of a user belonging
 * to a global group. For example, the fact that user Mary belongs to the global-sysop group is a
 * global user group membership.
 *
 * The class encapsulates rows in the global_user_groups table. The logic is low-level and
 * doesn't run any hooks.
 *
 * This class inherits from UserGroupMembership for compatibility and code deduplication, although
 * since UserGroupMembership does not allow proper inheriting, as the member variables are
 * private, this is quite limited.
 */
class GlobalUserGroupMembership extends UserGroupMembership {
	/** @var int The ID of the user who belongs to the global group */
	private $userId;

	/** @var string */
	private $group;

	/** @var string|null Timestamp of expiry in TS_MW format, or null if no expiry */
	private $expiry;

	/**
	 * @param int $userId The ID of the user who belongs to the group
	 * @param string|null $group The internal group name
	 * @param string|null $expiry Timestamp of expiry in TS_MW format, or null if no expiry
	 */
	public function __construct( $userId = 0, $group = null, $expiry = null ) {
		parent::__construct( $userId, $group, $expiry );

		$this->userId = $userId;
		$this->group = $group;
		$this->expiry = $expiry;
	}

	/**
	 * @return int
	 */
	public function getUserId() {
		return $this->userId;
	}

	/**
	 * @return string
	 */
	public function getGroup() {
		return $this->group;
	}

	/**
	 * @return string|null Timestamp of expiry in TS_MW format, or null if no expiry
	 */
	public function getExpiry() {
		return $this->expiry;
	}

	protected function initFromRow( $row ) {
		$this->userId = (int)$row->gug_user;
		$this->group = $row->gug_group;
		$this->expiry = $row->gug_expiry === null ?
			null :
			wfTimestamp( TS_MW, $row->gug_expiry );
	}

	/**
	 * Creates a new GlobalUserGroupMembership object from a database row.
	 *
	 * @param stdClass $row The row from the global_user_groups table
	 * @return GlobalUserGroupMembership
	 */
	public static function newFromRow( $row ) {
		$ugm = new self();
		$ugm->initFromRow( $row );
		return $ugm;
	}

	/**
	 * Returns the list of user_groups fields that should be selected to create
	 * a new user group membership.
	 * @return array
	 */
	public static function selectFields() {
		return [
			'gug_user',
			'gug_group',
			'gug_expiry',
		];
	}

	public function delete( IDatabase $dbw = null ) {
		if ( MediaWikiServices::getInstance()->getReadOnlyMode()->isReadOnly() ) {
			return false;
		}

		if ( $dbw === null ) {
			$dbw = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( DB_PRIMARY );
		}

		$dbw->delete(
			'global_user_groups',
			[ 'gug_user' => $this->userId, 'gug_group' => $this->group ],
			__METHOD__ );
		if ( !$dbw->affectedRows() ) {
			return false;
		}

		return true;
	}

	/**
	 * Insert a user right membership into the database. When $allowUpdate is false,
	 * the function fails if there is a conflicting membership entry (same user and
	 * group) already in the table.
	 *
	 * @throws MWException
	 * @param bool $allowUpdate Whether to perform "upsert" instead of INSERT
	 * @param IDatabase|null $dbw If you have one available
	 * @return bool Whether or not anything was inserted
	 */
	public function insert( $allowUpdate = false, IDatabase $dbw = null ) {
		$dbw = $dbw ?: $dbw = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( DB_PRIMARY );

		// Purge old, expired memberships from the DB
		self::purgeExpired( $dbw );

		// Check that the values make sense
		if ( $this->group === null ) {
			throw new UnexpectedValueException(
				'Don\'t try inserting an uninitialized GlobalUserGroupMembership object' );
		} elseif ( $this->userId <= 0 ) {
			throw new UnexpectedValueException(
				'GlobalUserGroupMembership::insert() needs a positive user ID. ' .
				'Did you forget to add your User object to the database before calling addGroup()?' );
		}

		$row = $this->getDatabaseArray( $dbw );
		$dbw->insert( 'global_user_groups', $row, __METHOD__, [ 'IGNORE' ] );
		$affected = $dbw->affectedRows();

		// Don't collide with expired user group memberships
		// Do this after trying to insert, in order to avoid locking
		if ( !$affected ) {
			$conds = [
				'gug_user' => $row['gug_user'],
				'gug_group' => $row['gug_group'],
			];
			// if we're unconditionally updating, check that the expiry is not already the
			// same as what we are trying to update it to; otherwise, only update if
			// the expiry date is in the past
			if ( $allowUpdate ) {
				if ( $this->expiry ) {
					$conds[] = 'gug_expiry IS NULL OR gug_expiry != ' .
						$dbw->addQuotes( $dbw->timestamp( $this->expiry ) );
				} else {
					$conds[] = 'gug_expiry IS NOT NULL';
				}
			} else {
				$conds[] = 'gug_expiry < ' . $dbw->addQuotes( $dbw->timestamp() );
			}

			$dbw->update(
				'global_user_groups',
				[ 'gug_expiry' => $this->expiry ? $dbw->timestamp( $this->expiry ) : null ],
				$conds,
				__METHOD__
			);
			$affected = $dbw->affectedRows();
		}

		return $affected > 0;
	}

	/**
	 * Get an array suitable for passing to $dbw->insert() or $dbw->update()
	 * @param IDatabase $db
	 * @return array
	 */
	protected function getDatabaseArray( IDatabase $db ) {
		return [
			'gug_user' => $this->userId,
			'gug_group' => $this->group,
			'gug_expiry' => $this->expiry ? $db->timestamp( $this->expiry ) : null,
		];
	}

	/**
	 * Has the membership expired?
	 * @return bool
	 */
	public function isExpired() {
		if ( !$this->expiry ) {
			return false;
		} else {
			return wfTimestampNow() > $this->expiry;
		}
	}

	/**
	 * Purge expired memberships from the user_groups table
	 *
	 * @param IDatabase|null $dbw
	 */
	public static function purgeExpired( IDatabase $dbw = null ) {
		if ( MediaWikiServices::getInstance()->getReadOnlyMode()->isReadOnly() ) {
			return;
		}

		if ( $dbw === null ) {
			$dbw = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( DB_PRIMARY );
		}

		DeferredUpdates::addUpdate( new AtomicSectionUpdate(
			$dbw,
			__METHOD__,
			static function ( IDatabase $dbw, $fname ) {
				$expiryCond = [ 'gug_expiry < ' . $dbw->addQuotes( $dbw->timestamp() ) ];

				// delete 'em all
				$dbw->delete( 'global_user_groups', $expiryCond, $fname );
			}
		) );
	}

	/**
	 * Returns GlobalUserGroupMembership objects for all the groups a user currently
	 * belongs to.
	 *
	 * @param int $userId ID of the user to search for
	 * @param IDatabase|null $db Optional database connection
	 * @return array Associative array of (group name => UserGroupMembership object)
	 */
	public static function getMembershipsForUser( $userId, IDatabase $db = null ) {
		if ( !$db ) {
			$db = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( DB_PRIMARY );
		}

		$res = $db->select( 'global_user_groups',
			self::selectFields(),
			[ 'gug_user' => $userId ],
			__METHOD__ );

		$gugms = [];
		foreach ( $res as $row ) {
			$ugm = self::newFromRow( $row );
			if ( !$ugm->isExpired() ) {
				$gugms[$ugm->group] = $ugm;
			}
		}

		return $gugms;
	}

	/**
	 * Returns a UserGroupMembership object that pertains to the given user and group,
	 * or false if the user does not belong to that group (or the assignment has
	 * expired).
	 *
	 * @param int $userId ID of the user to search for
	 * @param string $group User group name
	 * @param IDatabase|null $db Optional database connection
	 * @return UserGroupMembership|false
	 */
	public static function getMembership( $userId, $group, IDatabase $db = null ) {
		if ( !$db ) {
			$db = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( DB_REPLICA );
		}

		$row = $db->selectRow( 'global_user_groups',
			self::selectFields(),
			[ 'gug_user' => $userId, 'gug_group' => $group ],
			__METHOD__ );
		if ( !$row ) {
			return false;
		}

		$ugm = self::newFromRow( $row );
		if ( !$ugm->isExpired() ) {
			return $ugm;
		} else {
			return false;
		}
	}

	/**
	 * Gets a link for a user group, possibly including the expiry date if relevant.
	 *
	 * @param string|GlobalUserGroupMembership $ugm Either a group name as a string, or
	 *   a GlobalUserGroupMembership object
	 * @param IContextSource $context
	 * @param string $format Either 'wiki' or 'html'
	 * @param string|null $userName If you want to use the group member message
	 *   ("administrator"), pass the name of the user who belongs to the group; it
	 *   is used for GENDER of the group member message. If you instead want the
	 *   group name message ("Administrators"), omit this parameter.
	 * @return string
	 * @throws MWException
	 */
	public static function getLink( $ugm, IContextSource $context, $format, $userName = null ) {
		if ( $format !== 'wiki' && $format !== 'html' ) {
			throw new MWException( 'GlobalUserGroupMembership::getLink() $format parameter should be ' .
				"'wiki' or 'html'" );
		}

		if ( $ugm instanceof GlobalUserGroupMembership ) {
			$expiry = $ugm->getExpiry();
			$group = $ugm->getGroup();
		} else {
			$expiry = null;
			$group = $ugm;
		}

		if ( $userName !== null ) {
			$groupName = $context->getLanguage()->getGroupMemberName( $group, $userName );
		} else {
			$groupName = $context->getLanguage()->getGroupName( $group );
		}

		// link to the group description page, if it exists
		$linkTitle = self::getGroupPage( $group );
		if ( $linkTitle ) {
			if ( $format === 'wiki' ) {
				$linkPage = $linkTitle->getFullText();
				$groupLink = "[[$linkPage|$groupName]]";
			} else {
				$groupLink = MediaWikiServices::getInstance()->getLinkRenderer()->makeLink( $linkTitle, $groupName );
			}
		} else {
			$groupLink = htmlspecialchars( $groupName );
		}

		if ( $expiry ) {
			// format the expiry to a nice string
			$uiLanguage = $context->getLanguage();
			$uiUser = $context->getUser();
			$expiryDT = $uiLanguage->userTimeAndDate( $expiry, $uiUser );
			$expiryD = $uiLanguage->userDate( $expiry, $uiUser );
			$expiryT = $uiLanguage->userTime( $expiry, $uiUser );
			if ( $format === 'html' ) {
				$groupLink = Message::rawParam( $groupLink );
			}
			return $context->msg( 'group-membership-link-with-expiry' )
				->params( $groupLink, $expiryDT, $expiryD, $expiryT )->text();
		} else {
			return $groupLink;
		}
	}
}
