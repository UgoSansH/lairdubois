<?php

namespace Ladb\CoreBundle\Controller\Workflow;

use Ladb\CoreBundle\Controller\PublicationControllerTrait;
use Ladb\CoreBundle\Utils\CollectionnableUtils;
use Symfony\Component\Routing\Annotation\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Ladb\CoreBundle\Form\Type\Workflow\WorkflowType;
use Ladb\CoreBundle\Utils\FieldPreprocessorUtils;
use Ladb\CoreBundle\Utils\TagUtils;
use Ladb\CoreBundle\Utils\CommentableUtils;
use Ladb\CoreBundle\Utils\FollowerUtils;
use Ladb\CoreBundle\Utils\LikableUtils;
use Ladb\CoreBundle\Utils\SearchUtils;
use Ladb\CoreBundle\Utils\WatchableUtils;
use Ladb\CoreBundle\Entity\Workflow\Workflow;
use Ladb\CoreBundle\Entity\Workflow\Task;
use Ladb\CoreBundle\Event\PublicationEvent;
use Ladb\CoreBundle\Event\PublicationListener;
use Ladb\CoreBundle\Event\PublicationsEvent;
use Ladb\CoreBundle\Manager\Workflow\WorkflowManager;
use Ladb\CoreBundle\Manager\Core\WitnessManager;
use Ladb\CoreBundle\Entity\Howto\Howto;
use Ladb\CoreBundle\Entity\Wonder\Creation;
use Ladb\CoreBundle\Entity\Wonder\Plan;
use Ladb\CoreBundle\Entity\Wonder\Workshop;
use Ladb\CoreBundle\Model\HiddableInterface;
use Ladb\CoreBundle\Utils\PaginatorUtils;

/**
 * @Route("/processus")
 */
class WorkflowController extends AbstractWorkflowBasedController {

	use PublicationControllerTrait;

	/**
	 * @Route("/new", name="core_workflow_new")
	 * @Template("LadbCoreBundle:Workflow:Workflow/new.html.twig")
	 */
	public function newAction(Request $request) {

		$workflow = new Workflow();
		$form = $this->createForm(WorkflowType::class, $workflow);

		$tagUtils = $this->get(TagUtils::NAME);

		return array(
			'form'         => $form->createView(),
			'owner'        => $this->retrieveOwner($request),
			'tagProposals' => $tagUtils->getProposals($workflow),
		);
	}

	/**
	 * @Route("/create", methods={"POST"}, name="core_workflow_create")
	 * @Template("LadbCoreBundle:Workflow:Workflow/new.html.twig")
	 */
	public function createAction(Request $request) {

		$owner = $this->retrieveOwner($request);

		$this->createLock('core_workflow_create', false, self::LOCK_TTL_CREATE_ACTION, false);

		$om = $this->getDoctrine()->getManager();

		$workflow = new Workflow();
		$form = $this->createForm(WorkflowType::class, $workflow);
		$form->handleRequest($request);

		if ($form->isValid()) {

			$fieldPreprocessorUtils = $this->get(FieldPreprocessorUtils::NAME);
			$fieldPreprocessorUtils->preprocessFields($workflow);

			$workflow->setUser($owner);
			$owner->getMeta()->incrementPrivateWorkflowCount();

			// Append a default root task
			$task = new Task();
			$task->setTitle('Tâche 1 : Changer le monde');
			$task->setStatus(Task::STATUS_WORKABLE);
			$workflow->addTask($task);
			$workflow->incrementTaskCount();

			$om->persist($workflow);
			$om->flush();

			// Dispatch publication event
			$dispatcher = $this->get('event_dispatcher');
			$dispatcher->dispatch(PublicationListener::PUBLICATION_CREATED, new PublicationEvent($workflow));

			return $this->redirect($this->generateUrl('core_workflow_show', array( 'id' => $workflow->getId(), 'layout' => 'workspace' )));
		}

		// Flashbag
		$this->get('session')->getFlashBag()->add('error', $this->get('translator')->trans('default.form.alert.error'));

		$tagUtils = $this->get(TagUtils::NAME);

		return array(
			'workflow'     => $workflow,
			'form'         => $form->createView(),
			'owner'        => $owner,
			'tagProposals' => $tagUtils->getProposals($workflow),
		);
	}

