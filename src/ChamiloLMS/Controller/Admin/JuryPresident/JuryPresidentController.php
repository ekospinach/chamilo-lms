<?php
/* For licensing terms, see /license.txt */

namespace ChamiloLMS\Controller\Admin\JuryPresident;

use ChamiloLMS\Controller\CommonController;
use ChamiloLMS\Form\JuryType;
use ChamiloLMS\Form\JuryUserType;
use Entity;
use Silex\Application;
use Symfony\Component\Form\Extension\Validator\Constraints\FormValidator;
use Symfony\Component\HttpFoundation\Response;

use Symfony\Component\Routing\Annotation\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Whoops\Example\Exception;

/**
 * Class RoleController
 * @todo @route and @method function don't work yet
 * @package ChamiloLMS\Controller
 * @author Julio Montoya <gugli100@gmail.com>
 */
class JuryPresidentController extends CommonController
{
    /**
    * @Route("/")
    * @Method({"GET"})
    */
    public function indexAction()
    {
        $template = $this->get('template');
        $response = $template->render_template($this->getTemplatePath().'index.tpl');
        return new Response($response, 200, array());
    }

    /**
    * @Route("/open-jury")
    * @Method({"GET, POST"})
    */
    public function openJuryAction()
    {
        $user = $this->getUser();

        // @todo where is get this value?
        $juryId = null;

        /** @var Entity\Jury $jury */

        $jury = $this->getEntity($juryId);
        $jury->setOpeningDate(new \DateTime());
        $jury->setOpeningUserId($user->getUserId());
        $this->updateAction($jury);

        $this->get('session')->getFlashBag()->add('success', "Comité abierto");
        //$this->get('session')->getFlashBag()->add('success', "Comité no encontrado");
        $url = $this->get('url_generator')->generate('jury_president.controller:indexAction');
        return $this->redirect($url);
    }

    /**
    * @Route("/close-jury")
    * @Method({"GET, POST"})
    */
    public function closeJuryAction(Application $app)
    {
        $user = $this->getUser();

        // @todo where is get this value?
        $juryId = null;

        /** @var Entity\Jury $jury */
        $jury = $this->getEntity($juryId);
        $jury->setClosureDate(new \DateTime());
        $jury->setClosureUserId($user->getUserId());
        $this->updateAction($jury);

        $this->get('session')->getFlashBag()->add('success', "Comité cerrado");
        $url = $this->get('url_generator')->generate('jury_president.controller:indexAction');
        return $this->redirect($url);
    }

    /**
    * @Route("/close-score")
    * @Method({"GET, POST"})
    */
    public function closeScoreAction()
    {
        $user = $this->getUser();

        // @todo where is get this value?
        $juryId = null;

        /** @var Entity\Jury $jury */
        $jury = $this->getEntity($juryId);

        // @todo ???
        $this->updateAction($jury);

        $this->get('session')->getFlashBag()->add('success', "Notas cerradas");
        $url = $this->get('url_generator')->generate('jury_president.controller:indexAction');
        return $this->redirect($url);
    }

    /**
    * @Route("/assign-user/{userId}/{juryMemberId}")
    * @Method({"GET"})
    */
    public function assignUserToJuryMemberAction($userId, $juryMemberId)
    {
        /** @var Entity\Jury $jury */
        $em = $this->getManager();

        $member = $em->getRepository('Entity\JuryMembers')->find($juryMemberId);
        if ($member) {
            $criteria = array('userId' => $userId, 'juryMemberId'=> $juryMemberId);
            $result = $em->getRepository('Entity\JuryMemberRelUser')->findOneBy($criteria);
            if (empty($result)) {

                $object = new Entity\JuryMemberRelUser();
                $object->setMember($member);
                $object->setUserId($userId);

                $em = $this->getManager();
                $em->persist($object);
                $em->flush();
                return '1';
            }
        }
        return '0';
    }

