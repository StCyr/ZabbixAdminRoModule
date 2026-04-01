<?php
//$this->addCssFile('modules/ZabbixAdminRoModule/views/css/style.css');

$widget = (new CWidget())->setTitle(_('Triggers'));

// Copied from include/views/configuration.triggers.list.php
$filter_column1 = (new CFormList())
        ->addRow((new CLabel(_('Host groups'), 'filter_groupids__ms')),
                (new CMultiSelect([
                        'name' 		=> 'filter_hostgroupids[]',
                        'object_name' 	=> 'hostGroup',
			'data' 		=> $data['filter_hostgroupids']
                ]))->setWidth(ZBX_TEXTAREA_FILTER_STANDARD_WIDTH)
        )
        ->addRow((new CLabel(_('Hosts'))),
                (new CMultiSelect([
                        'name' 		=> 'filter_hostids[]',
                        'object_name' 	=> 'hosts',
			'data' 		=> $data['filter_hostids']
                ]))->setWidth(ZBX_TEXTAREA_FILTER_STANDARD_WIDTH)
        )
        ->addRow(_('Name'),
                (new CTextBox('filter_name', $data['filter_name']))->setWidth(ZBX_TEXTAREA_FILTER_STANDARD_WIDTH)
        )
        ->addRow(_('Severity'),
                (new CCheckBoxList('filter_priority'))
                        ->setOptions(CSeverityHelper::getSeverities())
			->setChecked($data['filter_priority'])
                        ->setColumns(3)
                        ->setVertical()
                        ->showTitles()
       )
       ->addRow(_('Status'),
               (new CRadioButtonList('filter_status', (int) $data['filter_status']))
                       ->addValue(_('all'), -1)
                       ->addValue(triggerIndicator(TRIGGER_STATUS_ENABLED), TRIGGER_STATUS_ENABLED)
                       ->addValue(triggerIndicator(TRIGGER_STATUS_DISABLED), TRIGGER_STATUS_DISABLED)
                       ->setModern(true)
       );
$filter = (new CFilter())
	->setResetUrl((new CUrl('zabbix.php'))->setArgument('action', 'triggers_ro'))
        ->addFilterTab(_('Filter'), [$filter_column1])
	->setActiveTab(1)
	->addVar('action', 'triggers_ro');
$widget->addItem($filter);

// Create result table
$table = new CTableInfo();
$table->setHeader([
	(new CColHeader(_('Host'))),
	(new CColHeader(_('Trigger'))),
#	(new CColHeader(_('Expression'))), # Do not display expressions as it may leak credentials
	(new CColHeader(_('Tags')))
]);

// Adds data to the table
foreach($data['triggers'] as $trigger) {
	$tags = array_reduce($trigger['tags'], function($carry, $tag) { $text = $tag['tag'] . '=' . $tag['value'] . ', '; $carry .= $text; return $carry; }, "");
	$tags = rtrim($tags, ', ');
	$host = new CCol($trigger['hosts'][0]['host']);
	$description = new CCol($trigger['description']);
	$table->addRow([$host, $description, $tags]);
};

// Adds table
$widget->addItem($table);

// Shows screen
$widget->show();
?>
