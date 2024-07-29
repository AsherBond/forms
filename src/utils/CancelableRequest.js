/**
 * @copyright Copyright (c) 2019 Marco Ambrosini <marcoambrosini@icloud.com>
 *
 * @author Marco Ambrosini <marcoambrosini@icloud.com>
 * @author John Molakvoæ <skjnldsv@protonmail.com>
 *
 * @license AGPL-3.0-or-later
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
 */

import axios from '@nextcloud/axios'

/**
 * Creates a cancelable axios 'request object'.
 *
 * @param {Function} request the axios promise request
 * @return {object}
 */
const CancelableRequest = function (request) {
	/**
	 * Generate an axios cancel token
	 */
	const CancelToken = axios.CancelToken
	const source = CancelToken.source()

	/**
	 * Execute the request
	 *
	 * @param {string} url the url to send the request to
	 * @param {object} [options] optional config for the request
	 */
	const fetch = async function (url, options) {
		return request(
			url,
			Object.assign({ cancelToken: source.token }, { ...options }),
		)
	}
	return {
		request: fetch,
		cancel: source.cancel,
	}
}

export default CancelableRequest
