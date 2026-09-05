<?php
/**
 * Mattermost Perforce Swarm module
 *
 * @copyright   2026 PoFig Games Studio. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 */

namespace Mattermost\Controller;

use Application\Permissions\IPermissions;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;
use Mattermost\Config\IConfig;
use Throwable;

/**
 * Renders the Mattermost configuration page. The page itself is a small standalone
 * front end (plain JavaScript, no build step) that talks to the Configurations REST API:
 *   GET    /api/v11/configurations/mattermost
 *   PATCH  /api/v11/configurations/mattermost/workspaces/{id}
 *   DELETE /api/v11/configurations/mattermost/workspaces/{id}
 * @package Mattermost\Controller
 */
class IndexController extends AbstractActionController
{
    const TEMPLATE    = 'mattermost/index/index';
    const API_VERSION = 'v11';

    private $services;

    /**
     * Created by Application\Controller\IndexControllerFactory, which passes the service container.
     * @param mixed $services
     */
    public function __construct($services)
    {
        $this->services = $services;
    }

    /**
     * Configuration page. Restricted to authenticated administrators, the same
     * requirement the Configurations API enforces.
     * @return ViewModel
     */
    public function indexAction()
    {
        $this->services->get(IPermissions::PERMISSIONS)->enforce([IPermissions::ADMIN, IPermissions::AUTHENTICATED]);

        $view = new ViewModel(
            [
                'baseUrl'    => rtrim((string) $this->getRequest()->getBaseUrl(), '/'),
                'apiVersion' => self::API_VERSION,
                'configId'   => IConfig::MATTERMOST,
                'csrfToken'  => $this->getCsrfToken(),
            ]
        );
        $view->setTemplate(self::TEMPLATE);
        return $view;
    }

    /**
     * Best-effort lookup of the session CSRF token so the page can send it in the
     * X-CSRF-TOKEN header exactly like the Swarm React front end does. The page also
     * falls back to the token published in the layout, so an empty value here is not fatal.
     * @return string
     */
    private function getCsrfToken(): string
    {
        foreach (['csrf', 'Application\Permissions\Csrf\Service'] as $serviceName) {
            try {
                if (!$this->services->has($serviceName)) {
                    continue;
                }
                $service = $this->services->get($serviceName);
                if (is_object($service) && method_exists($service, 'getToken')) {
                    return (string) $service->getToken();
                }
            } catch (Throwable $e) {
                // Fall through to the next candidate; the view has its own fallbacks.
            }
        }
        return '';
    }
}
