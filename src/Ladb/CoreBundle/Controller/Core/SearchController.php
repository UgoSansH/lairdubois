<?php

namespace Ladb\CoreBundle\Controller\Core;

use Ladb\CoreBundle\Controller\AbstractController;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Ladb\CoreBundle\Utils\PaginatorUtils;
use Ladb\CoreBundle\Utils\SearchUtils;

/**
 * @Route("/rechercher")
 */
class SearchController extends AbstractController {

	/**
	 * @Route("/", name="core_search")
	 * @Route("/creations", name="core_search_creations")
	 */
	public function searchAction(Request $request) {
		return $this->redirect($this->generateUrl('core_creation_list', array(
			'q'    => $request->get('q'),
		)));
	}

	/**
	 * @Route("/typeahead/users.json", defaults={"_format" = "json"}, name="core_search_typeahead_users_json")
	 * @Template("LadbCoreBundle:Core/Search:searchTypeaheadUsers.json.twig")
	 */
	public function searchTypeaheadUsersAction(Request $request, $page = 0) {
		$searchUtils = $this->get(SearchUtils::NAME);

		$family = $request->get('family');

		$searchParameters = $searchUtils->searchPaginedEntities(
			$request,
			$page,
			function($facet, &$filters, &$sort, &$noGlobalFilters, &$couldUseDefaultSort) use ($family) {
				$bool = new \Elastica\Query\BoolQuery();
				if (!is_null($family)) {
					switch ($family) {
						case 'users':
							$q0 = new \Elastica\Query\Term([ 'isTeam' => [ 'value' => false, 'boost' => 1.0 ] ]);
							$bool->addMust($q0);
							break;
						case 'teams':
							$q0 = new \Elastica\Query\Term([ 'isTeam' => [ 'value' => true, 'boost' => 1.0 ] ]);
							$bool->addMust($q0);
							break;
					}
				}

				$q1 = new \Elastica\Query\QueryString('*'.$facet->value.'*');
				$q1->setFields(array( 'displayname^10', 'username^5', 'fullname' ));
				$bool->addMust($q1);
				$q2 = new \Elastica\Query\SimpleQueryString($facet->value.'*', array( 'displayname^10', 'username^5', 'fullname' ));	// Starts with boost
				$bool->addShould($q2);
				$filters[] = $bool;
			},
			null,
			null,
			'fos_elastica.index.ladb.core_user',
			\Ladb\CoreBundle\Entity\Core\User::CLASS_NAME,
			null
		);

		$parameters = array_merge($searchParameters,  array(
			'users'  => $searchParameters['entities'],
		));
		return $parameters;
	}

	/**
	 * @Route("/typeahead/tags.json", defaults={"_format" = "json"}, name="core_search_typeahead_tags_json")
	 * @Template("LadbCoreBundle:Core/Search:searchTypeaheadTags.json.twig")
	 */
	public function searchTypeaheadTagsAction(Request $request, $page = 0) {
		$searchUtils = $this->get(SearchUtils::NAME);

		$searchParameters = $searchUtils->searchPaginedEntities(
			$request,
			$page,
			function($facet, &$filters, &$sort, &$noGlobalFilters, &$couldUseDefaultSort) {
				$filters[] = new \Elastica\Query\Match('label', $facet->value);
			},
			null,
			null,
			'fos_elastica.index.ladb.core_tag',
			\Ladb\CoreBundle\Entity\Core\Tag::CLASS_NAME,
			null
		);

		$parameters = array_merge($searchParameters,  array(
			'tags'  => $searchParameters['entities'],
		));
		return $parameters;
	}

	/**
	 * @Route("/typeahead/input-skills.json", defaults={"_format" = "json"}, name="core_search_typeahead_input_skills_json")
	 * @Template("LadbCoreBundle:Core/Search:searchTypeaheadInputSkills.json.twig")
	 */
	public function searchTypeaheadInputSkillsAction(Request $request, $page = 0) {
		$searchUtils = $this->get(SearchUtils::NAME);

		$searchParameters = $searchUtils->searchPaginedEntities(
			$request,
			$page,
			function($facet, &$filters, &$sort, &$noGlobalFilters, &$couldUseDefaultSort) {
				$filters[] = new \Elastica\Query\Match('label', $facet->value);
			},
			null,
			null,
			'fos_elastica.index.ladb.input_skill',
			\Ladb\CoreBundle\Entity\Input\Skill::CLASS_NAME,
			null
		);

		$parameters = array_merge($searchParameters,  array(
			'skills'  => $searchParameters['entities'],
		));
		return $parameters;
	}