	/**
	 * @Route("/{id}/lock", requirements={"id" = "\d+"}, defaults={"lock" = true}, name="core_workflow_lock")
	 * @Route("/{id}/unlock", requirements={"id" = "\d+"}, defaults={"lock" = false}, name="core_workflow_unlock")
	 */
	public function lockUnlockAction($id, $lock) {

		$workflow = $this->retrievePublication($id, Workflow::CLASS_NAME);
		$this->assertLockUnlockable($workflow, $lock);

		// Lock or Unlock
		$workflowManager = $this->get(WorkflowManager::NAME);
		if ($lock) {
			$workflowManager->lock($workflow);
		} else {
			$workflowManager->unlock($workflow);
		}

		// Flashbag
		$this->get('session')->getFlashBag()->add('success', $this->get('translator')->trans('workflow.workflow.form.alert.'.($lock ? 'lock' : 'unlock').'_success', array( '%title%' => $workflow->getTitle() )));

		return $this->redirect($this->generateUrl('core_workflow_show', array( 'id' => $workflow->getSluggedId() )));
	}

	/**
	 * @Route("/{id}/publish", requirements={"id" = "\d+"}, name="core_workflow_publish")
	 */
	public function publishAction($id) {

		$workflow = $this->retrievePublication($id, Workflow::CLASS_NAME);
		$this->assertPublishable($workflow);

		// Publish
		$workflowManager = $this->get(WorkflowManager::NAME);
		$workflowManager->publish($workflow);

		// Flashbag
		$this->get('session')->getFlashBag()->add('success', $this->get('translator')->trans('workflow.workflow.form.alert.publish_success', array( '%title%' => $workflow->getTitle() )));

		return $this->redirect($this->generateUrl('core_workflow_show', array( 'id' => $workflow->getSluggedId() )));
	}

	/**
	 * @Route("/{id}/unpublish", requirements={"id" = "\d+"}, name="core_workflow_unpublish")
	 */
	public function unpublishAction(Request $request, $id) {

		$workflow = $this->retrievePublication($id, Workflow::CLASS_NAME);
		$this->assertUnpublishable($workflow);

		// Unpublish
		$workflowManager = $this->get(WorkflowManager::NAME);
		$workflowManager->unpublish($workflow);

		// Flashbag
		$this->get('session')->getFlashBag()->add('success', $this->get('translator')->trans('workflow.workflow.form.alert.unpublish_success', array( '%title%' => $workflow->getTitle() )));

		// Return to
		$returnToUrl = $request->get('rtu');
		if (is_null($returnToUrl)) {
			$returnToUrl = $request->headers->get('referer');
		}

		return $this->redirect($returnToUrl);
	}

	/**
	 * @Route("/{id}/edit", requirements={"id" = "\d+"}, name="core_workflow_edit")
	 * @Template("LadbCoreBundle:Workflow:Workflow/edit.html.twig")
	 */
	public function editAction($id) {

		$workflow = $this->retrievePublication($id, Workflow::CLASS_NAME);
		$this->assertEditabable($workflow);

		$form = $this->createForm(WorkflowType::class, $workflow);

		$tagUtils = $this->get(TagUtils::NAME);

		return array(
			'workflow'     => $workflow,
			'form'         => $form->createView(),
			'tagProposals' => $tagUtils->getProposals($workflow),
		);
	}

	/**
	 * @Route("/{id}/update", requirements={"id" = "\d+"}, methods={"POST"}, name="core_workflow_update")
	 * @Template("LadbCoreBundle:Workflow:Workflow/edit.html.twig")
	 */
	public function updateAction(Request $request, $id) {

		$workflow = $this->retrievePublication($id, Workflow::CLASS_NAME);
		$this->assertEditabable($workflow);

		$previouslyUsedTags = $workflow->getTags()->toArray();	// Need to be an array to copy values

		$form = $this->createForm(WorkflowType::class, $workflow);
		$form->handleRequest($request);

		if ($form->isValid()) {

			$fieldPreprocessorUtils = $this->get(FieldPreprocessorUtils::NAME);
			$fieldPreprocessorUtils->preprocessFields($workflow);

			if ($workflow->getUser() == $this->getUser()) {
				$workflow->setUpdatedAt(new \DateTime());
			}

			$om = $this->getDoctrine()->getManager();
			$om->flush();

			// Dispatch publication event
			$dispatcher = $this->get('event_dispatcher');
			$dispatcher->dispatch(PublicationListener::PUBLICATION_UPDATED, new PublicationEvent($workflow, array( 'previouslyUsedTags' => $previouslyUsedTags )));

			// Flashbag
			$this->get('session')->getFlashBag()->add('success', $this->get('translator')->trans('workflow.workflow.form.alert.update_success', array( '%title%' => $workflow->getTitle() )));

			// Regenerate the form
			$form = $this->createForm(WorkflowType::class, $workflow);

		} else {

			// Flashbag
			$this->get('session')->getFlashBag()->add('error', $this->get('translator')->trans('default.form.alert.error'));

		}

		$tagUtils = $this->get(TagUtils::NAME);

		return array(
			'workflow'     => $workflow,
			'form'         => $form->createView(),
			'tagProposals' => $tagUtils->getProposals($workflow),
		);
	}

