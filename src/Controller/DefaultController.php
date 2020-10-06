<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\CourseRepository;
use App\Security\LtiAuthenticator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use OAT\Bundle\Lti1p3Bundle\Security\Authentication\Token\Message\LtiMessageToken;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Security\Core\Security;
use Symfony\Component\Security\Guard\GuardAuthenticatorHandler;

class DefaultController extends AbstractController
{
    /** @var Security */
    private $security;

    public function __construct(Security $security)
    {
        $this->security = $security;
    }

    /**
     * @Route("/home", name="default")
     */
    public function index()
    {
        return $this->render('default/index.html.twig', [
            'controller_name' => 'DefaultController',
        ]);
    }

    /**
     * @Route("/lti_test", name="lti_test")
     */
    public function testing(CourseRepository $courseRepository, GuardAuthenticatorHandler $guardAuthenticatorHandler, LtiAuthenticator $ltiAuthenticator, Request $request)
    {
        /** @var LtiMessageToken $token */
        $token = $this->security->getToken();
        if (!$token instanceof LtiMessageToken) {
            throw $this->createAccessDeniedException("Ryan is going to come and get you!!");
        }

        // Related registration
        $registration = $token->getRegistration();
        // You can even access validation results
        $validationResults = $token->getValidationResult();

        // Related LTI message
        //all the payload from ELC; payload depend on how Deployment is created on platform;
        // be sure to include all user and course info in Security Settings
        $ltiMessage = $token->getLtiMessage();

        $userIdentity = $ltiMessage->getUserIdentity();
        $email = $userIdentity->getEmail();
        $firstname = $userIdentity->getGivenName();
        $lastname = $userIdentity->getFamilyName();
        $roles = $ltiMessage->getClaim("https://purl.imsglobal.org/spec/lti/claim/roles");

        $username_claim = $ltiMessage->getClaim("http://www.brightspace.com");
        $username_key='username';
        $username = $username_claim[$username_key];

        $user = $this->getDoctrine()->getManager()->getRepository(User::class)->findOneBy(['username' => $username]);
        if (!$user) {
            //Create User
            //Set Role
        }

        $context = $ltiMessage->getClaim("https://purl.imsglobal.org/spec/lti/claim/context");
        $context_key = 'id';
        $lti_id = $context[$context_key];
        $course =  $courseRepository->findOneByLtiId($lti_id);
        if (!$course) {
            //Create Course
            //Add User to Roll
        }
        $courseid = $courseRepository->findCourseIdByLtiId($lti_id);

        // Actual passing of auth to Symfony firewall and sessioning
        $guardAuthenticatorHandler->authenticateUserAndHandleSuccess($user, $request, $ltiAuthenticator, 'main');

        return $this->redirectToRoute('course_show', ['courseid' => $courseid]);

    }


}