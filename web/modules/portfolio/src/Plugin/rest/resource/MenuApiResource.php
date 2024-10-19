<?php

declare (strict_types = 1);

namespace Drupal\portfolio\Plugin\rest\resource;

use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreInterface;
use Drupal\Core\Menu\MenuTreeParameters;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\rest\Attribute\RestResource;
use Drupal\rest\ModifiedResourceResponse;
use Drupal\rest\Plugin\ResourceBase;
use Drupal\rest\ResourceResponse;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Route;

/**
 * Represents menu api records as resources.
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
    id: 'portfolio_menu_api',
    label: new TranslatableMarkup('menu api'),
    uri_paths: [
        'canonical' => '/api/portfolio-menu-api/{menu_name}',
        'create' => '/api/portfolio-menu-api',
    ],
)]
final class MenuApiResource extends ResourceBase
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
        $this->storage = $keyValueFactory->get('portfolio_menu_api');
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
        $this->logger->notice('Created new menu api record @id.', ['@id' => $data['id']]);
        // Return the newly created record in the response body.
        return new ModifiedResourceResponse($data, 201);
    }

    /**
     * Responds to GET requests.
     */
    public function get($menu_name): ResourceResponse
    {
        $menu_tree_service = \Drupal::menuTree();
        $parameters = new MenuTreeParameters();
        $tree = $menu_tree_service->load($menu_name, $parameters);
        $menu_items = $this->buildTree($tree);
        return new ResourceResponse($menu_items);
    }

    private function buildTree(array $tree)
    {
        $items = [];
        foreach ($tree as $element) {
            $menu_link = $element->link;
            $item = [
                'title' => $menu_link->getTitle(),
                'url' => $menu_link->getUrlObject()->toString(),
            ];
            if (!empty($element->subtree)) {
                $item['sub_menu'] = $this->buildTree($element->subtree);
            }
            $items[] = $item;
        }
        return $items;
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
        $this->logger->notice('The menu api record @id has been updated.', ['@id' => $id]);
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
        $this->logger->notice('The menu api record @id has been deleted.', ['@id' => $id]);
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
