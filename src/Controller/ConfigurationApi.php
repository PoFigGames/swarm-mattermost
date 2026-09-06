<?php
/**
 * Mattermost Perforce Swarm module
 *
 * @copyright   2026 PoFig Games Studio. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 */

namespace Mattermost\Controller;

use Api\Controller\AbstractRestfulController;
use Application\Permissions\Exception\ForbiddenException;
use Application\Permissions\Exception\UnauthorizedException;
use Application\Permissions\IPermissions;
use Configurations\Model\Configuration;
use Exception;
use InvalidArgumentException;
use Laminas\Http\Response;
use Laminas\View\Model\JsonModel;
use Mattermost\Filter\WorkspaceInputFilter;
use Mattermost\Model\IConfiguration;
use Mattermost\Service\IConfigurationStore;
use Record\Exception\NotFoundException as RecordNotFoundException;

/**
 * REST API for the Mattermost servers, used by the module's configuration page:
 *   GET    /api/v11/mattermost/configuration
 *   PATCH  /api/v11/mattermost/configuration/workspaces/{id}   create or update a server
 *   DELETE /api/v11/mattermost/configuration/workspaces/{id}   soft-delete a server
 * Responses carry {"data": {"configurations": [record]}}. Administrators only.
 * @package Mattermost\Controller
 */
class ConfigurationApi extends AbstractRestfulController
{
    const LOG_PREFIX          = ConfigurationApi::class;
    const DATA_CONFIGURATIONS = 'configurations';
    const PARAM_WORKSPACE_ID  = 'workspaceId';

    /**
     * GET the configuration record, creating it on first use.
     *
     * @return JsonModel
     */
    public function configurationAction(): JsonModel
    {
        return $this->run(
            function () {
                return $this->services->get(IConfigurationStore::SERVICE_NAME)->getOrCreate();
            }
        );
    }

    /**
     * PATCH a server: creates it when the id is unknown, merges the given fields otherwise.
     * A blank token or url on update keeps the stored value.
     *
     * @return JsonModel
     */
    public function patchWorkspaceAction(): JsonModel
    {
        $workspaceId = (string) $this->getEvent()->getRouteMatch()->getParam(self::PARAM_WORKSPACE_ID);
        $inputData   = json_decode((string) $this->getRequest()->getContent(), true);
        return $this->run(
            function () use ($workspaceId, $inputData) {
                if (!is_array($inputData) || empty($inputData)) {
                    throw new InvalidArgumentException('Invalid PATCH payload: expected a non-empty JSON object');
                }
                $store         = $this->services->get(IConfigurationStore::SERVICE_NAME);
                $configuration = $store->getOrCreate();
                $workspaces    = (array) ($configuration->get(IConfiguration::WORKSPACES) ?? []);
                $index         = $this->findWorkspace($workspaces, $workspaceId);
                $isNew         = $index === null;
                if ($isNew) {
                    foreach ([IConfiguration::TOKEN, IConfiguration::URL] as $field) {
                        if (trim((string) ($inputData[$field] ?? '')) === '') {
                            throw new InvalidArgumentException($field . ' is required when creating a new server');
                        }
                    }
                }
                $inputData[IConfiguration::ID] = $workspaceId;
                $filter = $this->services->build(WorkspaceInputFilter::SERVICE_NAME);
                $filter->setData($inputData)->setValidationGroupSafe(array_keys($inputData));
                if (!$filter->isValid()) {
                    throw new ValidationException($filter->getMessages());
                }
                $values = $this->prunePatchValues($filter->getValues(), $inputData);
                // Write-only secrets: blank means "keep what is stored"
                foreach ([IConfiguration::TOKEN, IConfiguration::URL] as $field) {
                    if (array_key_exists($field, $values) && trim((string) $values[$field]) === '') {
                        unset($values[$field]);
                    }
                }
                if ($isNew) {
                    $values[IConfiguration::ID]         = $workspaceId;
                    $values[IConfiguration::IS_DELETED] = false;
                    $workspaces[]                       = $values;
                } else {
                    $workspaces[$index] = array_merge($workspaces[$index], $values);
                    // Re-adding a soft-deleted server makes it active again
                    $workspaces[$index][IConfiguration::IS_DELETED] = false;
                }
                $configuration->set(IConfiguration::WORKSPACES, array_values($workspaces));
                return $store->save($configuration);
            }
        );
    }

