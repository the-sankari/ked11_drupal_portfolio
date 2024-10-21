<?php

declare (strict_types = 1);

namespace Drupal\portfolio\Plugin\rest\resource;

use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\rest\Attribute\RestResource;
use Drupal\rest\ModifiedResourceResponse;
use Drupal\rest\Plugin\ResourceBase;
use Drupal\rest\ResourceResponse;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Route;

/**
 * Represents portfolio records as resources.
 *
 * @DCG
 * The plugin exposes key-value records as REST resources. In order to enable it
 * import the resource configuration into active configuration storage. An
 * example of such configuration can be located in the following file:
 * core/modules/rest/config/optional/rest.resource.entity.node.yml.
 * Alternatively, you can enable it through admin interface provider by REST UI
 * module.
 * @see https://www.drupal.org/project/restui
 *
 * @DCG
 * Notice that this plugin does not provide any validation for the data.
 * Consider creating custom normalizer to validate and normalize the incoming
 * data. It can be enabled in the plugin definition as follows.
 * @code
 *   serialization_class = "Drupal\foo\MyDataStructure",
 * @endcode
 *
 * @DCG
 * For entities, it is recommended to use REST resource plugin provided by
 * Drupal core.
 * @see \Drupal\rest\Plugin\rest\resource\EntityResource
 */
#[RestResource(
    id: 'portfolio_portfolio',
    label: new TranslatableMarkup('portfolio'),
    uri_paths: [
        'canonical' => '/api/decoupled-portfolio/{path}',
        'create' => '/api/decoupled-portfolio',
    ],
)]
final class PortfolioResource extends ResourceBase
{

    /**
     * The key-value storage.
     */
    private readonly KeyValueStoreInterface $storage;

    /**
     * {@inheritdoc}
     */
    public function __construct(
        array $configuration,
        $plugin_id,
        $plugin_definition,
        array $serializer_formats,
        LoggerInterface $logger,
        KeyValueFactoryInterface $keyValueFactory,
    ) {
        parent::__construct($configuration, $plugin_id, $plugin_definition, $serializer_formats, $logger);
        $this->storage = $keyValueFactory->get('portfolio_portfolio');
    }

    /**
     * {@inheritdoc}
     */
    public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self
    {
        return new self(
            $configuration,
            $plugin_id,
            $plugin_definition,
            $container->getParameter('serializer.formats'),
            $container->get('logger.factory')->get('rest'),
            $container->get('keyvalue')
        );
    }

    /**
     * Responds to POST requests and saves the new record.
     */
    public function post(array $data): ModifiedResourceResponse
    {
        $data['id'] = $this->getNextId();
        $this->storage->set($data['id'], $data);
        $this->logger->notice('Created new portfolio record @id.', ['@id' => $data['id']]);
        // Return the newly created record in the response body.
        return new ModifiedResourceResponse($data, 201);
    }

    /**
     * Responds to GET requests.
     */
    public function get($path): ResourceResponse
    {
        try {
            $currentPath = \Drupal::service("path.current")->getPath();
            $pathItems = explode("/", $currentPath);
            $requestNameAlias = $pathItems[3] ?? null;

            if (empty($requestNameAlias)) {
                throw new \Exception('Name query missing!');
            } else {
                $nodeId = $this->getNode($requestNameAlias);
                $node = \Drupal::entityTypeManager()
                    ->getStorage('node')
                    ->load($nodeId);

                if (!$node) {
                    return new ResourceResponse([
                        'status' => 404,
                        'message' => 'Not Found.',
                        'supportMessage' => 'Content not found',
                    ]);
                }

                // Prepare the response data
                $response = [
                    'status' => 200,
                    'data' => [
                        'news' => [],
                    ],
                ];

                // Retrieve news items and format them
                $fieldNews = $node->field_news->getValue();
                foreach ($fieldNews as $newsItem) {
                    $newsNode = \Drupal::entityTypeManager()
                        ->getStorage('node')
                        ->load($newsItem['target_id']);

                    if ($newsNode) {
                        $formattedNewsItem = [
                            'title' => $newsNode->getTitle(),
                            'body' => $this->formatBody($newsNode->body->getValue()),
                            'banners' => $this->formatBanners($newsNode),
                        ];
                        $response['data']['news'][] = $formattedNewsItem;
                    }
                }
            }
        } catch (\Exception $e) {
            \Drupal::logger('portfolio')->error($e->getMessage());

            return new ResourceResponse([
                'status' => 500,
                'message' => 'An error occurred.',
            ]);
        }

        return new ResourceResponse($response);
    }