	/**
	 * @Route("/{id}/delete", requirements={"id" = "\d+"}, name="core_workflow_delete")
	 */
	public function deleteAction($id) {

		$workflow = $this->retrievePublication($id, Workflow::CLASS_NAME);
		$this->assertDeletable($workflow);

		// Delete
		$workflowManager = $this->get(WorkflowManager::NAME);
		$workflowManager->delete($workflow);

		// Flashbag
		$this->get('session')->getFlashBag()->add('success', $this->get('translator')->trans('workflow.workflow.form.alert.delete_success', array( '%title%' => $workflow->getTitle() )));

		return $this->redirect($this->generateUrl('core_workflow_list'));
	}

	/**
	 * @Route("/{id}/chown", requirements={"id" = "\d+"}, name="core_workflow_chown")
	 */
	public function chownAction(Request $request, $id) {

		$workflow = $this->retrievePublication($id, Workshop::CLASS_NAME);
		$this->assertChownable($workflow);

		$targetUser = $this->retrieveOwner($request);

		// Change owner
		$workflowManager = $this->get(WorkflowManager::NAME);
		$workflowManager->changeOwner($workflow, $targetUser);

		// Flashbag
		$this->get('session')->getFlashBag()->add('success', $this->get('translator')->trans('workflow.workflow.form.alert.chown_success', array( '%title%' => $workflow->getTitle() )));

		return $this->redirect($this->generateUrl('core_workflow_show', array( 'id' => $workflow->getSluggedId() )));
	}

	/**
	 * @Route("/{id}/widget", requirements={"id" = "\d+"}, name="core_workflow_widget")
	 * @Template("LadbCoreBundle:Workflow/Workflow:widget-xhr.html.twig")
	 */
	public function widgetAction($id) {

		$workflow = $this->retrievePublication($id, Workflow::CLASS_NAME);
		$this->assertShowable($workflow, true);

		return array(
			'workflow' => $workflow,
		);
	}

	/**
	 * @Route("/{id}/pas-a-pas", requirements={"id" = "\d+"}, name="core_workflow_howtos")
	 * @Route("/{id}/pas-a-pas/{filter}", requirements={"id" = "\d+", "filter" = "[a-z-]+"}, name="core_workflow_howtos_filter")
	 * @Route("/{id}/pas-a-pas/{filter}/{page}", requirements={"id" = "\d+", "filter" = "[a-z-]+", "page" = "\d+"}, name="core_workflow_howtos_filter_page")
	 * @Template("LadbCoreBundle:Workflow:Workflow/howtos.html.twig")
	 */
	public function howtosAction(Request $request, $id, $filter = "recent", $page = 0) {
		$om = $this->getDoctrine()->getManager();

		$workflow = $this->retrievePublication($id, Workflow::CLASS_NAME);
		$this->assertShowable($workflow);

		// Howtos

		$howtoRepository = $om->getRepository(Howto::CLASS_NAME);
		$paginatorUtils = $this->get(PaginatorUtils::NAME);

		$offset = $paginatorUtils->computePaginatorOffset($page);
		$limit = $paginatorUtils->computePaginatorLimit($page);
		$paginator = $howtoRepository->findPaginedByWorkflow($workflow, $offset, $limit, $filter);
		$pageUrls = $paginatorUtils->generatePrevAndNextPageUrl('core_workflow_howtos_filter_page', array( 'id' => $id, 'filter' => $filter ), $page, $paginator->count());

		$parameters = array(
			'filter'      => $filter,
			'prevPageUrl' => $pageUrls->prev,
			'nextPageUrl' => $pageUrls->next,
			'howtos'      => $paginator,
		);

		if ($request->isXmlHttpRequest()) {
			return $this->render('LadbCoreBundle:Howto/Howto:list-xhr.html.twig', $parameters);
		}

		return array_merge($parameters, array(
			'workflow' => $workflow,
		));
	}

