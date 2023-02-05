<?php

declare(strict_types=1);
/**
 * @copyright Copyright (c) 2023 Ferdinand Thiessen <rpm@fthiessen.de>
 *
 * @author Ferdinand Thiessen <rpm@fthiessen.de>
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
namespace OCA\Forms\Tests\Unit\Controller;

use OCA\Forms\Activity\ActivityManager;
use OCA\Forms\Controller\ApiController;
use OCA\Forms\Db\AnswerMapper;
use OCA\Forms\Db\Form;
use OCA\Forms\Db\FormMapper;
use OCA\Forms\Db\OptionMapper;
use OCA\Forms\Db\QuestionMapper;
use OCA\Forms\Db\ShareMapper;
use OCA\Forms\Db\SubmissionMapper;
use OCA\Forms\Events\FormCreatedEvent;
use OCA\Forms\Service\ConfigService;
use OCA\Forms\Service\FormsService;
use OCA\Forms\Service\SubmissionService;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;

use PHPUnit\Framework\MockObject\MockObject;
use Test\TestCase;

use Psr\Log\LoggerInterface;

class ApiControllerTest extends TestCase {
	private ApiController $apiController;
	/** @var ActivityManager|MockObject */
	private $activityManager;
	/** @var AnswerMapper|MockObject */
	private $answerMapper;
	/** @var FormMapper|MockObject */
	private $formMapper;
	/** @var OptionMapper|MockObject */
	private $optionMapper;
	/** @var QuestionMapper|MockObject */
	private $questionMapper;
	/** @var ShareMapper|MockObject */
	private $shareMapper;
	/** @var SubmissionMapper|MockObject */
	private $submissionMapper;
	/** @var ConfigService|MockObject */
	private $configService;
	/** @var FormsService|MockObject */
	private $formsService;
	/** @var SubmissionService|MockObject */
	private $submissionService;
	/** @var LoggerInterface|MockObject */
	private $logger;
	/** @var IRequest|MockObject */
	private $request;
	/** @var IUserManager|MockObject */
	private $userManager;
	/** @var IEventDispatcher|MockObject */
	private $eventDispatcher;
	/** @var IL10N|MockObject */
	private $l10n;

	public function setUp(): void {
		$this->activityManager = $this->createMock(ActivityManager::class);
		$this->answerMapper = $this->createMock(AnswerMapper::class);
		$this->formMapper = $this->createMock(FormMapper::class);
		$this->optionMapper = $this->createMock(OptionMapper::class);
		$this->questionMapper = $this->createMock(QuestionMapper::class);
		$this->shareMapper = $this->createMock(ShareMapper::class);
		$this->submissionMapper = $this->createMock(SubmissionMapper::class);
		$this->configService = $this->createMock(ConfigService::class);
		$this->formsService = $this->createMock(FormsService::class);
		$this->submissionService = $this->createMock(SubmissionService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->request = $this->createMock(IRequest::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->eventDispatcher = $this->createMock(IEventDispatcher::class);
		$this->l10n = $this->createMock(IL10N::class);
		$this->l10n->expects($this->any())
			->method('t')
			->willReturnCallback(function ($v) {
				return $v;
			});

		$this->apiController = new ApiController(
			'forms',
			$this->activityManager,
			$this->answerMapper,
			$this->formMapper,
			$this->optionMapper,
			$this->questionMapper,
			$this->shareMapper,
			$this->submissionMapper,
			$this->configService,
			$this->formsService,
			$this->submissionService,
			$this->l10n,
			$this->logger,
			$this->request,
			$this->userManager,
			$this->createUserSession(),
			$this->eventDispatcher
		);
	}

	/**
	 * Helper factory to prevent duplicated code
	 */
	protected function createUserSession() {
		$userSession = $this->createMock(IUserSession::class);
		$user = $this->createMock(IUser::class);
		$user->expects($this->any())
			->method('getUID')
			->willReturn('currentUser');
		$userSession->expects($this->once())
			->method('getUser')
			->willReturn($user);
		return $userSession;
	}

	/**
	 * Factory to create a validator used to compare forms passed as parameters
	 * Required as the timestamps might differ
	 */
	public static function createFormValidator(array $expected) {
		return function ($form) use ($expected): bool {
			self::assertInstanceOf(Form::class, $form);
			$read = $form->read();
			unset($read['created']);
			self::assertEquals($expected, $read);
			return true;
		};
	}

	public function testGetSubmissions_invalidForm() {
		$exception = $this->createMock(MapperException::class);
		$this->formMapper->expects($this->once())
			->method('findByHash')
			->with('hash')
			->willThrowException($exception);
		$this->expectException(OCSBadRequestException::class);
		$this->apiController->getSubmissions('hash');
	}

	public function testGetSubmissions_noPermissions() {
		$form = new Form();
		$form->setId(1);
		$form->setHash('hash');
		$form->setOwnerId('currentUser');

		$this->formMapper->expects($this->once())
			->method('findByHash')
			->with('hash')
			->willReturn($form);
	
		$this->formsService->expects(($this->once()))
			->method('canSeeResults')
			->with(1)
			->willReturn(false);

		$this->expectException(OCSForbiddenException::class);
		$this->apiController->getSubmissions('hash');
	}

	public function dataGetSubmissions() {
		return [
			'anon' => [
				'submissions' => [
					['userId' => 'anon-user-1']
				],
				'questions' => ['questions'],
				'expected' => [
					'submissions' => [
						[
							'userId' => 'anon-user-1',
							'userDisplayName' => 'Anonymous response',
						]
					],
					'questions' => ['questions'],
				]
			],
			'user' => [
				'submissions' => [
					['userId' => 'jdoe']
				],
				'questions' => ['questions'],
				'expected' => [
					'submissions' => [
						[
							'userId' => 'jdoe',
							'userDisplayName' => 'jdoe',
						]
					],
					'questions' => ['questions'],
				]
			]
		];
	}

	/**
	 * @dataProvider dataGetSubmissions
	 */
	public function testGetSubmissions(array $submissions, array $questions, array $expected) {
		$form = new Form();
		$form->setId(1);
		$form->setHash('hash');
		$form->setOwnerId('otherUser');

		$this->formMapper->expects($this->once())
			->method('findByHash')
			->with('hash')
			->willReturn($form);
	
		$this->formsService->expects(($this->once()))
			->method('canSeeResults')
			->with(1)
			->willReturn(true);

		$this->submissionService->expects($this->once())
			->method('getSubmissions')
			->with(1)
			->willReturn($submissions);

		$this->formsService->expects($this->once())
			->method('getQuestions')
			->with(1)
			->willReturn($questions);
	
		$this->assertEquals(new DataResponse($expected), $this->apiController->getSubmissions('hash'));
	}

	public function testExportSubmissions_invalidForm() {
		$exception = $this->createMock(MapperException::class);
		$this->formMapper->expects($this->once())
			->method('findByHash')
			->with('hash')
			->willThrowException($exception);
		$this->expectException(OCSBadRequestException::class);
		$this->apiController->exportSubmissions('hash');
	}

	public function testExportSubmissions_noPermissions() {
		$form = new Form();
		$form->setId(1);
		$form->setHash('hash');
		$form->setOwnerId('currentUser');

		$this->formMapper->expects($this->once())
			->method('findByHash')
			->with('hash')
			->willReturn($form);
	
		$this->formsService->expects(($this->once()))
			->method('canSeeResults')
			->with(1)
			->willReturn(false);

		$this->expectException(OCSForbiddenException::class);
		$this->apiController->exportSubmissions('hash');
	}

	public function testExportSubmissions() {
		$form = new Form();
		$form->setId(1);
		$form->setHash('hash');
		$form->setOwnerId('currentUser');

		$this->formMapper->expects($this->once())
			->method('findByHash')
			->with('hash')
			->willReturn($form);
	
		$this->formsService->expects(($this->once()))
			->method('canSeeResults')
			->with(1)
			->willReturn(true);

		$csv = ['data' => '__data__', 'fileName' => 'some.csv'];
		$this->submissionService->expects($this->once())
			->method('getSubmissionsCsv')
			->with('hash')
			->willReturn($csv);

		$this->assertEquals(new DataDownloadResponse($csv['data'], $csv['fileName'], 'text/csv'), $this->apiController->exportSubmissions('hash'));
	}

	public function testCreateNewForm_notAllowed() {
		$this->configService->expects($this->once())
			->method('canCreateForms')
			->willReturn(false);

		$this->expectException(OCSForbiddenException::class);
		$this->apiController->newForm();
	}

	public function dataTestCreateNewForm() {
		return [
			"forms" => ['expectedForm' => [
				'id' => 7,
				'hash' => 'formHash',
				'title' => '',
				'description' => '',
				'ownerId' => 'currentUser',
				'access' => [
					'permitAllUsers' => false,
					'showToAllUsers' => false,
				],
				'expires' => 0,
				'isAnonymous' => false,
				'submitMultiple' => false,
				'showExpiration' => false
			]]
		];
	}
	/**
	 * @dataProvider dataTestCreateNewForm()
	 */
	public function testCreateNewForm($expectedForm) {
		// Create a partial mock, as we only test newForm and not getForm
		/** @var ApiController|MockObject */
		$apiController = $this->getMockBuilder(ApiController::class)
			 ->onlyMethods(['getForm'])
			 ->setConstructorArgs(['forms',
			 	$this->activityManager,
			 	$this->answerMapper,
			 	$this->formMapper,
			 	$this->optionMapper,
			 	$this->questionMapper,
			 	$this->shareMapper,
			 	$this->submissionMapper,
			 	$this->configService,
			 	$this->formsService,
			 	$this->submissionService,
			 	$this->l10n,
			 	$this->logger,
			 	$this->request,
			 	$this->userManager,
			 	$this->createUserSession(),
			 	$this->eventDispatcher
			 ])->getMock();

		$this->configService->expects($this->once())
			->method('canCreateForms')
			->willReturn(true);
		$this->formsService->expects($this->once())
			->method('generateFormHash')
			->willReturn('formHash');
		$expected = $expectedForm;
		$expected['id'] = null;
		$this->formMapper->expects($this->once())
			->method('insert')
			->with(self::callback(self::createFormValidator($expected)))
			->willReturnCallback(function ($form) {
				$form->setId(7);
				return $form;
			});
		$this->eventDispatcher->expects($this->once())
			->method('dispatchTyped')
			->with(self::callback(fn ($event): bool =>
				$event instanceof FormCreatedEvent &&
				$validateForm($expectedForm)($event->getForm())
			));
		$apiController->expects($this->once())
			->method('getForm')
			->with(7)
			->willReturn(new DataResponse('succeeded'));
		$this->assertEquals(new DataResponse('succeeded'), $apiController->newForm());
	}
}
