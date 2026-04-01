<?php declare(strict_types = 1);
 
namespace Modules\ZabbixAdminRoModule\Actions;
 
use API;
use CControllerResponseData;
use CControllerResponseFatal;
use CController as CAction;
 
class CControllerTriggerRO extends CAction {
	/**
	 * Initialize action. Method called by Zabbix core.
	 *
	 * @return void
	 */
	public function init(): void {
		/**
		 * Disable SID (Sessoin ID) validation. Session ID validation should only be used for actions which involde data
		 * modification, such as update or delete actions. In such case Session ID must be presented in the URL, so that
		 * the URL would expire as soon as the session expired.
		 */
		$this->disableSIDvalidation();
	}
 
	/**
	 * Check and sanitize user input parameters. Method called by Zabbix core. Execution stops if false is returned.
	 *
	 * @return bool true on success, false on error.
	 */
	protected function checkInput(): bool {
		$fields = [
			'filter_hostgroupids' 	=> 'array',
			'filter_hostids' 	=> 'array',
			'filter_name'		=> 'string',
			'filter_priority'	=> 'array',
			'filter_status'		=> 'int32'
		];

		$this->validateInput($fields);
		return true;
	}
 
	/**
	 * Check if the user has permission to execute this action. Method called by Zabbix core.
	 * Execution stops if false is returned.
	 *
	 * @return bool
	 */
	protected function checkPermissions(): bool {
		$permit_user_types = [USER_TYPE_ZABBIX_USER, USER_TYPE_ZABBIX_ADMIN, USER_TYPE_SUPER_ADMIN];
 
		return in_array($this->getUserType(), $permit_user_types);
	}
 
	/**
	 * Prepare the response object for the view. Method called by Zabbix core.
	 *
	 * @return void
	 */
	protected function doAction(): void {
 
		// Retrieve inputs
		$input = $this->getInputAll();
		$hostgroupids = array_values(array_key_exists('filter_hostgroupids', $input) ? $input['filter_hostgroupids'] : []);
		$hostids = array_values(array_key_exists('filter_hostids', $input) ? $input['filter_hostids'] : []);
		$filter_name = array_key_exists('filter_name', $input) ? $input['filter_name'] : NULL;
		$filter_priority = array_values(array_key_exists('filter_priority', $input) ? $input['filter_priority'] : []);
		$filter_status = array_key_exists('filter_status', $input) ? $input['filter_status'] : -1;

		// Gets hosts 
		if (empty($hostgroupids)) {
			$hosts = API::Host()->get([
				'hostids' 	=> $hostids,
				'selectTags' 	=> 'extend',
				'output' 	=> ['hostid']
			]);
		} else {
			$hosts = API::Host()->get([
				'groupids' 	=> $hostgroupids,
				'selectTags' 	=> 'extend',
				'output' 	=> ['hostid']
			]);
		}

		// Gets host triggers based on search criteria
		$hostids = array_map(function($h) {
			return $h['hostid'];
		}, array_values($hosts));
		$params = [
                        'hostids' => $hostids,
                        'expandDescription' => 'true',
                        'expandExpression' => 'true',
                        'selectHosts' => ['host', 'hostid'],
                        'selectTags' => 'extend',
                        'output' => ['description', 'expression', 'status', 'priority']
		];
		if (!is_null($filter_name)) {
			$params['search'] = [
				'description' => $filter_name
			];
		}
		if (!empty($filter_priority) || $filter_status != -1) {
			$filters = [];
			if (!empty($filter_priority)) {
				$filters['priority'] = $filter_priority;
			}
			if ($filter_status != -1) {
				$filters['status'] = $filter_status;
			}
			$params['filter'] = $filters;
		}
		$triggers = API::Trigger()->get($params);

		// Merges tags from host with trigger's ones
		$triggers = array_values($triggers);
		foreach($triggers as &$trigger) {
			$hostid = $trigger['hosts'][0]['hostid'];
			$host = array_filter($hosts, function($host) use ($hostid) {
				return $host['hostid'] == $hostid ? true : false;
			});
			$host = array_values($host);
			$host_tags = array_values($host[0]['tags']);
			$tags = array_merge($trigger['tags'], $host_tags);
			$trigger['tags'] = $tags;
		}

		// Returns response
		$hostgroupids = API::HostGroup()->get([
                        'output'	=> ['groupid', 'name'],
                        'groupids' 	=> $hostgroupids,
                        'preservekeys'	=> true
                ]);
		$hostids = API::Host()->get([
                        'output'	=> ['hostid', 'name'],
                        'hostids' 	=> $hostids,
                        'preservekeys' 	=> true
                ]);
		$response = new CControllerResponseData([
			'filter_hostgroupids' 	=> array_map(function($group) {
				return [
					'id'	=> $group['groupid'],
					'name' 	=> $group['name']
				];
			}, $hostgroupids),
			'filter_hostids'	=> array_map(function($host) {
				return [
					'id' 	=> $host['hostid'],
					'name'	=> $host['name']
				];
			}, $hostids),
			'filter_name'		=> $filter_name,
			'filter_priority'	=> $filter_priority,
			'filter_status'		=> $filter_status,
			'triggers' 		=> $triggers
		]);
		$this->setResponse($response);
	} 
}
