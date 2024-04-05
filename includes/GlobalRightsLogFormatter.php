<?php
/**
 * Formatter for global user rights log entries.
 *
 * @file
 * @ingroup Extensions
 * @license GPL-2.0-or-later
 */

/**
 * This class formats global rights log entries.
 */
class GlobalRightsLogFormatter extends RightsLogFormatter {

	/**
	 * Return the old key here for backwards compatibility.
	 * This preserves old translations and log entries
	 *
	 * @return string message key
	 */
	protected function getMessageKey() {
		$key = parent::getMessageKey();

		$params = $this->getMessageParameters();
		if ( !isset( $params[4] ) ) {
			$key = 'gur-rightslog-entry';
		}

		return $key;
	}

	protected function getMessageParameters() {
		// This is hacky but required, because the parent RightsLogFormatter's method
		// must be avoided, otherwise the group expiration date appears twice in the logs
		$params = LogFormatter::getMessageParameters();

		// Old entries do not contain a fourth parameter
		if ( !isset( $params[4] ) ) {
			return $params;
		}

		$oldGroups = $this->makeGroupArray( $params[3] );
		$newGroups = $this->makeGroupArray( $params[4] );

		$userName = $this->entry->getTarget()->getText();
		if ( !$this->plaintext && count( $oldGroups ) ) {
			foreach ( $oldGroups as &$group ) {
				$group = $this->context->getLanguage()->getGroupMemberName( $group, $userName );
			}
		}
		if ( !$this->plaintext && count( $newGroups ) ) {
			foreach ( $newGroups as &$group ) {
				$group = $this->context->getLanguage()->getGroupMemberName( $group, $userName );
			}
		}

		// fetch the metadata about each group membership
		$allParams = $this->entry->getParameters();

		if ( count( $oldGroups ) ) {
			$params[3] = Message::rawParam( $this->formatRightsList( $oldGroups,
				isset( $allParams['oldmetadata'] ) ? $allParams['oldmetadata'] : [] ) );
		} else {
			$params[3] = $this->msg( 'rightsnone' )->text();
		}

		if ( count( $newGroups ) ) {
			// Array_values is used here because of T44211
			// see use of array_unique in UserrightsPage::doSaveUserGroups on $newGroups.
			$params[4] = Message::rawParam( $this->formatRightsList( array_values( $newGroups ),
				isset( $allParams['newmetadata'] ) ? $allParams['newmetadata'] : [] ) );
		} else {
			$params[4] = $this->msg( 'rightsnone' )->text();
		}

		$params[5] = $userName;

		return $params;
	}

	private function makeGroupArray( $group ) {
		// Migrate old group params from string to array
		if ( $group === '' ) {
			$group = [];
		} elseif ( is_string( $group ) ) {
			$group = array_map( 'trim', explode( ',', $group ) );
		}
		return $group;
	}
}
