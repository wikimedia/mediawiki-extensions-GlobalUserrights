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
		if ( !$this->shouldProcessParams( $params ) ) {
			$key = 'gur-rightslog-entry';
		}

		return $key;
	}

	protected function shouldProcessParams( $params ) {
		return isset( $params[4] );
	}
}
