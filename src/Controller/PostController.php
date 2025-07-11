<?php

namespace App\Controller;

use App\Entity\Post;
use App\Entity\User;
use App\Repository\PostRepository;
use App\Repository\UserRepository;
use App\Validation\Post\CreatePostDto;
use App\Validation\Post\PostValidator;
use App\Validation\Post\UpdatePostDto;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Serializer\Exception\NotEncodableValueException;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api/posts')]
class PostController extends AbstractController
{
    public function __construct(
        private readonly SerializerInterface $serializer,
        private readonly ValidatorInterface $validator,
        private readonly PostRepository $postRepository,
        private readonly UserRepository $userRepository,
        private readonly LoggerInterface $logger,
        private readonly PostValidator $postValidator,
        private readonly EntityManagerInterface $entityManager
    ) {
    }

    #[Route('', name: 'post_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        try {
            $content = $request->getContent();
            $this->logger->debug('Contenu de la requête reçue', ['content' => $content]);

            // Désérialisation des données JSON vers le DTO
            $createPostDto = $this->serializer->deserialize(
                $content,
                CreatePostDto::class,
                'json'
            );

            $this->logger->debug('DTO après désérialisation', [
                'content' => $createPostDto->getContent(),
                'type' => $createPostDto->getType(),
                'parent_post_id' => $createPostDto->getParentPostId()
            ]);

            // Validation du DTO
            $errors = $this->validator->validate($createPostDto);
            if (count($errors) > 0) {
                $this->logger->debug('Erreurs de validation', [
                    'errors' => $this->formatValidationErrors($errors)
                ]);
                return $this->json(
                    ['errors' => $this->formatValidationErrors($errors)],
                    Response::HTTP_BAD_REQUEST
                );
            }

            // Récupérer l'utilisateur connecté
            /** @var User $user */
            $user = $this->getUser();
            if (!$user) {
                return $this->json(
                    ['error' => 'Utilisateur non authentifié'],
                    Response::HTTP_UNAUTHORIZED
                );
            }

            // Création de l'entité Post
            $post = new Post();
            $this->createPostFromDto($post, $createPostDto, $user);

            // Persistance en base de données
            $this->postRepository->save($post, true);

            // Logging
            $this->logger->info('Nouveau post créé', [
                'post_id' => $post->getId(),
                'user_id' => $user->getId(),
                'type' => $post->getType()
            ]);