    /**
    * @Route("/remove-user/{userId}/{juryMemberId}")
    * @Method({"GET"})
    */
    public function removeUserToJuryMemberAction($userId, $juryMemberId)
    {
        $em = $this->getManager();
        $member = $em->getRepository('Entity\JuryMembers')->find($juryMemberId);
        if ($member) {

            $criteria = array('userId' => $userId, 'juryMemberId'=> $juryMemberId);
            $result = $em->getRepository('Entity\JuryMemberRelUser')->findOneBy($criteria);
            if (!empty($result)) {
                $em = $this->getManager();
                $em->remove($result);
                $em->flush();
                return '1';
            }
        }
        return '0';
    }

    /**
    * @Route("/assign-members")
    * @Method({"GET"})
    */
    public function assignMembersAction()
    {
        $user = $this->getUser();
        $userId = $user->getUserId();

        /** @var Entity\Jury $jury */

        $jury = $this->getRepository()->getJuryByUserId($userId);
        if (!$jury) {
            $this->get('session')->getFlashBag()->add('warning', "No tiene un comité asignado.");
            $url = $this->get('url_generator')->generate('jury_president.controller:indexAction');
            return $this->redirect($url);
        }

        // @todo add to a repository
        // $students = $this->getRepository()->getStudentsByJury($jury->getId());

        $attempts = $jury->getExerciseAttempts();

        // @todo move logic in a repository
        /** @var Entity\TrackExercise $attempt */
        $students = array();
        $relations = array();
        $myStatusForStudent = array();
        foreach ($attempts as $attempt) {

            $user = $attempt->getUser();
            $students[] = $user;
            $juryAttempts = $attempt->getJuryAttempts();

            /** @var Entity\TrackExerciseAttemptJury $juryAttempt */
            $tempAttempt = array();
            foreach ($juryAttempts as $juryAttempt) {
                if (!isset($tempAttempt[$juryAttempt->getJuryMemberId()])) {
                    $tempAttempt[$juryAttempt->getJuryMemberId()] = 1;
                } else {
                    $tempAttempt[$juryAttempt->getJuryMemberId()]++;
                }
            }

            foreach ($tempAttempt as $memberId => $answerCount) {
                $relations[$user->getUserId()][$memberId] = $answerCount;
                if ($userId == $memberId) {
                    if ($answerCount == 3) {
                        $myStatusForStudent[$user->getUserId()] = true;
                    } else {
                        $myStatusForStudent[$user->getUserId()] = false;
                    }
                }
            }
        }

        $members = $jury->getMembers();
        /** @var Entity\JuryMembers $member */
        $studentsByMember = array();
        foreach ($members as $member) {
            $students = $member->getStudents();
            foreach ($students as $student) {
                $studentsByMember[$member->getId()][] = $student->getUserId();
            }
        }

        $template = $this->get('template');

        $template->assign('my_status_for_student', $myStatusForStudent);
        $template->assign('relations', $relations);
        $template->assign('attempts', $attempts);
        $template->assign('members', $members);
        $template->assign('jury', $jury);
        $template->assign('students_by_member', $studentsByMember);

        $response = $template->render_template($this->getTemplatePath().'assign_members.tpl');

        return new Response($response, 200, array());
    }

    /**
    * @Route("/check-answers")
    * @Method({"GET"})
    */
    public function checkAnswersAction()
    {
        $template = $this->get('template');
        $response = $template->render_template($this->getTemplatePath().'check_answers.tpl');
        return new Response($response, 200, array());
    }

    protected function getControllerAlias()
    {
        return 'jury_president.controller';
    }

    /**
    * {@inheritdoc}
    */
    protected function getTemplatePath()
    {
        return 'admin/jury_president/';
    }

    /**
     * @return \Entity\Repository\JuryRepository
     */
    protected function getRepository()
    {
        return $this->get('orm.em')->getRepository('Entity\Jury');
    }

    /**
     * {@inheritdoc}
     */
    protected function getNewEntity()
    {
        return new Entity\Jury();
    }

    /**
     * {@inheritdoc}
     */
    protected function getFormType()
    {
        return new JuryType();
    }
}