	/**
	 * @Route("/{id}/creations", requirements={"id" = "\d+"}, name="core_workflow_creations")
	 * @Route("/{id}/creations/{filter}", requirements={"id" = "\d+", "filter" = "[a-z-]+"}, name="core_workflow_creations_filter")
	 * @Route("/{id}/creations/{filter}/{page}", requirements={"id" = "\d+", "filter" = "[a-z-]+", "page" = "\d+"}, name="core_workflow_creations_filter_page")
	 * @Template("LadbCoreBundle:Workflow:Workflow/creations.html.twig")
	 */
	public function creationsAction(Request $request, $id, $filter = "recent", $page = 0) {
		$om = $this->getDoctrine()->getManager();

		$workflow = $this->retrievePublication($id, Workflow::CLASS_NAME);
		$this->assertShowable($workflow);

		// Creations

		$creationRepository = $om->getRepository(Creation::CLASS_NAME);
		$paginatorUtils = $this->get(PaginatorUtils::NAME);

		$offset = $paginatorUtils->computePaginatorOffset($page);
		$limit = $paginatorUtils->computePaginatorLimit($page);
		$paginator = $creationRepository->findPaginedByWorkflow($workflow, $offset, $limit, $filter);
		$pageUrls = $paginatorUtils->generatePrevAndNextPageUrl('core_workflow_creations_filter_page', array( 'id' => $id, 'filter' => $filter ), $page, $paginator->count());

		$parameters = array(
			'filter'      => $filter,
			'prevPageUrl' => $pageUrls->prev,
			'nextPageUrl' => $pageUrls->next,
			'creations'   => $paginator,
		);

		if ($request->isXmlHttpRequest()) {
			return $this->render('LadbCoreBundle:Wonder/Creation:list-xhr.html.twig', $parameters);
		}

		return array_merge($parameters, array(
			'workflow' => $workflow,
		));
	}

	/**
	 * @Route("/{id}/plans", requirements={"id" = "\d+"}, name="core_workflow_plans")
	 * @Route("/{id}/plans/{filter}", requirements={"id" = "\d+", "filter" = "[a-z-]+"}, name="core_workflow_plans_filter")
	 * @Route("/{id}/plans/{filter}/{page}", requirements={"id" = "\d+", "filter" = "[a-z-]+", "page" = "\d+"}, name="core_workflow_plans_filter_page")
	 * @Template("LadbCoreBundle:Workflow:Workflow/plans.html.twig")
	 */
	public function plansAction(Request $request, $id, $filter = "recent", $page = 0) {
		$om = $this->getDoctrine()->getManager();

		$workflow = $this->retrievePublication($id, Workflow::CLASS_NAME);
		$this->assertShowable($workflow);

		// Plans

		$planRepository = $om->getRepository(Plan::CLASS_NAME);
		$paginatorUtils = $this->get(PaginatorUtils::NAME);

		$offset = $paginatorUtils->computePaginatorOffset($page);
		$limit = $paginatorUtils->computePaginatorLimit($page);
		$paginator = $planRepository->findPaginedByWorkflow($workflow, $offset, $limit, $filter);
		$pageUrls = $paginatorUtils->generatePrevAndNextPageUrl('core_workflow_plans_filter_page', array( 'id' => $id, 'filter' => $filter ), $page, $paginator->count());

		$parameters = array(
			'filter'      => $filter,
			'prevPageUrl' => $pageUrls->prev,
			'nextPageUrl' => $pageUrls->next,
			'plans'       => $paginator,
		);

		if ($request->isXmlHttpRequest()) {
			return $this->render('LadbCoreBundle:Wonder/Plan:list-xhr.html.twig', $parameters);
		}

		return array_merge($parameters, array(
			'workflow' => $workflow,
		));
	}

