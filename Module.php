<?php declare(strict_types = 1);
 
namespace Modules\ZabbixAdminRoModule;
 
use CController as CAction;
use \Core\CModule,
	APP,
	CMenu,
	CMenuItem;
 
class Module extends CModule {
 
	/**
	 * Initialize module.
	 */
	public function init(): void {

		$menuItem1 = new CMenuItem('Triggers view');
		$menuItem1->setAction('triggers_ro');

		$submenu = new CMenu([
			$menuItem1
		]);

		$menu = new CMenuItem('Custom');
		$menu->setIcon('icon-dashboard');
		$menu->setSubMenu($submenu);

		APP::Component()->get('menu.main')
			->insertAfter(_('Administration'), $menu);
	}
 
	/**
	 * Event handler, triggered before executing the action.
	 *
	 * @param CAction $action  Action instance responsible for current request.
	 */
	public function onBeforeAction(CAction $action): void {
	}
 
	/**
	 * Event handler, triggered on application exit.
	 *
	 * @param CAction $action  Action instance responsible for current request.
	 */
	public function onTerminate(CAction $action): void {
	}
}
