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
		$this->disableCsrfValidation();
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
		return true;
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

		// Gets the list of all hostids concerned by this call
		$hostids = array_map(function($h) {
			return $h['hostid'];
		}, array_values($hosts));

		// Gets host templates, for merging tags later
		$params = [
			'hostids' => $hostids,
			'selectDiscoveries' => 'extend',
                        'selectTags' => 'extend',
                        'selectTriggers' => 'extend',
			'output' => ['templateid', 'tags']
		];
		$templates = API::Template()->get($params);


		// Gets host LLD rules from templates, to find tags originating from lld rules later
		$params = [
			'hostids' => $hostids,
		];
		$lld_rules = API::Discoveryrule()->get($params);

		// Gets host triggers
		$params = [
                        'hostids' => $hostids,
                        'expandDescription' => 'true',
                        'expandExpression' => 'true',
			'selectDiscoveryRule' => ['itemid'],
                        'selectHosts' => ['host', 'hostid'],
                        'selectTags' => 'extend',
                        'output' => ['description', 'expression', 'status', 'priority', 'templateid']
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

		// Assesses if a trigger is configured to send alerts to AMS when a problem occurs based on it.
		$triggers = array_values($triggers);
		$parent_triggers = [];
		foreach($triggers as &$trigger) {

			// Merges tags from host with trigger's ones
			$hostid = $trigger['hosts'][0]['hostid'];
			$host = array_filter($hosts, function($host) use ($hostid) {
				return ($host['hostid'] == $hostid);
			});
			$host = array_values($host);
			$host_tags = array_values($host[0]['tags']);
			$tags = array_merge($trigger['tags'], $host_tags);
			$trigger['tags'] = $tags;

			// Merges tags from parent trigger when it comes from a template
			if ($trigger['templateid'] != 0) {
				// Get the parent trigger
				$p_triggerid = $trigger['templateid'];
				if (array_key_exists("t" . strval($p_triggerid), $parent_triggers)) {
					$p_trigger = $parent_triggers["t" . strval($p_triggerid)];
				} else {
					$params = [
			                        'triggerids' => [$trigger['templateid']],
			                        'selectTags' => 'extend',
					];
					$p_trigger = API::Trigger()->get($params);
					$p_trigger = array_values($p_trigger)[0];
					$parent_triggers["t" . strval($p_triggerid)] = $p_trigger; 
				}

				// Merges parent trigger tags with trigger's ones
				$p_trigger_tags = array_values($p_trigger['tags']);
				$tags = array_merge($trigger['tags'], $p_trigger_tags);
				$trigger['tags'] = $tags;

				// Merges parent trigger's template tags with trigger's ones
				$template = array_filter($templates, function($template) use ($p_triggerid) {
					$has_trigger = array_filter($template['triggers'], function($t) use ($p_triggerid) {
						return ($t['triggerid'] == $p_triggerid);
					});
					$has_trigger = array_values($has_trigger);

					return (!empty($has_trigger));
				});
				$template = array_values($template);
				if (!empty($template)) {
					$template_tags =  array_values($template[0]['tags']);
					$tags = array_merge($trigger['tags'], $template_tags);
					$trigger['tags'] = $tags;
				}
			}

			// Merges tags from parent trigger when it comes from a LLD discory rule
			if (!empty($trigger['discoveryRule'])) {
				// Gets the trigger's host LLD rule 
				$lld_ruleid = $trigger['discoveryRule']['itemid'];
				$lld_rule = array_filter($lld_rules, function($rule) use ($lld_ruleid) {
					return ($rule['itemid'] == $lld_ruleid);
				});
				$lld_rule = array_values($lld_rule);

				// Finds the top parent LLD rule
				$lld_rule = $this->findTopParentLLDRule($lld_rules, $lld_rule[0]);

				// Merges the top parent LLD rule template tags with trigger's ones
				if (array_key_exists('hostid', $lld_rule) && $lld_rule['hostid'] != 0) {
					$templateid = $lld_rule['hostid'];
					$template = array_filter($templates, function($template) use ($templateid) {
						return ($template['templateid'] == $templateid);
					});
					$template = array_values($template);
					if (!empty($template)) {
						$template_tags =  array_values($template[0]['tags']);
						$tags = array_merge($trigger['tags'], $template_tags);
						$trigger['tags'] = $tags;
					}
				}
			}

			// Creates an ams_compatible property that tells when the trigger is configured so that Zabbix may send an alert to the AMS platform
			$ams_compatible = [];
			foreach($tags as $tag) {
				if ($tag['tag'] == 'incident_alerts' and $tag['value'] == 'true') {
					$ams_compatible['incident_alerts'] = 'true';
				}
				if ($tag['tag'] == 'ams_eventname' and $tag['value'] != '') {
					$ams_compatible['ams_eventname'] = 'true';
				}
				if ($tag['tag'] == 'ams_admin_group' and $tag['value'] != '') {
					$ams_compatible['ams_admin_group'] = 'true';
				}
			}
			if (array_key_exists('incident_alerts', $ams_compatible) &&
			    array_key_exists('ams_admin_group', $ams_compatible) &&
			    array_key_exists('ams_eventname', $ams_compatible)) {
				$trigger['ams_compatible'] = 'true';
			} else {
				$trigger['ams_compatible'] = 'false';
			}
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
			'triggers' 		=> $triggers,
			'templates'		=> $templates
		]);
		$this->setResponse($response);
	} 

	// Recursively searches for the top parent lld rule
	private function findTopParentLLDRule(&$lld_rules, $lld_rule) {
		if (array_key_exists('templateid', $lld_rule) && $lld_rule['templateid'] != 0) {
			// Finds parent LLD rule
			$p_lld_ruleid = $lld_rule['templateid'];
			$lld_rule = array_filter($lld_rules, function($rule) use ($p_lld_ruleid) {
				return ($rule['itemid'] == $p_lld_ruleid);
			});
			$lld_rule = array_values($lld_rule);
			if(empty($lld_rule)) {
				// parent LLD rule hasn't been fetched yet
				$params = [
					'itemids' => $p_lld_ruleid,
				];
				$lld_rule = API::Discoveryrule()->get($params);
			}
			$lld_rule = array_values($lld_rule);
			array_push($lld_rules, $lld_rule[0]);
			$lld_rule = $this->findTopParentLLDRule($lld_rules, $lld_rule[0]);
		}
		return $lld_rule;
	}
}