	/**
	 * @Route("/{id}/ateliers", requirements={"id" = "\d+"}, name="core_workflow_workshops")
	 * @Route("/{id}/ateliers/{filter}", requirements={"id" = "\d+", "filter" = "[a-z-]+"}, name="core_workflow_workshops_filter")
	 * @Route("/{id}/ateliers/{filter}/{page}", requirements={"id" = "\d+", "filter" = "[a-z-]+", "page" = "\d+"}, name="core_workflow_workshops_filter_page")
	 * @Template("LadbCoreBundle:Workflow:Workflow/workshops.html.twig")
	 */
	public function workshopsAction(Request $request, $id, $filter = "recent", $page = 0) {
		$om = $this->getDoctrine()->getManager();

		$workflow = $this->retrievePublication($id, Workflow::CLASS_NAME);
		$this->assertShowable($workflow);

		// Workshops

		$workshopRepository = $om->getRepository(Workshop::CLASS_NAME);
		$paginatorUtils = $this->get(PaginatorUtils::NAME);

		$offset = $paginatorUtils->computePaginatorOffset($page);
		$limit = $paginatorUtils->computePaginatorLimit($page);
		$paginator = $workshopRepository->findPaginedByWorkflow($workflow, $offset, $limit, $filter);
		$pageUrls = $paginatorUtils->generatePrevAndNextPageUrl('core_workflow_workshops_filter_page', array( 'id' => $id, 'filter' => $filter ), $page, $paginator->count());

		$parameters = array(
			'filter'      => $filter,
			'prevPageUrl' => $pageUrls->prev,
			'nextPageUrl' => $pageUrls->next,
			'workshops'   => $paginator,
		);

		if ($request->isXmlHttpRequest()) {
			return $this->render('LadbCoreBundle:Wonder/Workshop:list-xhr.html.twig', $parameters);
		}

		return array_merge($parameters, array(
			'workflow' => $workflow,
		));
	}

	/**
	 * @Route("/{id}/inspirations", requirements={"id" = "\d+"}, name="core_workflow_inspirations")
	 * @Route("/{id}/inspirations/{filter}", requirements={"id" = "\d+", "filter" = "[a-z-]+"}, name="core_workflow_inspirations_filter")
	 * @Route("/{id}/inspirations/{filter}/{page}", requirements={"id" = "\d+", "filter" = "[a-z-]+", "page" = "\d+"}, name="core_workflow_inspirations_filter_page")
	 * @Template("LadbCoreBundle:Workflow:Workflow/inspirations.html.twig")
	 */
	public function inspirationsAction(Request $request, $id, $filter = "recent", $page = 0) {
		$om = $this->getDoctrine()->getManager();
		$workflowRepository = $om->getRepository(Workflow::CLASS_NAME);

		$workflow = $this->retrievePublication($id, Workflow::CLASS_NAME);
		$this->assertShowable($workflow);

		// Inspirations

		$paginatorUtils = $this->get(PaginatorUtils::NAME);

		$offset = $paginatorUtils->computePaginatorOffset($page);
		$limit = $paginatorUtils->computePaginatorLimit($page);
		$paginator = $workflowRepository->findPaginedByRebound($workflow, $offset, $limit, $filter);
		$pageUrls = $paginatorUtils->generatePrevAndNextPageUrl('core_workflow_inspirations_filter_page', array( 'id' => $id, 'filter' => $filter ), $page, $paginator->count());

		$parameters = array(
			'filter'      => $filter,
			'prevPageUrl' => $pageUrls->prev,
			'nextPageUrl' => $pageUrls->next,
			'workflows'   => $paginator,
		);

		if ($request->isXmlHttpRequest()) {
			return $this->render('LadbCoreBundle:Workflow:Workflow/list-xhr.html.twig', $parameters);
		}

		return array_merge($parameters, array(
			'workflow' => $workflow,
		));
	}