    /**
     * DELETE a server (soft delete). The last active server cannot be deleted.
     *
     * @return JsonModel
     */
    public function deleteWorkspaceAction(): JsonModel
    {
        $workspaceId = (string) $this->getEvent()->getRouteMatch()->getParam(self::PARAM_WORKSPACE_ID);
        return $this->run(
            function () use ($workspaceId) {
                $store         = $this->services->get(IConfigurationStore::SERVICE_NAME);
                $configuration = $store->getOrCreate();
                $workspaces    = (array) ($configuration->get(IConfiguration::WORKSPACES) ?? []);
                $index         = $this->findWorkspace($workspaces, $workspaceId);
                if ($index === null) {
                    throw new RecordNotFoundException('Server not found');
                }
                $workspaces[$index][IConfiguration::IS_DELETED] = true;
                $active = array_filter(
                    $workspaces,
                    function ($workspace) {
                        return empty($workspace[IConfiguration::IS_DELETED]);
                    }
                );
                if (count($active) === 0) {
                    throw new InvalidArgumentException('Cannot delete the last active server');
                }
                $configuration->set(IConfiguration::WORKSPACES, array_values($workspaces));
                return $store->save($configuration);
            }
        );
    }

    /**
     * Enforce permissions, run the action and translate exceptions into API responses.
     *
     * @param callable $action Returns the Configuration to send back
     * @return JsonModel
     */
    private function run(callable $action): JsonModel
    {
        $errors = null;
        $result = null;
        try {
            $this->services->get(IPermissions::PERMISSIONS)
                ->enforce([IPermissions::ADMIN, IPermissions::AUTHENTICATED]);
            $result = $action();
        } catch (UnauthorizedException $e) {
            $errors = [Response::STATUS_CODE_401, 'Unauthorized'];
        } catch (ForbiddenException $e) {
            $errors = [Response::STATUS_CODE_403, $e->getMessage()];
        } catch (RecordNotFoundException $e) {
            $errors = [Response::STATUS_CODE_404, $e->getMessage()];
        } catch (ValidationException $e) {
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_400);
            return $this->error($e->getMessages(), Response::STATUS_CODE_400);
        } catch (InvalidArgumentException $e) {
            $errors = [Response::STATUS_CODE_400, $e->getMessage()];
        } catch (Exception $e) {
            $errors = [Response::STATUS_CODE_500, $e->getMessage()];
        }
        if ($errors !== null) {
            $this->getResponse()->setStatusCode($errors[0]);
            return $this->error([$this->buildMessage($errors[0], $errors[1])], $errors[0]);
        }
        /** @var Configuration $result */
        return $this->success([self::DATA_CONFIGURATIONS => [$result->toArray()]]);
    }

    /**
     * @param array  $workspaces
     * @param string $workspaceId
     * @return int|null Index of the server with that id, null when absent
     */
    private function findWorkspace(array $workspaces, string $workspaceId): ?int
    {
        foreach ($workspaces as $index => $workspace) {
            if ((string) ($workspace[IConfiguration::ID] ?? '') === $workspaceId) {
                return (int) $index;
            }
        }
        return null;
    }

    /**
     * Keep only the fields that were present in the PATCH payload (recursively), so defaults the
     * filter adds for absent inputs do not overwrite stored values.
     *
     * @param array $validated
     * @param array $original
     * @return array
     */
    private function prunePatchValues(array $validated, array $original): array
    {
        foreach ($validated as $key => $value) {
            if (!array_key_exists($key, $original)) {
                unset($validated[$key]);
            } elseif (is_array($value) && is_array($original[$key])) {
                $validated[$key] = $this->prunePatchValues($value, $original[$key]);
            }
        }
        return $validated;
    }
}