            // Retour de la réponse
            return $this->json(
                [
                    'message' => 'Post créé avec succès',
                    'post' => $post,
                    'links' => [
                        'self' => $this->generateUrl('post_read', ['id' => $post->getId()]),
                        'update' => $this->generateUrl('post_update', ['id' => $post->getId()]),
                        'delete' => $this->generateUrl('post_delete', ['id' => $post->getId()])
                    ]
                ],
                Response::HTTP_CREATED,
                [],
                ['groups' => ['post:read']]
            );

        } catch (NotEncodableValueException $e) {
            $this->logger->error('Erreur de désérialisation JSON', [
                'error' => $e->getMessage(),
                'content' => $request->getContent()
            ]);
            return $this->json(
                ['error' => 'Format JSON invalide'],
                Response::HTTP_BAD_REQUEST
            );
        } catch (\Exception $e) {
            $this->logger->error('Erreur inattendue', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return $this->json(
                ['error' => 'Une erreur est survenue lors de la création du post'],
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    #[Route('', name: 'post_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $page = max(1, $request->query->getInt('page', 1));
        $limit = max(1, min(50, $request->query->getInt('limit', 10))); // Limite entre 1 et 50
        $filters = [
            'user_id' => $request->query->get('user_id'),
            'type' => $request->query->get('type'),
            'language' => $request->query->get('language'),
            'search' => $request->query->get('search')
        ];

        $result = $this->postRepository->findPaginated($page, $limit, $filters);

        return $this->json(
            [
                'posts' => $result['items'],
                'pagination' => [
                    'totalItems' => $result['totalItems'],
                    'totalPages' => $result['totalPages'],
                    'currentPage' => $result['currentPage'],
                    'itemsPerPage' => $result['itemsPerPage'],
                    'hasNextPage' => $result['hasNextPage'],
                    'hasPreviousPage' => $result['hasPreviousPage']
                ]
            ],
            Response::HTTP_OK,
            [],
            ['groups' => ['post:list']]
        );
    }

    #[Route('/popular', name: 'post_popular', methods: ['GET'])]
    public function popular(Request $request): JsonResponse
    {
        $page = max(1, $request->query->getInt('page', 1));
        $limit = max(1, min(50, $request->query->getInt('limit', 10)));

        $result = $this->postRepository->findPopularPosts($page, $limit);

        return $this->json(
            [
                'posts' => $result['items'],
                'pagination' => [
                    'totalItems' => $result['totalItems'],
                    'totalPages' => $result['totalPages'],
                    'currentPage' => $result['currentPage'],
                    'itemsPerPage' => $result['itemsPerPage'],
                    'hasNextPage' => $result['hasNextPage'],
                    'hasPreviousPage' => $result['hasPreviousPage']
                ]
            ],
            Response::HTTP_OK,
            [],
            ['groups' => ['post:list']]
        );
    }

    #[Route('/search', name: 'post_search', methods: ['GET'])]
    public function search(Request $request): JsonResponse
    {
        $search = $request->query->get('q');
        if (empty($search)) {
            return $this->json(
                ['error' => 'Le paramètre de recherche "q" est requis'],
                Response::HTTP_BAD_REQUEST
            );
        }

        $page = max(1, $request->query->getInt('page', 1));
        $limit = max(1, min(50, $request->query->getInt('limit', 10)));

        $result = $this->postRepository->searchPosts($search, $page, $limit);

        return $this->json(
            [
                'posts' => $result['items'],
                'search' => $search,
                'pagination' => [
                    'totalItems' => $result['totalItems'],
                    'totalPages' => $result['totalPages'],
                    'currentPage' => $result['currentPage'],
                    'itemsPerPage' => $result['itemsPerPage'],
                    'hasNextPage' => $result['hasNextPage'],
                    'hasPreviousPage' => $result['hasPreviousPage']
                ]
            ],
            Response::HTTP_OK,
            [],
            ['groups' => ['post:list']]
        );
    }

    #[Route('/{id}', name: 'post_read', methods: ['GET'])]
    public function read(int $id): JsonResponse
    {
        $post = $this->postRepository->find($id);
        if (!$post) {
            return $this->json(
                ['error' => 'Post non trouvé'],
                Response::HTTP_NOT_FOUND
            );
        }

        // Incrémenter le compteur de vues
        $post->incrementViewsCount();
        $this->postRepository->save($post, true);

        return $this->json(
            $post,
            Response::HTTP_OK,
            [],
            ['groups' => ['post:read']]
        );
    }

    #[Route('/{id}', name: 'post_update', methods: ['PUT'])]
    public function update(int $id, Request $request): JsonResponse
    {
        try {
            $post = $this->postRepository->find($id);
            if (!$post) {
                return $this->json(
                    ['error' => 'Post non trouvé'],
                    Response::HTTP_NOT_FOUND
                );
            }

            // Vérifier que l'utilisateur est le propriétaire du post
            /** @var User $user */
            $user = $this->getUser();
            if ($post->getUser()->getId() !== $user->getId()) {
                throw new AccessDeniedException('Vous n\'êtes pas autorisé à modifier ce post');
            }

            $content = $request->getContent();
            $this->logger->debug('Contenu de la requête de mise à jour', ['content' => $content]);

            // Désérialisation des données JSON vers le DTO
            $updatePostDto = $this->serializer->deserialize(
                $content,
                UpdatePostDto::class,
                'json'
            );

            // Validation du DTO
            $errors = $this->validator->validate($updatePostDto);
            if (count($errors) > 0) {
                return $this->json(
                    ['errors' => $this->formatValidationErrors($errors)],
                    Response::HTTP_BAD_REQUEST
                );
            }

            // Mise à jour du post
            $this->updatePostFromDto($post, $updatePostDto);

            // Persistance en base de données
            $this->postRepository->save($post, true);

            // Logging
            $this->logger->info('Post mis à jour', [
                'post_id' => $post->getId(),
                'user_id' => $user->getId()
            ]);

            return $this->json(
                [
                    'message' => 'Post mis à jour avec succès',
                    'post' => $post
                ],
                Response::HTTP_OK,
                [],
                ['groups' => ['post:read']]
            );

        } catch (AccessDeniedException $e) {
            return $this->json(
                ['error' => $e->getMessage()],
                Response::HTTP_FORBIDDEN
            );
        } catch (NotEncodableValueException $e) {
            $this->logger->error('Erreur de désérialisation JSON', [
                'error' => $e->getMessage(),
                'content' => $request->getContent()
            ]);
            return $this->json(
                ['error' => 'Format JSON invalide'],
                Response::HTTP_BAD_REQUEST
            );
        } catch (\Exception $e) {
            $this->logger->error('Erreur inattendue', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return $this->json(
                ['error' => 'Une erreur est survenue lors de la mise à jour du post'],
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    #[Route('/{id}', name: 'post_delete', methods: ['DELETE'])]
    public function delete(int $id): JsonResponse
    {
        try {
            $post = $this->postRepository->find($id);
            if (!$post) {
                return $this->json(
                    ['error' => 'Post non trouvé'],
                    Response::HTTP_NOT_FOUND
                );
            }

            // Vérifier que l'utilisateur est le propriétaire du post
            /** @var User $user */
            $user = $this->getUser();
            if ($post->getUser()->getId() !== $user->getId()) {
                throw new AccessDeniedException('Vous n\'êtes pas autorisé à supprimer ce post');
            }

            // Soft delete
            $post->softDelete();
            $this->postRepository->save($post, true);

            // Logging
            $this->logger->info('Post supprimé', [
                'post_id' => $post->getId(),
                'user_id' => $user->getId()
            ]);

            return $this->json(
                ['message' => 'Post supprimé avec succès'],
                Response::HTTP_OK
            );

        } catch (AccessDeniedException $e) {
            return $this->json(
                ['error' => $e->getMessage()],
                Response::HTTP_FORBIDDEN
            );
        } catch (\Exception $e) {
            $this->logger->error('Erreur inattendue', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return $this->json(
                ['error' => 'Une erreur est survenue lors de la suppression du post'],
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    #[Route('/{id}/replies', name: 'post_replies', methods: ['GET'])]
    public function replies(int $id, Request $request): JsonResponse
    {
        $post = $this->postRepository->find($id);
        if (!$post) {
            return $this->json(
                ['error' => 'Post non trouvé'],
                Response::HTTP_NOT_FOUND
            );
        }

        $page = max(1, $request->query->getInt('page', 1));
        $limit = max(1, min(50, $request->query->getInt('limit', 10)));

        $result = $this->postRepository->findRepliesToPost($post, $page, $limit);

        return $this->json(
            [
                'replies' => $result['items'],
                'pagination' => [
                    'totalItems' => $result['totalItems'],
                    'totalPages' => $result['totalPages'],
                    'currentPage' => $result['currentPage'],
                    'itemsPerPage' => $result['itemsPerPage'],
                    'hasNextPage' => $result['hasNextPage'],
                    'hasPreviousPage' => $result['hasPreviousPage']
                ]
            ],
            Response::HTTP_OK,
            [],
            ['groups' => ['post:list']]
        );
    }

    private function createPostFromDto(Post $post, CreatePostDto $dto, User $user): void
    {
        $post->setContent(trim($dto->getContent()));
        $post->setType($dto->getType());
        $post->setLanguage($dto->getLanguage());
        $post->setUser($user);

        // Si c'est une réponse, définir le post parent
        if ($dto->getType() === 'reply' && $dto->getParentPostId()) {
            $parentPost = $this->postRepository->find($dto->getParentPostId());
            if ($parentPost) {
                $post->setParentPost($parentPost);
            }
        }
    }

    private function updatePostFromDto(Post $post, UpdatePostDto $dto): void
    {
        if ($dto->getContent() !== null) {
            $post->setContent(trim($dto->getContent()));
        }

        if ($dto->getLanguage() !== null) {
            $post->setLanguage($dto->getLanguage());
        }

        if ($dto->getIsPinned() !== null) {
            $post->setIsPinned($dto->getIsPinned());
        }
    }

    private function formatValidationErrors($errors): array
    {
        $formattedErrors = [];
        foreach ($errors as $error) {
            $formattedErrors[$error->getPropertyPath()] = $error->getMessage();
        }
        return $formattedErrors;
    }
} 