	/**
	 * @Route("/{id}/rebonds", requirements={"id" = "\d+"}, name="core_workflow_rebounds")
	 * @Route("/{id}/rebonds/{filter}", requirements={"id" = "\d+", "filter" = "[a-z-]+"}, name="core_workflow_rebounds_filter")
	 * @Route("/{id}/rebonds/{filter}/{page}", requirements={"id" = "\d+", "filter" = "[a-z-]+", "page" = "\d+"}, name="core_workflow_rebounds_filter_page")
	 * @Template("LadbCoreBundle:Workflow:Workflow/rebounds.html.twig")
	 */
	public function reboundsAction(Request $request, $id, $filter = "recent", $page = 0) {
		$om = $this->getDoctrine()->getManager();
		$workflowRepository = $om->getRepository(Workflow::CLASS_NAME);

		$workflow = $this->retrievePublication($id, Workflow::CLASS_NAME);
		$this->assertShowable($workflow);

		// Rebounds

		$paginatorUtils = $this->get(PaginatorUtils::NAME);

		$offset = $paginatorUtils->computePaginatorOffset($page);
		$limit = $paginatorUtils->computePaginatorLimit($page);
		$paginator = $workflowRepository->findPaginedByInspiration($workflow, $offset, $limit, $filter);
		$pageUrls = $paginatorUtils->generatePrevAndNextPageUrl('core_workflow_rebounds_filter_page', array( 'id' => $id, 'filter' => $filter ), $page, $paginator->count());

		$parameters = array(
			'filter'      => $filter,
			'prevPageUrl' => $pageUrls->prev,
			'nextPageUrl' => $pageUrls->next,
			'workflows'   => $paginator,
		);

		if ($request->isXmlHttpRequest()) {
			return $this->render('LadbCoreBundle:Workflow:Workflow/list-xhr.html.twig', $parameters);
		}

		return array_merge($parameters, array(
			'workflow' => $workflow,
		));
	}

	/**
	 * @Route("/{id}/restart_confirm", requirements={"id" = "\d+"}, name="core_workflow_restart_confirm")
	 * @Template("LadbCoreBundle:Workflow:Workflow/restart-confirm-xhr.html.twig")
	 */
	public function restartConfirmAction(Request $request, $id) {

		$workflow = $this->retrievePublication($id, Workflow::CLASS_NAME);
		$this->assertShowable($workflow);

		return array(
			'workflow' => $workflow,
		);
	}

	/**
	 * @Route("/{id}/restart", requirements={"id" = "\d+"}, name="core_workflow_restart")
	 */
	public function restartAction($id) {

		$workflow = $this->retrievePublication($id, Workflow::CLASS_NAME);
		$this->assertShowable($workflow);

		// Restart
		$workflowManager = $this->get(WorkflowManager::NAME);
		$workflowManager->restart($workflow, $this->getUser());

		$this->_push($workflow, array(
			'workflowInfos'    => $this->_generateWorkflowInfos($workflow),
			'updatedTaskInfos' => $this->_generateTaskInfos($workflow->getTasks(), self::TASKINFO_STATUS | self::TASKINFO_BOX),
		));

		return new Response();
	}

	/**
	 * @Route("/{id}/copy", requirements={"id" = "\d+"}, name="core_workflow_copy")
	 */
	public function copyAction($id) {

		$workflow = $this->retrievePublication($id, Workflow::CLASS_NAME);
		$this->assertShowable($workflow);

		// Copy
		$workflowManager = $this->get(WorkflowManager::NAME);
		$newWorkflow = $workflowManager->copy($workflow, $this->getUser());

		// Flashbag
		$this->get('session')->getFlashBag()->add('success', $this->get('translator')->trans('workflow.workflow.form.alert.copy_success', array( '%title%' => $workflow->getTitle() )));

		return $this->redirect($this->generateUrl('core_workflow_show', array( 'id' => $newWorkflow->getSluggedId() )));
	}

	/**
	 * @Route("/{id}/statistics", requirements={"id" = "\d+"}, name="core_workflow_statistics")
	 * @Template("LadbCoreBundle:Workflow:Workflow/statistics-xhr.html.twig")
	 */
	public function statisticsAction(Request $request, $id) {
		$om = $this->getDoctrine()->getManager();
		$taskRepository = $om->getRepository(Task::CLASS_NAME);

		$workflow = $this->retrievePublication($id, Workflow::CLASS_NAME);
		$this->assertShowable($workflow);

		$dataDurationsPerLabel = array();
		foreach ($workflow->getLabels() as $label) {
			$tasks = $taskRepository->findByLabel($label);
			$duration = 0;
			foreach ($tasks as $task) {
				$duration += $task->getDuration();
			}
			$dataDurationsPerLabel[] = array(
				'name'     => $label->getName(),
				'color'    => $label->getColor(),
				'duration' => floor($duration / 60),
			);
		}

		return array(
			'workflow'              => $workflow,
			'dataDurationsPerLabel' => $dataDurationsPerLabel,
		);
	}

