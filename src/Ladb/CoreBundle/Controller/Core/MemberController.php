<?php

namespace Ladb\CoreBundle\Controller\Core;

use Ladb\CoreBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\Routing\Annotation\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Ladb\CoreBundle\Entity\Core\Member;
use Ladb\CoreBundle\Entity\Core\User;
use Ladb\CoreBundle\Utils\MailerUtils;
use Ladb\CoreBundle\Utils\MemberUtils;
use Ladb\CoreBundle\Utils\ActivityUtils;

/**
 * @Route("/members")
 */
class MemberController extends AbstractController {

	/**
	 * @Route("/{teamId}/create", requirements={"teamId" = "\d+"}, name="core_member_create")
	 */
	public function createAction(Request $request, $teamId) {

		$this->createLock('core_member_create', false, self::LOCK_TTL_CREATE_ACTION, false);

		$om = $this->getDoctrine()->getManager();
		$userRepository = $om->getRepository(User::CLASS_NAME);

		// Check related user

		$team = $userRepository->findOneById($teamId);
		if (is_null($team)) {
			throw $this->createNotFoundException('Unable to find Team entity (id='.$teamId.').');
		}
		if (!$team->isEnabled()) {
			throw $this->createNotFoundException('Team not enabled');
		}

		$memberUtils = $this->get(MemberUtils::NAME);
		$memberUtils->create($team, $this->getUser());

		// Return to
		$returnToUrl = $request->get('rtu');
		if (is_null($returnToUrl)) {
			$returnToUrl = $request->headers->get('referer');
		}

		return $this->redirect($returnToUrl);
	}

	/**
	 * @Route("/{teamId}/delete", requirements={"teamId" = "\d+"}, name="core_member_delete")
	 */
	public function deleteAction(Request $request, $teamId) {
		$om = $this->getDoctrine()->getManager();
		$userRepository = $om->getRepository(User::CLASS_NAME);

		// Check related user

		$team = $userRepository->findOneById($teamId);
		if (is_null($team)) {
			throw $this->createNotFoundException('Unable to find Team entity (id='.$teamId.').');
		}
		if (!$team->isEnabled()) {
			throw $this->createNotFoundException('Team not enabled');
		}

		$memberUtils = $this->get(MemberUtils::NAME);
		$memberUtils->delete($team, $this->getUser());

		// Return to
		$returnToUrl = $request->get('rtu');
		if (is_null($returnToUrl)) {
			$returnToUrl = $request->headers->get('referer');
		}

		return $this->redirect($returnToUrl);
	}

}