    private function formatBanners($node)
    {
        $banners = [];
        $fileUrlGenerator = \Drupal::service('file_url_generator');

        // Format banner 1
        if ($node->hasField('field_banner_1') && !$node->field_banner_1->isEmpty()) {
            $banner1 = $node->field_banner_1->entity; // Get the first banner entity
            if ($banner1 instanceof \Drupal\file\Entity\File) { // Check if it's a valid File entity
                $banners['banner_1'] = [
                    'url' => $fileUrlGenerator->generateAbsoluteString($banner1->getFileUri()), // Generate the URL
                  
                ];
            }
        }

        // Format banner 2
        if ($node->hasField('field_banner_2') && !$node->field_banner_2->isEmpty()) {
            $banner2 = $node->field_banner_2->entity;
            if ($banner2 instanceof \Drupal\file\Entity\File) {
                $banners['banner_2'] = [
                    'url' => $fileUrlGenerator->generateAbsoluteString($banner2->getFileUri()),
                    
                ];
            }
        }

        // Format banner 3
        if ($node->hasField('field_banner_3') && !$node->field_banner_3->isEmpty()) {
            $banner3 = $node->field_banner_3->entity;
            if ($banner3 instanceof \Drupal\file\Entity\File) {
                $banners['banner_3'] = [
                    'url' => $fileUrlGenerator->generateAbsoluteString($banner3->getFileUri()),
              
                ];
            }
        }

        return $banners;
    }

    private function formatBody($bodyValue)
    {
        if (!empty($bodyValue)) {
            return $bodyValue[0]['value'] ?? '';
        }
        return '';
    }

    private function getNode($path)
    {
        $result = \Drupal::database()
            ->select('path_alias', 'p')
            ->fields('p')
            ->condition('alias', '/' . $path)
            ->execute()
            ->fetchObject();

        return $result && $result->path ? str_replace('/node/', '', $result->path) : '';
    }

    private function getBanners($node)
    {
        $banners = [];

        // Assume you have fields for banners
        if ($node->hasField('field_banner_1')) {
            $banners['banner_1'] = $node->field_banner_1->getValue();
        }
        if ($node->hasField('field_banner_2')) {
            $banners['banner_2'] = $node->field_banner_2->getValue();
        }
        if ($node->hasField('field_banner_3')) {
            $banners['banner_3'] = $node->field_banner_3->getValue();
        }

        return $banners;
    }

    /**
     * Responds to PATCH requests.
     */
    public function patch($id, array $data): ModifiedResourceResponse
    {
        if (!$this->storage->has($id)) {
            throw new NotFoundHttpException();
        }
        $stored_data = $this->storage->get($id);
        $data += $stored_data;
        $this->storage->set($id, $data);
        $this->logger->notice('The portfolio record @id has been updated.', ['@id' => $id]);
        return new ModifiedResourceResponse($data, 200);
    }

    /**
     * Responds to DELETE requests.
     */
    public function delete($id): ModifiedResourceResponse
    {
        if (!$this->storage->has($id)) {
            throw new NotFoundHttpException();
        }
        $this->storage->delete($id);
        $this->logger->notice('The portfolio record @id has been deleted.', ['@id' => $id]);
        // Deleted responses have an empty body.
        return new ModifiedResourceResponse(null, 204);
    }

    /**
     * {@inheritdoc}
     */
    protected function getBaseRoute($canonical_path, $method): Route
    {
        $route = parent::getBaseRoute($canonical_path, $method);
        // Set ID validation pattern.
        if ($method !== 'POST') {
            $route->setRequirement('id', '\d+');
        }
        return $route;
    }

    /**
     * Returns next available ID.
     */
    private function getNextId(): int
    {
        $ids = \array_keys($this->storage->getAll());
        return count($ids) > 0 ? max($ids) + 1 : 1;
    }

}