	/**
	 * @Route("/{id}.html", name="core_workflow_show")
	 * @Template("LadbCoreBundle:Workflow:Workflow/show.html.twig")
	 */
	public function showAction(Request $request, $id) {
		$witnessManager = $this->get(WitnessManager::NAME);

		$layout = $request->get('layout', 'page');

		$workflow = $this->retrievePublication($id, Workflow::CLASS_NAME);
		$this->assertShowable($workflow);

		// Dispatch publication event
		$dispatcher = $this->get('event_dispatcher');
		$dispatcher->dispatch(PublicationListener::PUBLICATION_SHOWN, new PublicationEvent($workflow));

		$followerUtils = $this->get(FollowerUtils::NAME);
		$likableUtils = $this->get(LikableUtils::NAME);

		$parameters = array(
			'workflow'          => $workflow,
			'permissionContext' => $this->getPermissionContext($workflow),
			'readOnly'          => !$this->get('security.authorization_checker')->isGranted('ROLE_ADMIN') && $workflow->getUser() != $this->getUser(),
			'durationsHidden'   => !$this->get('security.authorization_checker')->isGranted('ROLE_ADMIN') && $workflow->getUser() != $this->getUser(),
			'followerContext'   => $followerUtils->getFollowerContext($workflow->getUser(), $this->getUser()),
			'likeContext'       => $likableUtils->getLikeContext($workflow, $this->getUser()),
		);

		if ($layout == 'workspace') {

			// TODO switch layout from workspace to page if referrer is not LADB server

			// Exclude bots
			$isBot = preg_match('/bot|spider|crawler|curl|facebookexternalhit|^$/i', $_SERVER['HTTP_USER_AGENT']);

			if (!$isBot) {
				return $this->render('LadbCoreBundle:Workflow:Workflow/show-workspace.html.twig', $parameters);
			}

		}

		$watchableUtils = $this->get(WatchableUtils::NAME);
		$commentableUtils = $this->get(CommentableUtils::NAME);
		$collectionnableUtils = $this->get(CollectionnableUtils::NAME);

		$parameters = array_merge($parameters, array(
			'watchContext'      => $watchableUtils->getWatchContext($workflow, $this->getUser()),
			'commentContext'    => $commentableUtils->getCommentContext($workflow),
			'collectionContext' => $collectionnableUtils->getCollectionContext($workflow),
		));

		return $parameters;
	}

