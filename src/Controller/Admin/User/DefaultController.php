<?php

namespace App\Controller\Admin\User;

use App\Entity\User;
use App\Form\Admin\UserFormType;
use App\Repository\UserRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use JetBrains\PhpStorm\ArrayShape;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bridge\Twig\Attribute\Template;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/user', 'app_admin_user_default_')]
class DefaultController
{
    private EntityManagerInterface $entityManager;

    private FormFactoryInterface $formFactory;
    private PaginatorInterface $paginator;
    private UserPasswordHasherInterface $passwordHasher;

    private UserRepository $userRepository;

    public function __construct(
        EntityManagerInterface      $entityManager,
        FormFactoryInterface        $formFactory,
        PaginatorInterface          $paginator,
        UserRepository              $userRepository,
        UserPasswordHasherInterface $passwordHasher
    )
    {
        $this->entityManager = $entityManager;
        $this->formFactory = $formFactory;
        $this->paginator = $paginator;
        $this->userRepository = $userRepository;
        $this->passwordHasher = $passwordHasher;
    }


    #[ArrayShape(['pagination' => "\Knp\Component\Pager\Pagination\PaginationInterface", 'table_search' => "mixed"])]
    #[Route('', 'index')]
    #[Template('admin/user/default_index.html.twig')]
    public function index(Request $request): array
    {
        $searchString = $request->query->get('search', null);
        $queryBuilder = $this->userRepository->createSearchQueryBuilder($searchString);

        $pagination = $this->paginator->paginate($queryBuilder, $request->query->getInt('page', 1), $request->query->getInt('limit', 100));

        return [
            'pagination' => $pagination,
            'table_search' => $searchString,
        ];
    }

    #[Route('/create', 'form_create', condition: "request.isXmlHttpRequest()")]
    #[Route('/{id}/edit', 'form_edit', condition: "request.isXmlHttpRequest()")]
    #[Template('admin/user/default_form.html.twig')]
    #[ParamConverter('user', isOptional: true)]
    public function form(Request $request, ?User $user = null): array|Response
    {
        if (null === $user) {
            $user = new User();
        }

        $form = $this->formFactory->create(UserFormType::class, $user);
        $form->handleRequest($request);

        if (true === $form->isSubmitted() && true === $form->isValid()) {
            $user->setUpdatedAt(new DateTimeImmutable());

            if (null !== $user->getPlainPassword()) {
                $user->setPassword($this->passwordHasher->hashPassword($user, $user->getPlainPassword()));
            }

            $this->entityManager->persist($user);
            $this->entityManager->flush();

            return new Response();
        }

        return [
            'user' => $user,
            'form' => $form->createView(),
        ];
    }

    #[Route('/{id}/delete', 'delete', condition: "request.isXmlHttpRequest()")]
    #[ParamConverter('user')]
    public function delete(User $user): Response
    {
        $user
            ->setDeletedAt(new DateTimeImmutable())
            ->setUpdatedAt(new DateTimeImmutable());
        $deletedString = sprintf('DELETED-%s', $user->getId());

        $user
            ->setPassword(null)
            ->setName($deletedString)
            ->setEmail($deletedString);

        $this->entityManager->flush();

        return new Response();
    }
}