	/**
	 * @Route("/typeahead/input-woods.json", defaults={"_format" = "json"}, name="core_search_typeahead_input_woods_json")
	 * @Template("LadbCoreBundle:Core/Search:searchTypeaheadInputWoods.json.twig")
	 */
	public function searchTypeaheadInputWoodsAction(Request $request, $page = 0) {
		$searchUtils = $this->get(SearchUtils::NAME);

		$searchParameters = $searchUtils->searchPaginedEntities(
			$request,
			$page,
			function($facet, &$filters, &$sort, &$noGlobalFilters, &$couldUseDefaultSort) {
				$filters[] = new \Elastica\Query\Match('label', $facet->value);
			},
			null,
			null,
			'fos_elastica.index.ladb.input_wood',
			\Ladb\CoreBundle\Entity\Input\Wood::CLASS_NAME,
			null
		);

		$parameters = array_merge($searchParameters,  array(
			'woods'  => $searchParameters['entities'],
		));
		return $parameters;
	}

	/**
	 * @Route("/typeahead/input-tools.json", defaults={"_format" = "json"}, name="core_search_typeahead_input_tools_json")
	 * @Template("LadbCoreBundle:Core/Search:searchTypeaheadInputTools.json.twig")
	 */
	public function searchTypeaheadInputToolsAction(Request $request, $page = 0) {
		$searchUtils = $this->get(SearchUtils::NAME);

		$searchParameters = $searchUtils->searchPaginedEntities(
			$request,
			$page,
			function($facet, &$filters, &$sort, &$noGlobalFilters, &$couldUseDefaultSort) {
				$filters[] = new \Elastica\Query\Match('label', $facet->value);
			},
			null,
			null,
			'fos_elastica.index.ladb.input_tool',
			\Ladb\CoreBundle\Entity\Input\Tool::CLASS_NAME,
			null
		);

		$parameters = array_merge($searchParameters,  array(
			'tools'  => $searchParameters['entities'],
		));
		return $parameters;
	}

	/**
	 * @Route("/typeahead/input-finishes.json", defaults={"_format" = "json"}, name="core_search_typeahead_input_finishes_json")
	 * @Template("LadbCoreBundle:Core/Search:searchTypeaheadInputFinishes.json.twig")
	 */
	public function searchTypeaheadInputFinishesAction(Request $request, $page = 0) {
		$searchUtils = $this->get(SearchUtils::NAME);

		$searchParameters = $searchUtils->searchPaginedEntities(
			$request,
			$page,
			function($facet, &$filters, &$sort, &$noGlobalFilters, &$couldUseDefaultSort) {
				$filters[] = new \Elastica\Query\Match('label', $facet->value);
			},
			null,
			null,
			'fos_elastica.index.ladb.input_finish',
			\Ladb\CoreBundle\Entity\Input\Finish::CLASS_NAME,
			null
		);

		$parameters = array_merge($searchParameters,  array(
			'finishes'  => $searchParameters['entities'],
		));
		return $parameters;
	}

	/**
	 * @Route("/typeahead/input-hardwares.json", defaults={"_format" = "json"}, name="core_search_typeahead_input_hardwares_json")
	 * @Template("LadbCoreBundle:Core/Search:searchTypeaheadInputHardwares.json.twig")
	 */
	public function searchTypeaheadInputHardwaresAction(Request $request, $page = 0) {
		$searchUtils = $this->get(SearchUtils::NAME);

		$searchParameters = $searchUtils->searchPaginedEntities(
			$request,
			$page,
			function($facet, &$filters, &$sort, &$noGlobalFilters, &$couldUseDefaultSort) {
				$filters[] = new \Elastica\Query\Match('label', $facet->value);
			},
			null,
			null,
			'fos_elastica.index.ladb.input_hardware',
			\Ladb\CoreBundle\Entity\Input\Hardware::CLASS_NAME,
			null
		);

		$parameters = array_merge($searchParameters,  array(
			'hardwares'  => $searchParameters['entities'],
		));
		return $parameters;
	}

	/**
	 * @Route("/typeahead/softwares.json", defaults={"_format" = "json"}, name="core_search_typeahead_softwares_json")
	 * @Template("LadbCoreBundle:Core/Search:searchTypeaheadSoftwares.json.twig")
	 */
	public function searchTypeaheadSoftwareAction(Request $request, $page = 0) {
		$searchUtils = $this->get(SearchUtils::NAME);


		$searchParameters = $searchUtils->searchPaginedEntities(
			$request,
			$page,
			function($facet, &$filters, &$sort, &$noGlobalFilters, &$couldUseDefaultSort) {
				$bool = new \Elastica\Query\BoolQuery();
				$q1 = new \Elastica\Query\QueryString('*'.$facet->value.'*');
				$q1->setFields(array( 'name' ));
				$bool->addMust($q1);
				$q2 = new \Elastica\Query\SimpleQueryString($facet->value.'*', array( 'name' ));	// Starts with boost
				$bool->addShould($q2);
				$filters[] = $bool;
			},
			null,
			null,
			'fos_elastica.index.ladb.knowledge_software',
			\Ladb\CoreBundle\Entity\Knowledge\Software::CLASS_NAME,
			null
		);

		$parameters = array_merge($searchParameters, array(
			'softwares' => $searchParameters['entities'],
		));
		return $parameters;
	}

}