	/**
	 * @Route("/", name="core_workflow_list")
	 * @Route("/{page}", requirements={"page" = "\d+"}, name="core_workflow_list_page")
	 * @Template("LadbCoreBundle:Workflow:Workflow/list.html.twig")
	 */
	public function listAction(Request $request, $page = 0) {
		$searchUtils = $this->get(SearchUtils::NAME);

		// Elasticsearch paginiation limit
		if ($page > 624) {
			throw $this->createNotFoundException('Page limit reached (core_workflow_list_page)');
		}

		$layout = $request->get('layout', 'view');

		$routeParameters = array();
		if ($layout != 'view') {
			$routeParameters['layout'] = $layout;
		}

		$searchParameters = $searchUtils->searchPaginedEntities(
			$request,
			$page,
			function($facet, &$filters, &$sort, &$noGlobalFilters, &$couldUseDefaultSort) use ($searchUtils) {
				switch ($facet->name) {

					// Filters /////

					case 'admin-all':
						if ($this->get('security.authorization_checker')->isGranted('ROLE_ADMIN')) {

							$filters[] = new \Elastica\Query\MatchAll();

							$sort = array( 'changedAt' => array( 'order' => 'desc' ) );

							$noGlobalFilters = true;
						}
						break;

					case 'mine':

						if ($this->get('security.authorization_checker')->isGranted('ROLE_USER')) {

							$filter = new \Elastica\Query\MatchPhrase('user.username', $this->getUser()->getUsernameCanonical());
							$filters[] = $filter;

							$couldUseDefaultSort = true;

						}

						break;

					case 'period':

						if ($facet->value == 'last7days') {

							$filters[] = new \Elastica\Query\Range('createdAt', array( 'gte' => 'now-7d/d' ));

						} elseif ($facet->value == 'last30days') {

							$filters[] = new \Elastica\Query\Range('createdAt', array( 'gte' => 'now-30d/d' ));

						}

						break;

					case 'tag':

						$filter = new \Elastica\Query\QueryString($facet->value);
						$filter->setFields(array( 'tags.label' ));
						$filters[] = $filter;

						break;

					case 'author':

						$filter = new \Elastica\Query\QueryString($facet->value);
						$filter->setFields(array( 'user.displayname', 'user.fullname', 'user.username'  ));
						$filters[] = $filter;

						break;

					case 'license':

						$filter = new \Elastica\Query\Term([ 'license.strippedname' => [ 'value' => $facet->value, 'boost' => 1.0 ] ]);
						$filters[] = $filter;

						break;

					case 'content-creations':

						$filter = new \Elastica\Query\Range('creationCount', array( 'gte' => 1 ));
						$filters[] = $filter;

						break;

					case 'content-plans':

						$filter = new \Elastica\Query\Range('planCount', array( 'gte' => 1 ));
						$filters[] = $filter;

						break;

					case 'content-workshops':

						$filter = new \Elastica\Query\Range('workshopCount', array( 'gte' => 1 ));
						$filters[] = $filter;

						break;

					case 'content-howtos':

						$filter = new \Elastica\Query\Range('howtoCount', array( 'gte' => 1 ));
						$filters[] = $filter;

						break;

					// Sorters /////

					case 'sort-recent':
						$sort = array( 'changedAt' => array( 'order' => $searchUtils->getSorterOrder($facet) ) );
						break;

					case 'sort-popular-views':
						$sort = array( 'viewCount' => array( 'order' => $searchUtils->getSorterOrder($facet) ) );
						break;

					case 'sort-popular-likes':
						$sort = array( 'likeCount' => array( 'order' => $searchUtils->getSorterOrder($facet) ) );
						break;

					case 'sort-popular-comments':
						$sort = array( 'commentCount' => array( 'order' => $searchUtils->getSorterOrder($facet) ) );
						break;

					case 'sort-popular-copies':
						$sort = array( 'copyCount' => array( 'order' => $searchUtils->getSorterOrder($facet) ) );
						break;

					case 'sort-random':
						$sort = array( 'randomSeed' => isset($facet->value) ? $facet->value : '' );
						break;

					/////

					default:
						if (is_null($facet->name)) {

							$filter = new \Elastica\Query\QueryString($facet->value);
							$filter->setFields(array( 'title^100', 'body', 'tags.label' ));
							$filters[] = $filter;

							$couldUseDefaultSort = false;

						}

				}
			},
			function(&$filters, &$sort) {

				$sort = array( 'changedAt' => array( 'order' => 'desc' ) );

			},
			function(&$filters) use ($layout) {

				$this->pushGlobalVisibilityFilter($filters, $layout != 'choice', true);

			},
			'fos_elastica.index.ladb.workflow_workflow',
			\Ladb\CoreBundle\Entity\Workflow\Workflow::CLASS_NAME,
			'core_workflow_list_page',
			$routeParameters
		);

		// Dispatch publication event
		$dispatcher = $this->get('event_dispatcher');
		$dispatcher->dispatch(PublicationListener::PUBLICATIONS_LISTED, new PublicationsEvent($searchParameters['entities'], !$request->isXmlHttpRequest()));

		$parameters = array_merge($searchParameters, array(
			'workflows'       => $searchParameters['entities'],
			'layout'          => $layout,
			'routeParameters' => $routeParameters,
		));

		if ($request->isXmlHttpRequest()) {
			if ($layout == 'choice') {
				return $this->render('LadbCoreBundle:Workflow:Workflow/list-choice-xhr.html.twig', $parameters);
			} else {
				return $this->render('LadbCoreBundle:Workflow:Workflow/list-xhr.html.twig', $parameters);
			}
		}

		if ($layout == 'choice') {
			return $this->render('LadbCoreBundle:Workflow:Workflow/list-choice.html.twig', $parameters);
		}

		return $parameters;
	}

	/**
	 * @Route("/{id}/internal/diagram", name="core_workflow_internal_diagram")
	 * @Template("LadbCoreBundle:Workflow:Workflow/diagram.html.twig")
	 */
	public function internalDiagramAction($id) {

		// Retrieve workflow
		$workflow = $this->_retrieveWorkflow($id);

		return array(
			'workflow' => $workflow,
		);
	}

}
