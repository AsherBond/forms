<?php
/**
 * @copyright Copyright (c) 2017 Vinzenz Rosenkranz <vinzenz.rosenkranz@gmail.com>
 *
 * @author affan98 <affan98@gmail.com>
 * @author John Molakvoæ (skjnldsv) <skjnldsv@protonmail.com>
 * @author Jonas Rittershofer <jotoeri@users.noreply.github.com>
 * @author Roeland Jago Douma <roeland@famdouma.nl>
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
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\Forms\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Share\IShare;

/**
 * @extends QBMapper<Form>
 */
class FormMapper extends QBMapper {
	/** @var QuestionMapper */
	private $questionMapper;

	/** @var ShareMapper */
	private $shareMapper;

	/** @var SubmissionMapper */
	private $submissionMapper;

	/**
	 * FormMapper constructor.
	 *
	 * @param IDBConnection $db
	 */
	public function __construct(QuestionMapper $questionMapper,
		ShareMapper $shareMapper,
		SubmissionMapper $submissionMapper,
		IDBConnection $db) {
		parent::__construct($db, 'forms_v2_forms', Form::class);
		$this->questionMapper = $questionMapper;
		$this->shareMapper = $shareMapper;
		$this->submissionMapper = $submissionMapper;
	}

	/**
	 * @param int $id
	 * @return Form
	 * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException if more than one result
	 * @throws \OCP\AppFramework\Db\DoesNotExistException if not found
	 */
	public function findById(int $id): Form {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where(
				$qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT))
			);

		return $this->findEntity($qb);
	}

	/**
	 * @param string $hash
	 * @return Form
	 * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException if more than one result
	 * @throws \OCP\AppFramework\Db\DoesNotExistException if not found
	 */
	public function findByHash(string $hash): Form {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where(
				$qb->expr()->eq('hash', $qb->createNamedParameter($hash, IQueryBuilder::PARAM_STR))
			);

		return $this->findEntity($qb);
	}

	/**
	 * Get public shared forms (shared to all users)
	 * @param string $userId User ID to filter forms owned by the user
	 * @param bool $filterShown Set to false to also include forms shared but not visible on sidebar
	 * @return Form[]
	 */
	public function findPublicForms(string $userId, bool $filterShown = true): array {
		$qb = $this->db->getQueryBuilder();

		if ($filterShown) {
			$access = $qb->expr()->like('access_json', $qb->createNamedParameter('%"showToAllUsers":true%'));
		} else {
			$access = $qb->expr()->like('access_json', $qb->createNamedParameter('%"permitAllUsers":true%'));
		}

		$qb->select('*')
			->from($this->getTableName())
			// permitted access
			->where($access)
			// ensure not to include owned forms
			->andWhere($qb->expr()->neq('owner_id', $qb->createNamedParameter($userId)))
			//Last updated forms first, then newest forms first
			->addOrderBy('last_updated', 'DESC')
			->addOrderBy('created', 'DESC');

		return $this->findEntities($qb);
	}

	/**
	 * Get forms shared with the user
	 * @param string $userId The user ID
	 * @param string[] $groups IDs of groups the user is memeber of
	 * @param string[] $teams IDs of teams the user is memeber of
	 * @return Form[]
	 */
	public function findSharedForms(string $userId, array $groups = [], array $teams = []): array {
		$qb = $this->db->getQueryBuilder();

		$memberships = $qb->expr()->orX();
		// share type user and share with current user
		$memberships->add(
			$qb->expr()->andX(
				$qb->expr()->eq('shares.share_type', $qb->createNamedParameter(IShare::TYPE_USER)),
				$qb->expr()->eq('shares.share_with', $qb->createNamedParameter($userId)),
			),
		);
		// share type group and one of the user groups
		if (!empty($groups)) {
			$memberships->add(
				$qb->expr()->andX(
					$qb->expr()->eq('shares.share_type', $qb->createNamedParameter(IShare::TYPE_GROUP)),
					$qb->expr()->in('shares.share_with', $groups),
				),
			);
		}
		// share type team and one of the user teams
		if (!empty($teams)) {
			$memberships->add(
				$qb->expr()->andX(
					$qb->expr()->eq('shares.share_type', $qb->createNamedParameter(IShare::TYPE_CIRCLE)),
					$qb->expr()->in('shares.share_with', $teams),
				),
			);
		}

		$qb->select('forms.*')
			->from($this->getTableName(), 'forms')
			->innerJoin('forms', 'forms_v2_shares', 'shares', $qb->expr()->eq('forms.id', 'shares.form_id'))
			// user is memeber of
			->where($memberships)
			// ensure not to include owned forms
			->andWhere($qb->expr()->neq('forms.owner_id', $qb->createNamedParameter($userId)))
			//Last updated forms first, then newest forms first
			->addOrderBy('forms.last_updated', 'DESC')
			->addOrderBy('forms.created', 'DESC');

		return $this->findEntities($qb);
	}

	/**
	 * @return Form[]
	 */
	public function findAllByOwnerId(string $ownerId): array {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where(
				$qb->expr()->eq('owner_id', $qb->createNamedParameter($ownerId))
			)
			//Last updated forms first, then newest forms first
			->addOrderBy('last_updated', 'DESC')
			->addOrderBy('created', 'DESC');

		return $this->findEntities($qb);
	}

	/**
	 * Delete a Form including connected Questions, Submissions and shares.
	 * @param Form $form The form instance to delete
	 */
	public function deleteForm(Form $form): void {
		// Delete Submissions(incl. Answers), Questions(incl. Options), Shares and Form.
		$this->submissionMapper->deleteByForm($form->getId());
		$this->shareMapper->deleteByForm($form->getId());
		$this->questionMapper->deleteByForm($form->getId());
		$this->delete($form);
	}
}
